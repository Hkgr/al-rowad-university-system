import { useRef, useState } from 'react'
import { FaFilePdf, FaGraduationCap, FaSpinner } from 'react-icons/fa'
import CourseRequirementBadges from '../../../components/academic/CourseRequirementBadges'
import { apiRequest } from '../../../services/apiClient'
import StudentPicker from '../components/StudentPicker'
import { exportTranscriptPdf } from '../lib/transcriptPdf'

function gradeColor(letter) {
  if (!letter) return 'text-text-gray'
  const normalized = letter.toUpperCase()
  if (normalized.startsWith('A')) return 'text-green-600'
  if (normalized.startsWith('B')) return 'text-blue-600'
  if (normalized.startsWith('C')) return 'text-amber-600'
  if (normalized.startsWith('D')) return 'text-orange-500'
  return 'text-red-600'
}

export default function GradeSheetPage() {
  const [selected, setSelected] = useState(null)
  const [transcript, setTranscript] = useState(null)
  const [cgpa, setCgpa] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [pdfLoading, setPdfLoading] = useState(false)
  const [pdfError, setPdfError] = useState('')
  const pdfExporting = useRef(false)

  async function handleSelect(student) {
    setSelected(student)
    setTranscript(null)
    setCgpa(null)
    setError('')
    setPdfError('')
    setLoading(true)
    try {
      const [transcriptResponse, cgpaResponse] = await Promise.all([
        apiRequest(`/v1/students/${student.student_id}/transcript`),
        apiRequest(`/v1/students/${student.student_id}/cgpa`).catch(() => null),
      ])
      if (transcriptResponse?.success === false || !transcriptResponse?.data) {
        throw new Error(transcriptResponse?.message || 'فشل تحميل كشف الدرجات')
      }
      setTranscript(transcriptResponse.data)
      if (cgpaResponse?.success !== false && cgpaResponse?.data) setCgpa(cgpaResponse.data)
    } catch (requestError) {
      setError(requestError?.message || 'تعذّر الاتصال بالخادم')
    } finally {
      setLoading(false)
    }
  }

  async function handlePdfExport() {
    if (!transcript || pdfExporting.current) return
    pdfExporting.current = true
    setPdfLoading(true)
    setPdfError('')
    try {
      await exportTranscriptPdf({ transcript, selectedStudent: selected })
    } catch {
      setPdfError('تعذّر إنشاء ملف كشف الدرجات. يرجى المحاولة مجددًا.')
    } finally {
      pdfExporting.current = false
      setPdfLoading(false)
    }
  }

  const terms = transcript?.terms ?? []

  return (
    <>
      <div className="mb-5" dir="rtl">
        <h2 className="text-[20px] font-black text-text-dark mb-[3px]">كشوف الدرجات</h2>
        <p className="text-[12.5px] text-text-light">Grade Sheets</p>
      </div>

      <StudentPicker onSelect={handleSelect} selected={selected} />

      {loading && (
        <div className="flex justify-center py-16 text-primary">
          <FaSpinner className="animate-spin text-[28px]" />
        </div>
      )}

      {error && <p className="text-center text-red-600 text-[13px] py-8" dir="rtl">⚠ {error}</p>}

      {transcript && !loading && (
        <>
          <div className="flex items-center justify-between mb-4 flex-wrap gap-3" dir="rtl">
            <div>
              <span className="text-[15px] font-extrabold text-text-dark">{selected?.first_name} {selected?.last_name}</span>
              <span className="mr-2 text-[12px] text-text-light font-mono">{selected?.student_number}</span>
            </div>
            <div className="flex items-center gap-2 flex-wrap">
              {cgpa && (
                <div className="flex items-center gap-2 bg-primary/[0.05] border border-primary/15 rounded-[10px] px-4 py-2">
                  <span className="text-[11px] text-text-light">المعدل التراكمي</span>
                  <span className="text-[20px] font-black text-primary">
                    {cgpa.cgpa === null || cgpa.cgpa === undefined ? '—' : Number(cgpa.cgpa).toFixed(2)}
                  </span>
                </div>
              )}
              <button
                type="button"
                onClick={handlePdfExport}
                disabled={pdfLoading}
                className="inline-flex items-center gap-2 rounded-[10px] bg-primary px-4 py-2.5 text-[12px] font-extrabold text-white hover:bg-primary-dark disabled:cursor-not-allowed disabled:opacity-60"
              >
                {pdfLoading ? <FaSpinner className="animate-spin" /> : <FaFilePdf />}
                {pdfLoading ? 'جاري إنشاء الملف...' : 'استخراج كشف PDF'}
              </button>
            </div>
          </div>

          {pdfError && <p className="mb-4 text-[12px] text-red-600" dir="rtl">⚠ {pdfError}</p>}

          {terms.length === 0 ? (
            <div className="flex flex-col items-center py-16 gap-2">
              <FaGraduationCap className="text-[42px] text-primary/15" />
              <p className="text-[13px] text-text-light" dir="rtl">لا توجد بيانات دراسية</p>
            </div>
          ) : (
            <div className="space-y-4">
              {terms.map((term, termIndex) => (
                <div key={termIndex} className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
                  <div className="flex items-center justify-between px-5 py-3 bg-primary/[0.05] border-b border-primary/10" dir="rtl">
                    <div className="flex items-center gap-2">
                      <span className="text-[14px] font-extrabold text-primary-dark">{term.academic_year?.year_name}</span>
                      <span className="text-primary/30">•</span>
                      <span className="text-[13px] font-semibold text-text-dark">{term.semester?.semester_name}</span>
                    </div>
                    <span className="text-[12px] text-text-light">{term.courses.reduce((sum, course) => sum + (course.credit_hours || 0), 0)} ساعة</span>
                  </div>
                  <div className="overflow-x-auto">
                    <table className="w-full border-collapse text-[13px]">
                      <thead>
                        <tr className="bg-[#fafaf8]">
                          <th className="px-4 py-2.5 text-right text-[11px] font-bold text-text-light" dir="rtl">المقرر</th>
                          <th className="px-4 py-2.5 text-right text-[11px] font-bold text-text-light" dir="rtl">التصنيف الأكاديمي</th>
                          <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">الساعات</th>
                          <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">نظري</th>
                          <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">عملي</th>
                          <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">المجموع</th>
                          <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">التقدير</th>
                          <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">الحالة</th>
                        </tr>
                      </thead>
                      <tbody>
                        {term.courses.map((course, courseIndex) => (
                          <tr key={courseIndex} className="border-t border-primary/6 hover:bg-primary/[0.02]">
                            <td className="px-4 py-3" dir="rtl">
                              <div className="font-semibold text-text-dark">{course.course_name}</div>
                              <div className="text-[11px] text-text-light font-mono mt-0.5">{course.course_code}</div>
                            </td>
                            <td className="px-4 py-3" dir="rtl"><CourseRequirementBadges classification={course.requirement_classification} compact /></td>
                            <td className="px-4 py-3 text-center font-bold text-text-dark">{course.credit_hours}</td>
                            <td className="px-4 py-3 text-center text-text-gray">{course.theoretical_mark ?? '—'}</td>
                            <td className="px-4 py-3 text-center text-text-gray">{course.practical_mark ?? '—'}</td>
                            <td className="px-4 py-3 text-center font-bold text-text-dark">{course.final_mark ?? '—'}</td>
                            <td className={`px-4 py-3 text-center text-[16px] font-black ${gradeColor(course.letter_grade)}`}>{course.letter_grade || '—'}</td>
                            <td className="px-4 py-3 text-center">
                              <span className={`inline-block px-2.5 py-0.5 rounded-full text-[11px] font-bold border ${course.result_status?.status_code === 'passed' ? 'bg-green-500/10 text-green-700 border-green-500/25' : 'bg-red-500/10 text-red-600 border-red-500/25'}`} dir="rtl">
                                {course.result_status?.status_code === 'passed' ? 'ناجح' : (course.result_status?.status_name || '—')}
                              </span>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              ))}
            </div>
          )}
        </>
      )}
    </>
  )
}
