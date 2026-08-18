import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { FaArrowRight, FaSpinner } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import { hasPermission, PERMISSIONS } from '../../auth/auth'
import {
  eventLabel,
  formatDateTime,
  missingRolesLabel,
  offeringTitle,
  requestStatusLabel,
  reviewStatusLabel,
} from '../utils/exceptionalOpeningLabels'
import { instructorCoverageSummary, instructorRoleTeacherName } from '../../dean-dashboard/utils/courseOfferingDisplay'

function payload(response) {
  return response?.data ?? null
}

function ReviewCard({ title, review }) {
  return (
    <div className="bg-primary/[0.03] border border-primary/10 rounded-[14px] px-4 py-3.5">
      <p className="text-[11.5px] text-text-light font-semibold mb-1.5">{title}</p>
      <p className="text-[15px] font-extrabold text-text-dark">{reviewStatusLabel(review?.status)}</p>
      {review?.notes ? (
        <p className="text-[12.5px] text-amber-800 mt-2 whitespace-pre-wrap">{review.notes}</p>
      ) : null}
      <p className="text-[11.5px] text-text-gray mt-2">
        {review?.reviewer?.username || '—'} • {formatDateTime(review?.reviewed_at)}
      </p>
    </div>
  )
}

export default function ExceptionalOpeningDetail({ office }) {
  const { id } = useParams()
  const navigate = useNavigate()
  const authority = office === 'administrative' ? 'administrative' : 'scientific'
  const basePath = office === 'administrative' ? '/vp/administrative' : '/vp/scientific'
  const canDecide = authority === 'administrative'
    ? hasPermission(PERMISSIONS.exceptionalOpenReviewAdministrative)
    : hasPermission(PERMISSIONS.exceptionalOpenReviewScientific)
  const [row, setRow] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [saving, setSaving] = useState(false)
  const [returnOpen, setReturnOpen] = useState(false)
  const [reason, setReason] = useState('')

  useEffect(() => {
    let active = true
    async function run() {
      setLoading(true)
      setError('')
      try {
        const response = await apiRequest(`/v1/vice-presidency/course-offering-exceptions/${id}`)
        if (!active) return
        setRow(payload(response))
      } catch (requestError) {
        if (!active) return
        if (requestError.status === 401) {
          navigate('/login', { replace: true })
          return
        }
        setError(requestError.status === 403
          ? 'ليس لديك صلاحية لعرض هذا الطلب.'
          : (requestError.message || 'تعذّر تحميل الطلب.'))
      } finally {
        if (active) setLoading(false)
      }
    }
    run()
    return () => { active = false }
  }, [id, navigate])

  const ownReview = authority === 'administrative' ? row?.administrative_review : row?.scientific_review
  const canAct = canDecide
    && row?.status !== 'superseded'
    && row?.status !== 'approved'
    && !row?.materialized_at
    && ownReview?.status === 'pending'

  async function approve() {
    if (saving || !canAct) return
    setSaving(true)
    setError('')
    setNotice('')
    const path = authority === 'administrative'
      ? `/v1/vice-presidency/course-offering-exceptions/${id}/administrative/approve`
      : `/v1/vice-presidency/course-offering-exceptions/${id}/scientific/approve`
    try {
      const response = await apiRequest(path, { method: 'POST' })
      setRow(payload(response))
      setNotice(response?.message || 'تمت الموافقة.')
    } catch (requestError) {
      if (requestError.status === 401) {
        navigate('/login', { replace: true })
        return
      }
      setError(requestError.message || 'تعذّر تنفيذ الموافقة.')
    } finally {
      setSaving(false)
    }
  }

  async function returnToDean() {
    if (saving || !canAct || !reason.trim()) return
    setSaving(true)
    setError('')
    setNotice('')
    const path = authority === 'administrative'
      ? `/v1/vice-presidency/course-offering-exceptions/${id}/administrative/return`
      : `/v1/vice-presidency/course-offering-exceptions/${id}/scientific/return`
    try {
      const response = await apiRequest(path, {
        method: 'POST',
        body: JSON.stringify({ reason: reason.trim() }),
      })
      setRow(payload(response))
      setReturnOpen(false)
      setReason('')
      setNotice(response?.message || 'أُعيد الطلب إلى العميد.')
    } catch (requestError) {
      if (requestError.status === 401) {
        navigate('/login', { replace: true })
        return
      }
      setError(requestError.message || 'تعذّر إعادة الطلب.')
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center gap-2 py-16 text-primary-light" dir="rtl">
        <FaSpinner className="animate-[spin_0.7s_linear_infinite]" aria-hidden="true" />
        <span className="text-[13px]">جاري التحميل…</span>
      </div>
    )
  }

  const coverage = row?.instructor_coverage
  const requiredRoles = coverage?.required_roles ?? []

  return (
    <div className="space-y-5 py-6 px-2" dir="rtl">
      <Link to={`${basePath}/exceptional-openings`} className="inline-flex items-center gap-2 text-[13px] font-semibold text-text-gray">
        <FaArrowRight aria-hidden="true" />
        رجوع إلى القائمة
      </Link>

      {notice && (
        <div className="bg-green-500/8 border border-green-500/25 rounded-[12px] px-[18px] py-3 text-[13.5px] text-green-700 font-semibold">
          {notice}
        </div>
      )}
      {error && <p className="text-[13px] text-red-600">⚠ {error}</p>}

      {!row ? (
        <p className="text-[13px] text-text-gray">لا يمكن عرض هذا الطلب.</p>
      ) : (
        <>
          <header className="bg-white border border-primary/12 rounded-[18px] px-6 py-5">
            <p className="text-[12px] font-bold text-text-light mb-1">
              {row.course_offering?.course?.course_code || '—'}
            </p>
            <h1 className="text-[20px] font-black text-text-dark">{offeringTitle(row.course_offering)}</h1>
            <p className="mt-2 text-[13px] text-text-gray">
              {[
                row.course_offering?.college?.college_name,
                row.course_offering?.academic_program?.program_name,
                row.course_offering?.academic_year?.year_name,
                row.course_offering?.semester?.semester_name,
              ].filter(Boolean).join(' • ')}
            </p>
            <p className="mt-3 text-[13px] font-bold text-text-dark">الحالة: {requestStatusLabel(row.status)}</p>
            <p className="text-[12.5px] text-text-gray mt-1">نسخة الإرسال: {row.submission_version ?? '—'}</p>
          </header>

          <div className="bg-white border border-primary/12 rounded-[14px] px-4 py-3.5 space-y-2">
            <p className="text-[11.5px] text-text-light font-semibold">سبب العميد</p>
            <p className="text-[14px] text-text-dark whitespace-pre-wrap">{row.reason || '—'}</p>
            <p className="text-[12px] text-text-gray">
              مقدّم الطلب: {row.requester?.username || '—'} • {formatDateTime(row.submitted_at)}
            </p>
          </div>

          <div className="bg-white border border-primary/12 rounded-[14px] px-4 py-3.5">
            <p className="text-[11.5px] text-text-light font-semibold mb-1.5">تكليف المدرسين الحالي</p>
            <p className="text-[14px] font-extrabold text-amber-800">{instructorCoverageSummary(coverage)}</p>
            <p className="text-[12.5px] text-text-gray mt-1">الأدوار الناقصة: {missingRolesLabel(coverage)}</p>
            {requiredRoles.includes('theoretical') && (
              <p className="text-[12.5px] text-text-dark mt-1">مدرس النظري: {instructorRoleTeacherName(coverage, 'theoretical') || '—'}</p>
            )}
            {requiredRoles.includes('practical') && (
              <p className="text-[12.5px] text-text-dark mt-1">مدرس العملي: {instructorRoleTeacherName(coverage, 'practical') || '—'}</p>
            )}
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <ReviewCard title="موافقة النائب العلمي" review={row.scientific_review} />
            <ReviewCard title="موافقة النائب الإداري" review={row.administrative_review} />
          </div>

          {canAct && (
            <div className="flex items-center gap-2">
              <button
                type="button"
                className="px-4 py-2 bg-primary text-white rounded-[10px] text-[13px] font-bold disabled:opacity-40"
                disabled={saving}
                onClick={approve}
              >
                موافقة
              </button>
              <button
                type="button"
                className="px-4 py-2 border border-amber-300 text-amber-800 rounded-[10px] text-[13px] font-bold disabled:opacity-40"
                disabled={saving}
                onClick={() => setReturnOpen(true)}
              >
                إعادة للعميد
              </button>
            </div>
          )}

          {returnOpen && (
            <div className="bg-white border border-amber-200 rounded-[14px] px-4 py-4 space-y-3">
              <p className="text-[13px] font-bold text-text-dark">سبب الإعادة للعميد</p>
              <textarea
                className="w-full min-h-[96px] py-2.5 px-3 border-[1.5px] border-primary/20 rounded-[10px] text-[13px]"
                value={reason}
                onChange={event => setReason(event.target.value)}
                required
              />
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  className="px-4 py-2 bg-amber-700 text-white rounded-[10px] text-[13px] font-bold disabled:opacity-40"
                  disabled={saving || !reason.trim()}
                  onClick={returnToDean}
                >
                  تأكيد الإعادة
                </button>
                <button
                  type="button"
                  className="px-4 py-2 text-[13px] text-text-gray"
                  disabled={saving}
                  onClick={() => setReturnOpen(false)}
                >
                  إلغاء
                </button>
              </div>
            </div>
          )}

          <section className="bg-white border border-primary/12 rounded-[14px] px-4 py-4">
            <p className="text-[13px] font-bold text-text-dark mb-3">سجل الأحداث</p>
            <ul className="space-y-2">
              {(row.events || []).map((event, index) => (
                <li key={`${event.event_type}-${event.created_at}-${index}`} className="text-[12.5px] text-text-dark">
                  <span className="font-bold">{eventLabel(event.event_type)}</span>
                  <span className="text-text-gray"> • {formatDateTime(event.created_at)}</span>
                  {event.notes ? <span className="block text-amber-800 mt-0.5">{event.notes}</span> : null}
                </li>
              ))}
              {(!row.events || row.events.length === 0) && (
                <li className="text-[12.5px] text-text-light">لا يوجد سجل بعد.</li>
              )}
            </ul>
          </section>
        </>
      )}
    </div>
  )
}
