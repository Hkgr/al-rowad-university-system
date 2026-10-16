import { useCallback, useEffect, useRef, useState } from 'react'
import { useBlocker, useLocation, useParams, useSearchParams } from 'react-router-dom'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { apiRequest } from '../../../services/apiClient'
import { ACCESS, canAccess, getIdentity } from '../../auth/auth'
import { OfficeLink as Link } from '../../vice-presidency/utils/administrativeOfficeNavigation'
import ManualGradeDialog from '../components/ManualGradeDialog'
import { Pager, button, card, field } from '../components/RegistrationGridRow'
import { searchPath } from '../lib/manualGradeEntry'
import { studentSearchLifecycle } from '../lib/studentSearchLifecycle'
import { caseStates, damascusTime, deadlineEvidence, expiryOutcomes, failedAttempt, freezeAttempt, hasRecoveryRead, incompleteError, incompleteLabels, incompletePage, incompleteRead, listQuery, newDraft, operationLabels, receiveDraft, registrationResultStates, reviewDraft, sendAttempt } from '../lib/incompleteResults'
import { missingValue } from '../../academic-record/lib/resultSymbols'

const stamp = () => JSON.stringify(getIdentity())
const authorized = () => canAccess(ACCESS.manualGradeEntry, getIdentity())
const primary = 'inline-flex items-center justify-center gap-2 rounded-[10px] border border-primary bg-primary px-3.5 py-2.5 text-[12px] font-bold text-white hover:bg-primary-dark disabled:opacity-40'
const label = 'flex min-w-0 flex-col gap-2 text-[12px] font-bold text-text-dark'

function useAuthorization() {
  const [session, setSession] = useState(() => ({ identity: stamp(), allowed: authorized() }))
  useEffect(() => {
    const check = () => setSession(current => current.identity !== stamp() || current.allowed !== authorized() ? { identity: stamp(), allowed: authorized() } : current)
    window.addEventListener('storage', check); window.addEventListener('focus', check)
    const timer = setInterval(check, 500)
    return () => { clearInterval(timer); window.removeEventListener('storage', check); window.removeEventListener('focus', check) }
  }, [])
  return session
}

function useRead(path, identity, onDenied) {
  const [snapshot, setSnapshot] = useState(null), [version, setVersion] = useState(0)
  const key = `${path}:${version}`
  useEffect(() => {
    const abort = new AbortController()
    let active = true
    const current = () => active && !abort.signal.aborted && identity === stamp() && authorized()
    incompleteRead(path, abort.signal).then(data => { if (current()) setSnapshot({ key, path, data, readKey: key, error: '' }) })
      .catch(e => { if (current() && e.name !== 'AbortError') { setSnapshot(previous => ({ key, path, data: previous?.path === path ? previous.data : null, readKey: previous?.path === path ? previous.readKey : null, error: incompleteError(e) })); if ([401, 403].includes(e.status)) onDenied() } })
    return () => { active = false; abort.abort() }
  }, [path, identity, key, onDenied])
  return { data: snapshot?.path === path ? snapshot.data : null, readKey: snapshot?.path === path ? snapshot.readKey : null, error: snapshot?.key === key ? snapshot.error : '', loading: snapshot?.key !== key, reload: () => setVersion(value => value + 1) }
}

