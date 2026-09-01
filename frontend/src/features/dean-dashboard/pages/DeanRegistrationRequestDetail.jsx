import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { FaSpinner } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import DeanConfirmDialog from '../components/DeanConfirmDialog'
import CourseRequirementBadges from '../../../components/academic/CourseRequirementBadges'
import { advisorActionsVisible, formatUniversityDateTime, registrationPhaseLabel } from '../../registration-requests/registrationDeadlinePresentation'
import OfficialTimetable from '../../registration-requests/OfficialTimetable'

const STATUS_LABELS = {
  draft: 'مسودة',
  submitted: 'بانتظار مراجعة المرشد الأكاديمي',
  returned: 'أعيد للتعديل',
  approved: 'معتمد',
  expired: 'انتهت المهلة دون اعتماد',
}

const REASON_LABELS = {
  timetable_schema_not_ready: 'مخطط الجدول الرسمي غير جاهز',
  offering_schedule_incomplete: 'الجدول الأسبوعي غير مكتمل',
  timetable_conflict: 'تعارض في الجدول',
  timetable_reference_incomplete: 'تعذر التحقق من التعارض لأن جدول أحد المقررات غير مكتمل',
  already_registered: 'مسجل مسبقاً',
  course_already_passed: 'تم اجتياز هذا المقرر سابقاً ولا يمكن تسجيله مجدداً ضمن التسجيل العادي.',
  missing_prerequisites: 'متطلب سابق غير محقق',
  credit_limit_exceeded: 'تجاوز الساعات',
  offering_closed: 'المادة مغلقة',
  wrong_program: 'ليست ضمن برنامج الطالب',
  not_current_term: 'ليست ضمن الفصل الحالي',
  not_on_curriculum: 'ليست ضمن الخطة الدراسية',
}

function formatDateTime(value) {
  if (!value) return '—'
  return String(value).replace('T', ' ').slice(0, 16)
}

