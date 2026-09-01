import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  FaBookOpen, FaCheckCircle, FaClock, FaPlus, FaMinus, FaSpinner,
} from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import CourseRequirementBadges from '../../../components/academic/CourseRequirementBadges'
import StudentConfirmDialog from '../components/StudentConfirmDialog'
import { formatUniversityDateTime, studentRegistrationNotice } from '../../registration-requests/registrationDeadlinePresentation'
import OfficialTimetable from '../../registration-requests/OfficialTimetable'

const REASON_LABELS = {
  timetable_schema_not_ready: { ar: 'مخطط الجدول الرسمي غير جاهز حالياً.', tone: 'full' },
  offering_schedule_incomplete: { ar: 'الجدول الأسبوعي غير مكتمل بعد.', tone: 'prerequisite' },
  timetable_conflict: { ar: 'يوجد تعارض في الجدول.', tone: 'full' },
  timetable_reference_incomplete: { ar: 'تعذر التحقق من التعارض لأن جدول أحد المقررات المسجلة أو المطلوبة غير مكتمل.', tone: 'full' },
  already_registered: { ar: 'مسجل مسبقاً', tone: 'registered' },
  already_in_request: { ar: 'مضاف إلى الطلب', tone: 'request' },
  course_already_passed: { ar: 'تم اجتياز هذا المقرر سابقاً ولا يمكن تسجيله مجدداً ضمن التسجيل العادي.', tone: 'registered' },
  missing_prerequisites: { ar: 'متطلب سابق غير محقق', tone: 'prerequisite' },
  credit_limit_exceeded: { ar: 'تجاوز الساعات', tone: 'hours' },
  elective_requirement_completed: { ar: 'لقد استوفيت الساعات المطلوبة لهذا المتطلب الاختياري.', tone: 'hours' },
  elective_requirement_fully_committed: { ar: 'تم حجز كامل الساعات المطلوبة لهذا المتطلب ضمن مقرراتك المسجلة أو طلبات التسجيل الحالية.', tone: 'hours' },
  elective_requirement_limit_exceeded: { ar: 'إضافة هذا المقرر ستتجاوز الساعات المطلوبة لهذا المتطلب الاختياري.', tone: 'hours' },
  course_outside_current_curriculum: { ar: 'هذا المقرر ليس ضمن خطتك الدراسية الحالية.', tone: 'prerequisite' },
  academic_requirement_configuration_invalid: { ar: 'تعذر التحقق من متطلبات الخطة حالياً. يرجى مراجعة شؤون الطلاب.', tone: 'full' },
}

const BADGE_CLASS = {
  eligible: 'bg-green-100 text-green-700',
  registered: 'bg-blue-100 text-blue-700',
  request: 'bg-primary/10 text-primary-dark',
  prerequisite: 'bg-amber-100 text-amber-800',
  full: 'bg-orange-100 text-orange-700',
  hours: 'bg-yellow-100 text-yellow-800',
}

const STATUS_LABELS = {
  draft: { ar: 'مسودة', className: 'bg-gray-100 text-text-dark border-gray-200' },
  submitted: { ar: 'بانتظار مراجعة المرشد الأكاديمي', className: 'bg-amber-100 text-amber-900 border-amber-200' },
  returned: { ar: 'أعيد للتعديل', className: 'bg-orange-100 text-orange-800 border-orange-200' },
  approved: { ar: 'تم اعتماد طلب التسجيل', className: 'bg-green-100 text-green-800 border-green-200' },
  expired: { ar: 'انتهت المهلة دون اعتماد', className: 'bg-red-100 text-red-800 border-red-200' },
}

function knownReasonLabel(code) {
  if (typeof code !== 'string' || code === '') return null
  return REASON_LABELS[code]?.ar ?? null
}

function collectKnownReasonCodes(error) {
  const codes = []

  function push(value) {
    if (typeof value === 'string' && REASON_LABELS[value] && !codes.includes(value)) {
      codes.push(value)
    }
  }

  function pushFromList(list) {
    if (!Array.isArray(list)) {
      push(list)
      return
    }
    list.forEach(item => {
      if (typeof item === 'string') {
        push(item)
        return
      }
      if (item && typeof item === 'object') {
        push(item.reason)
        if (Array.isArray(item.reasons)) item.reasons.forEach(push)
      }
    })
  }

  push(error?.errorCode)
  pushFromList(error?.itemFailures)
  const details = error?.details && typeof error.details === 'object' ? error.details : {}
  pushFromList(details.course_offering_id)
  pushFromList(details.items)

  return codes
}

function registrationErrorMessage(error, fallback) {
  const labels = collectKnownReasonCodes(error)
    .map(knownReasonLabel)
    .filter(Boolean)

  if (labels.length === 1) return labels[0]
  if (labels.length > 1) return labels.join('، ')

  return fallback || error?.message || ''
}

function studentRegistrationPath(semesterId) {
  const params = new URLSearchParams()
  if (semesterId) params.set('semester_id', semesterId)
  const query = params.toString()
  return `/v1/student/registration${query ? `?${query}` : ''}`
}

function HoursPanel({ hours, requestStatus }) {
  const snapshot = hours?.approved_snapshot
  const approved = requestStatus === 'approved' && snapshot
  const registered = approved ? (snapshot.registered_hours_before_approval ?? 0) : (hours?.registered_hours ?? 0)
  const requestHours = approved ? (snapshot.request_hours_at_approval ?? 0) : (hours?.request_hours ?? 0)
  const projected = approved ? (snapshot.projected_hours_at_approval ?? registered + requestHours) : (hours?.projected_hours ?? registered + requestHours)
  const max = approved ? (snapshot.max_allowed_hours_at_approval ?? 0) : (hours?.max_allowed_hours ?? 0)
  const remaining = approved ? (snapshot.remaining_hours_after_approval ?? 0) : (hours?.remaining_after_approval ?? 0)
  const officialCgpa = hours?.official_cgpa
  const recommendedMinimum = hours?.recommended_minimum_hours ?? 12
  const belowRecommendedMinimum = hours?.below_recommended_minimum === true
  const pct = max > 0 ? Math.min((projected / max) * 100, 100) : 0
  const color = pct >= 100 ? 'bg-red-500' : pct >= 90 ? 'bg-red-500' : pct >= 70 ? 'bg-amber-500' : 'bg-primary'

  return (
    <section className="bg-white border border-primary/12 rounded-[16px] p-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]" dir="rtl">
      <div className="grid grid-cols-6 max-[1050px]:grid-cols-3 max-[700px]:grid-cols-2 max-[520px]:grid-cols-1 gap-4 mb-4">
        <div>
          <p className="text-[11.5px] font-semibold text-text-light mb-1">{approved ? 'المعدل التراكمي الرسمي الحالي' : 'المعدل التراكمي الرسمي'}</p>
          <p className="text-[22px] font-black text-text-dark tabular-nums">
            {officialCgpa == null ? 'لا يوجد معدل تراكمي رسمي حتى الآن' : Number(officialCgpa).toFixed(2)}
          </p>
        </div>
        <div>
          <p className="text-[11.5px] font-semibold text-text-light mb-1">{approved ? 'الساعات قبل الاعتماد' : 'الساعات المسجلة حالياً'}</p>
          <p className="text-[22px] font-black text-text-dark tabular-nums">{registered}</p>
        </div>
        <div>
          <p className="text-[11.5px] font-semibold text-text-light mb-1">{approved ? 'ساعات الطلب المعتمدة' : 'ساعات الطلب'}</p>
          <p className="text-[22px] font-black text-primary tabular-nums">{requestHours}</p>
        </div>
        <div>
          <p className="text-[11.5px] font-semibold text-text-light mb-1">{approved ? 'إجمالي الساعات بعد الاعتماد' : 'الإجمالي بعد الاعتماد'}</p>
          <p className="text-[22px] font-black text-text-dark tabular-nums">{projected}</p>
        </div>
        <div>
          <p className="text-[11.5px] font-semibold text-text-light mb-1">{approved ? 'الحد الأقصى وقت الاعتماد' : 'الحد الأقصى المسموح'}</p>
          <p className="text-[22px] font-black text-text-dark tabular-nums">{max}</p>
        </div>
        <div>
          <p className="text-[11.5px] font-semibold text-text-light mb-1">{approved ? 'المتبقي بعد الاعتماد' : 'المتبقي بعد الاعتماد'}</p>
          <p className="text-[22px] font-black text-primary tabular-nums">{remaining}</p>
        </div>
      </div>
      <div className="h-2.5 bg-gray-100 rounded-full overflow-hidden">
        <div className={`h-full rounded-full transition-all duration-500 ${color}`} style={{ width: `${pct}%` }} />
      </div>
      {belowRecommendedMinimum ? (
        <p className="mt-3 rounded-[10px] border border-amber-200 bg-amber-50 px-3 py-2 text-[12.5px] font-semibold leading-6 text-amber-900">
          عدد الساعات المختارة أقل من العبء الدراسي المعتاد ({recommendedMinimum} ساعة)، ويمكنك متابعة إرسال الطلب.
        </p>
      ) : null}
    </section>
  )
}