function StudentFilter({ onSelect, onDenied }) {
  const [q, setQ] = useState(''), [intent, setIntent] = useState(null), [applied, setApplied] = useState(null), [result, setResult] = useState(null), [error, setError] = useState(''), [loading, setLoading] = useState(false)
  const lifecycle = useRef(studentSearchLifecycle())
  useEffect(() => {
    if (!intent?.query) return
    const timer = setTimeout(() => { if (lifecycle.current.valid(intent)) setApplied(intent) }, 350)
    return () => clearTimeout(timer)
  }, [intent])
  useEffect(() => {
    if (!applied?.query || !lifecycle.current.valid(applied)) return
    const abort = new AbortController(); lifecycle.current.bind(applied, abort)
    const identity = stamp(), current = () => !abort.signal.aborted && lifecycle.current.valid(applied) && stamp() === identity && authorized()
    setLoading(true); setError('')
    apiRequest(searchPath(applied.query, applied.page), { signal: abort.signal }).then(json => { if (current()) setResult(json.data) })
      .catch(e => { if (current() && e.name !== 'AbortError') { setError(incompleteError(e)); if ([401, 403].includes(e.status)) onDenied() } }).finally(() => { if (current()) setLoading(false) })
    return () => abort.abort()
  }, [applied, onDenied])
  useEffect(() => () => lifecycle.current.invalidate(), [])
  const change = value => {
    setQ(value); const next = lifecycle.current.change(value)
    if (next) { setIntent(next); setResult(null); setError(''); setLoading(false); if (!next.query) setApplied(null) }
  }
  return <div className="space-y-2"><label className={label}>تصفية باسم الطالب أو رقمه<input type="text" className={field} value={q} onChange={event => change(event.target.value)} /></label>
    {loading && <p role="status">جاري البحث…</p>}{error && <p role="alert">{error} <button className={button} onClick={() => { const retry = lifecycle.current.paginate(applied, applied.page); if (retry) setApplied(retry) }}>إعادة البحث</button></p>}
    {result && <div className="rounded-[10px] border border-primary/15 p-3">{result.students.map(student => <button className="block w-full rounded p-2 text-right hover:bg-primary/5" key={student.student_id} onClick={() => { onSelect(student.student_id); change('') }}>{student.name} — <bdi>{student.student_number}</bdi> — {student.college}</button>)}
      {!result.students.length && <p>لا توجد نتائج ضمن نطاقك.</p>}<Pager meta={result.meta} disabled={loading} onPage={page => { const next = lifecycle.current.paginate(applied, page); if (next) { setResult(null); setApplied(next) } }} /></div>}
  </div>
}

export default function IncompleteResultsPage() {
  const session = useAuthorization()
  const [denied, setDenied] = useState(false)
  const deny = useCallback(() => setDenied(true), [])
  const { caseId, registrationId } = useParams()
  if (!session.allowed || denied) return <p role="alert" dir="rtl">الوصول غير متاح. أعد تسجيل الدخول أو راجع المسؤول.</p>
  return caseId || registrationId
    ? <CaseWorkspace key={`${session.identity}:${caseId ? `case:${caseId}` : `registration:${registrationId}`}`} identity={session.identity} path={caseId ? `/${caseId}` : `/registrations/${registrationId}`} onDenied={deny} />
    : <CaseList key={session.identity} identity={session.identity} onDenied={deny} />
}

