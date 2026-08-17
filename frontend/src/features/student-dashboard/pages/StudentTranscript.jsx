import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaPrint, FaGraduationCap } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import CourseRequirementBadges from '../../../components/academic/CourseRequirementBadges'

const STATUS_LABELS = {
  passed: { ar: 'ناجح', className: 'bg-green-100 text-green-800 border-green-200' },
  failed: { ar: 'راسب', className: 'bg-red-100 text-red-800 border-red-200' },
  deprived: { ar: 'محروم', className: 'bg-orange-100 text-orange-900 border-orange-200' },
  incomplete: { ar: 'غير مكتمل', className: 'bg-amber-100 text-amber-900 border-amber-200' },
  withdrawn: { ar: 'منسحب', className: 'bg-gray-100 text-text-dark border-gray-200' },
}

function formatMark(value) {
  if (value === null || value === undefined || value === '') return '—'
  const number = Number(value)
  if (!Number.isFinite(number)) return '—'
  return String(parseFloat(number.toFixed(2)))
}

function formatGpa(value) {
  if (value === null || value === undefined || value === '') return '—'
  const number = Number(value)
  if (!Number.isFinite(number)) return '—'
  return number.toFixed(2)
}

function statusInfo(course) {
  if (course.is_deprived || course.result_status?.status_code === 'deprived') {
    return STATUS_LABELS.deprived
  }
  return STATUS_LABELS[course.result_status?.status_code] ?? {
    ar: course.result_status?.status_name || '—',
    className: 'bg-gray-100 text-text-light border-gray-200',
  }
}

function SkeletonBlock({ className }) {
  return <div className={`animate-pulse rounded-[12px] bg-primary/10 ${className}`} />
}

function TranscriptSkeleton() {
  return (
    <div className="space-y-5" dir="rtl" aria-busy="true" aria-live="polite">
      <section className="bg-white border border-primary/12 rounded-[20px] px-6 py-6">
        <SkeletonBlock className="h-4 w-28 mb-3" />
        <SkeletonBlock className="h-7 w-48 mb-4" />
        <div className="grid grid-cols-4 max-[720px]:grid-cols-2 gap-3">
          <SkeletonBlock className="h-12" />
          <SkeletonBlock className="h-12" />
          <SkeletonBlock className="h-12" />
          <SkeletonBlock className="h-12" />
        </div>
      </section>
      <div className="grid grid-cols-4 max-[800px]:grid-cols-2 gap-3">
        <SkeletonBlock className="h-24" />
        <SkeletonBlock className="h-24" />
        <SkeletonBlock className="h-24" />
        <SkeletonBlock className="h-24" />
      </div>
      <section className="bg-white border border-primary/12 rounded-[16px] overflow-hidden">
        <SkeletonBlock className="h-12 rounded-none" />
        <div className="p-5 space-y-3">
          <SkeletonBlock className="h-8" />
          <SkeletonBlock className="h-8" />
          <SkeletonBlock className="h-8" />
        </div>
      </section>
    </div>
  )
}

function EmptyApprovedState() {
  return (
    <section className="bg-white border border-primary/12 rounded-[18px] px-6 py-16 text-center shadow-[0_2px_12px_rgba(26,46,16,0.05)]" dir="rtl">
      <FaGraduationCap className="mx-auto text-[40px] text-primary/25 mb-4" aria-hidden="true" />
      <h3 className="text-[17px] font-black text-text-dark mb-2">لا توجد نتائج معتمدة متاحة حتى الآن</h3>
      <p className="text-[13.5px] text-text-light leading-7 max-w-[460px] mx-auto">
        ستظهر نتائج المواد في كشف الدرجات بعد اعتمادها رسمياً.
      </p>
    </section>
  )
}

