import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaCalendarCheck, FaChevronDown, FaRedo } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import CourseRequirementBadges, { pickRequirementClassification } from '../../../components/academic/CourseRequirementBadges'

const STATUS_LABELS = {
  present: { text: 'حاضر', className: 'bg-green-100 text-green-800 border-green-200' },
  absent: { text: 'غائب', className: 'bg-red-100 text-red-800 border-red-200' },
  excused: { text: 'غياب بعذر', className: 'bg-amber-100 text-amber-900 border-amber-200' },
  late: { text: 'متأخر', className: 'bg-sky-100 text-sky-900 border-sky-200' },
}

const DEPRIVATION_LABELS = {
  normal: { text: 'ضمن الحد المسموح', className: 'bg-primary/10 text-primary-dark border-primary/20' },
  candidate: { text: 'متجاوز لحد الغياب', className: 'bg-amber-100 text-amber-900 border-amber-200' },
  deprived: { text: 'محروم بسبب الغياب', className: 'bg-red-100 text-red-800 border-red-200' },
}

function formatDate(value) {
  if (!value) return '—'
  const parts = String(value).slice(0, 10).split('-')
  if (parts.length !== 3) return value
  return `${parts[2]}/${parts[1]}/${parts[0]}`
}

function formatPercent(value) {
  if (value === null || value === undefined || value === '') return '—'
  const number = Number(value)
  if (!Number.isFinite(number)) return '—'
  return `${number.toFixed(1)}%`
}

function sessionTypeLabel(type) {
  if (type === 'practical') return 'عملي'
  return 'نظري'
}

function statusDisplay(session) {
  if (session?.record_state !== 'recorded' || !session?.attendance_status) {
    return { text: 'لم تسجل الحالة بعد', className: 'bg-gray-100 text-text-dark border-gray-200' }
  }
  const code = session.attendance_status.status_code
  return STATUS_LABELS[code] ?? {
    text: session.attendance_status.status_name || code || '—',
    className: 'bg-gray-100 text-text-dark border-gray-200',
  }
}

function SkeletonBlock({ className }) {
  return <div className={`animate-pulse rounded-[12px] bg-primary/10 ${className}`} />
}

function AttendanceSkeleton() {
  return (
    <div className="space-y-5" dir="rtl" aria-busy="true" aria-live="polite">
      <div className="grid grid-cols-3 max-[800px]:grid-cols-1 gap-3">
        <SkeletonBlock className="h-16" />
        <SkeletonBlock className="h-16" />
        <SkeletonBlock className="h-16" />
      </div>
      <div className="grid grid-cols-5 max-[900px]:grid-cols-2 gap-3">
        <SkeletonBlock className="h-24" />
        <SkeletonBlock className="h-24" />
        <SkeletonBlock className="h-24" />
        <SkeletonBlock className="h-24" />
        <SkeletonBlock className="h-24" />
      </div>
      <SkeletonBlock className="h-40" />
      <SkeletonBlock className="h-40" />
    </div>
  )
}

function MetricCard({ label, value }) {
  return (
    <article className="bg-white border border-primary/12 rounded-[16px] px-4 py-4 shadow-[0_2px_12px_rgba(26,46,16,0.05)]">
      <p className="text-[12px] font-bold text-text-light mb-2">{label}</p>
      <p className="text-[26px] font-black text-text-dark leading-none tabular-nums">{value}</p>
    </article>
  )
}

function AbsenceMeter({ percentage, threshold }) {
  if (percentage === null || percentage === undefined) return null
  const pct = Math.min(100, Math.max(0, Number(percentage)))
  const thr = Math.min(100, Math.max(0, Number(threshold) || 0))

  return (
    <div className="mt-4" dir="rtl">
      <div className="relative h-3 rounded-full bg-primary/10 overflow-hidden">
        <div
          className="absolute inset-y-0 right-0 rounded-full bg-primary"
          style={{ width: `${pct}%` }}
          aria-hidden="true"
        />
      </div>
      <div className="relative h-4 mt-1">
        <span
          className="absolute top-0 w-px h-4 bg-text-dark"
          style={{ right: `${thr}%` }}
          aria-hidden="true"
        />
      </div>
      <div className="flex justify-between text-[11px] text-text-light">
        <span>0%</span>
        <span>حد الحرمان {formatPercent(threshold)}</span>
        <span>100%</span>
      </div>
      <p className="mt-1 text-[12px] text-text-gray">
        نسبة الغياب الحالية: <span className="font-black text-text-dark">{formatPercent(percentage)}</span>
      </p>
    </div>
  )
}

