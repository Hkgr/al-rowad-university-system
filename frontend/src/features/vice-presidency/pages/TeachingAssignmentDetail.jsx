import { useCallback, useEffect, useState } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { FaArrowRight, FaInfoCircle, FaRedo, FaSpinner } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import {
  APPROVAL_EFFECT_TEXT,
  BLOCKED_REASON_TEXT,
  actionLabel,
  decisionErrorText,
  eventLabel,
  facultyName,
  formatDateTime,
  offeringTitle,
  requestStatusLabel,
  reviewStatusLabel,
  roleLabel,
  shouldReloadAfterError,
} from '../utils/teachingAssignmentLabels'

function payload(response) {
  return response?.data ?? null
}

function Card({ title, children, tone = 'default' }) {
  const border = tone === 'warning' ? 'border-amber-200 bg-amber-50/60' : tone === 'danger' ? 'border-red-200 bg-red-50/50' : 'border-primary/12 bg-white'
  return (
    <section className={`border rounded-[14px] px-4 py-3.5 ${border}`}>
      <p className="text-[11.5px] text-text-light font-semibold mb-1.5">{title}</p>
      {children}
    </section>
  )
}

function TeacherCard({ title, faculty, note }) {
  return (
    <Card title={title}>
      <p className="text-[15px] font-extrabold text-text-dark">{facultyName(faculty)}</p>
      {faculty && (
        <dl className="mt-1.5 grid gap-0.5 text-[12px]">
          <div className="flex gap-2"><dt className="text-text-light">الرتبة:</dt><dd className="text-text-gray">{faculty.academic_rank || '—'}</dd></div>
          <div className="flex gap-2"><dt className="text-text-light">الاختصاص:</dt><dd className="text-text-gray">{faculty.specialization || '—'}</dd></div>
          <div className="flex gap-2"><dt className="text-text-light">الوحدة الأساسية:</dt><dd className="text-text-gray">{faculty.home_unit?.unit_name || '—'}</dd></div>
          {faculty.is_active === false && <div className="text-red-600 font-bold">الملف التدريسي غير مفعّل</div>}
        </dl>
      )}
      {note && <p className="text-[12px] text-text-light mt-2">{note}</p>}
    </Card>
  )
}

function ReviewCard({ title, review, highlight }) {
  return (
    <Card title={title} tone={review?.status === 'returned' ? 'warning' : 'default'}>
      <p className={`text-[15px] font-extrabold ${highlight ? 'text-primary-dark' : 'text-text-dark'}`}>{reviewStatusLabel(review?.status)}</p>
      {review?.reason ? <p className="text-[12.5px] text-amber-800 mt-2 whitespace-pre-wrap">ملاحظة الإعادة: {review.reason}</p> : null}
      <p className="text-[11.5px] text-text-gray mt-2">{review?.reviewer?.username || '—'} • {formatDateTime(review?.reviewed_at)}</p>
    </Card>
  )
}

