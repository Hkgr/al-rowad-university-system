import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useBlocker, useParams } from 'react-router-dom'
import { apiRequest } from '../../../services/apiClient'
import { ACCESS, canAccess, getIdentity } from '../../auth/auth'
import ManualGradeDialog from '../components/ManualGradeDialog'
import CatalogGradeRow from '../components/CatalogGradeRow'
import { Pager, button, card, field } from '../components/RegistrationGridRow'
import { navigationDecision } from '../lib/manualGradeDraft'
import { MANUAL_GRADE_NOTICE, manualError, requestSequence } from '../lib/manualGradeEntry'
import { catalogPath, periodsPath, catalogRequestKey } from '../lib/manualGradeGrid'
const identityStamp = () => JSON.stringify(getIdentity())

export default function StudentManualGradePage() {
  const { studentId } = useParams()
  return <StudentGrid key={studentId} studentId={studentId} />
}

function StudentGrid({ studentId }) {
  const [identity, setIdentity] = useState(identityStamp)
  const [allowed, setAllowed] = useState(() => canAccess(ACCESS.manualGradeEntry, getIdentity()))
  const [q, setQ] = useState('')
  const [search, setSearch] = useState('')
  const [queryVersion, setQueryVersion] = useState(0)
  const [draftEpoch, setDraftEpoch] = useState(0)
  const [term, setTerm] = useState({})
  const [historyMode, setHistoryMode] = useState(false)
  const [historyTerm, setHistoryTerm] = useState({})
  const selectedTerm = historyMode ? historyTerm : term
  const recordingReady = !!term.academic_year_id && !!term.semester_id
  const [page, setPage] = useState(1)
  const [snapshot, setSnapshot] = useState(null)
  const catalogKey = catalogRequestKey({ studentId, identity, historyMode, term: selectedTerm, search, page })
  // Refreshes of this exact request retain mounted editors/drafts. A different request never seeds them.
  const data = snapshot?.key === catalogKey ? snapshot.data : null
  const [terms, setTerms] = useState([])
  const [semesters, setSemesters] = useState([])
  const [periodError, setPeriodError] = useState('')
  const [periodLoading, setPeriodLoading] = useState(false)
  const [periodRetry, setPeriodRetry] = useState(0)
  const [loading, setLoading] = useState(false)
  const [dataError, setDataError] = useState('')
  const [notice, setNotice] = useState('')
  const [pendingCount, setPendingCount] = useState(0)
  const [discard, setDiscard] = useState(null)
  const dirty = useRef(new Set())
  const busy = useRef(new Set())
  const sequence = useRef(requestSequence())
  const controller = useRef(null)
  const periodController = useRef(null)
  const queryInput = useRef('')
  const mounted = useRef(true)
  const onDirty = useCallback((id, value) => { value ? dirty.current.add(id) : dirty.current.delete(id) }, [])
  const onBusy = useCallback((id, value) => {
    value ? busy.current.add(id) : busy.current.delete(id)
    setPendingCount(busy.current.size)
  }, [])
  const clear = useCallback(() => {
    sequence.current.invalidate(); controller.current?.abort(); periodController.current?.abort(); dirty.current.clear(); busy.current.clear()
    setSnapshot(null); setTerms([]); setSemesters([]); setPeriodError(''); setPeriodLoading(false); setDiscard(null); setQ(''); setSearch(''); setAllowed(false)
    setDataError(''); setNotice(''); setPendingCount(0)
  }, [])
  const blocker = useBlocker(useCallback(() => navigationDecision({
    authorized: allowed && identityStamp() === identity && canAccess(ACCESS.manualGradeEntry, getIdentity()),
    dirty: dirty.current.size > 0, pending: busy.current.size > 0,
  }) !== 'allow', [allowed, identity]))
  useEffect(() => {
    if (!allowed && blocker.state === 'blocked') blocker.proceed()
  }, [allowed, blocker])
  useEffect(() => {
    mounted.current = true
    const check = () => { if (identityStamp() !== identity || !canAccess(ACCESS.manualGradeEntry, getIdentity())) { clear(); setIdentity(identityStamp()) } }
    const timer = setInterval(check, 1000)
    window.addEventListener('storage', check); window.addEventListener('focus', check)
    return () => { mounted.current = false; clearInterval(timer); window.removeEventListener('storage', check); window.removeEventListener('focus', check); sequence.current.invalidate(); controller.current?.abort() }
  }, [identity, clear])
  useEffect(() => {
    const warn = event => { if (canAccess(ACCESS.manualGradeEntry, getIdentity()) && (dirty.current.size || busy.current.size)) { event.preventDefault(); event.returnValue = '' } }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [])

  useEffect(() => {
    if (queryInput.current === q) return
    queryInput.current = q
    const timer = setTimeout(() => { setSearch(q.trim()); setPage(1); setQueryVersion(value => value + 1) }, 350)
    return () => clearTimeout(timer)
  }, [q])
  const reload = useCallback(async () => {
    if (!studentId || !allowed) return false
    controller.current?.abort(); controller.current = new AbortController()
    const generation = sequence.current.next()
    setLoading(true); setDataError('')
    try {
      const json = await apiRequest(catalogPath(studentId, { ...selectedTerm, q: search, page, per_page: 15 }), { signal: controller.current.signal })
      if (!mounted.current || !sequence.current.valid(generation) || identityStamp() !== identity) return false
      setSnapshot({ key: catalogKey, data: json.data }); return true
    } catch (e) {
      if (mounted.current && sequence.current.valid(generation) && e.name !== 'AbortError') { setDataError(manualError(e)); if ([401, 403].includes(e.status)) clear() }
      return false
    } finally { if (mounted.current && sequence.current.valid(generation)) setLoading(false) }
  }, [studentId, selectedTerm, search, queryVersion, page, identity, allowed, clear, catalogKey])
  useEffect(() => { reload() }, [reload])
  useEffect(() => {
    if (!allowed) return
    const abort = new AbortController()
    periodController.current = abort
    let active = true
    const current = () => active && !abort.signal.aborted && identityStamp() === identity && canAccess(ACCESS.manualGradeEntry, getIdentity())
    setPeriodLoading(true); setPeriodError('')
    apiRequest(periodsPath(studentId), { signal: abort.signal }).then(json => {
      if (current()) { setTerms(json.data.academic_years); setSemesters(json.data.semesters) }
    }).catch(e => {
      if (current() && e.name !== 'AbortError') { setPeriodError(manualError(e)); if ([401, 403].includes(e.status)) clear() }
    }).finally(() => { if (current()) setPeriodLoading(false) })
    return () => { active = false; abort.abort() }
  }, [studentId, identity, allowed, periodRetry, clear])
  const change = action => {
    if (busy.current.size) { setNotice('انتظر اكتمال العملية الحالية قبل تغيير السياق.'); return }
    const apply = () => { dirty.current.clear(); setDraftEpoch(value => value + 1); action(); setDiscard(null) }
    if (dirty.current.size) setDiscard(() => apply)
    else apply()
  }
  const changeCatalog = action => change(() => {
    sequence.current.invalidate(); controller.current?.abort()
    setSnapshot(null) // Includes A -> B -> A before either response: no stale cached editor initialization.
    setLoading(true)
    action()
  })
  const changeMode = next => {
    if (next === historyMode) return // Before guards, cancellation, pagination or draft epochs.
    changeCatalog(() => { setHistoryMode(next); setPage(1) })
  }
  const changeTerm = (key, value) => changeCatalog(() => {
    const updateTerm = historyMode ? setHistoryTerm : setTerm
    updateTerm(current => {
      const next = key === 'academic_year_id' ? {} : { ...current }
      if (value) next[key] = value
      else delete next[key]
      return next
    })
    setPage(1)
  })

  if (!allowed) return <p role="alert" dir="rtl">الوصول غير متاح. أعد تسجيل الدخول للتحقق من الصلاحيات.</p>
  return <main dir="rtl" className="min-w-0 space-y-5 text-[13px] text-text-gray">
    <header className="flex flex-wrap items-center justify-between gap-3"><div><h1 className="text-[20px] font-black text-text-dark">كشف إدخال علامات الطالب</h1><p className="text-[12.5px] text-text-light">توثيق العلامات الحالية والتاريخية في السجل الأكاديمي</p></div><Link className={button} to="/exam-board/manual-grade-entry">العودة للبحث</Link></header>
    <section className={`${card} space-y-3 p-4`}><p className="text-[12.5px] leading-7 text-amber-900">{MANUAL_GRADE_NOTICE}</p>
      <Link className={button} to="/exam-board/approvals">واجهة الاعتمادات الحالية</Link></section>
    {notice && <p role="status" className="text-primary-dark">{notice}</p>}
    <section className={`${card} space-y-3 p-4`}>
      {data && <><h2 className="text-[15px] font-bold text-text-dark">{data.student.name} — <bdi>{data.student.student_number}</bdi></h2>
      <p>{data.student.college} — {data.student.program}</p></>}
      {periodLoading && <p role="status">جاري تحميل الفترات المتاحة…</p>}
      {periodError && <div role="alert"><p>{periodError}</p><button className={button} onClick={() => setPeriodRetry(value => value + 1)}>إعادة تحميل الفترات</button></div>}
      <div className="flex flex-wrap gap-2"><button className={button} aria-pressed={!historyMode} onClick={() => changeMode(false)}>إدخال العلامات</button>
        <button className={button} aria-pressed={historyMode} onClick={() => changeMode(true)}>استعراض السجل — قراءة فقط</button></div>
      <div className="grid grid-cols-3 gap-3 max-[680px]:grid-cols-1">
        <label>{historyMode ? 'تصفية سنة الاستعراض' : 'سنة العلامات'}<select className={field} value={selectedTerm.academic_year_id ?? ''} onChange={e => changeTerm('academic_year_id', e.target.value)}><option value="">{historyMode ? 'كل السنوات' : 'اختر سنة العلامات'}</option>
          {[...new Map(terms.map(t => [t.academic_year_id, t])).values()].map(t => <option key={t.academic_year_id} value={t.academic_year_id}>{t.year_name}</option>)}</select></label>
        <label>{historyMode ? 'تصفية فصل الاستعراض' : 'فصل العلامات'}<select className={field} value={selectedTerm.semester_id ?? ''} onChange={e => changeTerm('semester_id', e.target.value)}><option value="">{historyMode ? 'كل الفصول' : 'اختر فصل العلامات'}</option>
          {semesters.map(t => <option key={t.semester_id} value={t.semester_id}>{t.semester_name}</option>)}</select></label>
        <label>بحث في المقررات<input className={field} value={q} onChange={e => { const value = e.target.value; changeCatalog(() => setQ(value)) }} /></label>
      </div>
    </section>
    {!historyMode && !recordingReady && <section className={`${card} p-5`} role="status"><h2 className="font-bold">حدد سنة العلامات وفصلها لبدء الإدخال</h2><p>يمكنك اختيار فترة تاريخية. ستظهر حدود المكونات وحقول العلامات دون اشتراط طرح أو تسجيل مسبق. لاستعراض جميع السنوات استخدم وضع القراءة فقط.</p></section>}
    {loading && <p role="status">جاري تحميل الحالة الرسمية…</p>}
    {dataError && <div role="alert" className="space-y-3 rounded-[12px] border border-red-200 bg-red-50 p-4 text-red-700"><p>{dataError}</p><button className={button} disabled={pendingCount > 0} onClick={reload}>إعادة التحميل مع الاحتفاظ بالمسودات</button></div>}
    {data && (recordingReady || historyMode) && <><div className={`${card} overflow-x-auto`}><table className="w-full border-collapse text-right text-[12.5px]">
      <caption className="p-3 text-right text-text-light">المقررات دون تسجيل تبقى ظاهرة. الحفظ مسودة؛ الإرسال يشمل جزء الطرح بالكامل.</caption>
      <thead className="bg-primary/[0.05] text-text-dark"><tr>{['الرمز', 'المقرر / التصنيف', 'الساعات', 'فترة العلامات / المحاولة', 'النظري', 'العملي', 'حالة التسجيل', 'الإجراءات'].map(text => <th scope="col" key={text} className="whitespace-nowrap p-3">{text}</th>)}</tr></thead>
      <tbody>{data.courses.map(course => <CatalogGradeRow key={`${catalogKey}:${course.course_id}`} course={course} term={selectedTerm} historyMode={historyMode} change={change} draftEpoch={draftEpoch} student={data.student} identity={identity} readOnly={historyMode || loading || !!dataError} onDirty={onDirty} onBusy={onBusy} reload={reload} onForbidden={clear} />)}</tbody>
    </table>{!data.courses.length && <p className="p-6 text-center">لا توجد مقررات مطابقة ضمن النطاق.</p>}</div><Pager meta={data.meta} disabled={loading || pendingCount > 0} onPage={p => changeCatalog(() => setPage(p))} /></>}
    {discard && <ManualGradeDialog title="تغييرات غير محفوظة" confirmTone="discard" onCancel={() => setDiscard(null)} onConfirm={discard} confirmLabel="تجاهل التغييرات والمتابعة"><p>هل تريد تجاهل المسودات قبل تغيير السياق؟</p></ManualGradeDialog>}
    {blocker.state === 'blocked' && <ManualGradeDialog title="مغادرة إدخال العلامات" disabled={pendingCount > 0}
      onCancel={() => { blocker.reset(); setNotice('أُلغي الانتقال. مسوداتك محفوظة محليًا ويمكنك متابعة التحرير والحفظ.') }}
      onConfirm={() => { if (!busy.current.size) blocker.proceed() }} confirmTone="discard" confirmLabel="تجاهل المسودات والانتقال">
      <p>{pendingCount > 0 ? 'توجد عملية قيد التنفيذ. انتظر نتيجتها قبل المغادرة.' : 'توجد مسودات غير محفوظة. هل تريد تجاهلها والانتقال؟'}</p>
    </ManualGradeDialog>}
  </main>
}
