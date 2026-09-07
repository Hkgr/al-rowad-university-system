import { useEffect, useRef, useState } from 'react'
import { apiRequest } from '../../../services/apiClient'
import { getIdentity } from '../../auth/auth'
import { manualError } from '../lib/manualGradeEntry'
import { catalogLabel, preparationError, preparationPath, selectedContext } from '../lib/manualGradeGrid'
import ManualGradeDialog from './ManualGradeDialog'
import { RegistrationGridRow, button, field } from './RegistrationGridRow'

export default function CatalogGradeRow({ course, change, ...props }) {
  const [offeringId, setOfferingId] = useState('')
  const [registrationId, setRegistrationId] = useState('')
  const [dialog, setDialog] = useState(null)
  const [reason, setReason] = useState('')
  const [message, setMessage] = useState('')
  const [uncertain, setUncertain] = useState(false)
  const [busy, setBusy] = useState(false)
  const pending = useRef(false)
  const mounted = useRef(true)
  const abort = useRef(null)
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; abort.current?.abort() } }, [])
  const valid = () => mounted.current && JSON.stringify(getIdentity()) === props.identity
  const { offering, registration, attempts } = selectedContext(course, offeringId, registrationId)
  const execute = async (operation, write = false) => {
    if (pending.current || props.readOnly || !valid()) return
    pending.current = true; setBusy(true); props.onBusy(`context-${course.course_id}`, true); setMessage('')
    try {
      const json = await operation()
      if (!valid()) return
      if (write) {
        setDialog(null)
        const refreshed = await props.reload()
        if (valid()) { setUncertain(!refreshed); setMessage(refreshed ? 'تم التجهيز وتحديث الحالة الرسمية.' : 'تمت العملية، لكن تعذر تحديث العرض. أعد التحميل قبل المتابعة.') }
      } else setDialog({ type: 'components', preview: json.data })
    } catch (e) {
      if (!valid() || e.name === 'AbortError') return
      setMessage(preparationError(e) ?? manualError(e)); setDialog(null)
      if ([401, 403].includes(e.status)) { props.onForbidden(); return }
      if (write && (!e.status || e.status >= 500 || e.status === 409)) {
        setUncertain(true)
        const refreshed = await props.reload()
        if (valid() && refreshed) setUncertain(false)
      }
    } finally {
      pending.current = false
      if (valid()) { setBusy(false); props.onBusy(`context-${course.course_id}`, false) }
    }
  }
  const preview = () => {
    abort.current?.abort(); abort.current = new AbortController()
    execute(() => apiRequest(preparationPath(props.student.student_id, offering.course_offering_id, 'component-preview'), { signal: abort.current.signal }))
  }
  const confirm = () => {
    const body = dialog.type === 'components' ? { confirmed: true, revision: dialog.preview.revision }
      : { confirmed: true, reason, course_id: course.course_id, academic_year_id: offering.academic_year_id, semester_id: offering.semester_id }
    execute(() => apiRequest(preparationPath(props.student.student_id, offering.course_offering_id, dialog.type === 'components' ? 'components' : 'registration'),
      { method: 'POST', body: JSON.stringify(body) }), true)
  }
  const context = <div className="space-y-2">
    <label>الطرح الفعلي<select className={field} disabled={busy || props.readOnly} value={offering?.course_offering_id ?? ''}
      onChange={e => { const value = e.target.value; change(() => { setOfferingId(value); setRegistrationId('') }) }}>
      <option value="">اختر الطرح / الشعبة</option>{course.offerings.map(o => <option key={o.course_offering_id} value={o.course_offering_id}>{o.academic_year} / {o.semester} — {o.program} — الشعبة {o.course_offering_id} ({o.status})</option>)}
    </select></label>
    {attempts.length > 1 && <label>التسجيل / المحاولة<select className={field} value={registration?.registration_id ?? ''} disabled={busy || props.readOnly}
      onChange={e => { const value = e.target.value; change(() => setRegistrationId(value)) }}><option value="">اختر المحاولة صراحةً</option>
      {attempts.map(r => <option key={r.registration_id} value={r.registration_id}>{r.registration_id} — {r.registration_status}</option>)}
    </select></label>}
    {offering && !attempts.length && <><p className="text-[11px]">لا يوجد تسجيل في السياق المختار. التجهيز الاستثنائي لا يتجاوز التقويم أو الأهلية.</p>
      <button className={button} type="button" disabled={busy || uncertain || props.readOnly} onClick={() => { setReason(''); setDialog({ type: 'registration' }) }}>استكمال التسجيل الاستثنائي</button></>}
    {offering && (!registration?.components.length || offering.configuration_missing) && <button className={button} type="button" disabled={busy || uncertain || props.readOnly} onClick={preview}>تجهيز مكونات العلامات</button>}
    {message && <p role="status" className="text-[12px] text-amber-800">{message}</p>}
    {uncertain && <button className={button} disabled={busy} type="button" onClick={async () => { if (await props.reload()) setUncertain(false) }}>إعادة تحميل الحالة الرسمية</button>}
    {dialog && <ManualGradeDialog title={dialog.type === 'components' ? 'تجهيز مكونات الطرح بالكامل' : 'تأكيد التسجيل الاستثنائي لهيئة الامتحانات'} busy={busy}
      disabled={props.readOnly || uncertain || (dialog.type === 'registration' && !reason.trim())} onCancel={() => setDialog(null)} onConfirm={confirm}>
      <p>{props.student.name} — {course.course_name} — {offering.academic_year} / {offering.semester} — الشعبة {offering.course_offering_id}</p>
      {dialog.type === 'components' ? <><p>تسري المكونات على جميع طلاب الطرح؛ لا تُغيّر العلامات أو ترسلها للاعتماد.</p>
        <ul>{dialog.preview.components.map(c => <li key={c.component_type}>{c.component_name}: {c.max_mark}</li>)}</ul></>
        : <><p>استثناء موثق من طلب الطالب واعتماد المرشد فقط. تبقى شروط التسجيل الأخرى سارية، ولا تنشأ علامات أو نتيجة رسمية بهذه العملية.</p>
          <label>سبب الإدخال الاستثنائي<textarea className={field} value={reason} maxLength={1000} onChange={e => setReason(e.target.value)} /></label></>}
    </ManualGradeDialog>}
  </div>
  if (registration) return <RegistrationGridRow key={`${registration.registration_id}:${props.draftEpoch}`} row={registration} course={course} context={context} requiredParts={offering.required_parts} {...props} readOnly={props.readOnly || busy || uncertain} />
  return <tr className="border-b border-primary/10 align-top even:bg-primary/[0.02] hover:bg-primary/[0.04]">
    <td className="p-3 font-mono" dir="ltr">{course.course_code}</td><td className="p-3"><strong>{course.course_name}</strong><p className="text-[11px] text-text-light">{catalogLabel(course)}</p></td>
    <td className="p-3" dir="ltr">{course.credit_hours}</td><td className="min-w-[200px] p-3">{context}</td>
    <td colSpan={4} className="p-3 text-text-light">{!course.offerings.length ? 'لا يوجد طرح مصرح به في الفترة المختارة؛ يلزم تجهيز الطرح عبر العميد.' : !offering || attempts.length > 1 ? 'اختر السياق الأكاديمي صراحةً.' : 'يلزم تسجيل رسمي قبل إدخال العلامات.'}</td>
  </tr>
}
