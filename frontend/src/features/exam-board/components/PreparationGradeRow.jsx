import { useEffect, useRef, useState } from 'react'
import { apiRequest } from '../../../services/apiClient'
import { getIdentity } from '../../auth/auth'
import { MANUAL_GRADE_ACKNOWLEDGEMENT, manualError, partLabel, markText } from '../lib/manualGradeEntry'
import { catalogLabel, preparationError } from '../lib/manualGradeGrid'
import { contextPath, preparationMarks, rebasePreparationValues } from '../lib/manualGradePreparation'
import { button, field } from './RegistrationGridRow'
import ManualGradeDialog from './ManualGradeDialog'

export default function PreparationGradeRow(props) {
  const { course, student, offering, registration, term, identity, readOnly, onDirty, onBusy, onForbidden, onSaved } = props
  const key = `preparation-${course.course_id}`
  const [selection] = useState(() => ({ academic_year_id: term?.academic_year_id || offering?.academic_year_id,
    semester_id: term?.semester_id || offering?.semester_id,
    ...(offering ? { course_offering_id: offering.course_offering_id } : {}), ...(registration ? { registration_id: registration.registration_id } : {}) }))
  const [preview, setPreview] = useState(null)
  const [server, setServer] = useState(null)
  const [values, setValues] = useState({})
  const valuesRef = useRef({})
  const [loading, setLoading] = useState(false)
  const [busy, setBusy] = useState(false)
  const [blocked, setBlocked] = useState(false)
  const [message, setMessage] = useState('')
  const [review, setReview] = useState(null)
  const [reason, setReason] = useState('')
  const [ack, setAck] = useState(false)
  const [retry, setRetry] = useState(0)
  const mounted = useRef(true), pending = useRef(false), abort = useRef(null)
  const valid = () => mounted.current && JSON.stringify(getIdentity()) === identity
  const ready = selection.academic_year_id && selection.semester_id && !props.ambiguous
  useEffect(() => {
    mounted.current = true
    if (!ready) return () => { mounted.current = false }
    let active = true
    const controller = new AbortController(); abort.current = controller
    setLoading(true)
    apiRequest(`${contextPath(student.student_id, course.course_id, 'preview')}?${new URLSearchParams(selection)}`, { signal: controller.signal }).then(json => {
      if (!active || !mounted.current || JSON.stringify(getIdentity()) !== identity) return
      setServer(json.data)
      if (!Object.keys(valuesRef.current).length) { setPreview(json.data); setBlocked(false) }
      else { setBlocked(true); setMessage('احتُفظ بالقيم المقترحة؛ راجع الحالة المحملة ثم اختر إعادة المراجعة صراحةً.') }
    }).catch(e => {
      if (!active || !mounted.current || e.name === 'AbortError' || JSON.stringify(getIdentity()) !== identity) return
      setBlocked(true); setMessage(preparationError(e) ?? (e.status === 409 ? e.message : manualError(e)))
      if ([401, 403].includes(e.status)) onForbidden()
    }).finally(() => { if (active && mounted.current) setLoading(false) })
    return () => { active = false; mounted.current = false; controller.abort() }
  }, [selection, ready, retry, student.student_id, course.course_id, identity, onForbidden])
  const edit = (id, value) => {
    const next = { ...valuesRef.current, [id]: value }; valuesRef.current = next; setValues(next); setAck(false)
    onDirty(key, true)
  }
  const prepareReview = () => {
    try { const marks = preparationMarks(preview, values); if (marks.length) { setReason(''); setAck(false); setReview(marks) } }
    catch (e) { setMessage(e.message) }
  }
  const confirm = async () => {
    if (pending.current || !valid() || blocked || readOnly || !ack || !reason.trim()) return
    pending.current = true; setBusy(true); onBusy(key, true)
    try {
      const response = await apiRequest(contextPath(student.student_id, course.course_id, 'save'), { method: 'POST', body: JSON.stringify({
        ...selection, revision: preview.revision, components: review, confirmed: true, acknowledged: true,
        reason, correction_confirmed: review.some(m => preview.components.find(c => c.key === m.key)?.mark !== null),
      }) })
      if (!valid()) return
      onDirty(key, false); onBusy(key, false)
      onSaved(response.data) // Only the confirmed row changes mode; other drafts remain mounted.
      await props.reload()
    } catch (e) {
      if (!valid()) return
      setReview(null); setAck(false)
      setMessage(preparationError(e) ?? (e.status === 409 ? e.message : manualError(e)))
      if ([401, 403].includes(e.status)) { onForbidden(); return }
      if (!e.status || e.status >= 500 || e.status === 409) {
        setBlocked(true); setServer(null)
        setMessage('تعذر تأكيد الحفظ أو تغير السياق. احتُفظ بالمسودة؛ حمّل الحالة ثم راجعها. لن يعاد إرسال العملية تلقائيًا.')
        setRetry(n => n + 1) // Read only; never retry a write or assume rollback after lost response.
      }
    } finally {
      pending.current = false
      if (valid()) { setBusy(false); onBusy(key, false) }
    }
  }
  const rebase = () => {
    const next = rebasePreparationValues(preview, server, values)
    if (next === null) { setMessage('تغير تعريف المكونات؛ احتفظ بالقيم للمراجعة أو تجاهل المسودة صراحةً.'); return }
    setPreview(server); valuesRef.current = next; setValues(next); setBlocked(false); setAck(false); setMessage('راجع القيم مقابل الحالة الجديدة ثم أعد التأكيد.')
  }
  return <tr className="border-b border-primary/10 align-top even:bg-primary/[0.02] hover:bg-primary/[0.04]" aria-busy={busy || loading}>
    <td className="p-3 font-mono" dir="ltr">{course.course_code}</td><td className="p-3"><strong>{course.course_name}</strong><p className="text-[11px] text-text-light">{catalogLabel(course)}</p></td>
    <td className="p-3" dir="ltr">{course.credit_hours}</td><td className="min-w-[180px] p-3">{props.context}{preview && <p>{preview.academic_year} / {preview.semester} — {preview.program}</p>}</td>
    {['theoretical', 'practical'].map(part => <td key={part} className="min-w-[145px] space-y-2 p-3">
      {preview?.components.filter(c => c.component_type === part).map(c => <label className="block space-y-1" key={c.key}>{c.name} / {c.max_mark}
        <input className={field} type="text" inputMode="decimal" dir="ltr" aria-label={`${c.name} — ${partLabel(part)}`}
          disabled={busy || loading || blocked || readOnly} value={values[c.key] ?? (c.mark === null ? '' : String(c.mark))} onChange={e => edit(c.key, e.target.value)} />
      </label>)}
    </td>)}
    <td className="p-3 text-[12px]">{preview?.create_offering ? 'سيُجهّز السياق عند الحفظ دون فتح تسجيل الطلاب' : preview?.create_registration ? 'سيُستكمل التسجيل عند الحفظ' : 'السياق الحالي'}</td>
    <td className="min-w-[240px] space-y-3 p-3">
      {!ready && <p>اختر السنة والفصل الفعليين، وحدد الطرح والمحاولة عند تعددهما.</p>}
      {loading && <p role="status">جاري التحقق من السياق وحدود العلامات…</p>}
      {message && <p role="status" className="text-amber-900">{message}</p>}
      {preview && <button className={button} disabled={busy || loading || blocked || readOnly || !Object.keys(values).length} onClick={prepareReview}>مراجعة وحفظ العلامات</button>}
      {blocked && <button className={button} disabled={busy || loading} onClick={() => setRetry(n => n + 1)}>تحميل الحالة الرسمية مع الاحتفاظ بالقيم</button>}
      {blocked && server && preview && <><p>القيم المقترحة أعلاه محفوظة محليًا. الحالة الحالية:</p>
        <ul>{server.components.map(c => <li key={c.key}>{c.name}: {markText(c.mark)}</li>)}</ul>
        <button className={button} disabled={busy || loading || readOnly} onClick={rebase}>إبقاء المقترحات وإعادة المراجعة</button>
        <button className={button} disabled={busy || loading || readOnly} onClick={() => { valuesRef.current = {}; setValues({}); setPreview(server); setBlocked(false); setAck(false); onDirty(key, false) }}>تجاهل المسودة واستخدام نسخة الخادم</button></>}
      {review && <ManualGradeDialog title="مراجعة العلامات والسياق الأكاديمي" busy={busy} disabled={!ack || !reason.trim() || readOnly || blocked} onCancel={() => setReview(null)} onConfirm={confirm}>
        <p>{student.name} — {course.course_name} — {preview.academic_year} / {preview.semester} — {preview.program}</p>
        <p>{preview.create_offering ? 'سيُنشأ طرح عادي مغلق للتسجيل.' : 'سيُستخدم الطرح الحالي دون إعادة فتحه.'} {preview.create_registration ? 'سيُنشأ التسجيل الأكاديمي.' : 'ستُستخدم المحاولة الحالية.'} {preview.create_components ? 'ستُجهّز المكونات من السياسة الرسمية.' : 'ستُحفظ المكونات الحالية دون تغيير تعريفها.'}</p>
        <p>إعفاء موثق من طلب الطالب واعتماد المرشد والسنة الحالية ونافذة التسجيل وفتح الطرح والجدول الأسبوعي. لا يتجاوز المنهج والمتطلبات وحد الساعات وأقفال العلامات والتكميلي. الحفظ مسودة فقط؛ الإرسال والاعتماد إجراءان منفصلان.</p>
        <ul>{review.map(m => { const c = preview.components.find(c => c.key === m.key); return <li key={m.key}>{c.name}: {markText(c.mark)} ← {markText(m.mark)}</li> })}</ul>
        <label>سبب الإدخال أو التصحيح<textarea className={field} value={reason} maxLength={1000} onChange={e => setReason(e.target.value)} /></label>
        <label className="flex gap-2"><input type="checkbox" checked={ack} onChange={e => setAck(e.target.checked)} />{MANUAL_GRADE_ACKNOWLEDGEMENT}</label>
      </ManualGradeDialog>}
    </td>
  </tr>
}