export default function TeachingAssignmentDetail({ office }) {
  const { id } = useParams()
  const navigate = useNavigate()
  const location = useLocation()
  const authority = office === 'administrative' ? 'administrative' : 'scientific'
  const basePath = office === 'administrative' ? '/vp/administrative' : '/vp/scientific'
  const backTo = `${basePath}/teaching-assignments${location.state?.fromQueue ?? ''}`
  const [row, setRow] = useState(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')
  const [decisionError, setDecisionError] = useState('')
  const [notice, setNotice] = useState('')
  const [saving, setSaving] = useState(false)
  const [returnOpen, setReturnOpen] = useState(false)
  const [reason, setReason] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setLoadError('')
    try {
      const response = await apiRequest(`/v1/vice-presidency/teaching-assignments/${id}?authority=${authority}`)
      setRow(payload(response))
    } catch (requestError) {
      if (requestError.status === 401) {
        navigate('/login', { replace: true })
        return
      }
      setRow(null)
      setLoadError(requestError.status === 403
        ? 'ليس لديك صلاحية لعرض هذا الطلب، أو أنه خارج نطاقك.'
        : requestError.status === 404 ? 'الطلب غير موجود.' : (requestError.message || 'تعذّر تحميل الطلب.'))
    } finally {
      setLoading(false)
    }
  }, [authority, id, navigate])

  useEffect(() => {
    const timer = setTimeout(load, 0)
    return () => clearTimeout(timer)
  }, [load])

  const context = row?.viewer_context ?? null
  const canApprove = Boolean(context?.can_approve)
  const canReturn = Boolean(context?.can_return)
  const version = context?.submission_version ?? row?.submission_version

  async function decide(kind) {
    if (saving) return
    if (kind === 'return' && !reason.trim()) return
    setSaving(true)
    setDecisionError('')
    setNotice('')
    const path = `/v1/vice-presidency/teaching-assignments/${id}/${authority}/${kind === 'approve' ? 'approve' : 'return'}`
    const body = { expected_submission_version: version }
    if (kind === 'return') body.reason = reason.trim()
    try {
      const response = await apiRequest(path, { method: 'POST', body: JSON.stringify(body) })
      setReturnOpen(false)
      setReason('')
      setNotice(response?.message || 'تم تسجيل القرار.')
      // Always show the server's state after a decision (events, other office, effect).
      await load()
    } catch (requestError) {
      if (requestError.status === 401) {
        navigate('/login', { replace: true })
        return
      }
      setDecisionError(decisionErrorText(requestError))
      if (shouldReloadAfterError(requestError)) {
        setReturnOpen(false)
        await load()
      }
    } finally {
      setSaving(false)
    }
  }

  if (loading && !row) {
    return (
      <div className="flex items-center justify-center gap-2 py-16 text-primary-light" dir="rtl">
        <FaSpinner className="animate-[spin_0.7s_linear_infinite]" aria-hidden="true" />
        <span className="text-[13px]">جاري التحميل…</span>
      </div>
    )
  }

  const isRemoval = row?.action_type === 'remove'
  const ownReview = authority === 'administrative' ? row?.administrative_review : row?.scientific_review

  return (
    <div className="space-y-5 py-6 px-2" dir="rtl">
      <Link to={backTo} className="inline-flex items-center gap-2 text-[13px] font-semibold text-text-gray">
        <FaArrowRight aria-hidden="true" />
        رجوع إلى القائمة
      </Link>

      {notice && (
        <div className="bg-green-50 border border-green-200 rounded-[12px] px-5 py-3 text-[13px] text-green-700 font-semibold" role="status">✓ {notice}</div>
      )}
      {decisionError && (
        <div className="flex items-center justify-between gap-3 bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 text-[13px] text-red-600" role="alert">
          <span>⚠ {decisionError}</span>
          <button type="button" onClick={load} className="flex items-center gap-1.5 px-3 py-1 border border-red-300 rounded-[7px] text-[12px]"><FaRedo className="text-[10px]" /> إعادة التحميل</button>
        </div>
      )}
      {loadError && (
        <div className="flex items-center justify-between gap-3 bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 text-[13px] text-red-600" role="alert">
          <span>⚠ {loadError}</span>
          <button type="button" onClick={load} className="px-3 py-1 border border-red-300 rounded-[7px] text-[12px]">إعادة المحاولة</button>
        </div>
      )}

      {row && (
        <>
          <header className="bg-white border border-primary/12 rounded-[18px] px-6 py-5">
            <p className={`text-[12px] font-bold mb-1 ${isRemoval ? 'text-red-600' : 'text-text-light'}`}>
              {actionLabel(row.action_type)} • الشق {roleLabel(row.instructor_role)} • النسخة {row.submission_version}
            </p>
            <h1 className="text-[20px] font-black text-text-dark">{offeringTitle(row.course_offering)}</h1>
            <p className="mt-2 text-[13px] text-text-gray">
              {[
                row.course_offering?.college?.college_name,
                row.course_offering?.department?.department_name,
                row.course_offering?.academic_program?.program_name,
                row.course_offering?.academic_year?.year_name,
                row.course_offering?.semester?.semester_name,
                `طرح #${row.course_offering?.course_offering_id}`,
              ].filter(Boolean).join(' • ')}
            </p>
            <p className="mt-3 text-[13px] font-bold text-text-dark">
              الحالة: {requestStatusLabel(row.status)}
              <span className="font-normal text-text-gray"> • أرسله {row.requester?.username || '—'} في {formatDateTime(row.submitted_at)}</span>
            </p>
          </header>

          {row.action_reason && (
            <Card title={isRemoval ? 'سبب إنهاء التكليف (من العميد)' : 'سبب التغيير (من العميد)'} tone={isRemoval ? 'danger' : 'default'}>
              <p className="text-[13px] text-text-dark whitespace-pre-wrap">{row.action_reason}</p>
            </Card>
          )}

          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            {isRemoval
              ? <TeacherCard title="المدرس المطلوب إنهاء تكليفه" faculty={row.removal_target || row.proposed_faculty_member} note="تبقى الشعبة بلا مدرس لهذا الشق بعد اعتماد الإنهاء من المكتبين." />
              : <TeacherCard title="المدرس المقترح" faculty={row.proposed_faculty_member} />}
            <TeacherCard
              title="المدرس النافذ حاليًا"
              faculty={row.effective_faculty_member}
              note={row.status === 'approved' ? null : 'يبقى نافذًا إلى أن يوافق المكتبان على الطلب.'}
            />
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <ReviewCard title="مراجعة النائب العلمي" review={row.scientific_review} highlight={authority === 'scientific'} />
            <ReviewCard title="مراجعة النائب الإداري" review={row.administrative_review} highlight={authority === 'administrative'} />
          </div>

          {context && (
            <section className="bg-primary/5 border border-primary/15 rounded-[14px] px-4 py-3.5 space-y-3">
              <p className="flex items-start gap-2 text-[13px] text-text-dark">
                <FaInfoCircle className="text-primary mt-0.5 flex-shrink-0" aria-hidden="true" />
                <span>
                  <span className="font-bold">الأثر المتوقع: </span>
                  {APPROVAL_EFFECT_TEXT[context.approval_effect] ?? APPROVAL_EFFECT_TEXT.not_applicable}
                </span>
              </p>
              {context.blocked_reason && (
                <p className="text-[12.5px] text-text-gray">{BLOCKED_REASON_TEXT[context.blocked_reason] ?? ''}</p>
              )}
              {(canApprove || canReturn) && (
                <div className="flex items-center gap-2 flex-wrap">
                  {canApprove && (
                    <button type="button" className="flex items-center gap-2 px-5 py-2 bg-primary text-white rounded-[9px] text-[13px] font-bold hover:bg-primary-dark disabled:opacity-50" disabled={saving} onClick={() => decide('approve')}>
                      {saving && <FaSpinner className="animate-spin text-[11px]" />}
                      {authority === 'administrative' ? 'موافقة إدارية' : 'موافقة علمية'} على النسخة {version}
                    </button>
                  )}
                  {canReturn && (
                    <button type="button" className="px-5 py-2 border border-amber-300 text-amber-800 rounded-[9px] text-[13px] font-bold disabled:opacity-50" disabled={saving} onClick={() => setReturnOpen(true)}>
                      إعادة للعميد
                    </button>
                  )}
                </div>
              )}
              {!canApprove && !canReturn && ownReview?.status && (
                <p className="text-[12px] text-text-light">قرار مكتبك على النسخة الحالية: {reviewStatusLabel(ownReview.status)}.</p>
              )}
            </section>
          )}

          {returnOpen && (
            <div className="bg-white border border-amber-200 rounded-[14px] px-4 py-4 space-y-3">
              <label className="block text-[13px] font-bold text-text-dark" htmlFor="return-reason">سبب الإعادة للعميد (يظهر له ويُحفظ في السجل)</label>
              <textarea
                id="return-reason"
                className="w-full min-h-[96px] py-2.5 px-3 border-[1.5px] border-primary/20 rounded-[10px] text-[13px] outline-none focus:border-primary"
                value={reason}
                maxLength={1000}
                onChange={event => setReason(event.target.value)}
                required
              />
              <div className="flex items-center gap-2">
                <button type="button" className="px-4 py-2 bg-amber-700 text-white rounded-[9px] text-[13px] font-bold disabled:opacity-40" disabled={saving || !reason.trim()} onClick={() => decide('return')}>
                  تأكيد الإعادة
                </button>
                <button type="button" className="px-4 py-2 text-[13px] text-text-gray" disabled={saving} onClick={() => setReturnOpen(false)}>إلغاء</button>
              </div>
            </div>
          )}

          {Array.isArray(row.previous_requests) && row.previous_requests.length > 0 && (
            <section className="bg-white border border-primary/12 rounded-[14px] px-4 py-4">
              <p className="text-[13px] font-bold text-text-dark mb-3">الطلبات السابقة لهذا الشق</p>
              <ul className="space-y-2">
                {row.previous_requests.map(previous => (
                  <li key={previous.teaching_assignment_request_id} className="text-[12.5px] text-text-dark">
                    <span className="font-bold">#{previous.teaching_assignment_request_id}</span>
                    <span className="text-text-gray"> • {facultyName(previous.faculty_member)} • {requestStatusLabel(previous.status)} • النسخة {previous.submission_version} • {formatDateTime(previous.superseded_at || previous.submitted_at)}</span>
                  </li>
                ))}
              </ul>
            </section>
          )}

          <section className="bg-white border border-primary/12 rounded-[14px] px-4 py-4">
            <p className="text-[13px] font-bold text-text-dark mb-3">سجل الأحداث</p>
            <ul className="space-y-2">
              {(row.events || []).map((event, index) => (
                <li key={`${event.event_type}-${event.created_at}-${index}`} className="text-[12.5px] text-text-dark">
                  <span className="font-bold">{eventLabel(event.event_type)}</span>
                  <span className="text-text-gray"> • النسخة {event.submission_version ?? '—'} • {event.actor?.username || '—'} • {formatDateTime(event.created_at)}</span>
                  {event.notes ? <span className="block text-amber-800 mt-0.5 whitespace-pre-wrap">{event.notes}</span> : null}
                </li>
              ))}
              {(!row.events || row.events.length === 0) && <li className="text-[12.5px] text-text-light">لا يوجد سجل بعد.</li>}
            </ul>
          </section>
        </>
      )}
    </div>
  )
}
