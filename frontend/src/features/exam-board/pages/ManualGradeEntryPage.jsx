import { useCallback, useEffect, useReducer, useRef, useState } from 'react'
import { Link, useBlocker } from 'react-router-dom'
import { FaCheckDouble, FaChevronLeft, FaChevronRight, FaClipboardList, FaExclamationTriangle, FaPaperPlane, FaRedo, FaSave, FaSearch, FaSpinner, FaUserGraduate } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import { ACCESS, canAccess, getIdentity } from '../../auth/auth'
import ManualGradeDialog from '../components/ManualGradeDialog'
import { createGradeDraft, gradeDraftReducer, hasGradeDraft, navigationDecision } from '../lib/manualGradeDraft'
import { MANUAL_GRADE_NOTICE, MANUAL_GRADE_ACKNOWLEDGEMENT, blockedLabel, registrationLabel, changedComponents, manualError, manualPath, markText, partLabel, requestSequence, savePayload, searchPath, stateLabel } from '../lib/manualGradeEntry'

// Presentation tokens follow StudentPicker and ApprovalsPage; no workflow decisions live here.
const action = 'inline-flex max-w-full items-center justify-center gap-2 rounded-[10px] px-3.5 py-2.5 text-[12px] font-bold leading-5 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-40'
const button = `${action} border border-primary/15 bg-white text-primary-dark hover:bg-primary/[0.05]`
const primaryButton = `${action} border border-primary bg-primary text-white hover:bg-primary-dark`
const warningButton = `${action} border border-amber-300 bg-amber-100 text-amber-900 hover:bg-amber-200`
const discardButton = `${action} border border-red-200 bg-white text-red-700 hover:bg-red-50`
const field = 'w-full min-w-0 rounded-[10px] border border-primary/20 bg-white px-3 py-2.5 text-[13px] font-normal text-text-dark outline-none focus:border-primary focus:ring-2 focus:ring-primary/10 disabled:bg-primary/[0.03] disabled:text-text-light'
const card = 'min-w-0 rounded-[16px] border border-primary/12 bg-white shadow-[0_2px_12px_rgba(26,46,16,0.05)]'
const label = 'flex min-w-0 flex-col gap-1.5 text-[12px] font-bold text-text-dark'
const identityStamp = () => JSON.stringify(getIdentity())
const markInput = value => value == null ? '' : String(value)

function StatusChip({ status, children }) {
  const colors = { draft: 'bg-primary/[0.05] text-text-gray', submitted: 'bg-amber-50 text-amber-700',
    returned: 'bg-red-50 text-red-700', approved: 'bg-primary/10 text-primary-dark', registered: 'bg-primary/10 text-primary-dark' }
  return <span className={`inline-flex max-w-full items-center rounded-full px-2.5 py-1 text-[11px] font-bold leading-5 ${colors[status] ?? 'bg-primary/[0.04] text-text-gray'}`}>{children}</span>
}

function MetaItem({ label, children }) {
  return <div className="flex min-w-0 flex-wrap items-baseline gap-x-1.5 gap-y-0.5"><dt className="text-text-light">{label}</dt><dd className="min-w-0 break-words font-semibold text-text-gray">{children}</dd></div>
}

