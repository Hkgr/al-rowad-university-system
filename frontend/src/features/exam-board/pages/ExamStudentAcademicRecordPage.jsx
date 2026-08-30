import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import {
  FaArrowRight,
  FaFilePdf,
  FaGraduationCap,
  FaRedo,
  FaSpinner,
  FaUserGraduate,
} from 'react-icons/fa'
import AcademicRequirementProgress, {
  AcademicRequirementProgressSkeleton,
} from '../../../components/academic/AcademicRequirementProgress'
import CourseRequirementBadges from '../../../components/academic/CourseRequirementBadges'
import { apiRequest } from '../../../services/apiClient'
import { exportTranscriptPdf } from '../lib/transcriptPdf'

function value(item) {
  return item === null || item === undefined || item === '' ? '—' : item
}

function formatMark(mark) {
  if (mark === null || mark === undefined || mark === '') return '—'
  const number = Number(mark)
  return Number.isFinite(number) ? String(parseFloat(number.toFixed(2))) : '—'
}

function statusClass(code) {
  if (code === 'passed') return 'bg-green-100 text-green-800 border-green-200'
  if (code === 'failed') return 'bg-red-100 text-red-700 border-red-200'
  if (code === 'deprived') return 'bg-orange-100 text-orange-900 border-orange-200'
  return 'bg-gray-100 text-text-gray border-gray-200'
}

function IdentityCard({ student }) {
  const fields = [
    ['الكلية', student?.college?.college_name],
    ['القسم', student?.department?.department_name],
    ['البرنامج الأكاديمي', student?.program?.program_name],
    ['المستوى الأكاديمي', student?.academic_level?.level_name],
    ['حالة الطالب', student?.student_status?.status_name],
  ]

  return (
    <section className="rounded-[20px] border border-primary/15 bg-[linear-gradient(135deg,rgba(86,153,51,0.10),rgba(255,255,255,0.97))] p-6 shadow-[0_3px_18px_rgba(26,46,16,0.06)]">
      <div className="mb-5 flex items-center gap-3">
        <div className="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary">
          <FaUserGraduate className="text-[20px]" aria-hidden="true" />
        </div>
        <div>
          <h1 className="text-[21px] font-black text-text-dark">{value(student?.full_name)}</h1>
          <p className="mt-0.5 font-mono text-[12px] text-text-light">{value(student?.student_number)}</p>
        </div>
      </div>
      <dl className="grid grid-cols-5 gap-x-6 gap-y-4 max-[1100px]:grid-cols-3 max-[720px]:grid-cols-2 max-[460px]:grid-cols-1">
        {fields.map(([label, item]) => (
          <div key={label}>
            <dt className="mb-1 text-[11px] font-bold text-text-light">{label}</dt>
            <dd className="text-[13.5px] font-bold text-text-dark">{value(item)}</dd>
          </div>
        ))}
      </dl>
    </section>
  )
}

function AcademicSummary({ summary = {} }) {
  const metrics = [
    ['المعدل التراكمي', summary.cgpa == null ? '—' : Number(summary.cgpa).toFixed(2)],
    ['الساعات المحاولة', summary.total_attempted_credit_hours ?? 0],
    ['الساعات المجتازة', summary.total_passed_credit_hours ?? 0],
    ['المقررات الناجحة', summary.passed_courses_count ?? 0],
    ['المقررات الراسبة', summary.failed_courses_count ?? 0],
    ['المقررات المحروم منها', summary.deprived_courses_count ?? 0],
  ]

  return (
    <section>
      <h2 className="mb-3 text-[17px] font-black text-text-dark">الملخص الأكاديمي</h2>
      <div className="grid grid-cols-6 gap-3 max-[1100px]:grid-cols-3 max-[620px]:grid-cols-2">
        {metrics.map(([label, item], index) => (
          <article key={label} className="rounded-[16px] border border-primary/12 bg-white px-4 py-4 shadow-[0_2px_10px_rgba(26,46,16,0.04)]">
            <p className="text-[11.5px] font-bold text-text-light">{label}</p>
            <p className={`mt-2 text-[24px] font-black tabular-nums ${index === 0 ? 'text-primary' : 'text-text-dark'}`}>{item}</p>
          </article>
        ))}
      </div>
    </section>
  )
}