function advisoryPlanLabel(course) {
  const plan = course?.advisory_plan
  if (!plan) return null
  const parts = [plan.academic_level_name, plan.recommended_semester_name].filter(Boolean)
  return parts.length > 0 ? parts.join(' — ') : null
}

function splitAdvisoryCourses(courses, workspaceSemesterId, studentAcademicLevelId) {
  const recommended = []
  const other = []
  const seen = new Set()
  const hasStudentLevel = studentAcademicLevelId != null && studentAcademicLevelId !== ''
  const hasWorkspaceSemester = workspaceSemesterId != null && workspaceSemesterId !== ''

  courses.forEach(course => {
    const offeringId = course?.course_offering_id
    if (offeringId == null || seen.has(offeringId)) return
    seen.add(offeringId)

    const plan = course?.advisory_plan
    const advisoryLevelId = plan?.academic_level_id
    const recommendedSemesterId = plan?.recommended_semester_id
    const isRecommended = hasStudentLevel
      && hasWorkspaceSemester
      && advisoryLevelId != null
      && recommendedSemesterId != null
      && Number(advisoryLevelId) === Number(studentAcademicLevelId)
      && Number(recommendedSemesterId) === Number(workspaceSemesterId)

    if (isRecommended) recommended.push(course)
    else other.push(course)
  })

  return { recommended, other }
}