function Pager({ meta, onPage, disabled }) {
  if (!meta) return null
  return <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-primary/10 pt-3 text-[12px] text-text-light">
    <span>{meta.total} سجل</span>
    <div className="flex flex-wrap items-center gap-2">
      <button type="button" className="inline-flex items-center gap-1 rounded-[9px] p-2 text-text-gray hover:bg-primary/[0.05] focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-30" disabled={disabled || meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}><FaChevronRight aria-hidden="true" className="text-[10px]" />السابق</button>
      <span>صفحة {meta.current_page} من {meta.last_page}</span>
      <button type="button" className="inline-flex items-center gap-1 rounded-[9px] p-2 text-text-gray hover:bg-primary/[0.05] focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-30" disabled={disabled || meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>التالي<FaChevronLeft aria-hidden="true" className="text-[10px]" /></button>
    </div>
  </div>
}

function RegistrationEditor({ row, student, onDirty, onBusy, reload, onForbidden, identity, readOnly }) {
  const [draft, dispatch] = useReducer(gradeDraftReducer, row, createGradeDraft)
  const { baseline, edits } = draft
  const dirty = hasGradeDraft(draft)
  const [acknowledged, setAcknowledged] = useState(false)
  const [reason, setReason] = useState('')
  const [dialog, setDialog] = useState(null)
  const [busy, setBusy] = useState(false)
  const conflict = draft.conflict || (dirty && row.revision !== baseline.revision)
  const [uncertain, setUncertain] = useState(false)
  const [message, setMessage] = useState('')
  const mounted = useRef(true)
  const pending = useRef(false)
  const readController = useRef(null)
  const valid = () => mounted.current && identityStamp() === identity
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; readController.current?.abort() } }, [])
  useEffect(() => { dispatch({ type: 'receive', row }) }, [row])
  useEffect(() => { onDirty(row.registration_id, dirty || conflict) }, [row.registration_id, dirty, conflict, onDirty])
  const edit = (id, value) => {
    dispatch({ type: 'edit', id, value }); setAcknowledged(false)
    // Update the navigation guard synchronously, before React commits the next render.
    onDirty(row.registration_id, conflict || hasGradeDraft({ ...draft, edits: { ...edits, [id]: value } }))
  }
  const perform = async (action, savedDraft = null) => {
    if (pending.current || !valid() || readOnly || conflict || uncertain) return
    pending.current = true; setBusy(true); onBusy(row.registration_id, true); setMessage('')
    try {
      const response = await action()
      if (!valid()) return
      setDialog(null); setAcknowledged(false); setReason('')
      if (savedDraft) dispatch({ type: 'saved', ...savedDraft, row: response.data })
      else dispatch({ type: 'receive', row: response.data })
      const refreshed = await reload()
      if (valid()) { setUncertain(!refreshed); setMessage(refreshed ? 'تمت العملية وأعيد تحميل الحالة الرسمية.' : 'تمت العملية، لكن تعذر تحديث العرض. أعد التحميل قبل المتابعة.') }
    } catch (error) {
      if (!valid()) return
      setMessage(manualError(error)); setDialog(null)
      if ([401, 403].includes(error.status)) { onForbidden(); return }
      // An interrupted write is indeterminate, not proof of rollback. Never automatically retry it.
      if (!error.status || error.status >= 500 || error.status === 409) {
        setUncertain(true)
        dispatch({ type: 'conflict' })
        const refreshed = await reload()
        if (valid() && refreshed) { setUncertain(false); setAcknowledged(false) }
      }
    } finally {
      pending.current = false
      if (valid()) { setBusy(false); onBusy(row.registration_id, false) }
    }
  }
  const save = confirmed => {
    try {
      const payload = savePayload(baseline, edits, acknowledged, confirmed, reason)
      perform(() => apiRequest(`${manualPath(student.student_id, row.registration_id)}/marks`, { method: 'PUT', body: JSON.stringify(payload) }), { version: draft.version, revision: baseline.revision })
    } catch (error) { setMessage(error.message) }
  }
  const prepareSave = () => {
    try {
      if (!acknowledged) throw new Error('يجب الإقرار بصحة العلامات.')
      const changes = changedComponents(baseline.components, edits)
      if (changes.some(c => c.mark !== null)) { setReason(''); setDialog({ type: 'correction', changes }) }
      else save(false)
    } catch (error) { setMessage(error.message) }
  }
  const readiness = async part => {
    if (pending.current || !valid() || readOnly || dirty || conflict || uncertain) return
    pending.current = true; setBusy(true); onBusy(row.registration_id, true)
    try {
      readController.current?.abort(); readController.current = new AbortController()
      const response = await apiRequest(`${manualPath(student.student_id, row.registration_id)}/parts/${part}/submission-readiness`, { signal: readController.current.signal })
      if (valid()) setDialog({ type: 'submission', ready: response.data, part })
    } catch (error) { if (valid()) { setMessage(manualError(error)); if ([401, 403].includes(error.status)) onForbidden() } }
    finally { pending.current = false; if (valid()) { setBusy(false); onBusy(row.registration_id, false) } }
  }
  const resolve = type => {
    dispatch({ type }); setAcknowledged(false); setReason(''); setDialog(null); setMessage('')
  }
  const removedComponents = Object.keys(edits).some(id => !row.components.some(c => String(c.grade_component_id) === id))
  return <article className={`${card} space-y-4 p-5 max-[560px]:p-4`} aria-busy={busy}>
    <header className="space-y-3 border-b border-primary/10 pb-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0 space-y-1"><h2 className="break-words text-[15px] font-extrabold text-text-dark">{row.course_name}</h2>
          <bdi className="inline-block rounded-[6px] bg-primary/[0.05] px-2 py-0.5 font-mono text-[11.5px] text-primary-dark">{row.course_code}</bdi></div>
        <StatusChip status={row.registration_status}>حالة التسجيل: {registrationLabel(row.registration_status)}</StatusChip>
      </div>
      <dl className="flex flex-wrap gap-x-5 gap-y-1.5 text-[11.5px]">
        <MetaItem label="السنة الأكاديمية">{row.academic_year}</MetaItem><MetaItem label="الفصل">{row.semester}</MetaItem>
        <MetaItem label="الشعبة">{row.section}</MetaItem><MetaItem label="التسجيل/المحاولة">{row.registration_id}</MetaItem>
        <MetaItem label="الكلية">{row.college}</MetaItem><MetaItem label="البرنامج">{row.program}</MetaItem>
      </dl>
    </header>
    {!row.components.length && <p className="rounded-[10px] bg-primary/[0.03] px-3 py-3 text-[12.5px] text-text-light">لا توجد مكونات علامات مطلوبة؛ هذا السجل للعرض فقط.</p>}
    {conflict && <section role="alert" className="space-y-3 rounded-[12px] border border-amber-200 bg-amber-50/70 p-4 text-[12.5px] leading-7 text-amber-900">
      <div className="flex items-start gap-2"><FaExclamationTriangle aria-hidden="true" className="mt-1.5 shrink-0 text-amber-600" /><p>تغيّرت الحالة الرسمية أو تعذر تأكيد الكتابة. احتُفظ بمسودتك ونسخة بدء التحرير؛ اختر صراحةً قبل المتابعة. لن يعاد إرسالها تلقائيًا.</p></div>
      <ul className="space-y-2">{baseline.components.filter(c => Object.hasOwn(edits, c.grade_component_id)).map(c => <li key={c.grade_component_id} className="rounded-[10px] border border-amber-200/70 bg-white/80 px-3 py-2.5">
        <p className="mb-2 font-bold text-text-dark">{c.name}</p>
        <dl className="grid grid-cols-3 gap-3 max-[560px]:grid-cols-1">
          <div><dt className="text-[11px] text-text-light">عند بدء التحرير</dt><dd className="font-bold text-text-gray">{markText(c.mark)}</dd></div>
          <div><dt className="text-[11px] text-amber-700">المقترح</dt><dd className="font-bold text-amber-900">{edits[c.grade_component_id] || '—'}</dd></div>
          <div><dt className="text-[11px] text-text-light">الخادم</dt><dd className="font-bold text-text-dark">{markText(row.components.find(s => s.grade_component_id === c.grade_component_id)?.mark)}</dd></div>
        </dl>
      </li>)}</ul>
      {removedComponents && <p>تغيّر تعريف المكونات؛ لا يمكن نقل هذه المسودة إلى النسخة الجديدة. راجع القيم قبل تجاهلها.</p>}
      <div className="flex flex-wrap gap-2">
        <button type="button" className={discardButton} disabled={busy || uncertain || readOnly} onClick={() => resolve('discard')}>تجاهل المسودة واستخدام نسخة الخادم</button>
        <button type="button" className={warningButton} disabled={busy || uncertain || readOnly || removedComponents} onClick={() => resolve('rebase')}>إبقاء المقترحات ومراجعتها على نسخة الخادم الجديدة</button>
      </div>
    </section>}
    {Object.entries(row.parts).map(([part, state]) => <fieldset key={part} className="min-w-0 rounded-[12px] border border-primary/10 bg-primary/[0.02] p-4" disabled={busy || uncertain || conflict || readOnly || !state.can_edit}>
      <legend className="max-w-full px-2"><span className="inline-flex flex-wrap items-center gap-2"><span className="text-[13px] font-extrabold text-text-dark">الجزء {partLabel(part)}</span><StatusChip status={state.status}>{stateLabel(state.status)}</StatusChip></span></legend>
      {state.blocked_reason && <p className="mb-3 rounded-[9px] border border-amber-100 bg-amber-50 px-3 py-2 text-[12px] leading-6 text-amber-800">{state.status === 'submitted' ? 'مرسل للاعتماد؛ استخدم مسار الإعادة للتصحيح في واجهة الاعتمادات.' : state.status === 'approved' || state.blocked_reason === 'official_result_locked' ? 'النتيجة معتمدة ومقفلة. تصحيح النتائج المنشورة خارج هذه الواجهة.' : blockedLabel(state.blocked_reason)}</p>}
      <div className="grid grid-cols-2 gap-3 max-[560px]:grid-cols-1">{row.components.filter(c => c.component_type === part).map(c => <label key={c.grade_component_id} className={label}>
        <span className="flex flex-wrap items-baseline justify-between gap-x-2 gap-y-0.5"><span className="break-words">{c.name}</span><span className="text-[11px] font-normal text-text-light">الحد الأعلى {c.max_mark}</span></span>
        <input className={field} inputMode="decimal" type="text" aria-label={`${c.name} — ${partLabel(part)}`}
          value={edits[c.grade_component_id] ?? markInput(baseline.components.find(b => b.grade_component_id === c.grade_component_id)?.mark)} onChange={event => edit(c.grade_component_id, event.target.value)} />
      </label>)}</div>
    </fieldset>)}
    {Object.values(row.parts).some(p => p.can_edit) && <div className="space-y-3 rounded-[12px] border border-primary/10 bg-primary/[0.03] p-4">
      <label className="flex items-start gap-2.5 text-[12.5px] leading-7 text-text-gray"><input className="mt-1.5 h-4 w-4 shrink-0 accent-primary focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-40" type="checkbox" checked={acknowledged} disabled={busy || uncertain || conflict || readOnly} onChange={event => setAcknowledged(event.target.checked)} /><span>{MANUAL_GRADE_ACKNOWLEDGEMENT}</span></label>
      <button type="button" className={primaryButton} disabled={busy || uncertain || conflict || readOnly || !dirty || !acknowledged} onClick={prepareSave}><FaSave aria-hidden="true" />حفظ العلامات كمسودة</button>
    </div>}
    <div className="flex flex-wrap gap-2">{Object.entries(row.parts).filter(([, p]) => p.can_check_submission).map(([part]) => <button key={part} type="button" className={button} disabled={busy || uncertain || conflict || readOnly || dirty} onClick={() => readiness(part)}><FaPaperPlane aria-hidden="true" className="text-[11px]" />إرسال الجزء {partLabel(part)} للاعتماد</button>)}</div>
    {message && <p role="status" className="rounded-[10px] border border-amber-200 bg-amber-50 px-3 py-2.5 text-[12.5px] leading-7 text-amber-900">{message}</p>}
    {uncertain && <button className={button} type="button" disabled={busy} onClick={async () => { if (await reload()) setUncertain(false) }}><FaRedo aria-hidden="true" />إعادة تحميل الحالة قبل المتابعة</button>}
    {dialog && <ManualGradeDialog title={dialog.type === 'correction' ? 'تأكيد تصحيح العلامات' : 'تأكيد إرسال جزء الطرح بالكامل'} busy={busy}
      disabled={readOnly || uncertain || conflict || (dialog.type === 'correction' ? !reason.trim() : !dialog.ready.can_submit)} onCancel={() => setDialog(null)}
      onConfirm={() => dialog.type === 'correction' ? save(true) : perform(() => apiRequest(`${manualPath(student.student_id, row.registration_id)}/parts/${dialog.part}/submit`, { method: 'POST', body: JSON.stringify({ confirmed: true, revision: dialog.ready.revision }) }))}>
      <div className="space-y-1 rounded-[10px] border border-primary/10 bg-primary/[0.03] px-3 py-2.5"><p className="font-bold text-text-dark">{student.name} — <bdi className="font-mono text-[12px] text-text-light">{student.student_number}</bdi></p><p className="text-[12px] text-text-gray">{row.course_name} — {row.academic_year} / {row.semester} — الشعبة {row.section}</p></div>
      {dialog.type === 'correction' ? <>
        <ul className="divide-y divide-primary/10 rounded-[10px] border border-primary/10 px-3">{dialog.changes.map(c => <li key={c.grade_component_id} className="flex flex-wrap items-baseline justify-between gap-2 py-2"><span className="font-bold text-text-dark">{c.name}</span><span>{markText(c.mark)} ← <strong className="text-primary-dark">{markText(c.proposed)}</strong></span></li>)}</ul>
        <label className={label}>سبب التصحيح<textarea className={`${field} min-h-[96px] resize-y`} value={reason} maxLength={1000} onChange={event => setReason(event.target.value)} /></label>
      </> : <><p>هذا الإرسال يشمل الجزء {partLabel(dialog.part)} لجميع طلاب الطرح، وليس الطالب المختار فقط.</p>
        <dl className="grid grid-cols-2 gap-2 max-[400px]:grid-cols-1 text-[12px]">
          <MetaItem label="المؤهلون">{dialog.ready.counts.eligible}</MetaItem><MetaItem label="المكتملون">{dialog.ready.counts.completed}</MetaItem>
          <MetaItem label="الناقصون">{dialog.ready.counts.incomplete}</MetaItem><MetaItem label="المستثنون وفق النظام">{dialog.ready.counts.exempt}</MetaItem>
        </dl>
        {!dialog.ready.can_submit && <p role="alert" className="rounded-[10px] border border-amber-200 bg-amber-50 px-3 py-2 text-amber-900">{blockedLabel(dialog.ready.blocked_reason)}</p>}</>}
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
  const [dataError, setDataError] = useState('')
  const [lookupError, setLookupError] = useState('')
  const [notice, setNotice] = useState('')
  const [pendingCount, setPendingCount] = useState(0)
  const [discard, setDiscard] = useState(null)
  const dirty = useRef(new Set())
  const busy = useRef(new Set())
  const sequence = useRef(requestSequence())
  const controller = useRef(null)
  const mounted = useRef(true)
  const onDirty = useCallback((id, value) => { value ? dirty.current.add(id) : dirty.current.delete(id) }, [])
  const onBusy = useCallback((id, value) => {
    value ? busy.current.add(id) : busy.current.delete(id)
    setPendingCount(busy.current.size)
  }, [])
  const clear = useCallback(() => {
    sequence.current.invalidate(); controller.current?.abort(); dirty.current.clear(); busy.current.clear()
    setData(null); setStudent(null); setStudents(null); setDiscard(null); setQ(''); setSearch(''); setAllowed(false)
    setDataError(''); setLookupError(''); setNotice(''); setPendingCount(0)
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
  useEffect(() => { const timer = setTimeout(() => { setSearch(q.trim()); setSearchPage(1) }, 350); return () => clearTimeout(timer) }, [q])
  useEffect(() => {
    if (!allowed || !search) { setStudents(null); return }
    setLookupError('')
    const abort = new AbortController()
    let active = true
    apiRequest(searchPath(search, searchPage), { signal: abort.signal }).then(json => { if (active && identityStamp() === identity) setStudents(json.data) })
      .catch(e => { if (active && e.name !== 'AbortError') { setLookupError(manualError(e)); if ([401, 403].includes(e.status)) clear() } })
    return () => { active = false; abort.abort() }
  }, [search, searchPage, identity, allowed, clear])
  const reload = useCallback(async () => {
    if (!student || !allowed) return false
    controller.current?.abort(); controller.current = new AbortController()
    const generation = sequence.current.next()
    setLoading(true); setDataError('')
    try {
      const json = await apiRequest(`${manualPath(student.student_id)}?${new URLSearchParams({ ...term, page, per_page: 15 })}`, { signal: controller.current.signal })
      if (!mounted.current || !sequence.current.valid(generation) || identityStamp() !== identity) return false
      setData(json.data); return true
    } catch (e) {
      if (mounted.current && sequence.current.valid(generation) && e.name !== 'AbortError') { setDataError(manualError(e)); if ([401, 403].includes(e.status)) clear() }
      return false
    } finally { if (mounted.current && sequence.current.valid(generation)) setLoading(false) }
  }, [student, term, page, identity, allowed, clear])
  useEffect(() => { reload() }, [reload])
  const change = action => {
    if (busy.current.size) { setNotice('انتظر اكتمال العملية الحالية قبل تغيير السياق.'); return }
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
  if (!allowed) return <p role="alert" dir="rtl" className="rounded-[16px] border border-red-200 bg-red-50 px-5 py-4 text-[13px] leading-7 text-red-700">الوصول غير متاح. أعد تسجيل الدخول للتحقق من الصلاحيات.</p>
  return <main dir="rtl" className="min-w-0 space-y-5 text-[13px] text-text-gray">
    <div><h1 className="mb-[3px] text-[20px] font-black text-text-dark">إدخال العلامات اليدوي</h1><p className="text-[12.5px] text-text-light" lang="en">Manual grade entry</p></div>
    <section className="rounded-[16px] border border-amber-200 bg-amber-50/70 px-5 py-4 max-[560px]:px-4">
      <div className="flex items-start gap-3"><FaExclamationTriangle aria-hidden="true" className="mt-1 shrink-0 text-[15px] text-amber-600" /><p className="min-w-0 text-[12.5px] leading-7 text-amber-900">{MANUAL_GRADE_NOTICE}</p></div>
      <div className="mt-3 flex flex-wrap justify-end"><Link to="/exam-board/approvals" className={button}><FaCheckDouble aria-hidden="true" className="text-[12px]" />واجهة الاعتمادات الحالية</Link></div>
    </section>
    {notice && <p role="status" className="rounded-[10px] border border-primary/12 bg-primary/[0.04] px-4 py-3 text-[12.5px] leading-7 text-primary-dark">{notice}</p>}
    <section className={`${card} p-5 max-[560px]:p-4`} aria-label="اختيار الطالب">
      <div className="mb-3 flex items-center gap-2"><FaUserGraduate aria-hidden="true" className="shrink-0 text-[16px] text-primary" /><h2 className="text-[14.5px] font-extrabold text-text-dark">اختر الطالب</h2><span className="text-[11px] text-text-light" lang="en">Select a student</span></div>
      <label className={label}><span>البحث باسم الطالب أو رقمه</span><span className="relative block"><FaSearch aria-hidden="true" className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[13px] text-text-light" /><input className={`${field} pr-9`} value={q} onChange={e => setQ(e.target.value)} /></span></label>
      {lookupError && <p role="alert" className="mt-3 rounded-[10px] border border-red-200 bg-red-50 px-3 py-2.5 text-[12.5px] leading-6 text-red-700">تعذر البحث: {lookupError}</p>}
      {students && <section aria-label="نتائج البحث" className="mt-3">
        <div className="divide-y divide-primary/10 overflow-hidden rounded-[10px] border border-primary/10">
          {!students.students.length && <p className="py-6 text-center text-[12.5px] text-text-light">لا توجد نتائج ضمن نطاقك.</p>}
          {students.students.map(s => <button type="button" key={s.student_id} aria-pressed={student?.student_id === s.student_id}
            className={`flex w-full min-w-0 items-start gap-3 border-r-2 px-3 py-2.5 text-right transition-colors hover:bg-primary/[0.04] focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary ${student?.student_id === s.student_id ? 'border-r-primary bg-primary/[0.06]' : 'border-r-transparent bg-white'}`}
            onClick={() => change(() => { setStudent(s); setTerm({}); setPage(1) })}>
            <FaUserGraduate aria-hidden="true" className="mt-1 shrink-0 text-[13px] text-primary/70" />
            <span className="min-w-0 flex-1"><span className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5"><span className="break-words text-[13px] font-bold text-text-dark">{s.name}</span><bdi className="break-all font-mono text-[11.5px] text-text-light">{s.student_number}</bdi></span>
              <span className="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-[11.5px] leading-6 text-text-light"><span className="break-words">الكلية: {s.college}</span><span className="break-words">البرنامج: {s.program}</span></span>
            </span>
          </button>)}
        </div>
        <Pager meta={students.meta} onPage={setSearchPage} />
      </section>}
    </section>
    {student && <section className={`${card} space-y-4 p-5 max-[560px]:p-4`}>
      <header className="space-y-2 border-b border-primary/10 pb-3"><div className="flex flex-wrap items-baseline gap-x-3 gap-y-1"><h2 className="break-words text-[15px] font-extrabold text-text-dark">{data?.student?.name ?? student.name}</h2><bdi className="rounded-full bg-primary/[0.06] px-2.5 py-1 font-mono text-[12px] text-primary-dark">{data?.student?.student_number ?? student.student_number}</bdi></div>
        <dl className="flex flex-wrap gap-x-5 gap-y-1 text-[12px]"><MetaItem label="الكلية">{data?.student?.college ?? student.college}</MetaItem><MetaItem label="البرنامج">{data?.student?.program ?? student.program}</MetaItem></dl>
      </header>
      <div className="grid grid-cols-2 gap-4 max-[560px]:grid-cols-1"><label className={label}>السنة الأكاديمية<select className={field} value={term.academic_year_id ?? ''} onChange={e => changeTerm('academic_year_id', e.target.value)}><option value="">كل السنوات</option>{[...new Map((data?.terms ?? []).map(t => [t.academic_year_id, t])).values()].map(t => <option key={t.academic_year_id} value={t.academic_year_id}>{t.year_name}</option>)}</select></label>
      <label className={label}>الفصل<select className={field} value={term.semester_id ?? ''} onChange={e => changeTerm('semester_id', e.target.value)}><option value="">كل الفصول</option>{[...new Map((data?.terms ?? []).filter(t => !term.academic_year_id || String(t.academic_year_id) === term.academic_year_id).map(t => [t.semester_id, t])).values()].map(t => <option key={t.semester_id} value={t.semester_id}>{t.semester_name}</option>)}</select></label></div>
    </section>}
    {loading && <p role="status" className="flex items-center justify-center gap-2 py-4 text-[12.5px] text-primary"><FaSpinner aria-hidden="true" className="animate-spin text-[18px] motion-reduce:animate-none" />جاري تحميل الحالة الرسمية…</p>}
    {dataError && <div role="alert" className="space-y-3 rounded-[12px] border border-red-200 bg-red-50 px-4 py-3 text-[12.5px] leading-7 text-red-700"><p>{dataError}</p>{student && <button type="button" className={button} disabled={pendingCount > 0} onClick={reload}><FaRedo aria-hidden="true" />إعادة التحميل مع الاحتفاظ بالمسودات</button>}</div>}
    {data && <><div className="space-y-4">{data.registrations.map(row => <RegistrationEditor key={row.registration_id} row={row} student={data.student} identity={identity} readOnly={loading || !!dataError} onDirty={onDirty} onBusy={onBusy} reload={reload} onForbidden={clear} />)}</div>
      {!data.registrations.length && <div className={`${card} flex flex-col items-center gap-2 px-4 py-8 text-center`}><FaClipboardList aria-hidden="true" className="text-[28px] text-primary/25" /><p className="text-[12.5px] text-text-light">لا توجد تسجيلات فعلية مطابقة ضمن نطاقك.</p></div>}<Pager meta={data.meta} disabled={loading} onPage={p => change(() => setPage(p))} /></>}
    {discard && <ManualGradeDialog title="تغييرات غير محفوظة" confirmTone="discard" onCancel={() => setDiscard(null)} onConfirm={discard} confirmLabel="تجاهل التغييرات والمتابعة"><p>لن تُحفظ العلامات المعدلة عند تغيير الطالب أو الفترة أو الصفحة.</p></ManualGradeDialog>}
    {blocker.state === 'blocked' && <ManualGradeDialog title="مغادرة إدخال العلامات" disabled={pendingCount > 0}
      onCancel={() => { blocker.reset(); setNotice('أُلغي الانتقال. مسوداتك محفوظة محليًا ويمكنك متابعة التحرير والحفظ.') }}
      onConfirm={() => { if (!busy.current.size) blocker.proceed() }} confirmTone="discard" confirmLabel="تجاهل المسودات والانتقال">
      <p>{pendingCount > 0 ? 'توجد عملية قيد التنفيذ. انتظر نتيجتها قبل المغادرة؛ الإلغاء يبقيك في الصفحة.' : 'توجد مسودات غير محفوظة. هل تريد تجاهلها والانتقال إلى الوجهة المطلوبة؟'}</p>
    </ManualGradeDialog>}
  </main>
}