function SessionHistory({ sessions }) {
  if (!sessions?.length) {
    return <p className="px-1 py-3 text-[13px] text-text-light">لا توجد جلسات حضور لهذه المادة حتى الآن.</p>
  }

  return (
    <>
      <div className="overflow-x-auto hidden min-[701px]:block">
        <table className="w-full border-collapse text-[13px]">
          <thead>
            <tr className="bg-[#fafaf8]">
              <th className="px-3 py-2.5 text-right text-[11px] font-bold text-text-light">التاريخ</th>
              <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">نوع الجلسة</th>
              <th className="px-3 py-2.5 text-right text-[11px] font-bold text-text-light">الموضوع</th>
              <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">الوقت</th>
              <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">الحالة</th>
            </tr>
          </thead>
          <tbody>
            {sessions.map(session => {
              const status = statusDisplay(session)
              const time = [session.start_time, session.end_time].filter(Boolean).join(' — ')
              return (
                <tr key={session.attendance_session_id} className="border-t border-primary/8">
                  <td className="px-3 py-2.5 font-semibold text-text-dark">{formatDate(session.session_date)}</td>
                  <td className="px-3 py-2.5 text-center text-text-gray">{sessionTypeLabel(session.session_type)}</td>
                  <td className="px-3 py-2.5 text-text-dark">{session.topic || '—'}</td>
                  <td className="px-3 py-2.5 text-center text-text-light tabular-nums">{time || '—'}</td>
                  <td className="px-3 py-2.5 text-center">
                    <span className={`inline-block px-2.5 py-0.5 rounded-full text-[11px] font-bold border ${status.className}`}>
                      {status.text}
                    </span>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      <div className="hidden max-[700px]:block divide-y divide-primary/8">
        {sessions.map(session => {
          const status = statusDisplay(session)
          const time = [session.start_time, session.end_time].filter(Boolean).join(' — ')
          return (
            <article key={session.attendance_session_id} className="py-3">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <p className="font-bold text-[13.5px] text-text-dark">{formatDate(session.session_date)}</p>
                  <p className="text-[12px] text-text-light mt-0.5">{sessionTypeLabel(session.session_type)}{time ? ` · ${time}` : ''}</p>
                </div>
                <span className={`shrink-0 px-2.5 py-0.5 rounded-full text-[11px] font-bold border ${status.className}`}>
                  {status.text}
                </span>
              </div>
              <p className="mt-2 text-[12.5px] text-text-gray">{session.topic || 'بدون موضوع'}</p>
            </article>
          )
        })}
      </div>
    </>
  )
}

export default function StudentAttendance() {
  const navigate = useNavigate()
  const [payload, setPayload] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [yearId, setYearId] = useState('')
  const [semesterId, setSemesterId] = useState('')
  const [courseId, setCourseId] = useState('')
  const [expanded, setExpanded] = useState({})

  async function loadOverview() {
    setLoading(true)
    setError('')
    try {
      const response = await apiRequest('/v1/student/attendance-overview')
      setPayload(response?.data ?? null)
    } catch (requestError) {
      if (requestError.status === 401) {
        navigate('/login', { replace: true })
        return
      }
      setError(
        requestError.status === 403
          ? 'ليس لديك صلاحية لعرض سجل الحضور.'
          : 'تعذّر تحميل بيانات الحضور. يرجى المحاولة مرة أخرى.',
      )
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    let active = true
    ;(async () => {
      setLoading(true)
      setError('')
      try {
        const response = await apiRequest('/v1/student/attendance-overview')
        if (!active) return
        setPayload(response?.data ?? null)
      } catch (requestError) {
        if (!active) return
        if (requestError.status === 401) {
          navigate('/login', { replace: true })
          return
        }
        setError(
          requestError.status === 403
            ? 'ليس لديك صلاحية لعرض سجل الحضور.'
            : 'تعذّر تحميل بيانات الحضور. يرجى المحاولة مرة أخرى.',
        )
      } finally {
        if (active) setLoading(false)
      }
    })()
    return () => { active = false }
  }, [navigate])

  const years = payload?.years ?? []
  const allCourses = payload?.courses ?? []
  const selectedYear = years.find(year => String(year.academic_year_id) === String(yearId)) ?? null

  const visibleCourses = useMemo(() => {
    return allCourses.filter(course => {
      if (yearId && String(course.academic_year?.academic_year_id) !== String(yearId)) return false
      if (semesterId && String(course.semester?.semester_id) !== String(semesterId)) return false
      if (courseId && String(course.course_offering_id) !== String(courseId)) return false
      return true
    })
  }, [allCourses, yearId, semesterId, courseId])

  const courseOptions = useMemo(() => {
    return allCourses.filter(course => {
      if (yearId && String(course.academic_year?.academic_year_id) !== String(yearId)) return false
      if (semesterId && String(course.semester?.semester_id) !== String(semesterId)) return false
      return true
    })
  }, [allCourses, yearId, semesterId])

  const filterSummary = useMemo(() => ({
    recorded_sessions_count: visibleCourses.reduce((sum, course) => sum + (course.recorded_sessions_count || 0), 0),
    present_count: visibleCourses.reduce((sum, course) => sum + (course.present_count || 0), 0),
    absent_count: visibleCourses.reduce((sum, course) => sum + (course.absent_count || 0), 0),
    excused_count: visibleCourses.reduce((sum, course) => sum + (course.excused_count || 0), 0),
    late_count: visibleCourses.reduce((sum, course) => sum + (course.late_count || 0), 0),
    candidate_courses_count: visibleCourses.filter(course => course.deprivation_status === 'candidate').length,
    deprived_courses_count: visibleCourses.filter(course => course.deprivation_status === 'deprived').length,
  }), [visibleCourses])

  function handleYearChange(value) {
    setYearId(value)
    setSemesterId('')
    setCourseId('')
  }

  function handleSemesterChange(value) {
    setSemesterId(value)
    setCourseId('')
  }

  function toggleExpanded(id) {
    setExpanded(current => ({ ...current, [id]: !current[id] }))
  }

  useEffect(() => {
    if (!courseId) return
    setExpanded(current => ({ ...current, [courseId]: true }))
  }, [courseId])

  return (
    <div dir="rtl">
      <div className="flex items-start justify-between gap-3 mb-6 flex-wrap">
        <div>
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">الحضور والغياب</h2>
          <p className="text-[12.5px] text-text-light leading-7">متابعة سجل حضورك المسجل من أعضاء الهيئة التدريسية</p>
        </div>
        <button
          type="button"
          onClick={loadOverview}
          className="inline-flex items-center gap-2 px-3.5 py-2 rounded-[10px] border border-primary/20 text-[12.5px] font-bold text-primary-dark hover:bg-primary/8"
        >
          <FaRedo aria-hidden="true" />
          تحديث البيانات
        </button>
      </div>

      {loading ? <AttendanceSkeleton /> : null}

      {!loading && error ? (
        <section className="bg-white border border-red-200 rounded-[18px] px-5 py-4 text-[13.5px] text-red-700">
          {error}
        </section>
      ) : null}

      {!loading && !error && allCourses.length === 0 ? (
        <section className="bg-white border border-primary/12 rounded-[18px] px-6 py-16 text-center shadow-[0_2px_12px_rgba(26,46,16,0.05)]">
          <FaCalendarCheck className="mx-auto text-[40px] text-primary/25 mb-4" aria-hidden="true" />
          <h3 className="text-[17px] font-black text-text-dark mb-2">لا توجد بيانات حضور متاحة حتى الآن</h3>
          <p className="text-[13.5px] text-text-light leading-7 max-w-[480px] mx-auto">
            ستظهر سجلات الحضور هنا بعد تسجيلك في المواد وبدء أعضاء الهيئة التدريسية بتوثيق الحضور.
          </p>
        </section>
      ) : null}

      {!loading && !error && allCourses.length > 0 ? (
        <div className="space-y-5">
          <section className="bg-white border border-primary/12 rounded-[18px] p-4 shadow-[0_2px_12px_rgba(26,46,16,0.05)]">
            <div className="grid grid-cols-3 max-[800px]:grid-cols-1 gap-3">
              <label className="flex flex-col gap-1.5">
                <span className="text-[12px] font-bold text-text-dark">السنة الدراسية</span>
                <select
                  className="px-3 py-2.5 border border-primary/20 rounded-[10px] bg-white text-[13.5px] text-text-dark outline-none focus:border-primary focus:shadow-[0_0_0_3px_rgba(86,153,51,0.1)]"
                  value={yearId}
                  onChange={event => handleYearChange(event.target.value)}
                >
                  <option value="">كل السنوات</option>
                  {years.map(year => (
                    <option key={year.academic_year_id} value={year.academic_year_id}>{year.year_name}</option>
                  ))}
                </select>
              </label>
              <label className="flex flex-col gap-1.5">
                <span className="text-[12px] font-bold text-text-dark">الفصل الدراسي</span>
                <select
                  className="px-3 py-2.5 border border-primary/20 rounded-[10px] bg-white text-[13.5px] text-text-dark outline-none focus:border-primary focus:shadow-[0_0_0_3px_rgba(86,153,51,0.1)] disabled:bg-[#f4fbee] disabled:text-text-light"
                  value={semesterId}
                  onChange={event => handleSemesterChange(event.target.value)}
                  disabled={!selectedYear}
                >
                  <option value="">كل الفصول</option>
                  {(selectedYear?.semesters ?? []).map(semester => (
                    <option key={semester.semester_id} value={semester.semester_id}>{semester.semester_name}</option>
                  ))}
                </select>
              </label>
              <label className="flex flex-col gap-1.5">
                <span className="text-[12px] font-bold text-text-dark">المادة</span>
                <select
                  className="px-3 py-2.5 border border-primary/20 rounded-[10px] bg-white text-[13.5px] text-text-dark outline-none focus:border-primary focus:shadow-[0_0_0_3px_rgba(86,153,51,0.1)]"
                  value={courseId}
                  onChange={event => setCourseId(event.target.value)}
                >
                  <option value="">كل المواد</option>
                  {courseOptions.map(course => (
                    <option key={course.course_offering_id} value={course.course_offering_id}>
                      {course.course?.course_code} — {course.course?.course_name}
                    </option>
                  ))}
                </select>
              </label>
            </div>
          </section>

          <section className="grid grid-cols-5 max-[1100px]:grid-cols-3 max-[640px]:grid-cols-2 gap-3">
            <MetricCard label="الجلسات المسجلة" value={filterSummary.recorded_sessions_count} />
            <MetricCard label="الحضور" value={filterSummary.present_count} />
            <MetricCard label="الغياب" value={filterSummary.absent_count} />
            <MetricCard label="الغياب بعذر" value={filterSummary.excused_count} />
            <MetricCard label="التأخر" value={filterSummary.late_count} />
          </section>

          {filterSummary.candidate_courses_count > 0 || filterSummary.deprived_courses_count > 0 ? (
            <section className="grid grid-cols-2 max-[640px]:grid-cols-1 gap-3">
              {filterSummary.candidate_courses_count > 0 ? (
                <article className="bg-white border border-amber-200 rounded-[16px] px-4 py-4">
                  <p className="text-[12px] font-bold text-text-light">مواد متجاوزة للحد</p>
                  <p className="mt-1 text-[22px] font-black text-text-dark">{filterSummary.candidate_courses_count}</p>
                </article>
              ) : null}
              {filterSummary.deprived_courses_count > 0 ? (
                <article className="bg-white border border-red-200 rounded-[16px] px-4 py-4">
                  <p className="text-[12px] font-bold text-text-light">مواد محرومة</p>
                  <p className="mt-1 text-[22px] font-black text-text-dark">{filterSummary.deprived_courses_count}</p>
                </article>
              ) : null}
            </section>
          ) : null}

          <div className="space-y-4">
            {visibleCourses.map(course => {
              const badge = DEPRIVATION_LABELS[course.deprivation_status] ?? DEPRIVATION_LABELS.normal
              const open = Boolean(expanded[course.course_offering_id] || (courseId && String(course.course_offering_id) === String(courseId)))
              const hasRecorded = (course.recorded_sessions_count || 0) > 0

              return (
                <article
                  key={course.course_offering_id}
                  className="bg-white border border-primary/12 rounded-[18px] p-5 shadow-[0_2px_12px_rgba(26,46,16,0.05)]"
                >
                  <div className="flex items-start justify-between gap-3 flex-wrap">
                    <div>
                      <h3 className="text-[16px] font-black text-text-dark">{course.course?.course_name}</h3>
                      <p className="text-[12px] text-text-light font-mono mt-1">
                        {course.course?.course_code}
                        {course.academic_year?.year_name ? ` · ${course.academic_year.year_name}` : ''}
                        {course.semester?.semester_name ? ` · ${course.semester.semester_name}` : ''}
                      </p>
                      <div className="mt-1.5">
                        <CourseRequirementBadges classification={pickRequirementClassification(course)} compact />
                      </div>
                    </div>
                    <span className={`shrink-0 px-2.5 py-1 rounded-full text-[11.5px] font-bold border ${badge.className}`}>
                      {badge.text}
                    </span>
                  </div>

                  {!hasRecorded ? (
                    <p className="mt-4 text-[13.5px] text-text-gray leading-7">
                      لم يتم تسجيل حضور أو غياب لك في هذه المادة حتى الآن.
                    </p>
                  ) : (
                    <>
                      <div className="mt-4 grid grid-cols-5 max-[700px]:grid-cols-2 gap-2 text-center">
                        {[
                          ['الجلسات المسجلة', course.recorded_sessions_count],
                          ['حضور', course.present_count],
                          ['غياب', course.absent_count],
                          ['بعذر', course.excused_count],
                          ['متأخر', course.late_count],
                        ].map(([label, value]) => (
                          <div key={label} className="rounded-[12px] bg-[#fafaf8] border border-primary/8 py-2.5">
                            <p className="text-[18px] font-black text-text-dark tabular-nums">{value}</p>
                            <p className="text-[11px] text-text-light mt-0.5">{label}</p>
                          </div>
                        ))}
                      </div>
                      <p className="mt-4 text-[13px] text-text-gray">
                        نسبة الغياب المحتسبة: <span className="font-black text-text-dark">{formatPercent(course.absence_percentage)}</span>
                        <span className="mx-2">·</span>
                        الحد المسموح: <span className="font-black text-text-dark">{formatPercent(course.deprivation_threshold)}</span>
                      </p>
                      <AbsenceMeter percentage={course.absence_percentage} threshold={course.deprivation_threshold} />
                    </>
                  )}

                  <button
                    type="button"
                    className="mt-4 inline-flex items-center gap-2 text-[13px] font-bold text-primary-dark"
                    onClick={() => toggleExpanded(course.course_offering_id)}
                    aria-expanded={open}
                  >
                    <FaChevronDown className={`transition-transform ${open ? 'rotate-180' : ''}`} aria-hidden="true" />
                    سجل الجلسات
                  </button>

                  {open ? (
                    <div className="mt-3 border-t border-primary/10 pt-3">
                      <SessionHistory sessions={course.sessions} />
                    </div>
                  ) : null}
                </article>
              )
            })}
          </div>
        </div>
      ) : null}
    </div>
  )
}
