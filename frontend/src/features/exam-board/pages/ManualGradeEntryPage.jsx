import { useCallback, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { apiRequest } from '../../../services/apiClient'
import { ACCESS, canAccess, getIdentity } from '../../auth/auth'
import ManualGradeDialog from '../components/ManualGradeDialog'
import { MANUAL_GRADE_NOTICE, MANUAL_GRADE_ACKNOWLEDGEMENT, blockedLabel, registrationLabel, changedComponents, manualError, manualPath, markText, partLabel, requestSequence, savePayload, searchPath, stateLabel } from '../lib/manualGradeEntry'

const button = 'rounded-lg border border-primary/25 px-3 py-2 text-primary font-bold disabled:opacity-40'
const field = 'rounded-lg border border-primary/25 px-3 py-2 w-full'
const identityStamp = () => JSON.stringify(getIdentity())

function Pager({ meta, onPage, disabled }) {
  if (!meta) return null
  return <div className="flex items-center gap-3 my-3">
    <button type="button" className={button} disabled={disabled || meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>السابق</button>
    <span>صفحة {meta.current_page} من {meta.last_page} — {meta.total} سجل</span>
    <button type="button" className={button} disabled={disabled || meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>التالي</button>
  </div>
}

function RegistrationEditor({ row, student, onDirty, onBusy, reload, onForbidden, identity, readOnly }) {
  const [edits, setEdits] = useState({})
  const [acknowledged, setAcknowledged] = useState(false)
  const [reason, setReason] = useState('')
  const [dialog, setDialog] = useState(null)
  const [busy, setBusy] = useState(false)
  const [uncertain, setUncertain] = useState(false)
  const [message, setMessage] = useState('')
  const mounted = useRef(true)
  const pending = useRef(false)
  const readController = useRef(null)
  const valid = () => mounted.current && identityStamp() === identity
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; readController.current?.abort() } }, [])
  useEffect(() => { setEdits({}); setAcknowledged(false); setReason(''); setDialog(null); setUncertain(false); onDirty(row.registration_id, false) }, [row.revision, row.registration_id, onDirty])
  const edit = (id, value) => {
    const next = { ...edits, [id]: value }
    setEdits(next); setAcknowledged(false)
    try { onDirty(row.registration_id, changedComponents(row.components, next).length > 0) }
    catch { onDirty(row.registration_id, true) }
  }
  const perform = async action => {
    if (pending.current || !valid() || readOnly) return
    pending.current = true; setBusy(true); onBusy(row.registration_id, true); setMessage('')
    try {
      await action()
      if (!valid()) return
      setDialog(null); setEdits({}); setAcknowledged(false); onDirty(row.registration_id, false)
      const refreshed = await reload()
      if (valid()) { setUncertain(!refreshed); setMessage(refreshed ? 'تمت العملية وأعيد تحميل الحالة الرسمية.' : 'تمت العملية، لكن تعذر تحديث العرض. أعد التحميل قبل المتابعة.') }
    } catch (error) {
      if (!valid()) return
      setMessage(manualError(error)); setDialog(null)
      if ([401, 403].includes(error.status)) { onForbidden(); return }
      // An interrupted write is indeterminate, not proof of rollback. Never automatically retry it.
      if (!error.status || error.status >= 500 || error.status === 409) {
        setUncertain(true)
        const refreshed = await reload()
        if (valid() && refreshed) { setUncertain(false); setEdits({}); setAcknowledged(false); onDirty(row.registration_id, false) }
      }
    } finally {
      pending.current = false
      if (valid()) { setBusy(false); onBusy(row.registration_id, false) }
    }
  }
  const save = confirmed => {
    try {
      const payload = savePayload(row, edits, acknowledged, confirmed, reason)
      perform(() => apiRequest(`${manualPath(student.student_id, row.registration_id)}/marks`, { method: 'PUT', body: JSON.stringify(payload) }))
    } catch (error) { setMessage(error.message) }
  }
  const prepareSave = () => {
    try {
      if (!acknowledged) throw new Error('يجب الإقرار بصحة العلامات.')
      const changes = changedComponents(row.components, edits)
      if (changes.some(c => c.mark !== null)) { setReason(''); setDialog({ type: 'correction', changes }) }
      else save(false)
    } catch (error) { setMessage(error.message) }
  }
  const readiness = async part => {
    if (pending.current || !valid() || readOnly) return
    pending.current = true; setBusy(true); onBusy(row.registration_id, true)
    try {
      readController.current?.abort(); readController.current = new AbortController()
      const response = await apiRequest(`${manualPath(student.student_id, row.registration_id)}/parts/${part}/submission-readiness`, { signal: readController.current.signal })
      if (valid()) setDialog({ type: 'submission', ready: response.data, part })
    } catch (error) { if (valid()) { setMessage(manualError(error)); if ([401, 403].includes(error.status)) onForbidden() } }
    finally { pending.current = false; if (valid()) { setBusy(false); onBusy(row.registration_id, false) } }
  }
  let dirty = false
  try { dirty = changedComponents(row.components, edits).length > 0 } catch { dirty = true }
  return <article className="rounded-2xl border border-primary/20 bg-white p-5 space-y-4" aria-busy={busy}>
    <header><h2 className="font-bold text-lg text-primary">{row.course_code} — {row.course_name}</h2>
      <p>{row.academic_year} / {row.semester} — الشعبة {row.section} — التسجيل/المحاولة {row.registration_id}</p>
      <p>{row.college} — {row.program} — حالة التسجيل: {registrationLabel(row.registration_status)}</p></header>
    {!row.components.length && <p>لا توجد مكونات علامات مطلوبة؛ هذا السجل للعرض فقط.</p>}
    {Object.entries(row.parts).map(([part, state]) => <fieldset key={part} className="rounded-xl border p-4" disabled={busy || uncertain || readOnly || !state.can_edit}>
      <legend className="px-2 font-bold">{partLabel(part)} — {stateLabel(state.status)}</legend>
      {state.blocked_reason && <p className="text-amber-800 mb-2">{state.status === 'submitted' ? 'مرسل للاعتماد؛ استخدم مسار الإعادة للتصحيح في واجهة الاعتمادات.' : state.status === 'approved' || state.blocked_reason === 'official_result_locked' ? 'النتيجة معتمدة ومقفلة. تصحيح النتائج المنشورة خارج هذه الواجهة.' : blockedLabel(state.blocked_reason)}</p>}
      <div className="grid gap-3 sm:grid-cols-2">{row.components.filter(c => c.component_type === part).map(c => <label key={c.grade_component_id}>
        <span>{c.name} — الحد الأعلى {c.max_mark}</span>
        <input className={field} inputMode="decimal" type="text" aria-label={`${c.name} — ${partLabel(part)}`}
          value={edits[c.grade_component_id] ?? (c.mark === null ? '' : String(c.mark))} onChange={event => edit(c.grade_component_id, event.target.value)} />
      </label>)}</div>
    </fieldset>)}
    {Object.values(row.parts).some(p => p.can_edit) && <>
      <label className="flex gap-2"><input type="checkbox" checked={acknowledged} disabled={busy || uncertain} onChange={event => setAcknowledged(event.target.checked)} />{MANUAL_GRADE_ACKNOWLEDGEMENT}</label>
      <button type="button" className={button} disabled={busy || uncertain || readOnly || !dirty || !acknowledged} onClick={prepareSave}>حفظ العلامات كمسودة</button>
    </>}
    <div className="flex flex-wrap gap-2">{Object.entries(row.parts).filter(([, p]) => p.can_check_submission).map(([part]) => <button key={part} type="button" className={button} disabled={busy || uncertain || readOnly || dirty} onClick={() => readiness(part)}>إرسال الجزء {partLabel(part)} للاعتماد</button>)}</div>
    {message && <p role="status" className="text-amber-900">{message}</p>}
    {uncertain && <button className={button} type="button" disabled={busy} onClick={async () => { if (await reload()) { setUncertain(false); setEdits({}); onDirty(row.registration_id, false) } }}>إعادة تحميل الحالة قبل المتابعة</button>}
    {dialog && <ManualGradeDialog title={dialog.type === 'correction' ? 'تأكيد تصحيح العلامات' : 'تأكيد إرسال جزء الطرح بالكامل'} busy={busy}
      disabled={dialog.type === 'correction' ? !reason.trim() : !dialog.ready.can_submit} onCancel={() => setDialog(null)}
      onConfirm={() => dialog.type === 'correction' ? save(true) : perform(() => apiRequest(`${manualPath(student.student_id, row.registration_id)}/parts/${dialog.part}/submit`, { method: 'POST', body: JSON.stringify({ confirmed: true, revision: dialog.ready.revision }) }))}>
      <p>{student.name} — {student.student_number}</p><p>{row.course_name} — {row.academic_year} / {row.semester} — الشعبة {row.section}</p>
      {dialog.type === 'correction' ? <>
        <ul>{dialog.changes.map(c => <li key={c.grade_component_id}>{c.name}: {markText(c.mark)} ← {markText(c.proposed)}</li>)}</ul>
        <label>سبب التصحيح<textarea className={field} value={reason} maxLength={1000} onChange={event => setReason(event.target.value)} /></label>
      </> : <><p>هذا الإرسال يشمل الجزء {partLabel(dialog.part)} لجميع طلاب الطرح، وليس الطالب المختار فقط.</p>
        <p>المؤهلون: {dialog.ready.counts.eligible} — المكتملون: {dialog.ready.counts.completed} — الناقصون: {dialog.ready.counts.incomplete} — المستثنون وفق النظام: {dialog.ready.counts.exempt}</p>
        {!dialog.ready.can_submit && <p role="alert">{blockedLabel(dialog.ready.blocked_reason)}</p>}</>}
    </ManualGradeDialog>}
  </article>
}