function TranscriptTable({ courses }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[820px] border-collapse text-[13px]">
        <thead>
          <tr className="bg-[#fafaf8]">
            {['المقرر', 'التصنيف الأكاديمي', 'الساعات', 'العملي', 'النظري', 'العلامة النهائية', 'التقدير', 'الحالة'].map(label => (
              <th key={label} className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light first:text-right">{label}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {courses.map(course => {
            const code = course.result_status?.status_code
            return (
              <tr key={course.registration_id} className="border-t border-primary/8">
                <td className="px-4 py-3"><p className="font-bold text-text-dark">{value(course.course_name)}</p><p className="mt-0.5 font-mono text-[11px] text-text-light">{value(course.course_code)}</p></td>
                <td className="px-3 py-3"><CourseRequirementBadges classification={course.requirement_classification} compact /></td>
                <td className="px-3 py-3 text-center font-bold">{value(course.credit_hours)}</td>
                <td className="px-3 py-3 text-center">{formatMark(course.practical_mark)}</td>
                <td className="px-3 py-3 text-center">{formatMark(course.theoretical_mark)}</td>
                <td className="px-3 py-3 text-center font-black">{formatMark(course.final_mark)}</td>
                <td className="px-3 py-3 text-center text-[15px] font-black">{value(course.letter_grade)}</td>
                <td className="px-3 py-3 text-center"><span className={`inline-flex rounded-full border px-2.5 py-0.5 text-[11px] font-bold ${statusClass(code)}`}>{value(course.result_status?.status_name)}</span></td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

function TranscriptSection({ transcript }) {
  const terms = transcript?.terms ?? []
  return (
    <section>
      <h2 className="mb-3 text-[17px] font-black text-text-dark">سجل الدرجات المعتمدة</h2>
      {terms.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-[18px] border border-primary/12 bg-white py-14">
          <FaGraduationCap className="text-[38px] text-primary/20" aria-hidden="true" />
          <p className="text-[13px] text-text-light">لا توجد نتائج معتمدة متاحة حتى الآن.</p>
        </div>
      ) : (
        <div className="space-y-4">
          {terms.map((term, index) => (
            <article key={`${term.academic_year?.academic_year_id}-${term.semester?.semester_id}-${index}`} className="overflow-hidden rounded-[16px] border border-primary/12 bg-white shadow-[0_2px_10px_rgba(26,46,16,0.04)]">
              <header className="flex items-center justify-between gap-3 border-b border-primary/10 bg-primary/[0.05] px-5 py-3.5">
                <div><p className="text-[15px] font-black text-primary-dark">{value(term.academic_year?.year_name)}</p><p className="text-[12.5px] font-bold text-text-dark">{value(term.semester?.semester_name)}</p></div>
                <div className="text-left text-[12px] text-text-light"><p>المعدل الفصلي: <strong className="text-text-dark">{term.term_gpa == null ? '—' : Number(term.term_gpa).toFixed(2)}</strong></p><p>الساعات المحتسبة: <strong className="text-text-dark">{term.included_credit_hours ?? 0}</strong></p></div>
              </header>
              <TranscriptTable courses={term.courses ?? []} />
            </article>
          ))}
        </div>
      )}
    </section>
  )
}

export default function ExamStudentAcademicRecordPage() {
  const { studentId } = useParams()
  const navigate = useNavigate()
  const [record, setRecord] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [reloadKey, setReloadKey] = useState(0)
  const [pdfLoading, setPdfLoading] = useState(false)
  const [pdfError, setPdfError] = useState('')
  const pdfExporting = useRef(false)
  const endpoint = `/v1/students/${studentId}/academic-record`

  useEffect(() => {
    let active = true
    setLoading(true)
    setError('')
    setPdfError('')
    apiRequest(endpoint)
      .then(response => { if (active) setRecord(response?.data ?? null) })
      .catch(requestError => {
        if (!active) return
        setRecord(null)
        setError(
          requestError.status === 403
            ? 'ليس لديك صلاحية للوصول إلى السجل الأكاديمي لهذا الطالب.'
            : requestError.status === 404
              ? 'لم يتم العثور على سجل الطالب المطلوب.'
              : 'تعذّر تحميل السجل الأكاديمي. يرجى المحاولة مجدداً.',
        )
      })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [endpoint, reloadKey])

  const exportPdf = useCallback(async () => {
    if (pdfExporting.current) return
    pdfExporting.current = true
    setPdfLoading(true)
    setPdfError('')
    try {
      const fresh = await apiRequest(endpoint)
      if (!fresh?.data) throw new Error('academic_record_missing')
      setRecord(fresh.data)
      await exportTranscriptPdf({ academicRecord: fresh.data })
    } catch {
      setPdfError('تعذّر إنشاء كشف العلامات الإلكتروني. يرجى المحاولة مجدداً.')
    } finally {
      pdfExporting.current = false
      setPdfLoading(false)
    }
  }, [endpoint])

  return (
    <div className="space-y-6" dir="rtl">
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <button type="button" onClick={() => navigate('/exam-board/grade-sheet')} className="inline-flex items-center gap-2 rounded-[10px] border border-primary/20 px-3.5 py-2 text-[12.5px] font-bold text-primary-dark hover:bg-primary/6"><FaArrowRight aria-hidden="true" />العودة إلى كشوف الدرجات</button>
        {record ? <button type="button" onClick={exportPdf} disabled={pdfLoading} className="inline-flex items-center gap-2 rounded-[10px] bg-primary px-4 py-2.5 text-[12.5px] font-black text-white hover:bg-primary-dark disabled:opacity-55">{pdfLoading ? <FaSpinner className="animate-spin" /> : <FaFilePdf />}{pdfLoading ? 'جاري إنشاء الملف...' : 'استخراج كشف العلامات الإلكتروني'}</button> : null}
      </div>

      {pdfError ? <p className="rounded-[12px] border border-red-200 bg-red-50 px-4 py-3 text-[12.5px] text-red-700" role="alert">⚠ {pdfError}</p> : null}
      {loading ? <><div className="h-48 animate-pulse rounded-[20px] bg-primary/10" /><AcademicRequirementProgressSkeleton /></> : null}
      {!loading && error ? <section className="rounded-[18px] border border-red-200 bg-white px-6 py-12 text-center" role="alert"><p className="mb-4 text-[14px] font-bold text-red-700">{error}</p><button type="button" onClick={() => setReloadKey(key => key + 1)} className="inline-flex items-center gap-2 rounded-[10px] border border-red-200 px-4 py-2 text-[12.5px] font-bold text-red-700"><FaRedo />إعادة المحاولة</button></section> : null}

      {!loading && !error && record ? (
        <>
          <IdentityCard student={record.student} />
          <AcademicSummary summary={record.transcript?.summary} />
          <section>
            <div className="mb-3"><h2 className="text-[17px] font-black text-text-dark">التقدم في الخطة الدراسية</h2><p className="mt-1 text-[12.5px] text-text-light">القيم محسوبة من خدمات المتطلبات والتخرج الرسمية.</p></div>
            {record.requirements?.status === 'available'
              ? <AcademicRequirementProgress progress={record.requirements.progress} eligibility={record.requirements.graduation_eligibility} />
              : <div className="rounded-[16px] border border-amber-200 bg-amber-50 px-5 py-5 text-[13px] leading-7 text-amber-900">تعذّر عرض تقدم الخطة بسبب إعداد أكاديمي يحتاج إلى مراجعة. يبقى سجل الدرجات المعتمد متاحاً.</div>}
          </section>
          <TranscriptSection transcript={record.transcript} />
        </>
      ) : null}
    </div>
  )
}