function CaseList({ identity, onDenied }) {
  const [search, setSearch] = useSearchParams(), query = listQuery(search.toString()), path = `?${query}`
  const { data, error, loading, reload } = useRead(path, identity, onDenied)
  const change = (key, value) => { const next = listQuery(search.toString()); next.delete('page'); value ? next.set(key, value) : next.delete(key); if (key === 'academic_year_id') next.delete('semester_id'); setSearch(next) }
  const terms = data?.filter_options?.terms || [], years = [...new Map(terms.map(term => [term.academic_year_id, term])).values()]
  const semesterOptions = terms.filter(term => !query.get('academic_year_id') || String(term.academic_year_id) === query.get('academic_year_id'))
  const semesters = [...new Map(semesterOptions.map(term => [term.semester_id, term])).values()]
  const selectedOption = (options, key, name) => {
    const value = query.get(key)
    return value && !options.some(option => String(option.value) === value) ? [...options, { value, label: `${name} المحدد (${value})` }] : options
  }
  return <main dir="rtl" className="min-w-0 space-y-5 text-[13px] text-text-gray">
    <header><h1 className="text-[20px] font-black text-text-dark">النتائج غير المكتملة I</h1><p className="text-[12.5px] text-text-light">منح موثقة ومهل وتسويات فردية؛ حفظها لا يستبدل اعتماد الطرح.</p></header>
    <section className={`${card} space-y-4 p-5`}><StudentFilter onSelect={id => change('student_id', id)} onDenied={onDenied} />
      {query.get('student_id') && <p>الطالب المحدد: {query.get('student_id')} <button className={button} onClick={() => change('student_id', '')}>إلغاء اختيار الطالب</button></p>}
      <FilterBar filters={[
        { key: 'state', value: query.get('state') || '', onChange: value => change('state', value), placeholder: 'جميع الحالات', options: Object.entries(caseStates).map(([value, text]) => ({ value, label: text })) },
        { key: 'year', value: query.get('academic_year_id') || '', onChange: value => change('academic_year_id', value), placeholder: 'جميع السنوات', options: selectedOption(years.map(term => ({ value: term.academic_year_id, label: term.year_name })), 'academic_year_id', 'العام') },
        { key: 'semester', value: query.get('semester_id') || '', onChange: value => change('semester_id', value), placeholder: 'جميع الفصول', options: selectedOption(semesters.map(term => ({ value: term.semester_id, label: term.semester_name })), 'semester_id', 'الفصل') },
      ]} hasActiveFilters={[...query.keys()].some(key => key !== 'page')} onClear={() => setSearch({})} />
      {data?.filter_options?.complete === false && <p role="status">خيارات الفترات كثيرة؛ حدد الطالب لتضييقها. لم تُختصر قائمة الحالات.</p>}
    </section>
    {error && <div role="alert">{error} <button className={button} onClick={reload}>إعادة المحاولة</button></div>}
    <DataTable columns={[
      { key: 'student', header: 'الطالب', render: row => <><strong>{row.student.full_name}</strong><p><bdi>{row.student.number}</bdi></p></> },
      { key: 'course', header: 'المقرر والفترة', render: row => <><strong>{row.course.name} — <bdi>{row.course.code}</bdi></strong><p>{row.academic_year.name} — {row.semester.name}</p></> },
      { key: 'state', header: 'الحالة', render: row => caseStates[row.state] || 'غير متاح' },
      { key: 'deadline', header: 'المهلة — دمشق', render: row => damascusTime(row.deadline_at) },
      { key: 'open', header: 'التفاصيل', render: row => <Link className={button} to={`${incompletePage}/${row.incomplete_case_id}?${query}`}>فتح الحالة</Link> },
    ]} rows={data?.data || []} rowKey={row => row.incomplete_case_id} loading={loading} animationKey={path} emptyTitle="لا توجد حالات ضمن المرشحات" page={data?.current_page || 1} totalPages={data?.last_page || 1} onPageChange={page => { const next = listQuery(search.toString()); next.set('page', page); setSearch(next) }} />
    {data && <p className="text-[12px]">{data.total} حالة — القائمة ضمن نطاق الطالب والطرح المصرح بهما.</p>}
  </main>
}

function CaseWorkspace({ path, identity, onDenied }) {
  const read = useRead(path, identity, onDenied), { search } = useLocation()
  return <main dir="rtl" className="min-w-0 space-y-5 text-[13px] text-text-gray">
    <header><Link className={button} to={`${incompletePage}${search}`}>العودة إلى الحالات</Link><h1 className="mt-4 text-[20px] font-black text-text-dark">النتيجة غير المكتملة — التفاصيل والإجراءات</h1></header>
    {read.error && <p role="alert">{read.error} <button className={button} onClick={read.reload}>قراءة الحالة الحالية</button></p>}
    {read.loading && <p role="status">جاري قراءة الحالة…</p>}
    {read.data && <CaseEditor view={read.data} readKey={read.readKey} readError={read.error} loading={read.loading} refresh={read.reload} identity={identity} onDenied={onDenied} />}
  </main>
}