export default function ManualGradeEntryPage() {
  const [identity, setIdentity] = useState(identityStamp)
  const [allowed, setAllowed] = useState(() => canAccess(ACCESS.manualGradeEntry, getIdentity()))
  const [q, setQ] = useState('')
  const [search, setSearch] = useState('')
  const [searchPage, setSearchPage] = useState(1)
  const [students, setStudents] = useState(null)
  const [student, setStudent] = useState(null)
  const [term, setTerm] = useState({})
  const [page, setPage] = useState(1)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [discard, setDiscard] = useState(null)
  const dirty = useRef(new Set())
  const busy = useRef(new Set())
  const sequence = useRef(requestSequence())
  const controller = useRef(null)
  const mounted = useRef(true)
  const onDirty = useCallback((id, value) => { value ? dirty.current.add(id) : dirty.current.delete(id) }, [])
  const onBusy = useCallback((id, value) => { value ? busy.current.add(id) : busy.current.delete(id) }, [])
  const clear = useCallback(() => {
    sequence.current.invalidate(); controller.current?.abort(); dirty.current.clear(); busy.current.clear()
    setData(null); setStudent(null); setStudents(null); setDiscard(null); setQ(''); setSearch(''); setAllowed(false)
  }, [])
  useEffect(() => {
    mounted.current = true
    const check = () => { if (identityStamp() !== identity || !canAccess(ACCESS.manualGradeEntry, getIdentity())) { clear(); setIdentity(identityStamp()) } }
    const timer = setInterval(check, 1000)
    window.addEventListener('storage', check); window.addEventListener('focus', check)
    return () => { mounted.current = false; clearInterval(timer); window.removeEventListener('storage', check); window.removeEventListener('focus', check); sequence.current.invalidate(); controller.current?.abort() }
  }, [identity, clear])
  useEffect(() => {
    const warn = event => { if (dirty.current.size || busy.current.size) { event.preventDefault(); event.returnValue = '' } }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [])
  useEffect(() => { const timer = setTimeout(() => { setSearch(q.trim()); setSearchPage(1) }, 350); return () => clearTimeout(timer) }, [q])
  useEffect(() => {
    if (!allowed || !search) { setStudents(null); return }
    const abort = new AbortController()
    let active = true
    apiRequest(searchPath(search, searchPage), { signal: abort.signal }).then(json => { if (active && identityStamp() === identity) setStudents(json.data) })
      .catch(e => { if (active && e.name !== 'AbortError') { setError(manualError(e)); if ([401, 403].includes(e.status)) clear() } })
    return () => { active = false; abort.abort() }
  }, [search, searchPage, identity, allowed, clear])
  const reload = useCallback(async () => {
    if (!student || !allowed) return false
    controller.current?.abort(); controller.current = new AbortController()
    const generation = sequence.current.next()
    setLoading(true); setError('')
    try {
      const json = await apiRequest(`${manualPath(student.student_id)}?${new URLSearchParams({ ...term, page, per_page: 15 })}`, { signal: controller.current.signal })
      if (!mounted.current || !sequence.current.valid(generation) || identityStamp() !== identity) return false
      setData(json.data); return true
    } catch (e) {
      if (mounted.current && sequence.current.valid(generation) && e.name !== 'AbortError') { setError(manualError(e)); if ([401, 403].includes(e.status)) clear() }
      return false
    } finally { if (mounted.current && sequence.current.valid(generation)) setLoading(false) }
  }, [student, term, page, identity, allowed, clear])
  useEffect(() => { reload() }, [reload])
  const change = action => {
    if (busy.current.size) { setError('انتظر اكتمال العملية الحالية قبل تغيير السياق.'); return }
    const apply = () => { sequence.current.invalidate(); controller.current?.abort(); dirty.current.clear(); setData(null); action(); setDiscard(null) }
    if (dirty.current.size) setDiscard(() => apply)
    else apply()
  }
  const changeTerm = (key, value) => change(() => {
    setTerm(current => {
      const next = key === 'academic_year_id' ? {} : { ...current }
      if (value) next[key] = value
      else delete next[key]
      return next
    })
    setPage(1)
  })
  if (!allowed) return <p role="alert" dir="rtl">الوصول غير متاح. أعد تسجيل الدخول للتحقق من الصلاحيات.</p>
  return <main dir="rtl" className="space-y-5">
    <h1 className="text-2xl font-black text-primary">إدخال العلامات اليدوي</h1>
    <p className="rounded-xl border border-amber-200 bg-amber-50 p-4">{MANUAL_GRADE_NOTICE}</p>
    <Link to="/exam-board/approvals" onClick={event => { if (dirty.current.size || busy.current.size) { event.preventDefault(); setError('احفظ أو تخلّ عن التغييرات قبل الانتقال إلى الاعتمادات.') } }} className="text-primary underline">واجهة الاعتمادات الحالية</Link>
    <label className="block">البحث باسم الطالب أو رقمه<input className={field} value={q} onChange={e => setQ(e.target.value)} /></label>
    {students && <section aria-label="نتائج البحث" className="rounded-xl border bg-white p-4">
      {!students.students.length && <p>لا توجد نتائج ضمن نطاقك.</p>}
      {students.students.map(s => <button type="button" key={s.student_id} className={`${button} block w-full text-right my-2`} onClick={() => change(() => { setStudent(s); setTerm({}); setPage(1) })}>{s.name} — {s.student_number} — {s.college} / {s.program}</button>)}
      <Pager meta={students.meta} onPage={setSearchPage} />
    </section>}
    {student && <section className="rounded-xl bg-white border p-4 space-y-3"><h2 className="font-bold">{data?.student?.name ?? student.name} — {data?.student?.student_number ?? student.student_number}</h2>
      <div className="grid gap-3 sm:grid-cols-2"><label>السنة الأكاديمية<select className={field} value={term.academic_year_id ?? ''} onChange={e => changeTerm('academic_year_id', e.target.value)}><option value="">كل السنوات</option>{[...new Map((data?.terms ?? []).map(t => [t.academic_year_id, t])).values()].map(t => <option key={t.academic_year_id} value={t.academic_year_id}>{t.year_name}</option>)}</select></label>
      <label>الفصل<select className={field} value={term.semester_id ?? ''} onChange={e => changeTerm('semester_id', e.target.value)}><option value="">كل الفصول</option>{[...new Map((data?.terms ?? []).filter(t => !term.academic_year_id || String(t.academic_year_id) === term.academic_year_id).map(t => [t.semester_id, t])).values()].map(t => <option key={t.semester_id} value={t.semester_id}>{t.semester_name}</option>)}</select></label></div>
    </section>}
    {loading && <p role="status">جاري تحميل الحالة الرسمية…</p>}
    {error && <div role="alert" className="text-red-800">{error} {student && <button type="button" className={button} onClick={() => change(() => reload())}>إعادة التحميل</button>}</div>}
    {data && <><div className="space-y-4">{data.registrations.map(row => <RegistrationEditor key={row.registration_id} row={row} student={data.student} identity={identity} readOnly={loading || !!error} onDirty={onDirty} onBusy={onBusy} reload={reload} onForbidden={clear} />)}</div>
      {!data.registrations.length && <p>لا توجد تسجيلات فعلية مطابقة ضمن نطاقك.</p>}<Pager meta={data.meta} disabled={loading} onPage={p => change(() => setPage(p))} /></>}
    {discard && <ManualGradeDialog title="تغييرات غير محفوظة" onCancel={() => setDiscard(null)} onConfirm={discard} confirmLabel="تجاهل التغييرات والمتابعة"><p>لن تُحفظ العلامات المعدلة عند تغيير الطالب أو الفترة أو الصفحة.</p></ManualGradeDialog>}
  </main>
}
