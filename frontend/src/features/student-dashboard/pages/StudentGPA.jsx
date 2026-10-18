import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaChartLine, FaGraduationCap } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import CourseRequirementBadges from '../../../components/academic/CourseRequirementBadges'
import GpaTrendChart from '../components/GpaTrendChart'

function formatGpa(value) {
  if (value === null || value === undefined || value === '') return '—'
  const number = Number(value)
  if (!Number.isFinite(number)) return '—'
  return number.toFixed(2)
}

function formatMark(value) {
  if (value === null || value === undefined || value === '') return '—'
  const number = Number(value)
  if (!Number.isFinite(number)) return '—'
  return String(parseFloat(number.toFixed(2)))
}

function roundDiff(value) {
  return Math.round(Math.abs(Number(value)) * 100) / 100
}

function SkeletonBlock({ className }) {
  return <div className={`animate-pulse rounded-[12px] bg-primary/10 ${className}`} />
}

function GpaSkeleton() {
  return (
    <div className="space-y-5" dir="rtl" aria-busy="true" aria-live="polite">
      <div className="grid grid-cols-2 max-[640px]:grid-cols-1 gap-3">
        <SkeletonBlock className="h-16" />
        <SkeletonBlock className="h-16" />
      </div>
      <div className="grid grid-cols-4 max-[800px]:grid-cols-2 gap-3">
        <SkeletonBlock className="h-24" />
        <SkeletonBlock className="h-24" />
        <SkeletonBlock className="h-24" />
        <SkeletonBlock className="h-24" />
      </div>
      <SkeletonBlock className="h-72" />
    </div>
  )
}

function MetricCard({ label, value, hint }) {
  return (
    <article className="bg-white border border-primary/12 rounded-[16px] px-4 py-4 shadow-[0_2px_12px_rgba(26,46,16,0.05)]">
      <p className="text-[12px] font-bold text-text-light mb-2">{label}</p>
      <p className="text-[28px] font-black text-text-dark leading-none tabular-nums">{value}</p>
      {hint ? <p className="mt-2 text-[11.5px] text-text-light leading-6">{hint}</p> : null}
    </article>
  )
}

function EmptyGpaState() {
  return (
    <section className="bg-white border border-primary/12 rounded-[18px] px-6 py-16 text-center shadow-[0_2px_12px_rgba(26,46,16,0.05)]" dir="rtl">
      <FaGraduationCap className="mx-auto text-[40px] text-primary/25 mb-4" aria-hidden="true" />
      <h3 className="text-[17px] font-black text-text-dark mb-2">لا توجد بيانات معدل معتمدة حتى الآن</h3>
      <p className="text-[13.5px] text-text-light leading-7 max-w-[460px] mx-auto">
        سيظهر تطور معدلك هنا بعد اعتماد نتائج المواد رسمياً.
      </p>
    </section>
  )
}

function insightFromTimeline(timeline) {
  const scored = timeline.filter(point => point.term_gpa !== null && point.term_gpa !== undefined)
  if (scored.length < 2) {
    return {
      text: 'لا توجد فصول سابقة كافية لقياس اتجاه المعدل.',
      accessible: 'لا توجد فصول سابقة كافية لقياس اتجاه المعدل.',
    }
  }

  const previous = Number(scored[scored.length - 2].term_gpa)
  const latest = Number(scored[scored.length - 1].term_gpa)
  const diff = roundDiff(latest - previous)

  if (latest > previous) {
    return {
      text: `تحسن معدل الفصل بمقدار ${diff.toFixed(2)} مقارنة بالفصل السابق.`,
      accessible: `ارتفع معدل الفصل من ${previous.toFixed(2)} إلى ${latest.toFixed(2)} خلال آخر فصلين.`,
    }
  }
  if (latest < previous) {
    return {
      text: `انخفض معدل الفصل بمقدار ${diff.toFixed(2)} مقارنة بالفصل السابق.`,
      accessible: `انخفض معدل الفصل من ${previous.toFixed(2)} إلى ${latest.toFixed(2)} خلال آخر فصلين.`,
    }
  }
  return {
    text: 'معدل الفصل مستقر مقارنة بالفصل السابق.',
    accessible: `معدل الفصل مستقر عند ${latest.toFixed(2)} خلال آخر فصلين.`,
  }
}