function CaseEditor({ view, readKey, readError, loading, refresh, identity, onDenied }) {
  const [storedDraft, setDraft] = useState(null), [confirm, setConfirm] = useState(false), [busy, setBusy] = useState(false), [error, setError] = useState(''), [notice, setNotice] = useState('')
  const draft = storedDraft ? receiveDraft(storedDraft, view) : null
  const mounted = useRef(true), writing = useRef(false)
  const current = useCallback(() => mounted.current && stamp() === identity && authorized(), [identity])
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  const dirty = !!draft
  const blocker = useBlocker(useCallback(() => current() && (dirty || writing.current), [current, dirty]))
  useEffect(() => { if (!current() && blocker.state === 'blocked') blocker.proceed() }, [current, blocker])
  useEffect(() => {
    const warn = event => { if (current() && (dirty || writing.current)) { event.preventDefault(); event.returnValue = '' } }
    window.addEventListener('beforeunload', warn); return () => window.removeEventListener('beforeunload', warn)
  }, [current, dirty])
  const execute = async () => {
    if (writing.current || !draft || draft.rejected || loading || readError || (draft.conflict && !draft.uncertain) || (draft.uncertain && !hasRecoveryRead(draft, readKey)) || !current()) return
    let attempt = draft.attempt
    try { attempt ||= freezeAttempt(draft.baseline, draft.action, draft.proposal) } catch (e) { setError(e.message); setConfirm(false); return }
    writing.current = true; setBusy(true); setError(''); setNotice(''); setConfirm(false); setDraft(previous => ({ ...previous, attempt }))
    try {
      await sendAttempt(attempt)
      if (!current()) return
      setDraft(null); setNotice('تم حفظ العملية. تُقرأ الحالة الحالية دون إعادة اعتماد الطرح.'); refresh()
    } catch (e) {
      if (!current()) return
      if ([401, 403].includes(e.status)) { setDraft(null); onDenied(); return }
      setError(incompleteError(e))
      setDraft(previous => failedAttempt(previous, e, readKey))
      if ([409, 422].includes(e.status)) {
        setNotice('رفض الخادم هذه المحاولة صراحةً. احتُفظ بالمقترح والطلب الأصلي؛ راجع الحالة الحالية ثم اختر إعادة الإعداد أو التجاهل. لا يعني ذلك إلغاء أثر محاولة سابقة.')
        refresh()
      } else setNotice('لم تتأكد نتيجة الكتابة. لم يُعد الطلب تلقائيًا؛ اقرأ الحالة الحالية أولًا.')
    } finally { writing.current = false; if (current()) setBusy(false) }
  }
  const patch = (key, value) => { if (!busy && !draft.attempt && !draft.uncertain && !draft.rejected) { setConfirm(false); setDraft(previous => ({ ...previous, proposal: { ...previous.proposal, [key]: value } })) } }
  const baseline = draft?.baseline || view, caseData = view.case
  const allowed = view.allowed_operations
  const recoveryReady = draft && hasRecoveryRead(draft, readKey) && !loading && !readError
  const locked = busy || loading || !!readError || draft?.conflict || !!draft?.attempt || draft?.uncertain || draft?.rejected
  return <>
    <section className={`${card} space-y-3 p-5`}>
      <h2 className="text-[17px] font-black text-text-dark">{view.student.full_name} — <bdi>{view.student.number}</bdi></h2>
      <p>{view.course.name} — <bdi>{view.course.code}</bdi></p><p>{view.academic_year.name} — {view.semester.name} — الطرح {view.offering.id}</p>
      <p>{caseData ? `الحالة: ${caseStates[caseData.state]}` : view.legacy_incomplete_without_grant ? 'نقص علامات قديم غير موثق كمنحة I' : 'لا توجد منحة I موثقة لهذا التسجيل'}</p>
      <p>{view.is_official ? 'الطرح معتمد؛ تعرض النتيجة وفق أهليتها الرسمية.' : 'بانتظار اعتماد الطرح؛ ليست نتيجة رسمية.'}</p>
      <p>{registrationResultStates[view.state] || 'وضع النتيجة غير متاح'}</p>
      {view.current_result && <p>النتيجة الحالية: <bdi>{missingValue(view.current_result.letter_grade)}</bdi> — العلامة {missingValue(view.current_result.final_mark)}</p>}
      {caseData && <dl className="space-y-2"><div><dt>المهلة — دمشق</dt><dd>{damascusTime(caseData.deadline_at)} (Asia/Damascus)</dd></div><div><dt>مصدر المهلة المقبولة</dt><dd>{deadlineEvidence(caseData).map(detail => <p key={detail}>{detail}</p>)}</dd></div><div><dt>السبب</dt><dd className="whitespace-pre-wrap break-words">{caseData.reason}</dd></div><div><dt>المتطلبات</dt><dd className="whitespace-pre-wrap break-words">{caseData.requirements}</dd></div><div><dt>مخرج الانتهاء</dt><dd><bdi>{expiryOutcomes[caseData.expiry_outcome] || caseData.expiry_outcome}</bdi></dd></div></dl>}
      {view.blocking_code && <p className="text-amber-800">{incompleteLabels[view.blocking_code] || 'الإجراء مقيد بالحالة الأكاديمية الحالية.'}</p>}
      {allowed.resolve && !view.resolution.available && <p className="text-amber-800">{incompleteLabels[view.resolution.blocking_code] || 'التسوية غير متاحة وفق تعريف المكونات الحالي.'}</p>}
      <button className={button} disabled={busy || loading} onClick={refresh}>قراءة الحالة الحالية</button>
    </section>
    {notice && <p role="status">{notice}</p>}{error && <p role="alert" className="text-red-700">{error}</p>}
    {!draft && <div className="flex flex-wrap gap-2">{allowed.grant && <button className={primary} disabled={loading || !!readError} onClick={() => { setError(''); setNotice(''); setDraft(newDraft(view, 'grant')) }}>منح I</button>}{allowed.deadline_update && <button className={button} disabled={loading || !!readError} onClick={() => { setError(''); setNotice(''); setDraft(newDraft(view, 'deadline')) }}>تعديل المهلة</button>}{allowed.resolve && view.resolution.available && <button className={primary} disabled={loading || !!readError} onClick={() => { setError(''); setNotice(''); setDraft(newDraft(view, 'resolve')) }}>تسوية الحالة</button>}</div>}
    {draft && <section className={`${card} space-y-4 p-5`}>
      <h2 className="text-[16px] font-black">{draft.action === 'deadline' ? 'تعديل المهلة' : draft.action === 'resolve' ? 'تسوية الحالة' : 'منح I'}</h2>
      {draft.conflict && <div role="alert" className="space-y-3 rounded-[10px] border border-amber-300 bg-amber-50 p-4"><p>احتُفظ بالمقترح ومراجعة البداية ({draft.baseline.case?.revision ?? 0}) منفصلين عن حالة الخادم ({draft.server.case?.revision ?? 0}). راجع الحالة أعلاه قبل القرار.</p><button className={button} disabled={loading || busy || !!readError || draft.uncertain || (!!draft.attempt && !recoveryReady)} onClick={() => { const next = reviewDraft(draft, readKey); if (next) { setDraft(next); setError(''); setConfirm(false) } else setError('لا تسمح الحالة الحالية أو المكونات بإعادة إعداد هذا المقترح؛ احتفظ بالقيم للمراجعة أو تجاهله صراحة. لا يمكن إعادة فتح حالة نهائية.') }}>راجعت الحالة — إعادة إعداد مقترحي</button></div>}
      {draft.action === 'grant' && <><label className={label}>سبب المنح<textarea className={field} maxLength={4000} disabled={locked} value={draft.proposal.reason} onChange={e => patch('reason', e.target.value)} /></label><label className={label}>متطلبات الاستكمال<textarea className={field} maxLength={4000} disabled={locked} value={draft.proposal.requirements} onChange={e => patch('requirements', e.target.value)} /></label>
        <fieldset disabled={locked} className="space-y-2"><legend>مخرج انتهاء المهلة — اختيار إلزامي</legend>{['F', 'Z'].map(outcome => <label key={outcome} className="ml-5 inline-flex gap-2"><input type="radio" name="expiry" checked={draft.proposal.expiry_outcome === outcome} onChange={() => patch('expiry_outcome', outcome)} />{expiryOutcomes[outcome]}</label>)}</fieldset>
        <p>{baseline.deadline_suggestion?.available ? `اقتراح التقويم المنشور: ${damascusTime(baseline.deadline_suggestion.deadline_at)} — المصدر: فترة الدراسة للفصل التالي.` : 'لا يوجد اقتراح تقويم موثوق؛ يلزم موعد يدوي.'}</p></>}
      {draft.action !== 'resolve' && <label className={label}>{draft.action === 'grant' && baseline.deadline_suggestion?.available ? 'موعد يدوي بديل — اختياري' : 'الموعد'}<input type="date" className={field} disabled={locked} value={draft.proposal.deadline_at} onChange={e => patch('deadline_at', e.target.value)} /><span className="text-text-light">نهاية اليوم المختار بتوقيت دمشق؛ يحسم الخادم الموعد ويحفظه UTC.</span></label>}
      {draft.action === 'resolve' && <div className="space-y-3"><p>تُستكمل العلامات المطلوبة الغائبة فقط. الصفر المحفوظ مكتمل ولا يُستبدل.</p>{baseline.components.map(component => <label key={component.grade_component_id} className={label}>{component.name} — الحد {component.max_mark}<input type="text" className={field} inputMode="decimal" disabled={locked || !component.editable} value={component.editable ? draft.proposal.marks[component.grade_component_id] ?? '' : missingValue(component.mark)} onChange={e => patch('marks', { ...draft.proposal.marks, [component.grade_component_id]: e.target.value })} /><span className="text-text-light">{incompleteLabels[component.blocking_code] || ''}</span></label>)}</div>}
      {draft.uncertain && <p role="alert">لا تعدّل الطلب غير المؤكد ولا تنشئ بديلًا له. اقرأ الحالة؛ إعادة الإرسال الصريحة تستخدم UUID والمحتوى الأصليين فقط.</p>}
      <div className="flex flex-wrap gap-2"><button className={primary} disabled={busy || loading || (draft.conflict && !draft.uncertain) || (draft.uncertain && !recoveryReady) || !!readError || draft.rejected} onClick={() => { try { if (!draft.attempt) freezeAttempt(baseline, draft.action, draft.proposal); setError(''); setConfirm(true) } catch (e) { setError(e.message) } }}>{draft.uncertain ? 'إعادة إرسال الطلب الأصلي بعد القراءة' : 'مراجعة وتأكيد'}</button><button className={button} disabled={busy || draft.uncertain} onClick={() => { setDraft(null); setConfirm(false); setError('') }}>تجاهل المقترح صراحة</button></div>
    </section>}
    {!draft && <section className={`${card} space-y-3 p-5`}><h2 className="font-black">المكونات المطلوبة</h2>{view.components.map(component => <p key={component.grade_component_id}>{component.name}: {missingValue(component.mark)} / {component.max_mark} — {incompleteLabels[component.blocking_code] || 'تُستكمل عبر إجراء التسوية فقط'}</p>)}{!view.components.length && <p>لم تُعرّف المكونات المطلوبة؛ لا تُفترض علامات أو تقسيمات.</p>}</section>}
    <section className={`${card} space-y-3 p-5`}><h2 className="text-[16px] font-black">تاريخ الحالة</h2>{view.events?.map(event => <article className="border-b border-primary/10 pb-3" key={event.incomplete_event_id}><strong>{operationLabels[event.action] || 'حدث مسجل'}</strong> — {damascusTime(event.performed_at)} — {event.actor_label}<p>المراجعة {event.revision}</p>{event.action === 'deadline_changed' && <p>المهلة: {damascusTime(event.before_values?.deadline_at)} ← {damascusTime(event.after_values?.deadline_at)}</p>}</article>)}{!view.events?.length && <p>لا توجد أحداث معروضة. افتح الحالة المحفوظة لعرض تاريخها.</p>}{caseData && !view.events && <Link className={button} to={`${incompletePage}/${caseData.incomplete_case_id}`}>فتح تاريخ الحالة</Link>}</section>
    {confirm && <ManualGradeDialog title="تأكيد العملية" busy={busy} onCancel={() => setConfirm(false)} onConfirm={execute}><p>{view.student.full_name} — {view.course.name} — {view.academic_year.name} — {view.semester.name}</p><p>هذا إجراء فردي للتسجيل المحدد، ولا يعيد اعتماد الطرح أو ينشر نتائج طلاب آخرين.</p>{draft.action !== 'resolve' && <p>الموعد: {draft.proposal.deadline_at ? `${draft.proposal.deadline_at} — نهاية اليوم بدمشق` : damascusTime(baseline.deadline_suggestion?.deadline_at)}</p>}{draft.action === 'grant' && <><p>السبب: {draft.proposal.reason}</p><p>المتطلبات: {draft.proposal.requirements}</p><p>مخرج الانتهاء: <bdi>{expiryOutcomes[draft.proposal.expiry_outcome]}</bdi></p></>}{draft.action === 'resolve' && <ul>{baseline.components.filter(component => component.editable).map(component => <li key={component.grade_component_id}>{component.name}: {missingValue(component.mark)} ← {draft.proposal.marks[component.grade_component_id]}</li>)}</ul>}</ManualGradeDialog>}
    {blocker.state === 'blocked' && <ManualGradeDialog title={busy ? 'توجد عملية قيد التنفيذ' : 'حماية المقترح غير المحفوظ'} disabled={busy} onCancel={() => blocker.reset()} onConfirm={() => blocker.proceed()} confirmLabel="تجاهل المقترح والانتقال"><p>{busy ? 'انتظر نتيجة الكتابة قبل الانتقال.' : draft?.uncertain ? 'النتيجة غير مؤكدة. الانتقال لا يلغي العملية؛ اقرأ الحالة قبل أي محاولة لاحقة.' : 'الانتقال سيتجاهل المقترح. الإلغاء يبقيه كما هو.'}</p></ManualGradeDialog>}
  </>
}