function ApprovedRegistrationModificationPanel({ workflow, semesterId, reload, onError, showToast }) {
  const [busy, setBusy] = useState('')
  const [notes, setNotes] = useState(workflow?.current?.student_notes ?? '')
  const current = workflow?.current ?? null
  const editable = current?.editable === true
  const items = current?.items ?? []
  const available = current?.available_courses ?? []
  const hours = current?.hours ?? null

  useEffect(() => {
    setNotes(current?.student_notes ?? '')
  }, [current?.student_registration_modification_request_id, current?.student_notes])

  async function mutate(path, options, key, message) {
    setBusy(key)
    onError('')
    try {
      await apiRequest(path, options)
      showToast(message)
      await reload()
    } catch (error) {
      onError(error?.message || 'تعذر تحديث طلب تعديل التسجيل.')
    } finally {
      setBusy('')
    }
  }

  if (workflow?.schema_ready !== true) {
    return null
  }

  if (!current) {
    if (workflow?.can_create !== true) return null
    return (
      <section className="rounded-[16px] border border-primary/20 bg-white p-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]" dir="rtl">
        <h2 className="text-[16px] font-black text-text-dark">تعديل التسجيل المعتمد</h2>
        <p className="mt-2 text-[13px] leading-7 text-text-light">يمكنك اقتراح إزالة أو إضافة مقررات. يبقى تسجيلك الرسمي الحالي نافذًا حتى اعتماد المرشد الأكاديمي.</p>
        <button
          type="button"
          disabled={!semesterId || busy !== ''}
          onClick={() => mutate('/v1/student/registration/modification', { method: 'POST', body: JSON.stringify({ semester_id: Number(semesterId) }) }, 'create', 'تم إنشاء مسودة تعديل التسجيل.')}
          className="mt-4 rounded-[11px] bg-primary px-4 py-2.5 text-[13px] font-black text-white disabled:opacity-40"
        >
          {busy === 'create' ? 'جاري الإنشاء…' : 'تعديل التسجيل المعتمد'}
        </button>
      </section>
    )
  }

  const statusLabel = {
    draft: 'مسودة', submitted: 'قيد مراجعة المرشد الأكاديمي', returned: 'أعيد للتعديل',
    approved: 'معتمد', expired: 'منتهي', superseded: 'استُبدل لتغير التسجيل الرسمي',
  }[current.status] ?? current.status

  return (
    <section className="rounded-[16px] border border-primary/20 bg-white p-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]" dir="rtl">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-[16px] font-black text-text-dark">تعديل التسجيل المعتمد</h2>
          <p className="mt-1 text-[12px] font-bold text-primary">{statusLabel}</p>
        </div>
        {hours ? <p className="text-[12px] font-bold text-text-dark">الساعات المتوقعة: {hours.projected_hours ?? 0} / {hours.max_allowed_hours ?? 0}</p> : null}
      </div>
      <p className="mt-3 rounded-[10px] border border-blue-200 bg-blue-50 px-3 py-2 text-[12.5px] font-semibold leading-6 text-blue-900">
        يبقى تسجيلك الرسمي الحالي نافذًا حتى اعتماد المرشد للتعديل.
      </p>
      {hours?.below_recommended_minimum ? <p className="mt-2 text-[12px] font-semibold text-amber-800">العبء المتوقع أقل من 12 ساعة، وهذا تنبيه إرشادي لا يمنع الإرسال.</p> : null}
      {current.advisor_notes ? <p className="mt-3 rounded-[10px] bg-orange-50 px-3 py-2 text-[12.5px] text-orange-900">ملاحظات المرشد: {current.advisor_notes}</p> : null}
      {(current?.failures ?? []).length > 0 ? (
        <div className="mt-3 rounded-[10px] border border-red-200 bg-red-50 px-3 py-2 text-[12px] text-red-800">
          {(current.failures ?? []).map((failure, index) => (
            <p key={`${failure.course_offering_id ?? 'term'}-${failure.reason ?? index}`}>
              {knownReasonLabel(failure.reason) ?? failure.reason}
            </p>
          ))}
        </div>
      ) : null}

      <div className="mt-4 space-y-2">
        <h3 className="text-[13px] font-black text-text-dark">التسجيل الرسمي الحالي والتغييرات المطلوبة</h3>
        {items.map(item => (
          <div key={item.student_registration_modification_item_id} className="flex flex-wrap items-center justify-between gap-3 rounded-[11px] border border-primary/10 px-3 py-3">
            <div>
              <p className="text-[13px] font-bold text-text-dark">{item.course?.course_name}</p>
              <p className="text-[11.5px] text-text-light">{item.course?.course_code} · {item.course?.credit_hours} ساعات</p>
              <p className={`mt-1 text-[11px] font-bold ${item.operation === 'remove' ? 'text-red-700' : item.operation === 'add' ? 'text-primary' : 'text-blue-700'}`}>
                {item.operation === 'remove' ? 'سيُحذف المقرر فقط بعد اعتماد المرشد الأكاديمي.' : item.operation === 'add' ? 'إضافة مقترحة' : 'مقرر مستمر'}
              </p>
            </div>
            {editable && item.operation !== 'add' ? (
              <button
                type="button"
                disabled={busy !== ''}
                onClick={() => mutate(`/v1/student/registration/modification/items/${item.student_registration_modification_item_id}`, { method: 'PATCH', body: JSON.stringify({ operation: item.operation === 'remove' ? 'keep' : 'remove' }) }, `toggle-${item.student_registration_modification_item_id}`, item.operation === 'remove' ? 'تم التراجع عن الإزالة.' : 'تم تعليم المقرر للإزالة بعد الاعتماد.')}
                className="rounded-[9px] border border-primary/25 px-3 py-1.5 text-[12px] font-bold text-primary"
              >{item.operation === 'remove' ? 'تراجع عن الإزالة' : 'إزالة من التسجيل'}</button>
            ) : null}
            {editable && item.operation === 'add' ? (
              <button
                type="button"
                disabled={busy !== ''}
                onClick={() => mutate(`/v1/student/registration/modification/items/${item.student_registration_modification_item_id}`, { method: 'DELETE' }, `delete-${item.student_registration_modification_item_id}`, 'تمت إزالة المقرر المقترح.')}
                className="rounded-[9px] border border-red-200 px-3 py-1.5 text-[12px] font-bold text-red-700"
              >إزالة المقترح</button>
            ) : null}
          </div>
        ))}
      </div>

      {editable ? (
        <>
          <div className="mt-5">
            <h3 className="text-[13px] font-black text-text-dark">إضافة مقررات إلى المجموعة المتوقعة</h3>
            <div className="mt-2 grid grid-cols-2 gap-2 max-[800px]:grid-cols-1">
              {available.filter(course => !course.eligibility_reasons?.includes('already_in_request')).map(course => (
                <button
                  type="button"
                  key={course.course_offering_id}
                  disabled={busy !== '' || course.eligibility_status !== 'eligible'}
                  onClick={() => mutate(`/v1/student/registration/modification/items/${course.course_offering_id}`, { method: 'POST', body: JSON.stringify({}) }, `add-${course.course_offering_id}`, 'تمت إضافة المقرر إلى التعديل.')}
                  className="rounded-[10px] border border-primary/15 px-3 py-2 text-right text-[12px] font-bold text-text-dark disabled:opacity-45"
                >{course.course_code} — {course.course_name}</button>
              ))}
            </div>
          </div>
          <label className="mt-4 block text-[12px] font-bold text-text-dark">
            ملاحظات للمرشد
            <textarea value={notes} onChange={event => setNotes(event.target.value)} maxLength={1000} className="mt-2 min-h-[80px] w-full rounded-[10px] border border-primary/20 p-3" />
          </label>
          <div className="mt-3 flex flex-wrap gap-2">
            <button type="button" disabled={busy !== ''} onClick={() => mutate('/v1/student/registration/modification', { method: 'PATCH', body: JSON.stringify({ student_notes: notes, semester_id: Number(semesterId) }) }, 'notes', 'تم حفظ الملاحظات.')} className="rounded-[10px] border border-primary/25 px-4 py-2 text-[12px] font-bold text-primary">حفظ الملاحظات</button>
            <button type="button" disabled={busy !== ''} onClick={() => mutate('/v1/student/registration/modification/submit', { method: 'POST', body: JSON.stringify({ semester_id: Number(semesterId) }) }, 'submit', 'تم إرسال تعديل التسجيل إلى المرشد.')} className="rounded-[10px] bg-primary px-4 py-2 text-[12px] font-black text-white">إرسال التعديل</button>
          </div>
        </>
      ) : null}
    </section>
  )
}