export default function StudentGPA() {
  const navigate = useNavigate()
  const [payload, setPayload] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [yearId, setYearId] = useState('')
  const [semesterId, setSemesterId] = useState('')

  useEffect(() => {
    let active = true
    ;(async () => {
      setLoading(true)
      setError('')
      try {
        const response = await apiRequest('/v1/student/gpa-overview')
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
            ? 'ليس لديك صلاحية لعرض بيانات المعدل.'
            : 'تعذّر تحميل بيانات المعدل. يرجى المحاولة مرة أخرى.',
        )
      } finally {
        if (active) setLoading(false)
      }
    })()
    return () => { active = false }
  }, [navigate])

  const summary = payload?.summary ?? {}
  const years = payload?.years ?? []
  const timeline = payload?.timeline ?? []
  const selectedYear = years.find(year => String(year.academic_year_id) === String(yearId)) ?? null
  const selectedSemester = selectedYear?.semesters?.find(semester => String(semester.semester_id) === String(semesterId)) ?? null
  const insight = useMemo(() => insightFromTimeline(timeline), [timeline])
  const hasGpaData = (summary.cgpa !== null && summary.cgpa !== undefined)
    || timeline.some(point => point.term_gpa !== null && point.term_gpa !== undefined)

  const period = useMemo(() => {
    if (selectedSemester) {
      return {
        label: 'معدل الفصل',
        value: selectedSemester.term_gpa,
        hours: selectedSemester.included_credit_hours,
        courses: selectedSemester.courses ?? [],
      }
    }
    if (selectedYear) {
      return {
        label: 'معدل السنة',
        value: selectedYear.year_gpa,
        hours: selectedYear.included_credit_hours,
        courses: (selectedYear.semesters ?? []).flatMap(semester => semester.courses ?? []),
      }
    }
    return {
      label: 'المعدل التراكمي الحالي',
      value: summary.cgpa,
      hours: summary.total_included_credit_hours,
      courses: [],
    }
  }, [selectedSemester, selectedYear, summary])

  function handleYearChange(value) {
    setYearId(value)
    setSemesterId('')
  }

  function handleChartSelect(point) {
    if (point?.academic_year_id == null) return
    setYearId(String(point.academic_year_id))
    setSemesterId(point.semester_id != null ? String(point.semester_id) : '')
  }

  return (
    <div dir="rtl">
      <div className="mb-6">
        <h2 className="text-[20px] font-black text-text-dark mb-[3px]">المعدل الدراسي</h2>
        <p className="text-[12.5px] text-text-light leading-7">متابعة المعدل الفصلي والتراكمي وتطور الأداء الأكاديمي</p>
      </div>

      {loading ? <GpaSkeleton /> : null}

      {!loading && error ? (
        <section className="bg-white border border-red-200 rounded-[18px] px-5 py-4 text-[13.5px] text-red-700">
          {error}
        </section>
      ) : null}

      {!loading && !error && !hasGpaData ? <EmptyGpaState /> : null}

      {!loading && !error && hasGpaData ? (
        <div className="space-y-5">
          <section className="bg-white border border-primary/12 rounded-[18px] p-4 shadow-[0_2px_12px_rgba(26,46,16,0.05)]">
            <div className="grid grid-cols-2 max-[640px]:grid-cols-1 gap-3">
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
                  onChange={event => setSemesterId(event.target.value)}
                  disabled={!selectedYear}
                >
                  <option value="">كل الفصول</option>
                  {(selectedYear?.semesters ?? []).map(semester => (
                    <option key={semester.semester_id} value={semester.semester_id}>{semester.semester_name}</option>
                  ))}
                </select>
              </label>
            </div>
          </section>

          <section className="grid grid-cols-4 max-[980px]:grid-cols-2 max-[520px]:grid-cols-1 gap-3">
            <MetricCard label="المعدل التراكمي" value={formatGpa(summary.cgpa)} hint="من 4.00" />
            <MetricCard label={period.label} value={formatGpa(period.value)} />
            <MetricCard label="الساعات المحتسبة" value={period.hours ?? 0} />
            <MetricCard label="الفصول المكتملة" value={summary.completed_terms_count ?? 0} />
          </section>

          {summary.highest_term || summary.lowest_term ? (
            <section className="grid grid-cols-2 max-[640px]:grid-cols-1 gap-3">
              {summary.highest_term ? (
                <article className="bg-white border border-primary/12 rounded-[16px] px-4 py-4">
                  <p className="text-[12px] font-bold text-text-light">أفضل فصل</p>
                  <p className="mt-1 text-[15px] font-black text-text-dark">{summary.highest_term.label}</p>
                  <p className="mt-1 text-[13px] text-text-gray">أعلى GPA: <span className="font-black text-text-dark">{formatGpa(summary.highest_term.term_gpa)}</span></p>
                </article>
              ) : null}
              {summary.lowest_term ? (
                <article className="bg-white border border-primary/12 rounded-[16px] px-4 py-4">
                  <p className="text-[12px] font-bold text-text-light">أقل فصل</p>
                  <p className="mt-1 text-[15px] font-black text-text-dark">{summary.lowest_term.label}</p>
                  <p className="mt-1 text-[13px] text-text-gray">أدنى GPA: <span className="font-black text-text-dark">{formatGpa(summary.lowest_term.term_gpa)}</span></p>
                </article>
              ) : null}
            </section>
          ) : null}

          <section className="bg-white border border-primary/12 rounded-[18px] p-5 shadow-[0_2px_12px_rgba(26,46,16,0.05)]">
            <div className="flex items-center justify-between gap-3 mb-4 flex-wrap">
              <div className="flex items-center gap-2">
                <FaChartLine className="text-primary" aria-hidden="true" />
                <h3 className="text-[15px] font-black text-text-dark">تطور المعدل</h3>
              </div>
              <ul className="flex items-center gap-4 text-[12px] text-text-gray">
                <li className="flex items-center gap-1.5">
                  <span className="inline-block w-6 border-t-[3px] border-solid border-primary" aria-hidden="true" />
                  <span>معدل الفصل GPA</span>
                </li>
                <li className="flex items-center gap-1.5">
                  <span className="inline-block w-6 border-t-[3px] border-dashed border-primary-dark" aria-hidden="true" />
                  <span>المعدل التراكمي CGPA</span>
                </li>
              </ul>
            </div>
            <GpaTrendChart
              points={timeline}
              selectedYearId={yearId}
              selectedSemesterId={semesterId}
              onSelectPoint={handleChartSelect}
            />
            <p className="mt-4 text-[13px] text-text-dark leading-7">{insight.text}</p>
            <p className="sr-only">{insight.accessible}</p>
          </section>

          {yearId ? (
            <section className="bg-white border border-primary/12 rounded-[18px] overflow-hidden shadow-[0_2px_12px_rgba(26,46,16,0.05)]">
              <div className="px-5 py-4 border-b border-primary/10">
                <h3 className="text-[15px] font-black text-text-dark">تفاصيل المواد المحتسبة</h3>
                <p className="text-[12px] text-text-light mt-1">
                  {selectedSemester ? selectedSemester.semester_name : selectedYear?.year_name}
                </p>
              </div>
              {period.courses.length === 0 ? (
                <p className="px-5 py-8 text-[13px] text-text-light">لا توجد مواد محتسبة في هذه الفترة.</p>
              ) : (
                <>
                  <div className="overflow-x-auto hidden min-[701px]:block">
                    <table className="w-full border-collapse text-[13px]">
                      <thead>
                        <tr className="bg-[#fafaf8]">
                          <th className="px-4 py-2.5 text-right text-[11px] font-bold text-text-light">المقرر</th>
                          <th className="px-3 py-2.5 text-right text-[11px] font-bold text-text-light">التصنيف الأكاديمي</th>
                          <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">الساعات</th>
                          <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">العلامة النهائية</th>
                          <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">التقدير</th>
                          <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">النقاط</th>
                        </tr>
                      </thead>
                      <tbody>
                        {period.courses.map(course => (
                          <tr key={course.registration_id} className="border-t border-primary/8">
                            <td className="px-4 py-3">
                              <div className="font-semibold text-text-dark">{course.course_name}</div>
                              <div className="text-[11px] text-text-light font-mono mt-0.5">{course.course_code}</div>
                            </td>
                            <td className="px-3 py-3">
                              <CourseRequirementBadges classification={course.requirement_classification} compact />
                            </td>
                            <td className="px-3 py-3 text-center font-bold text-text-dark">{course.credit_hours ?? '—'}</td>
                            <td className="px-3 py-3 text-center font-black text-text-dark">{formatMark(course.final_mark)}</td>
                            <td className="px-3 py-3 text-center font-black text-primary">{course.letter_grade || '—'}</td>
                            <td className="px-3 py-3 text-center font-bold text-text-dark">{formatGpa(course.grade_points)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                  <div className="hidden max-[700px]:block divide-y divide-primary/8">
                    {period.courses.map(course => (
                      <article key={course.registration_id} className="px-4 py-4">
                        <h4 className="font-bold text-[14px] text-text-dark">{course.course_name}</h4>
                        <p className="text-[11.5px] text-text-light font-mono mt-0.5">{course.course_code}</p>
                        <div className="mt-1.5">
                          <CourseRequirementBadges classification={course.requirement_classification} compact />
                        </div>
                        <div className="mt-3 grid grid-cols-2 gap-2 text-[12px]">
                          <p className="text-text-light">الساعات: <span className="font-bold text-text-dark">{course.credit_hours ?? '—'}</span></p>
                          <p className="text-text-light">التقدير: <span className="font-black text-primary">{course.letter_grade || '—'}</span></p>
                          <p className="text-text-light">العلامة: <span className="font-semibold text-text-dark">{formatMark(course.final_mark)}</span></p>
                          <p className="text-text-light">النقاط: <span className="font-semibold text-text-dark">{formatGpa(course.grade_points)}</span></p>
                        </div>
                      </article>
                    ))}
                  </div>
                </>
              )}
            </section>
          ) : null}
        </div>
      ) : null}
    </div>
  )
}
