import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  FaBookOpen, FaCheckCircle, FaClock, FaPlus, FaMinus, FaSpinner,
} from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import StudentConfirmDialog from '../components/StudentConfirmDialog'

const REASON_LABELS = {
  already_registered: { ar: 'مسجل مسبقاً', tone: 'registered' },
  missing_prerequisites: { ar: 'متطلب سابق غير محقق', tone: 'prerequisite' },
  no_available_seats: { ar: 'لا توجد مقاعد متاحة', tone: 'full' },
  credit_limit_exceeded: { ar: 'تجاوز الحد المسموح من الساعات', tone: 'hours' },
}

const BADGE_CLASS = {
  eligible: 'bg-green-100 text-green-700',
  registered: 'bg-blue-100 text-blue-700',
  prerequisite: 'bg-amber-100 text-amber-800',
  full: 'bg-orange-100 text-orange-700',
  hours: 'bg-yellow-100 text-yellow-800',
}

function HoursBar({ registered, max, remaining }) {
  const pct = max > 0 ? Math.min((registered / max) * 100, 100) : 0
  const color = pct >= 100 ? 'bg-red-500' : pct >= 90 ? 'bg-red-500' : pct >= 70 ? 'bg-amber-500' : 'bg-primary'

  return (
    <section className="bg-white border border-primary/12 rounded-[16px] p-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]" dir="rtl">
      <div className="grid grid-cols-3 max-[640px]:grid-cols-1 gap-4 mb-4">
        <div>
          <p className="text-[11.5px] font-semibold text-text-light mb-1">الساعات المسجلة</p>
          <p className="text-[22px] font-black text-text-dark tabular-nums">{registered}</p>
        </div>
        <div>
          <p className="text-[11.5px] font-semibold text-text-light mb-1">الحد الأقصى</p>
          <p className="text-[22px] font-black text-text-dark tabular-nums">{max}</p>
        </div>
        <div>
          <p className="text-[11.5px] font-semibold text-text-light mb-1">الساعات المتبقية</p>
          <p className="text-[22px] font-black text-primary tabular-nums">{remaining}</p>
        </div>
      </div>
      <div className="h-2.5 bg-gray-100 rounded-full overflow-hidden">
        <div className={`h-full rounded-full transition-all duration-500 ${color}`} style={{ width: `${pct}%` }} />
      </div>
      {remaining <= 0 ? (
        <p className="mt-3 text-[12.5px] font-semibold text-red-700">
          لقد وصلت إلى الحد الأقصى المسموح من الساعات لهذا الفصل
        </p>
      ) : null}
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
  if (reasons.includes('missing_prerequisites')) {
    return { label: 'شرط سابق', className: BADGE_CLASS.prerequisite }
  }
  if (reasons.includes('no_available_seats')) {
    return { label: 'ممتلئ', className: BADGE_CLASS.full }
  }
  if (reasons.includes('credit_limit_exceeded')) {
    return { label: 'تجاوز الساعات', className: BADGE_CLASS.hours }
  }
  return { label: 'غير مؤهل', className: 'bg-gray-100 text-text-light' }
}

function CourseRow({ course, onRegister, registering }) {
  const eligible = course.eligibility_status === 'eligible'
  const reasons = course.eligibility_reasons ?? []
  const missing = course.missing_prerequisites ?? []
  const seats = course.available_seats ?? 0
  const capacity = course.capacity ?? 0
  const badge = statusBadge(course)
  const busy = Boolean(registering[course.course_offering_id])

  return (
    <article className={`px-5 py-4 ${eligible ? 'bg-white' : 'bg-primary/[0.015]'}`} dir="rtl">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2 flex-wrap">
            <h4 className="font-bold text-[14px] text-text-dark">{course.course_name}</h4>
            <span className={`text-[10.5px] font-bold px-2 py-0.5 rounded-full ${badge.className}`}>{badge.label}</span>
          </div>
          <div className="flex items-center gap-2 mt-1 flex-wrap text-[11.5px] text-text-light">
            <span className="font-mono">{course.course_code}</span>
            <span className="text-primary font-bold">{course.credit_hours} ساعات</span>
            <span className={seats > 0 ? 'text-green-700 font-semibold' : 'text-orange-700 font-semibold'}>
              {seats}/{capacity} مقعد
            </span>
          </div>
          {reasons
            .filter(reason => reason !== 'already_registered')
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
        <button
          type="button"
          onClick={() => onRegister(course)}
          disabled={!eligible || busy}
          className="flex items-center gap-1.5 px-3 py-1.5 bg-primary text-white rounded-[10px] text-[12px] font-bold hover:enabled:bg-primary-dark disabled:opacity-35 disabled:cursor-not-allowed transition-colors shrink-0"
        >
          {busy ? <FaSpinner className="animate-spin text-[10px]" /> : <FaPlus className="text-[10px]" />}
          تسجيل
        </button>
      </div>
    </article>
  )
}

export default function StudentRegistration() {
  const navigate = useNavigate()
  const requestSeq = useRef(0)

  const [payload, setPayload] = useState(null)
  const [yearId, setYearId] = useState('')
  const [semesterId, setSemesterId] = useState('')
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState('')
  const [toast, setToast] = useState('')
  const [registering, setRegistering] = useState({})
  const [dropping, setDropping] = useState({})
  const [confirm, setConfirm] = useState(null)
  const [calendarYear, setCalendarYear] = useState(null)
  const hasLoadedRef = useRef(false)

  const academicYear = payload?.academic_year ?? calendarYear
  const semesters = payload?.semesters ?? []
  const selectedYearId = yearId || String(academicYear?.academic_year_id || '')
  const selectedSemesterId = semesterId || String(payload?.semester?.semester_id || '')
  const registrationOpen = payload?.registration_open === true
  const termReady = Boolean(selectedYearId && selectedSemesterId)
  const available = payload?.available_courses ?? []
  const summary = payload?.summary ?? null
  const registrations = summary?.registrations ?? []
  const busy = loading || refreshing

  useEffect(() => {
    let active = true
    // Keep these calendar lookups for the dual-role landing contract.
    // They must not populate the semester dropdown or fabricate a registration window.
    Promise.all([
      apiRequest('/v1/academic-years/current').catch(() => null),
      apiRequest('/v1/semesters/active').catch(() => null),
    ]).then(([yearResponse, semesterResponse]) => {
      if (!active) return
      setCalendarYear(yearResponse?.data ?? null)
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

      const params = new URLSearchParams()
      if (yearId) params.set('academic_year_id', yearId)
      if (semesterId) params.set('semester_id', semesterId)
      const query = params.toString()

      try {
        const response = await apiRequest(`/v1/student/registration${query ? `?${query}` : ''}`)
        if (!active || seq !== requestSeq.current) return
        setPayload(response?.data ?? null)
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
            : (requestError.message || 'تعذّر تحميل بيانات التسجيل. يرجى المحاولة مرة أخرى.'),
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
  }, [yearId, semesterId, navigate])

  const hours = useMemo(() => ({
    registered: summary?.total_registered_hours ?? 0,
    max: summary?.max_allowed_hours ?? 0,
    remaining: summary?.remaining_hours ?? 0,
  }), [summary])

  function showToast(message) {
    setToast(message)
    window.setTimeout(() => setToast(''), 3200)
  }

  async function confirmRegister() {
    const course = confirm?.course
    if (!course) return
    setRegistering(current => ({ ...current, [course.course_offering_id]: true }))
    setError('')
    try {
      await apiRequest(`/v1/student/registration/course-offerings/${course.course_offering_id}/register`, {
        method: 'POST',
        body: JSON.stringify({}),
      })
      showToast(`تم تسجيل "${course.course_name}" بنجاح`)
      setConfirm(null)
      const response = await apiRequest(`/v1/student/registration?academic_year_id=${selectedYearId}&semester_id=${selectedSemesterId}`)
      setPayload(response?.data ?? null)
    } catch (requestError) {
      if (requestError.status === 401) {
        navigate('/login', { replace: true })
        return
      }
      setError(requestError.message || 'فشل تسجيل المادة')
    } finally {
      setRegistering(current => ({ ...current, [course.course_offering_id]: false }))
    }
  }

  async function confirmDrop() {
    const registration = confirm?.registration
    if (!registration) return
    setDropping(current => ({ ...current, [registration.registration_id]: true }))
    setError('')
    try {
      await apiRequest(`/v1/student/registration/registrations/${registration.registration_id}/drop`, {
        method: 'POST',
        body: JSON.stringify({}),
      })
      showToast(`تم حذف "${registration.course_name}" من قائمة التسجيل`)
      setConfirm(null)
      const response = await apiRequest(`/v1/student/registration?academic_year_id=${selectedYearId}&semester_id=${selectedSemesterId}`)
      setPayload(response?.data ?? null)
    } catch (requestError) {
      if (requestError.status === 401) {
        navigate('/login', { replace: true })
        return
      }
      setError(requestError.message || 'فشل حذف التسجيل')
    } finally {
      setDropping(current => ({ ...current, [registration.registration_id]: false }))
    }
  }

  if (loading) {
    return (
      <div className="flex justify-center py-20 text-primary">
        <FaSpinner className="animate-spin text-[32px]" aria-hidden="true" />
      </div>
    )
  }

  const afterHours = confirm?.type === 'register'
    ? hours.registered + Number(confirm.course?.credit_hours || 0)
    : hours.registered

  return (
    <div className="space-y-5" dir="rtl">
      <header className="bg-[linear-gradient(135deg,rgba(86,153,51,0.12),rgba(255,255,255,0.95))] border border-primary/12 rounded-[20px] px-6 py-6 shadow-[0_2px_16px_rgba(26,46,16,0.06)]">
        <p className="text-[12px] font-bold text-primary mb-1">بوابة الطالب</p>
        <h1 className="text-[22px] font-black text-text-dark">تسجيل المواد</h1>
        <p className="mt-2 text-[13.5px] text-text-light leading-7">
          تظهر هنا المواد التي أتاحتها الكلية لبرنامجك الدراسي في الفصل الحالي.
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
          {refreshing ? <span className="text-[12px] text-text-light pb-1">جاري التحديث…</span> : null}
        </div>
      </header>

      {toast ? (
        <div className="px-4 py-2.5 text-[12.5px] text-green-700 bg-green-50 border border-green-200 rounded-[10px] flex items-center gap-2">
          <FaCheckCircle className="text-green-500 shrink-0" /> {toast}
        </div>
      ) : null}
      {error ? (
        <p className="px-4 py-2.5 text-[12.5px] text-red-600 bg-red-50 border border-red-200 rounded-[10px]">⚠ {error}</p>
      ) : null}

      {summary ? (
        <HoursBar registered={hours.registered} max={hours.max} remaining={hours.remaining} />
      ) : null}

      {!registrationOpen ? (
        <UnavailableState />
      ) : !termReady ? (
        <section className="border border-amber-200 bg-amber-50 rounded-[16px] px-5 py-4 text-[13px] font-semibold text-amber-900" dir="rtl">
          اختر الفصل الدراسي لعرض المواد المتاحة للتسجيل.
        </section>
      ) : (
        <div className="grid grid-cols-2 max-[900px]:grid-cols-1 gap-5">
          <section className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
            <div className="flex items-center gap-2 px-5 py-3 bg-primary/[0.05] border-b border-primary/10">
              <span className="text-[13px] font-extrabold text-text-dark">المواد المتاحة من الكلية</span>
              <span className="text-[11px] text-text-light bg-primary/10 px-2 py-0.5 rounded-full font-bold">{available.length}</span>
            </div>
            {available.length === 0 ? (
              <div className="flex flex-col items-center py-12 gap-2 px-5">
                <FaBookOpen className="text-[32px] text-primary/15" />
                <p className="text-[13px] font-bold text-text-dark">الوقت الآن ليس متاحاً للتسجيل على مواد</p>
                <p className="text-[12px] text-text-light text-center leading-6">
                  لا توجد مواد مفتوحة للتسجيل حالياً ضمن برنامجك الدراسي.
                  ستظهر المواد هنا عند إتاحتها من الكلية.
                </p>
              </div>
            ) : (
              <div className="divide-y divide-primary/8">
                {available.map(course => (
                  <CourseRow
                    key={course.course_offering_id}
                    course={course}
                    onRegister={selected => setConfirm({ type: 'register', course: selected })}
                    registering={registering}
                  />
                ))}
              </div>
            )}
          </section>

          <section className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
            <div className="flex items-center gap-2 px-5 py-3 bg-primary/[0.05] border-b border-primary/10">
              <span className="text-[13px] font-extrabold text-text-dark">المواد المسجلة</span>
              <span className="text-[11px] text-text-light bg-primary/10 px-2 py-0.5 rounded-full font-bold">{registrations.length}</span>
            </div>
            {registrations.length === 0 ? (
              <div className="flex flex-col items-center py-12 gap-2">
                <FaBookOpen className="text-[32px] text-primary/15" />
                <p className="text-[12.5px] text-text-light">لم تسجل أي مادة بعد</p>
              </div>
            ) : (
              <div className="divide-y divide-primary/8">
                {registrations.map(registration => {
                  const canDrop = registration.offering_status === 'open'
                  const dropBusy = Boolean(dropping[registration.registration_id])
                  return (
                    <div key={registration.registration_id} className="flex items-center justify-between gap-3 px-5 py-4">
                      <div className="min-w-0">
                        <p className="font-bold text-[13.5px] text-text-dark truncate">{registration.course_name}</p>
                        <div className="flex items-center gap-2 mt-1 flex-wrap text-[11.5px] text-text-light">
                          <span className="font-mono">{registration.course_code}</span>
                          <span className="text-primary font-bold">{registration.credit_hours} ساعات</span>
                          <span className="px-1.5 py-0.5 bg-green-100 text-green-700 text-[10px] font-bold rounded-full">
                            {registration.registration_status?.status_name || 'مسجل'}
                          </span>
                        </div>
                      </div>
                      {canDrop ? (
                        <button
                          type="button"
                          onClick={() => setConfirm({ type: 'drop', registration })}
                          disabled={dropBusy}
                          className="flex items-center gap-1.5 px-3 py-1.5 border border-red-300 text-red-600 rounded-[10px] text-[12px] font-bold hover:bg-red-50 disabled:opacity-40 shrink-0"
                        >
                          {dropBusy ? <FaSpinner className="animate-spin text-[10px]" /> : <FaMinus className="text-[10px]" />}
                          حذف
                        </button>
                      ) : null}
                    </div>
                  )
                })}
              </div>
            )}
          </section>
        </div>
      )}

      {!registrationOpen && registrations.length > 0 ? (
        <section className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
          <div className="flex items-center gap-2 px-5 py-3 bg-primary/[0.05] border-b border-primary/10">
            <span className="text-[13px] font-extrabold text-text-dark">المواد المسجلة</span>
            <span className="text-[11px] text-text-light bg-primary/10 px-2 py-0.5 rounded-full font-bold">{registrations.length}</span>
          </div>
          <div className="divide-y divide-primary/8">
            {registrations.map(registration => (
              <div key={registration.registration_id} className="px-5 py-4">
                <p className="font-bold text-[13.5px] text-text-dark">{registration.course_name}</p>
                <div className="flex items-center gap-2 mt-1 text-[11.5px] text-text-light">
                  <span className="font-mono">{registration.course_code}</span>
                  <span className="text-primary font-bold">{registration.credit_hours} ساعات</span>
                  <span className="px-1.5 py-0.5 bg-green-100 text-green-700 text-[10px] font-bold rounded-full">
                    {registration.registration_status?.status_name || 'مسجل'}
                  </span>
                </div>
              </div>
            ))}
          </div>
        </section>
      ) : null}

      {confirm?.type === 'register' ? (
        <StudentConfirmDialog
          title="تأكيد تسجيل المادة"
          confirmLabel="تأكيد التسجيل"
          busy={Boolean(registering[confirm.course.course_offering_id])}
          onConfirm={confirmRegister}
          onCancel={() => setConfirm(null)}
        >
          <p className="text-[13px] text-text-dark"><span className="text-text-light">المادة:</span> {confirm.course.course_name}</p>
          <p className="text-[13px] text-text-dark"><span className="text-text-light">الساعات:</span> {confirm.course.credit_hours}</p>
          <p className="text-[13px] font-semibold text-text-dark">
            بعد التسجيل: الساعات المسجلة ستكون {afterHours} من {hours.max}
          </p>
        </StudentConfirmDialog>
      ) : null}

      {confirm?.type === 'drop' ? (
        <StudentConfirmDialog
          title="تأكيد حذف التسجيل"
          confirmLabel="تأكيد الحذف"
          confirmTone="danger"
          busy={Boolean(dropping[confirm.registration.registration_id])}
          onConfirm={confirmDrop}
          onCancel={() => setConfirm(null)}
        >
          <p className="text-[13px] text-text-dark">
            هل تريد حذف تسجيل مادة "{confirm.registration.course_name}"؟
          </p>
        </StudentConfirmDialog>
      ) : null}
    </div>
  )
}