function CourseTable({ courses }) {
  return (
    <>
      <div className="overflow-x-auto hidden min-[701px]:block print:block">
        <table className="w-full border-collapse text-[13px]">
          <thead>
            <tr className="bg-[#fafaf8]">
              <th className="px-4 py-2.5 text-right text-[11px] font-bold text-text-light">المقرر</th>
              <th className="px-3 py-2.5 text-right text-[11px] font-bold text-text-light">التصنيف الأكاديمي</th>
              <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">الساعات</th>
              <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">العملي</th>
              <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">النظري</th>
              <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">العلامة النهائية</th>
              <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">التقدير</th>
              <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">الحالة</th>
            </tr>
          </thead>
          <tbody>
            {courses.map(course => {
              const status = statusInfo(course)
              return (
                <tr key={course.registration_id} className="border-t border-primary/8">
                  <td className="px-4 py-3">
                    <div className="font-semibold text-text-dark">{course.course_name}</div>
                    <div className="text-[11px] text-text-light font-mono mt-0.5">{course.course_code}</div>
                  </td>
                  <td className="px-3 py-3">
                    <CourseRequirementBadges classification={course.requirement_classification} compact />
                  </td>
                  <td className="px-3 py-3 text-center font-bold text-text-dark">{course.credit_hours ?? '—'}</td>
                  <td className="px-3 py-3 text-center text-text-gray">{formatMark(course.practical_mark)}</td>
                  <td className="px-3 py-3 text-center text-text-gray">{formatMark(course.theoretical_mark)}</td>
                  <td className="px-3 py-3 text-center font-black text-text-dark">{formatMark(course.final_mark)}</td>
                  <td className="px-3 py-3 text-center font-black text-primary">{course.letter_grade || '—'}</td>
                  <td className="px-3 py-3 text-center">
                    <span className={`inline-block px-2.5 py-0.5 rounded-full text-[11px] font-bold border ${status.className}`}>
                      {status.ar}
                    </span>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      <div className="hidden max-[700px]:block divide-y divide-primary/8 print:hidden">
        {courses.map(course => {
          const status = statusInfo(course)
          return (
            <article key={course.registration_id} className="px-4 py-4">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <h4 className="font-bold text-[14px] text-text-dark">{course.course_name}</h4>
                  <p className="text-[11.5px] text-text-light font-mono mt-0.5">{course.course_code}</p>
                  <div className="mt-1.5">
                    <CourseRequirementBadges classification={course.requirement_classification} compact />
                  </div>
                </div>
                <span className={`shrink-0 px-2.5 py-0.5 rounded-full text-[11px] font-bold border ${status.className}`}>
                  {status.ar}
                </span>
              </div>
              <div className="mt-3 grid grid-cols-2 gap-2 text-[12px]">
                <p className="text-text-light">الساعات: <span className="font-bold text-text-dark">{course.credit_hours ?? '—'}</span></p>
                <p className="text-text-light">التقدير: <span className="font-black text-primary">{course.letter_grade || '—'}</span></p>
                <p className="text-text-light">العملي: <span className="font-semibold text-text-dark">{formatMark(course.practical_mark)}</span></p>
                <p className="text-text-light">النظري: <span className="font-semibold text-text-dark">{formatMark(course.theoretical_mark)}</span></p>
              </div>
              <p className="mt-2 text-[13px] font-black text-text-dark">العلامة النهائية: {formatMark(course.final_mark)}</p>
            </article>
          )
        })}
      </div>
    </>
  )
}

export default function StudentTranscript() {
  const navigate = useNavigate()
  const [payload, setPayload] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [yearFilter, setYearFilter] = useState('')

  useEffect(() => {
    let active = true
    ;(async () => {
      setLoading(true)
      setError('')
      try {
        const response = await apiRequest('/v1/student/transcript')
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
            ? 'ليس لديك صلاحية لعرض كشف الدرجات.'
            : 'تعذّر تحميل كشف الدرجات. يرجى المحاولة مرة أخرى.',
        )
      } finally {
        if (active) setLoading(false)
      }
    })()
    return () => { active = false }
  }, [navigate])

  const student = payload?.student ?? payload
  const summary = payload?.summary ?? {}
  const terms = payload?.terms ?? []
  const years = useMemo(() => {
    const unique = []
    const seen = new Set()
    terms.forEach(term => {
      const id = String(term.academic_year?.academic_year_id || '')
      if (!id || seen.has(id)) return
      seen.add(id)
      unique.push(term.academic_year)
    })
    return unique
  }, [terms])
  const visibleTerms = yearFilter
    ? terms.filter(term => String(term.academic_year?.academic_year_id) === yearFilter)
    : terms

  if (loading) return <TranscriptSkeleton />

  if (error) {
    return (
      <p className="px-4 py-3 text-[13px] text-red-700 bg-red-50 border border-red-200 rounded-[12px]" dir="rtl">
        ⚠ {error}
      </p>
    )
  }

  const cgpa = summary.cgpa
  const identityBits = [
    student?.student_number && { label: 'رقم الطالب', value: student.student_number },
    student?.program?.program_name && { label: 'البرنامج', value: student.program.program_name },
    student?.college?.college_name && { label: 'الكلية', value: student.college.college_name },
    student?.academic_level?.level_name && { label: 'المستوى الحالي', value: student.academic_level.level_name },
  ].filter(Boolean)

  return (
    <div className="space-y-5 student-transcript" dir="rtl">
      <section className="hidden print:block mb-4 border-b border-primary/20 pb-4">
        <p className="text-[11px] font-bold text-primary mb-1">جامعة الرواد</p>
        <h1 className="text-[20px] font-black text-text-dark">كشف درجات إلكتروني</h1>
        <p className="text-[12px] text-text-light mt-1">
          هذا كشف درجات إلكتروني للنتائج المعتمدة. وهو ليس وثيقة مختومة أو مصدّقة.
        </p>
        <p className="text-[13px] font-bold text-text-dark mt-3">{student?.full_name}</p>
        <p className="text-[12px] text-text-light">{student?.student_number} — {student?.program?.program_name || '—'}</p>
        <p className="text-[12px] text-text-light">{student?.college?.college_name || '—'}</p>
      </section>

      <header className="bg-[linear-gradient(135deg,rgba(86,153,51,0.12),rgba(255,255,255,0.95))] border border-primary/12 rounded-[20px] px-6 py-6 shadow-[0_2px_16px_rgba(26,46,16,0.06)] print:shadow-none print:border-0 print:bg-white print:p-0">
        <div className="flex items-start justify-between gap-4 flex-wrap">
          <div>
            <p className="text-[12px] font-bold text-primary mb-1 print:hidden">بوابة الطالب</p>
            <h1 className="text-[22px] font-black text-text-dark">كشف الدرجات</h1>
            <p className="mt-1 text-[13.5px] text-text-light">السجل الأكاديمي الرسمي للنتائج المعتمدة</p>
          </div>
          <button
            type="button"
            onClick={() => window.print()}
            className="print-hidden flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-[10px] text-[13px] font-bold hover:bg-primary-dark"
          >
            <FaPrint aria-hidden="true" />
            طباعة الكشف
          </button>
        </div>
        <div className="mt-4 grid grid-cols-4 max-[800px]:grid-cols-2 gap-3">
          {identityBits.map(item => (
            <div key={item.label}>
              <p className="text-[11px] font-semibold text-text-light mb-0.5">{item.label}</p>
              <p className="text-[13.5px] font-bold text-text-dark">{item.value}</p>
            </div>
          ))}
        </div>
      </header>

      <section className="grid grid-cols-4 max-[800px]:grid-cols-2 gap-3">
        <article className="bg-white border border-primary/12 rounded-[16px] px-4 py-4">
          <p className="text-[11.5px] font-semibold text-text-light mb-1">المعدل التراكمي</p>
          <p className="text-[26px] font-black text-primary">{formatGpa(cgpa)}</p>
          {cgpa === null || cgpa === undefined ? (
            <p className="mt-1 text-[11.5px] text-text-light leading-5">لا توجد نتائج معتمدة كافية لاحتساب المعدل</p>
          ) : null}
        </article>
        <article className="bg-white border border-primary/12 rounded-[16px] px-4 py-4">
          <p className="text-[11.5px] font-semibold text-text-light mb-1">الساعات المجتازة</p>
          <p className="text-[26px] font-black text-text-dark">{summary.total_passed_credit_hours ?? 0}</p>
        </article>
        <article className="bg-white border border-primary/12 rounded-[16px] px-4 py-4">
          <p className="text-[11.5px] font-semibold text-text-light mb-1">المواد الناجحة</p>
          <p className="text-[26px] font-black text-green-700">{summary.passed_courses_count ?? 0}</p>
        </article>
        <article className="bg-white border border-primary/12 rounded-[16px] px-4 py-4">
          <p className="text-[11.5px] font-semibold text-text-light mb-1">المواد غير المجتازة</p>
          <p className="text-[26px] font-black text-red-700">{(summary.failed_courses_count ?? 0) + (summary.deprived_courses_count ?? 0)}</p>
        </article>
      </section>

      {years.length > 1 ? (
        <label className="print-hidden flex flex-col gap-1 text-[12px] font-semibold text-text-light">
          السنة الدراسية
          <select
            className="max-w-[260px] py-2.5 px-3 border-[1.5px] border-primary/20 rounded-[12px] bg-white text-[13.5px] text-text-dark"
            value={yearFilter}
            onChange={event => setYearFilter(event.target.value)}
          >
            <option value="">كل السنوات</option>
            {years.map(year => (
              <option key={year.academic_year_id} value={year.academic_year_id}>{year.year_name}</option>
            ))}
          </select>
        </label>
      ) : null}

      {terms.length === 0 ? (
        <EmptyApprovedState />
      ) : (
        <div className="space-y-5">
          {visibleTerms.map(term => (
            <section
              key={`${term.academic_year?.academic_year_id}-${term.semester?.semester_id}`}
              className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)] print:shadow-none"
            >
              <div className="flex items-center justify-between gap-3 px-5 py-3.5 bg-primary/[0.05] border-b border-primary/10">
                <div>
                  <p className="text-[15px] font-extrabold text-primary-dark">{term.academic_year?.year_name || '—'}</p>
                  <p className="text-[13px] font-semibold text-text-dark">{term.semester?.semester_name || '—'}</p>
                </div>
                <div className="text-left text-[12px] text-text-light">
                  <p>معدل الفصل: <span className="font-black text-text-dark">{formatGpa(term.term_gpa)}</span></p>
                  <p>الساعات المحتسبة: <span className="font-bold text-text-dark">{term.included_credit_hours ?? 0}</span></p>
                </div>
              </div>
              <CourseTable courses={term.courses ?? []} />
            </section>
          ))}
        </div>
      )}
    </div>
  )
}