function CancelledCourseReplacementPanel({ workflow, academicYearId, semesterId, reload, onError, showToast }) {
  const [busy, setBusy] = useState('')
  const [sourceId, setSourceId] = useState('')
  const [targetId, setTargetId] = useState('')
  const [notes, setNotes] = useState('')
  useEffect(() => { setNotes(workflow?.request?.student_notes ?? '') }, [workflow?.request?.student_notes])
  if (workflow?.schema_ready !== true) return null

  const request = workflow.request ?? null
  const sources = workflow.cancelled_sources ?? []
  const targets = workflow.replacement_targets ?? []
  const history = workflow.history ?? []
  const editable = workflow?.deadline?.student_registration_open === true
    && (!request || request.status === 'draft' || request.status === 'returned')

  async function mutate(path, options, key, message) {
    setBusy(key); onError('')
    try { await apiRequest(path, options); showToast(message); await reload() }
    catch (error) { onError(error?.message || 'تعذر تحديث طلب استبدال المقررات الملغاة.') }
    finally { setBusy('') }
  }

  if (!request && sources.length === 0) return null
  return (
    <section className="rounded-[16px] border border-amber-200 bg-white p-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]" dir="rtl">
      <h2 className="text-[16px] font-black text-text-dark">استبدال المقررات الملغاة لعدم اكتمال الحد الأدنى</h2>
      <p className="mt-2 text-[12.5px] leading-6 text-text-light">يبقى المقرر المصدر ملغى تاريخياً، ويصبح المقرر البديل تسجيلاً رسمياً مستقلاً فقط بعد اعتماد المرشد الأكاديمي.</p>
      {!request ? (
        <button type="button" disabled={!editable || busy !== ''} onClick={() => mutate('/v1/student/registration/replacement', { method: 'POST', body: JSON.stringify({ academic_year_id: Number(academicYearId), semester_id: Number(semesterId) }) }, 'create', 'تم إنشاء مسودة الاستبدال.')} className="mt-3 rounded-[10px] bg-primary px-4 py-2 text-[12px] font-black text-white disabled:opacity-40">إنشاء طلب استبدال</button>
      ) : (
        <>
          <p className="mt-2 text-[12px] font-bold text-primary">الحالة: {request.status}</p>
          {request.hours ? <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
            {[['الساعات الحالية', request.hours.registered_hours], ['ساعات الاستبدال', request.hours.replacement_hours], ['الساعات المتوقعة', request.hours.projected_hours], ['الحد الأعلى', request.hours.max_allowed_hours], ['المعدل الرسمي الحالي', request.hours.official_cgpa ?? '—']].map(([label, value]) => <div key={label} className="rounded-[10px] bg-primary/[0.05] p-2 text-[11px]"><span className="block text-text-light">{label}</span><b>{value ?? '—'}</b></div>)}
            {request.hours.below_recommended_minimum ? <p className="sm:col-span-2 lg:col-span-5 text-[11px] font-bold text-amber-700">المجموعة المتوقعة أقل من الحد الإرشادي 12 ساعة، وهذا تنبيه لا يمنع الإرسال.</p> : null}
          </div> : null}
          {(request.failures ?? []).length > 0 ? <div className="mt-3 rounded-[10px] bg-red-50 p-3 text-[11px] text-red-700">{request.failures.map((failure, index) => <p key={`${failure.reason}-${index}`}>{failure.reason}</p>)}</div> : null}
          <div className="mt-3 space-y-2">
            {(request.items ?? []).map(item => <div key={item.student_registration_replacement_item_id} className="rounded-[10px] border border-primary/12 p-3 text-[12px]"><b>{item.source_course?.course_code} — {item.source_course?.course_name}</b><span className="mx-2">←</span>{editable ? <select value={item.replacement_course_offering_id} disabled={busy !== ''} onChange={event => mutate(`/v1/student/registration/replacement/items/${item.student_registration_replacement_item_id}`, { method: 'PATCH', body: JSON.stringify({ replacement_course_offering_id: Number(event.target.value) }) }, `update-${item.student_registration_replacement_item_id}`, 'تم تحديث المقرر البديل.')} className="rounded-[8px] border border-primary/20 p-1.5">{targets.map(target => <option key={target.course_offering_id} value={target.course_offering_id} disabled={(target.eligibility_failures ?? []).length > 0}>{target.course?.course_code} — {target.course?.course_name}</option>)}</select> : <span>{item.target_course?.course_code} — {item.target_course?.course_name}</span>}{editable ? <button type="button" disabled={busy !== ''} onClick={() => mutate(`/v1/student/registration/replacement/items/${item.student_registration_replacement_item_id}`, { method: 'DELETE' }, `remove-${item.student_registration_replacement_item_id}`, 'تم حذف البديل من المسودة.')} className="ms-3 rounded-[8px] border border-red-200 px-2 py-1 font-bold text-red-700">حذف</button> : null}<p className="mt-2 text-[11px] text-text-light">الجدول الرسمي: {(item.official_timetable?.slots ?? []).map(slot => `${slot.iso_weekday} ${slot.starts_at}-${slot.ends_at}`).join('، ') || 'غير مكتمل'}</p>{(item.eligibility_failures ?? []).map((failure, index) => <p key={`${failure.reason}-${index}`} className="text-[11px] text-red-700">{failure.reason}</p>)}</div>)}
          </div>
          {editable ? (
            <div className="mt-4 space-y-3">
              <div className="flex flex-wrap items-end gap-2">
              <label className="text-[12px] font-bold">المقرر الملغى<select value={sourceId} onChange={event => setSourceId(event.target.value)} className="mt-1 block rounded-[9px] border border-primary/20 p-2"><option value="">اختر</option>{sources.map(source => <option key={source.student_course_registration_id} value={source.student_course_registration_id}>{source.course?.course_code} — {source.course?.course_name}</option>)}</select></label>
              <label className="text-[12px] font-bold">المقرر البديل<select value={targetId} onChange={event => setTargetId(event.target.value)} className="mt-1 block rounded-[9px] border border-primary/20 p-2"><option value="">اختر</option>{targets.map(target => <option key={target.course_offering_id} value={target.course_offering_id} disabled={(target.eligibility_failures ?? []).length > 0}>{target.course?.course_code} — {target.course?.course_name}</option>)}</select></label>
              <button type="button" disabled={!sourceId || !targetId || busy !== ''} onClick={() => mutate('/v1/student/registration/replacement/items', { method: 'POST', body: JSON.stringify({ academic_year_id: Number(academicYearId), semester_id: Number(semesterId), source_student_course_registration_id: Number(sourceId), replacement_course_offering_id: Number(targetId) }) }, 'item', 'تمت إضافة البديل.')} className="rounded-[9px] border border-primary/25 px-3 py-2 text-[12px] font-bold text-primary disabled:opacity-40">إضافة البديل</button>
              </div>
              <div className="flex flex-wrap items-end gap-2"><label className="min-w-[280px] flex-1 text-[12px] font-bold">ملاحظات الطالب<textarea value={notes} onChange={event => setNotes(event.target.value)} className="mt-1 min-h-[70px] w-full rounded-[9px] border border-primary/20 p-2" /></label><button type="button" disabled={busy !== ''} onClick={() => mutate('/v1/student/registration/replacement', { method: 'PATCH', body: JSON.stringify({ academic_year_id: Number(academicYearId), semester_id: Number(semesterId), student_notes: notes }) }, 'notes', 'تم حفظ الملاحظات.')} className="rounded-[9px] border border-primary/25 px-3 py-2 text-[12px] font-bold text-primary">حفظ الملاحظات</button></div>
              <button type="button" disabled={(request.items ?? []).length === 0 || busy !== ''} onClick={() => mutate('/v1/student/registration/replacement/submit', { method: 'POST', body: JSON.stringify({ academic_year_id: Number(academicYearId), semester_id: Number(semesterId) }) }, 'submit', 'تم إرسال طلب الاستبدال إلى المرشد.')} className="rounded-[9px] bg-primary px-3 py-2 text-[12px] font-black text-white disabled:opacity-40">إرسال الطلب</button>
            </div>
          ) : null}
        </>
      )}
      {history.length > 0 ? <div className="mt-5 border-t border-primary/10 pt-4"><h3 className="text-[13px] font-black">سجل طلبات الاستبدال</h3>{history.map(entry => <p key={entry.student_registration_replacement_request_id} className="mt-1 text-[11px] text-text-light">طلب #{entry.student_registration_replacement_request_id} — {entry.status} — النسخة {entry.submission_version}</p>)}</div> : null}
    </section>
  )
}

function AvailableCourseSection({ title, helper, count, children, empty }) {
  return (
    <section className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
      <div className="px-5 py-3 bg-primary/[0.05] border-b border-primary/10">
        <div className="flex items-center gap-2">
          <span className="text-[13px] font-extrabold text-text-dark">{title}</span>
          <span className="text-[11px] text-text-light bg-primary/10 px-2 py-0.5 rounded-full font-bold">{count}</span>
        </div>
        {helper ? (
          <p className="mt-1.5 text-[11.5px] leading-6 text-text-light">{helper}</p>
        ) : null}
      </div>
      {empty ? (
        <div className="flex flex-col items-center py-8 gap-2 px-5">
          <FaBookOpen className="text-[28px] text-primary/15" />
          <p className="text-[12.5px] text-text-light text-center">{empty}</p>
        </div>
      ) : (
        <div className="divide-y divide-primary/8">{children}</div>
      )}
    </section>
  )
}

function UnavailableState() {
  return (
    <section className="bg-white border border-primary/12 rounded-[18px] px-6 py-14 text-center shadow-[0_2px_12px_rgba(26,46,16,0.05)]" dir="rtl">
      <FaClock className="mx-auto text-[34px] text-primary/35 mb-4" aria-hidden="true" />
      <h3 className="text-[18px] font-black text-text-dark mb-2">الوقت الآن ليس متاحاً للتسجيل على مواد</h3>
      <p className="text-[13.5px] text-text-light leading-7 max-w-[460px] mx-auto">
        لا توجد مواد مفتوحة للتسجيل حالياً ضمن برنامجك الدراسي.
        ستظهر المواد هنا عند إتاحتها من الكلية.
      </p>
    </section>
  )
}

function statusBadge(course) {
  const reasons = course.eligibility_reasons ?? []
  if (course.eligibility_status === 'eligible') {
    return { label: 'متاح', className: BADGE_CLASS.eligible }
  }
  if (reasons.includes('already_registered')) {
    return { label: REASON_LABELS.already_registered.ar, className: BADGE_CLASS.registered }
  }
  if (reasons.includes('already_in_request')) {
    return { label: REASON_LABELS.already_in_request.ar, className: BADGE_CLASS.request }
  }
  if (reasons.includes('course_already_passed')) {
    return { label: 'تم اجتياز المقرر', className: BADGE_CLASS.registered }
  }
  if (reasons.includes('missing_prerequisites')) {
    return { label: 'متطلب سابق غير محقق', className: BADGE_CLASS.prerequisite }
  }
  if (reasons.includes('credit_limit_exceeded')) {
    return { label: 'تجاوز الساعات', className: BADGE_CLASS.hours }
  }
  if (reasons.includes('elective_requirement_completed')) {
    return { label: 'تم استيفاء الاختياري', className: BADGE_CLASS.hours }
  }
  if (reasons.includes('elective_requirement_fully_committed')) {
    return { label: 'الساعات محجوزة', className: BADGE_CLASS.hours }
  }
  if (reasons.includes('elective_requirement_limit_exceeded')) {
    return { label: 'تجاوز حد الاختياري', className: BADGE_CLASS.hours }
  }
  if (reasons.includes('course_outside_current_curriculum')) {
    return { label: 'خارج الخطة الحالية', className: BADGE_CLASS.prerequisite }
  }
  if (reasons.includes('academic_requirement_configuration_invalid')) {
    return { label: 'تعذر التحقق من الخطة', className: BADGE_CLASS.full }
  }
  return { label: 'غير مؤهل', className: 'bg-gray-100 text-text-light' }
}

function CourseRow({ course, onAdd, adding, canEdit, advisoryMode }) {
  const eligible = course.eligibility_status === 'eligible'
  const reasons = course.eligibility_reasons ?? []
  const missing = course.missing_prerequisites ?? []
  const badge = statusBadge(course)
  const busy = Boolean(adding[course.course_offering_id])
  const planLabel = advisoryPlanLabel(course)

  return (
    <article className={`px-5 py-4 ${eligible ? 'bg-white' : 'bg-primary/[0.015]'}`} dir="rtl">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2 flex-wrap">
            <h4 className="font-bold text-[14px] text-text-dark">{course.course_name}</h4>
            <span className={`text-[10.5px] font-bold px-2 py-0.5 rounded-full ${badge.className}`}>{badge.label}</span>
            {advisoryMode === 'recommended' ? (
              <span className="text-[10.5px] font-bold px-2 py-0.5 rounded-full bg-primary/10 text-primary-dark border border-primary/20">
                موصى بها لهذا الفصل
              </span>
            ) : advisoryMode === 'other' ? (
              <span className="text-[10.5px] font-bold px-2 py-0.5 rounded-full bg-slate-500/10 text-slate-700 border border-slate-500/20">
                {course?.advisory_plan?.recommended_semester_id
                  ? 'من الخطة — موصى بها في فصل آخر'
                  : 'من الخطة — الفصل الإرشادي غير محدد'}
              </span>
            ) : null}
          </div>
          <div className="mt-1.5">
            <CourseRequirementBadges classification={course.requirement_classification} compact />
          </div>
          {advisoryMode === 'other' && planLabel ? (
            <p className="mt-1.5 text-[11px] text-text-light">الخطة الإرشادية: {planLabel}</p>
          ) : null}
          <div className="flex items-center gap-2 mt-1 flex-wrap text-[11.5px] text-text-light">
            <span className="font-mono">{course.course_code}</span>
            <span className="text-primary font-bold">{course.credit_hours} ساعات</span>
          </div>
          <div className="mt-2">
            <OfficialTimetable schedule={course.official_timetable} conflicts={course.timetable_conflicts} incompleteSources={course.incomplete_timetable_sources} compact />
          </div>
          {reasons
            .filter(reason => reason !== 'already_registered' && reason !== 'already_in_request')
            .filter(reason => reason !== 'missing_prerequisites' || missing.length === 0)
            .map(reason => {
              const info = REASON_LABELS[reason]
              return (
                <p key={reason} className="mt-2 text-[12px] font-semibold text-text-dark">
                  {info?.ar ?? reason}
                </p>
              )
            })}
          {missing.length > 0 ? (
            <div className="mt-2 rounded-[10px] border border-amber-200 bg-amber-50 px-3 py-2">
              <p className="text-[12px] font-bold text-amber-900">متطلب سابق غير محقق:</p>
              <ul className="mt-1 space-y-0.5">
                {missing.map(item => (
                  <li key={item.course_id} className="text-[12.5px] text-amber-900">
                    {[item.course_code, item.course_name].filter(Boolean).join(' — ')}
                  </li>
                ))}
              </ul>
            </div>
          ) : null}
        </div>
        {canEdit ? (
          <button
            type="button"
            onClick={() => onAdd(course)}
            disabled={!eligible || busy}
            className="flex items-center gap-1.5 px-3 py-1.5 bg-primary text-white rounded-[10px] text-[12px] font-bold hover:enabled:bg-primary-dark disabled:opacity-35 disabled:cursor-not-allowed transition-colors shrink-0"
          >
            {busy ? <FaSpinner className="animate-spin text-[10px]" /> : <FaPlus className="text-[10px]" />}
            إضافة إلى الطلب
          </button>
        ) : null}
      </div>
    </article>
  )
}

export default function StudentRegistration() {
  const navigate = useNavigate()
  const requestSeq = useRef(0)
  const notesTimer = useRef(null)

  const [payload, setPayload] = useState(null)
  const [semesterId, setSemesterId] = useState('')
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState('')
  const [toast, setToast] = useState('')
  const [adding, setAdding] = useState({})
  const [removing, setRemoving] = useState({})
  const [submitting, setSubmitting] = useState(false)
  const [savingNotes, setSavingNotes] = useState(false)
  const [studentNotes, setStudentNotes] = useState('')
  const [confirm, setConfirm] = useState(null)
  const hasLoadedRef = useRef(false)

  const academicYear = payload?.academic_year ?? null
  const semesters = payload?.semesters ?? []
  const selectedSemesterId = semesterId || String(payload?.semester?.semester_id || '')
  const registrationOpen = payload?.registration_open === true
  const requestItemRemovalOpen = payload?.request_item_removal_open === true
  const registrationCalendar = payload?.registration_calendar ?? null
  const modificationWorkflow = payload?.modification_workflow ?? null
  const replacementWorkflow = payload?.replacement_workflow ?? null
  const termReady = Boolean(academicYear?.academic_year_id && selectedSemesterId)
  const available = payload?.available_courses ?? []
  const workspaceSemesterId = payload?.semester?.semester_id ?? selectedSemesterId
  const studentAcademicLevelId = payload?.summary?.student?.current_academic_level_id ?? null
  const { recommended, other } = useMemo(
    () => splitAdvisoryCourses(available, workspaceSemesterId, studentAcademicLevelId),
    [available, workspaceSemesterId, studentAcademicLevelId],
  )
  const summary = payload?.summary ?? null
  const registrations = summary?.registrations ?? []
  const request = payload?.request ?? null
  const requestItems = request?.items ?? []
  const hours = payload?.hours ?? request?.hours ?? null
  const status = request?.status ?? 'draft'
  const canEdit = registrationOpen && termReady && (!request || status === 'draft' || status === 'returned')
  const canRemoveItem = requestItemRemovalOpen
    && termReady
    && Boolean(request)
    && (status === 'draft' || status === 'returned')
  const readOnly = status === 'submitted' || status === 'approved' || status === 'expired'
  const busy = loading || refreshing
  const statusMeta = STATUS_LABELS[status] ?? STATUS_LABELS.draft

  useEffect(() => {
    let active = true
    Promise.all([
      apiRequest('/v1/academic-years/current').catch(() => null),
      apiRequest('/v1/semesters/active').catch(() => null),
    ]).then(([yearResponse, semesterResponse]) => {
      if (!active) return
      void yearResponse
      void semesterResponse
    })
    return () => { active = false }
  }, [])

  useEffect(() => {
    let active = true
    const seq = ++requestSeq.current

    async function load() {
      if (hasLoadedRef.current) setRefreshing(true)
      else setLoading(true)
      setError('')

      try {
        const response = await apiRequest(studentRegistrationPath(semesterId))
        if (!active || seq !== requestSeq.current) return
        setPayload(response?.data ?? null)
        setStudentNotes(response?.data?.request?.student_notes ?? '')
        hasLoadedRef.current = true
      } catch (requestError) {
        if (!active || seq !== requestSeq.current) return
        if (requestError.status === 401) {
          navigate('/login', { replace: true })
          return
        }
        setError(
          requestError.status === 403
            ? 'ليس لديك صلاحية لتسجيل المواد.'
            : registrationErrorMessage(
              requestError,
              'تعذّر تحميل بيانات التسجيل. يرجى المحاولة مرة أخرى.',
            ),
        )
      } finally {
        if (active && seq === requestSeq.current) {
          setLoading(false)
          setRefreshing(false)
        }
      }
    }

    load()
    return () => { active = false }
  }, [semesterId, navigate])

  function showToast(message) {
    setToast(message)
    window.setTimeout(() => setToast(''), 3200)
  }

  async function reload() {
    const response = await apiRequest(studentRegistrationPath(selectedSemesterId))
    setPayload(response?.data ?? null)
    setStudentNotes(response?.data?.request?.student_notes ?? studentNotes)
  }

  async function addCourse(course) {
    setAdding(current => ({ ...current, [course.course_offering_id]: true }))
    setError('')
    try {
      await apiRequest(`/v1/student/registration/request/items/${course.course_offering_id}`, {
        method: 'POST',
        body: JSON.stringify({}),
      })
      showToast(`تمت إضافة "${course.course_name}" إلى الطلب`)
      await reload()
    } catch (requestError) {
      if (requestError.status === 401) {
        navigate('/login', { replace: true })
        return
      }
      setError(registrationErrorMessage(requestError, 'تعذّر إضافة المادة إلى الطلب'))
    } finally {
      setAdding(current => ({ ...current, [course.course_offering_id]: false }))
    }
  }

  async function removeItem(item) {
    setRemoving(current => ({ ...current, [item.student_registration_request_item_id]: true }))
    setError('')
    try {
      await apiRequest(`/v1/student/registration/request/items/${item.student_registration_request_item_id}`, {
        method: 'DELETE',
      })
      showToast(`تمت إزالة "${item.course_name}" من الطلب`)
      await reload()
    } catch (requestError) {
      if (requestError.status === 401) {
        navigate('/login', { replace: true })
        return
      }
      setError(requestError.message || 'تعذّر إزالة المادة من الطلب')
    } finally {
      setRemoving(current => ({ ...current, [item.student_registration_request_item_id]: false }))
    }
  }

  function scheduleNotesSave(value) {
    setStudentNotes(value)
    if (!canEdit) return
    window.clearTimeout(notesTimer.current)
    notesTimer.current = window.setTimeout(async () => {
      setSavingNotes(true)
      try {
        await apiRequest('/v1/student/registration/request', {
          method: 'PATCH',
          body: JSON.stringify({
            student_notes: value,
            ...(selectedSemesterId ? { semester_id: Number(selectedSemesterId) } : {}),
          }),
        })
      } catch (requestError) {
        setError(requestError.message || 'تعذّر حفظ الملاحظات')
      } finally {
        setSavingNotes(false)
      }
    }, 500)
  }

  async function confirmSubmit() {
    setSubmitting(true)
    setError('')
    try {
      await apiRequest('/v1/student/registration/request', {
        method: 'PATCH',
        body: JSON.stringify({
          student_notes: studentNotes,
          ...(selectedSemesterId ? { semester_id: Number(selectedSemesterId) } : {}),
        }),
      })
      await apiRequest('/v1/student/registration/request/submit', {
        method: 'POST',
        body: JSON.stringify(selectedSemesterId ? { semester_id: Number(selectedSemesterId) } : {}),
      })
      showToast(status === 'returned' ? 'تم إعادة إرسال الطلب' : 'تم إرسال الطلب إلى المرشد الأكاديمي')
      setConfirm(null)
      await reload()
    } catch (requestError) {
      if (requestError.status === 401) {
        navigate('/login', { replace: true })
        return
      }
      setError(registrationErrorMessage(requestError, 'تعذّر إرسال الطلب'))
    } finally {
      setSubmitting(false)
    }
  }

  const submitLabel = status === 'returned' ? 'إعادة إرسال الطلب' : 'إرسال الطلب للمرشد الأكاديمي'

  if (loading) {
    return (
      <div className="flex justify-center py-20 text-primary">
        <FaSpinner className="animate-spin text-[32px]" aria-hidden="true" />
      </div>
    )
  }

  return (
    <div className="space-y-5" dir="rtl">
      <header className="bg-[linear-gradient(135deg,rgba(86,153,51,0.12),rgba(255,255,255,0.95))] border border-primary/12 rounded-[20px] px-6 py-6 shadow-[0_2px_16px_rgba(26,46,16,0.06)]">
        <p className="text-[12px] font-bold text-primary mb-1">بوابة الطالب</p>
        <h1 className="text-[22px] font-black text-text-dark">تسجيل المواد</h1>
        <p className="mt-2 text-[13.5px] text-text-light leading-7">
          ابنِ طلب التسجيل ثم أرسله إلى المرشد الأكاديمي. الخطة الدراسية إرشادية فقط، ويمكنك طلب أي مقرر مفتوح ومستوفٍ للمتطلبات.
          يصبح التسجيل رسميًا بعد اعتماد المرشد الأكاديمي.
        </p>
        <div className="mt-4 flex flex-wrap items-end gap-3">
          <div className="min-w-[200px]">
            <p className="text-[11.5px] font-semibold text-text-light mb-1">السنة الدراسية</p>
            <p className="text-[14px] font-bold text-text-dark">{academicYear?.year_name || '—'}</p>
          </div>
          {semesters.length > 1 ? (
            <label className="flex flex-col gap-1 text-[12px] font-semibold text-text-light">
              الفصل الدراسي
              <select
                className="min-w-[200px] py-2.5 px-3 border-[1.5px] border-primary/20 rounded-[12px] bg-white text-[13.5px] text-text-dark"
                value={selectedSemesterId}
                onChange={event => setSemesterId(event.target.value)}
                disabled={busy}
              >
                <option value="">اختر الفصل الدراسي</option>
                {semesters.map(semester => (
                  <option key={semester.semester_id} value={semester.semester_id}>
                    {semester.semester_name}
                  </option>
                ))}
              </select>
            </label>
          ) : (
            <div className="min-w-[160px]">
              <p className="text-[11.5px] font-semibold text-text-light mb-1">الفصل الدراسي</p>
              <p className="text-[14px] font-bold text-text-dark">
                {payload?.semester?.semester_name || (semesters[0]?.semester_name ?? '—')}
              </p>
            </div>
          )}
          <span className={`text-[12px] font-bold px-3 py-1.5 rounded-full border ${statusMeta.className}`}>
            {request ? statusMeta.ar : 'مسودة'}
          </span>
          {refreshing ? <span className="text-[12px] text-text-light pb-1">جاري التحديث…</span> : null}
        </div>
      </header>

      <ApprovedRegistrationModificationPanel
        workflow={modificationWorkflow}
        semesterId={selectedSemesterId}
        reload={reload}
        onError={setError}
        showToast={showToast}
      />
      <CancelledCourseReplacementPanel
        workflow={replacementWorkflow}
        academicYearId={academicYear?.academic_year_id}
        semesterId={selectedSemesterId}
        reload={reload}
        onError={setError}
        showToast={showToast}
      />

      {toast ? (
        <div className="px-4 py-2.5 text-[12.5px] text-green-700 bg-green-50 border border-green-200 rounded-[10px] flex items-center gap-2">
          <FaCheckCircle className="text-green-500 shrink-0" /> {toast}
        </div>
      ) : null}
      {error ? (
        <p className="px-4 py-2.5 text-[12.5px] text-red-600 bg-red-50 border border-red-200 rounded-[10px]">⚠ {error}</p>
      ) : null}

      {registrationCalendar ? (
        <section className="rounded-[14px] border border-primary/15 bg-white px-5 py-4 text-[13px] text-text-dark">
          <p className="font-black">{studentRegistrationNotice(registrationCalendar, request?.status)}</p>
          {registrationCalendar.student_registration_ends_at ? <p className="mt-1 text-text-light">نهاية تسجيل الطلاب: {formatUniversityDateTime(registrationCalendar.student_registration_ends_at)}</p> : null}
          {registrationCalendar.advisor_approval_ends_at ? <p className="mt-1 text-text-light">نهاية اعتماد المرشد: {formatUniversityDateTime(registrationCalendar.advisor_approval_ends_at)}</p> : null}
        </section>
      ) : null}

      {hours ? <HoursPanel hours={hours} requestStatus={request ? status : null} /> : null}

      <p className="text-[12.5px] text-text-gray bg-primary/5 border border-primary/12 rounded-[12px] px-4 py-3">
        الطلب المرسل يبقى قيد المراجعة، ويصبح التسجيل رسميًا بعد اعتماد المرشد الأكاديمي.
      </p>

      {status === 'submitted' ? (
        <section className="border border-amber-200 bg-amber-50 rounded-[16px] px-5 py-4 text-[13px] font-semibold text-amber-900">
          تم إرسال طلبك وهو بانتظار مراجعة المرشد الأكاديمي.
        </section>
      ) : null}

      {status === 'returned' ? (
        <section className="border border-orange-200 bg-orange-50 rounded-[16px] px-5 py-4">
          <h2 className="text-[14px] font-black text-orange-900 mb-2">ملاحظات المرشد الأكاديمي</h2>
          <p className="text-[13.5px] leading-7 text-orange-950 whitespace-pre-wrap">{request?.advisor_notes || '—'}</p>
        </section>
      ) : null}

      {status === 'approved' ? (
        <section className="border border-green-200 bg-green-50 rounded-[16px] px-5 py-4 text-[13px] font-semibold text-green-900 space-y-1">
          <p>تم اعتماد طلب التسجيل.</p>
          {request?.approved_at ? <p>تاريخ الاعتماد: {String(request.approved_at).slice(0, 16)}</p> : null}
          {request?.advisor?.full_name ? <p>المرشد الأكاديمي: {request.advisor.full_name}</p> : null}
          {hours?.approved_snapshot ? (
            <>
              <p>ساعات الطلب المعتمدة: {hours.approved_snapshot.request_hours_at_approval}</p>
              <p>الساعات قبل الاعتماد: {hours.approved_snapshot.registered_hours_before_approval}</p>
              <p>إجمالي الساعات بعد الاعتماد: {hours.approved_snapshot.projected_hours_at_approval}</p>
              <p>الحد الأقصى وقت الاعتماد: {hours.approved_snapshot.max_allowed_hours_at_approval}</p>
            </>
          ) : null}
        </section>
      ) : null}

      {status === 'expired' ? (
        <section className="border border-red-200 bg-red-50 rounded-[16px] px-5 py-4 text-[13px] font-semibold text-red-900">
          انتهت مهلة اعتماد المرشد الأكاديمي دون اعتماد الطلب. بقي الطلب وسجله متاحين للعرض ولم يُنشأ تسجيل رسمي بسببه.
        </section>
      ) : null}

      {!registrationOpen && request ? (
        <section className="border border-primary/20 bg-primary/5 rounded-[16px] px-5 py-4 text-[13px] font-semibold text-text-dark">
          انتهت فترة إضافة المقررات، ويمكنك الاطلاع على حالة طلبك.
        </section>
      ) : null}

      {!registrationOpen && !request ? (
        <UnavailableState />
      ) : !termReady ? (
        <section className="border border-amber-200 bg-amber-50 rounded-[16px] px-5 py-4 text-[13px] font-semibold text-amber-900" dir="rtl">
          اختر الفصل الدراسي لعرض المواد المتاحة للتسجيل.
        </section>
      ) : (
        <div className="grid grid-cols-2 max-[1100px]:grid-cols-1 gap-5">
          <div className="space-y-5 min-w-0">
            {available.length === 0 ? (
              <section className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
                <div className="flex items-center gap-2 px-5 py-3 bg-primary/[0.05] border-b border-primary/10">
                  <span className="text-[13px] font-extrabold text-text-dark">المواد المتاحة</span>
                </div>
                <div className="flex flex-col items-center py-12 gap-2 px-5">
                  <FaBookOpen className="text-[32px] text-primary/15" />
                  <p className="text-[13px] font-bold text-text-dark">لا توجد مواد مفتوحة حالياً</p>
                </div>
              </section>
            ) : (
              <>
                <AvailableCourseSection
                  title="مواد موصى بها لهذا الفصل"
                  helper="المقررات المقترحة لك وفق الخطة الإرشادية. يمكنك أيضًا تسجيل مقررات أخرى مفتوحة من خطتك إذا استوفيت متطلباتها."
                  count={recommended.length}
                  empty={recommended.length === 0 ? 'لا توجد مقررات موصى بها متاحة حاليًا.' : null}
                >
                  {recommended.map(course => (
                    <CourseRow
                      key={course.course_offering_id}
                      course={course}
                      onAdd={addCourse}
                      adding={adding}
                      canEdit={canEdit}
                      advisoryMode="recommended"
                    />
                  ))}
                </AvailableCourseSection>
                {other.length > 0 ? (
                  <AvailableCourseSection
                    title="مواد أخرى مفتوحة من خطتك"
                    helper="هذه المواد ضمن خطتك ومفتوحة للتسجيل في الفصل الحالي، لكنها موصى بها إرشاديًا لفصل أو مستوى آخر. يمكنك تسجيلها إذا استوفيت بقية الشروط."
                    count={other.length}
                  >
                    {other.map(course => (
                      <CourseRow
                        key={course.course_offering_id}
                        course={course}
                        onAdd={addCourse}
                        adding={adding}
                        canEdit={canEdit}
                        advisoryMode="other"
                      />
                    ))}
                  </AvailableCourseSection>
                ) : null}
              </>
            )}
          </div>

          <section className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
            <div className="flex items-center gap-2 px-5 py-3 bg-primary/[0.05] border-b border-primary/10">
              <span className="text-[13px] font-extrabold text-text-dark">طلب التسجيل</span>
              <span className="text-[11px] text-text-light bg-primary/10 px-2 py-0.5 rounded-full font-bold">{requestItems.length}</span>
            </div>
            {requestItems.length === 0 ? (
              <div className="flex flex-col items-center py-12 gap-2 px-5">
                <FaBookOpen className="text-[32px] text-primary/15" />
                <p className="text-[12.5px] text-text-light">لم تُضف أي مادة إلى الطلب بعد</p>
              </div>
            ) : (
              <div className="divide-y divide-primary/8">
                {requestItems.map(item => {
                  const removeBusy = Boolean(removing[item.student_registration_request_item_id])
                  return (
                    <div key={item.student_registration_request_item_id} className="flex items-center justify-between gap-3 px-5 py-4">
                      <div className="min-w-0">
                        <p className="font-bold text-[13.5px] text-text-dark truncate">{item.course_name}</p>
                        <div className="mt-1.5">
                          <CourseRequirementBadges classification={item.requirement_classification} compact />
                        </div>
                        <div className="flex items-center gap-2 mt-1 flex-wrap text-[11.5px] text-text-light">
                          <span className="font-mono">{item.course_code}</span>
                          <span className="text-primary font-bold">{item.credit_hours} ساعات</span>
                          <span className="px-1.5 py-0.5 bg-primary/10 text-primary-dark text-[10px] font-bold rounded-full">
                            في الطلب
                          </span>
                        </div>
                      </div>
                      <div className="min-w-[220px] flex-1">
                        <OfficialTimetable schedule={item.official_timetable} conflicts={item.timetable_conflicts} incompleteSources={item.incomplete_timetable_sources} compact />
                      </div>
                      {canRemoveItem ? (
                        <button
                          type="button"
                          onClick={() => removeItem(item)}
                          disabled={removeBusy}
                          className="flex items-center gap-1.5 px-3 py-1.5 border border-red-300 text-red-600 rounded-[10px] text-[12px] font-bold hover:bg-red-50 disabled:opacity-40 shrink-0"
                        >
                          {removeBusy ? <FaSpinner className="animate-spin text-[10px]" /> : <FaMinus className="text-[10px]" />}
                          إزالة
                        </button>
                      ) : null}
                    </div>
                  )
                })}
              </div>
            )}

            <div className="px-5 py-4 border-t border-primary/10 space-y-3">
              <label className="block text-[12.5px] font-bold text-text-dark">
                ملاحظات للمرشد الأكاديمي
                <textarea
                  className="mt-2 w-full min-h-[96px] rounded-[12px] border-[1.5px] border-primary/20 px-3 py-2 text-[13px] text-text-dark disabled:bg-gray-50"
                  maxLength={1000}
                  placeholder="يمكنك إضافة أي ملاحظة ترغب بإيصالها إلى المرشد الأكاديمي..."
                  value={studentNotes}
                  onChange={event => scheduleNotesSave(event.target.value)}
                  disabled={!canEdit}
                  readOnly={readOnly}
                />
              </label>
              {savingNotes ? <p className="text-[11.5px] text-text-light">جاري حفظ الملاحظات…</p> : null}
              {canEdit ? (
                <button
                  type="button"
                  onClick={() => setConfirm({ type: 'submit' })}
                  disabled={requestItems.length === 0 || submitting}
                  className="w-full py-2.5 rounded-[12px] bg-primary text-white text-[13.5px] font-black hover:enabled:bg-primary-dark disabled:opacity-40"
                >
                  {submitLabel}
                </button>
              ) : null}
            </div>
          </section>
        </div>
      )}

      {registrations.length > 0 ? (
        <section className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
          <div className="flex items-center gap-2 px-5 py-3 bg-primary/[0.05] border-b border-primary/10">
            <span className="text-[13px] font-extrabold text-text-dark">مسجل ومعتمد سابقاً</span>
            <span className="text-[11px] text-text-light bg-primary/10 px-2 py-0.5 rounded-full font-bold">{registrations.length}</span>
          </div>
          <div className="divide-y divide-primary/8">
            {registrations.map(registration => (
              <div key={registration.registration_id} className="px-5 py-4">
                <p className="font-bold text-[13.5px] text-text-dark">{registration.course_name}</p>
                <div className="mt-1.5">
                  <CourseRequirementBadges classification={registration.requirement_classification} compact />
                </div>
                <div className="flex items-center gap-2 mt-1 text-[11.5px] text-text-light">
                  <span className="font-mono">{registration.course_code}</span>
                  <span className="text-primary font-bold">{registration.credit_hours} ساعات</span>
                  <span className="px-1.5 py-0.5 bg-blue-100 text-blue-700 text-[10px] font-bold rounded-full">
                    مسجل ومعتمد سابقاً
                  </span>
                </div>
                <div className="mt-2">
                  <OfficialTimetable schedule={registration.official_timetable} compact />
                </div>
              </div>
            ))}
          </div>
        </section>
      ) : null}

      {confirm?.type === 'submit' ? (
        <StudentConfirmDialog
          title={submitLabel}
          confirmLabel="تأكيد الإرسال"
          busy={submitting}
          onConfirm={confirmSubmit}
          onCancel={() => setConfirm(null)}
        >
          <p className="text-[13px] text-text-dark">عدد المواد: {requestItems.length}</p>
          <p className="text-[13px] text-text-dark">ساعات الطلب: {hours?.request_hours ?? 0}</p>
          <p className="text-[13px] text-text-dark">الساعات المسجلة مسبقاً: {hours?.registered_hours ?? 0}</p>
          <p className="text-[13px] text-text-dark">الإجمالي المتوقع بعد الاعتماد: {hours?.projected_hours ?? 0}</p>
          <p className="text-[13px] font-semibold text-text-dark">الحد الأقصى: {hours?.max_allowed_hours ?? 0}</p>
        </StudentConfirmDialog>
      ) : null}
    </div>
  )
}