export default function DeanRegistrationRequestDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [request, setRequest] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [toast, setToast] = useState('')
  const [returnNotes, setReturnNotes] = useState('')
  const [dialog, setDialog] = useState(null)
  const [busy, setBusy] = useState(false)

  async function load() {
    setLoading(true)
    setError('')
    try {
      const response = await apiRequest(`/v1/academic-advising/registration-requests/${id}`)
      setRequest(response?.data ?? null)
    } catch (requestError) {
      if (requestError.status === 401) {
        navigate('/login', { replace: true })
        return
      }
      setError(requestError.status === 403
        ? 'ليس لديك صلاحية لعرض هذا الطلب.'
        : (requestError.message || 'تعذّر تحميل الطلب.'))
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  const hours = request?.hours
  const snapshot = hours?.approved_snapshot
  const approvedHours = request?.status === 'approved' && snapshot
  const canReview = advisorActionsVisible(request)

  async function confirmReturn() {
    setBusy(true)
    setError('')
    try {
      const response = await apiRequest(`/v1/academic-advising/registration-requests/${id}/return`, {
        method: 'POST',
        body: JSON.stringify({ advisor_notes: returnNotes }),
      })
      setRequest(response?.data ?? null)
      setDialog(null)
      setReturnNotes('')
      setToast('أُعيد الطلب إلى الطالب للتعديل')
    } catch (requestError) {
      setError(requestError.message || 'تعذّر إعادة الطلب')
    } finally {
      setBusy(false)
    }
  }

  async function confirmApprove() {
    setBusy(true)
    setError('')
    try {
      const response = await apiRequest(`/v1/academic-advising/registration-requests/${id}/approve`, {
        method: 'POST',
        body: JSON.stringify({}),
      })
      setRequest(response?.data ?? null)
      setDialog(null)
      setToast('تم اعتماد طلب التسجيل')
    } catch (requestError) {
      const failures = requestError.itemFailures || []
      const extra = failures.length
        ? failures.map(item => `${item.course_offering_id}: ${REASON_LABELS[item.reason] || item.reason}`).join(' — ')
        : ''
      setError([requestError.message || 'تعذّر اعتماد الطلب', extra].filter(Boolean).join(' '))
    } finally {
      setBusy(false)
    }
  }

  if (loading) {
    return (
      <div className="flex justify-center py-20 text-primary">
        <FaSpinner className="animate-spin text-[32px]" />
      </div>
    )
  }

  if (!request) {
    return <p className="text-[13px] text-red-600">{error || 'الطلب غير موجود.'}</p>
  }

  return (
    <div className="space-y-5" dir="rtl">
      <button type="button" className="text-[13px] font-bold text-primary" onClick={() => navigate('/dean/registration-requests')}>
        ← العودة إلى القائمة
      </button>

      <header className="bg-white border border-primary/12 rounded-[18px] px-6 py-5">
        <h1 className="text-[22px] font-black text-text-dark">مراجعة طلب التسجيل</h1>
        <p className="mt-2 text-[13.5px] text-text-light">
          {request.student?.full_name} — {request.student?.student_number}
        </p>
        <div className="mt-3 flex flex-wrap gap-3 text-[13px] text-text-dark">
          <span>البرنامج: {request.student?.program?.program_name || '—'}</span>
          <span>المستوى: {request.student?.academic_level?.level_name || '—'}</span>
          <span>{request.academic_year?.year_name} / {request.semester?.semester_name}</span>
          <span>الإصدار: {request.submission_version}</span>
          <span className="font-bold">{STATUS_LABELS[request.status] || request.status}</span>
        </div>
      </header>

      {toast ? <p className="px-4 py-2.5 text-[12.5px] text-green-700 bg-green-50 border border-green-200 rounded-[10px]">{toast}</p> : null}
      {error ? <p className="px-4 py-2.5 text-[12.5px] text-red-600 bg-red-50 border border-red-200 rounded-[10px]">⚠ {error}</p> : null}

      <section className="rounded-[16px] border border-primary/12 bg-white p-5 text-[13px] text-text-dark">
        <h2 className="font-black">{registrationPhaseLabel(request.registration_calendar)}</h2>
        <div className="mt-2 flex flex-wrap gap-4 text-text-light">
          <span>نهاية تسجيل الطلاب: {formatUniversityDateTime(request.registration_calendar?.student_registration_ends_at)}</span>
          <span>نهاية اعتماد المرشد: {formatUniversityDateTime(request.registration_calendar?.advisor_approval_ends_at)}</span>
          <span>تاريخ الإرسال: {formatDateTime(request.last_submitted_at)}</span>
        </div>
        {request.status === 'expired' ? <p className="mt-3 font-bold text-red-700">انتهت المهلة دون اعتماد، ولا تتوفر إجراءات مراجعة لهذا الطلب.</p> : null}
      </section>

      {hours ? (
        <section className="bg-white border border-primary/12 rounded-[16px] p-5">
          <div className="grid grid-cols-6 max-[1050px]:grid-cols-3 max-[700px]:grid-cols-2 gap-3">
          <div>
            <p className="text-[11px] text-text-light">المعدل التراكمي الرسمي الحالي</p>
            <p className="text-[20px] font-black">
              {hours.official_cgpa == null ? 'لا يوجد معدل رسمي' : Number(hours.official_cgpa).toFixed(2)}
            </p>
          </div>
          <div>
            <p className="text-[11px] text-text-light">{approvedHours ? 'الساعات قبل الاعتماد' : 'الساعات المسجلة حالياً'}</p>
            <p className="text-[20px] font-black">{approvedHours ? snapshot.registered_hours_before_approval : hours.registered_hours}</p>
          </div>
          <div>
            <p className="text-[11px] text-text-light">{approvedHours ? 'ساعات الطلب المعتمدة' : 'ساعات الطلب'}</p>
            <p className="text-[20px] font-black text-primary">{approvedHours ? snapshot.request_hours_at_approval : hours.request_hours}</p>
          </div>
          <div>
            <p className="text-[11px] text-text-light">{approvedHours ? 'إجمالي الساعات بعد الاعتماد' : 'الإجمالي بعد الاعتماد'}</p>
            <p className="text-[20px] font-black">{approvedHours ? snapshot.projected_hours_at_approval : hours.projected_hours}</p>
          </div>
          <div>
            <p className="text-[11px] text-text-light">{approvedHours ? 'الحد الأقصى وقت الاعتماد' : 'الحد الأقصى'}</p>
            <p className="text-[20px] font-black">{approvedHours ? snapshot.max_allowed_hours_at_approval : hours.max_allowed_hours}</p>
          </div>
          <div>
            <p className="text-[11px] text-text-light">المتبقي بعد الاعتماد</p>
            <p className="text-[20px] font-black">{approvedHours ? snapshot.remaining_hours_after_approval : hours.remaining_after_approval}</p>
          </div>
          </div>
          {hours.below_recommended_minimum === true ? (
            <p className="mt-4 rounded-[10px] border border-amber-200 bg-amber-50 px-3 py-2 text-[12.5px] font-semibold text-amber-900">
              ساعات الطلب أقل من العبء الدراسي المعتاد ({hours.recommended_minimum_hours ?? 12} ساعة). هذا تنبيه إرشادي ولا يمنع الاعتماد.
            </p>
          ) : null}
        </section>
      ) : null}

      <section className="bg-white border border-primary/12 rounded-[16px] p-5">
        <h2 className="text-[14px] font-black mb-2">ملاحظات الطالب</h2>
        <p className="text-[13.5px] leading-7 whitespace-pre-wrap">{request.student_notes || 'لا توجد ملاحظات.'}</p>
      </section>

      <section className="bg-white border border-primary/12 rounded-[16px] overflow-hidden">
        <div className="px-5 py-3 border-b border-primary/10 font-black text-[13px]">المقررات</div>
        <div className="divide-y divide-primary/8">
          {(request.items ?? []).map(item => (
            <div key={item.student_registration_request_item_id} className="px-5 py-4 flex items-start justify-between gap-3">
              <div>
                <p className="font-bold text-[14px]">{item.course_name}</p>
                <div className="mt-1.5">
                  <CourseRequirementBadges classification={item.requirement_classification} compact />
                </div>
                <p className="text-[12px] text-text-light mt-1">
                  {item.course_code} — {item.credit_hours} ساعات
                </p>
                <div className="mt-2">
                  <OfficialTimetable schedule={item.official_timetable} conflicts={item.timetable_conflicts} incompleteSources={item.incomplete_timetable_sources} compact />
                </div>
                {(item.missing_prerequisites ?? []).length > 0 ? (
                  <ul className="mt-2 space-y-1 text-[12px] text-amber-900">
                    {(item.missing_prerequisites ?? []).map(prerequisite => (
                      <li key={prerequisite.course_id}>
                        {[prerequisite.course_code, prerequisite.course_name].filter(Boolean).join(' — ')}
                      </li>
                    ))}
                  </ul>
                ) : null}
              </div>
              <span className={`text-[11px] font-bold px-2 py-1 rounded-full ${
                item.eligibility_status === 'eligible' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-900'
              }`}
              >
                {item.eligibility_status === 'eligible'
                  ? 'مؤهل حالياً'
                  : (REASON_LABELS[item.eligibility_reason] || item.eligibility_reason || 'غير مؤهل')}
              </span>
            </div>
          ))}
        </div>
      </section>

      {canReview ? (
        <div className="flex flex-wrap gap-3">
          <button
            type="button"
            className="px-4 py-2.5 rounded-[12px] border border-orange-300 text-orange-800 font-bold"
            onClick={() => setDialog('return')}
          >
            إعادة للتعديل
          </button>
          <button
            type="button"
            className="px-4 py-2.5 rounded-[12px] bg-primary text-white font-bold"
            onClick={() => setDialog('approve')}
          >
            اعتماد الطلب
          </button>
        </div>
      ) : null}

      {dialog === 'return' ? (
        <DeanConfirmDialog
          title="إعادة للتعديل"
          confirmLabel="إعادة الطلب"
          confirmTone="danger"
          busy={busy}
          disabled={returnNotes.trim().length < 8}
          onConfirm={confirmReturn}
          onCancel={() => setDialog(null)}
        >
          <label className="block text-[13px] font-bold">
            سبب الإعادة / ملاحظات المرشد
            <textarea
              className="mt-2 w-full min-h-[120px] rounded-[12px] border px-3 py-2 text-[13px]"
              value={returnNotes}
              onChange={event => setReturnNotes(event.target.value)}
              placeholder="يرجى حذف مقرر X وإضافة مقرر Y بسبب المتطلب السابق."
            />
          </label>
        </DeanConfirmDialog>
      ) : null}

      {dialog === 'approve' ? (
        <DeanConfirmDialog
          title="اعتماد الطلب"
          confirmLabel="تأكيد الاعتماد"
          busy={busy}
          onConfirm={confirmApprove}
          onCancel={() => setDialog(null)}
        >
          <p className="text-[13px]">الطالب: {request.student?.full_name} ({request.student?.student_number})</p>
          <p className="text-[13px]">عدد المقررات: {(request.items ?? []).length}</p>
          <p className="text-[13px]">ساعات الطلب: {hours?.request_hours}</p>
          <p className="text-[13px]">الإجمالي المتوقع: {hours?.projected_hours}</p>
          <p className="text-[13px] font-semibold">الحد الأقصى: {hours?.max_allowed_hours}</p>
        </DeanConfirmDialog>
      ) : null}
    </div>
  )
}
