import { useEffect, useReducer, useRef, useState } from 'react'
import { FaChevronLeft, FaChevronRight, FaExclamationTriangle, FaPaperPlane, FaRedo, FaSave } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import { getIdentity } from '../../auth/auth'
import ManualGradeDialog from '../components/ManualGradeDialog'
import { createGradeDraft, gradeDraftReducer, hasGradeDraft } from '../lib/manualGradeDraft'
import { catalogLabel } from '../lib/manualGradeGrid'
import { MANUAL_GRADE_ACKNOWLEDGEMENT, blockedLabel, registrationLabel, changedComponents, manualError, manualPath, markText, partLabel, savePayload, stateLabel } from '../lib/manualGradeEntry'

// Presentation tokens follow StudentPicker and ApprovalsPage; no workflow decisions live here.
const action = 'inline-flex max-w-full items-center justify-center gap-2 rounded-[10px] px-3.5 py-2.5 text-[12px] font-bold leading-5 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-40'
export const button = `${action} border border-primary/15 bg-white text-primary-dark hover:bg-primary/[0.05]`
const primaryButton = `${action} border border-primary bg-primary text-white hover:bg-primary-dark`
const warningButton = `${action} border border-amber-300 bg-amber-100 text-amber-900 hover:bg-amber-200`
const discardButton = `${action} border border-red-200 bg-white text-red-700 hover:bg-red-50`
export const field = 'w-full min-w-0 rounded-[10px] border border-primary/20 bg-white px-3 py-2.5 text-[13px] font-normal text-text-dark outline-none focus:border-primary focus:ring-2 focus:ring-primary/10 disabled:bg-primary/[0.03] disabled:text-text-light'
export const card = 'min-w-0 rounded-[16px] border border-primary/12 bg-white shadow-[0_2px_12px_rgba(26,46,16,0.05)]'
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

export function Pager({ meta, onPage, disabled }) {
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

export function RegistrationGridRow({ row, course, context, requiredParts, student, onDirty, onBusy, reload, onForbidden, identity, readOnly }) {
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
  return <tr className="border-b border-primary/10 align-top even:bg-primary/[0.02] hover:bg-primary/[0.04]" aria-busy={busy}>
    <td className="p-3 font-mono" dir="ltr">{course.course_code}</td>
    <td className="p-3"><strong>{course.course_name}</strong><p className="text-[11px] text-text-light">{catalogLabel(course)}</p></td>
    <td className="p-3" dir="ltr">{course.credit_hours}</td>
    <td className="min-w-[180px] p-3">{context}<p className="mt-2 text-[11px]">{row.academic_year} / {row.semester} — الشعبة {row.section}</p><p>التسجيل/المحاولة {row.registration_id}</p></td>
    {['theoretical', 'practical'].map(part => {
      const state = row.parts[part]
      return <td key={part} className="min-w-[145px] p-3">
        {!state ? <span className="text-text-light">{requiredParts?.length && !requiredParts.includes(part) ? 'غير مطلوب' : 'يلزم تجهيز المكونات'}</span> : <>
          <StatusChip status={state.status}>{stateLabel(state.status)}</StatusChip>
          <fieldset disabled={busy || uncertain || conflict || readOnly || !state.can_edit} className="mt-2 space-y-2">
          {row.components.filter(c => c.component_type === part).map(c => <label key={c.grade_component_id} className={label}>
            <span>{c.name} / {c.max_mark}</span>
            <input className={field} dir="ltr" inputMode="decimal" type="text" aria-label={`${c.name} — ${partLabel(part)}`}
              value={edits[c.grade_component_id] ?? markInput(baseline.components.find(b => b.grade_component_id === c.grade_component_id)?.mark)}
              onChange={event => edit(c.grade_component_id, event.target.value)} />
          </label>)}</fieldset>
          {state.blocked_reason && <p className="mt-2 text-[11px] text-amber-800">{blockedLabel(state.blocked_reason)}</p>}
        </>}
      </td>
    })}
    <td className="p-3"><StatusChip status={row.registration_status}>{registrationLabel(row.registration_status)}</StatusChip></td>
    <td className="min-w-[250px] max-w-[380px] space-y-3 p-3">
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

    </td>
  </tr>
}
