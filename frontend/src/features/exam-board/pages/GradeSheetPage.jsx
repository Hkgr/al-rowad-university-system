import { useState } from 'react'
import { FaSpinner, FaGraduationCap } from 'react-icons/fa'
import StudentPicker from '../components/StudentPicker'

const API = 'http://127.0.0.1:8000/api/v1'
function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

function gradeColor(letter) {
  if (!letter) return 'text-text-gray'
  const l = letter.toUpperCase()
  if (l.startsWith('A')) return 'text-green-600'
  if (l.startsWith('B')) return 'text-blue-600'
  if (l.startsWith('C')) return 'text-amber-600'
  if (l.startsWith('D')) return 'text-orange-500'
  return 'text-red-600'
}

export default function GradeSheetPage() {
  const [selected,   setSelected]   = useState(null)
  const [transcript, setTranscript] = useState(null)
  const [cgpa,       setCgpa]       = useState(null)
  const [loading,    setLoading]    = useState(false)
  const [error,      setError]      = useState('')

  function handleSelect(student) {
    setSelected(student)
    setTranscript(null)
    setCgpa(null)
    setError('')
    setLoading(true)
    const id = student.student_id
    Promise.all([
      fetch(`${API}/students/${id}/transcript`, { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/students/${id}/cgpa`,       { headers: authHeaders() }).then(r => r.json()),
    ]).then(([trans, cgpaRes]) => {
      if (trans.success)   setTranscript(trans.data)
      else                 setError(trans.message || 'فشل تحميل كشف الدرجات')
      if (cgpaRes.success) setCgpa(cgpaRes.data)
    }).catch(() => setError('تعذّر الاتصال بالخادم'))
      .finally(() => setLoading(false))
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
              <span className="text-[15px] font-extrabold text-text-dark">{selected.first_name} {selected.last_name}</span>
              <span className="mr-2 text-[12px] text-text-light font-mono">{selected.student_number}</span>
            </div>
            {cgpa && (
              <div className="flex items-center gap-2 bg-primary/[0.05] border border-primary/15 rounded-[10px] px-4 py-2">
                <span className="text-[11px] text-text-light">المعدل التراكمي</span>
                <span className="text-[20px] font-black text-primary">{Number(cgpa.cgpa).toFixed(2)}</span>
              </div>
            )}
          </div>

          {terms.length === 0 ? (
            <div className="flex flex-col items-center py-16 gap-2">
              <FaGraduationCap className="text-[42px] text-primary/15" />
              <p className="text-[13px] text-text-light" dir="rtl">لا توجد بيانات دراسية</p>
            </div>
          ) : (
            <div className="space-y-4">
              {terms.map((term, ti) => (
                <div key={ti} className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
                  <div className="flex items-center justify-between px-5 py-3 bg-primary/[0.05] border-b border-primary/10" dir="rtl">
                    <div className="flex items-center gap-2">
                      <span className="text-[14px] font-extrabold text-primary-dark">{term.academic_year?.year_name}</span>
                      <span className="text-primary/30">•</span>
                      <span className="text-[13px] font-semibold text-text-dark">{term.semester?.semester_name}</span>
                    </div>
                    <span className="text-[12px] text-text-light">{term.courses.reduce((s, c) => s + (c.credit_hours || 0), 0)} ساعة</span>
                  </div>
                  <div className="overflow-x-auto">
                    <table className="w-full border-collapse text-[13px]">
                      <thead>
                        <tr className="bg-[#fafaf8]">
                          <th className="px-4 py-2.5 text-right text-[11px] font-bold text-text-light" dir="rtl">المقرر</th>
                          <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">الساعات</th>
                          <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">نظري</th>
                          <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">عملي</th>
                          <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">المجموع</th>
                          <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">التقدير</th>
                          <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">الحالة</th>
                        </tr>
                      </thead>
                      <tbody>
                        {term.courses.map((c, ci) => (
                          <tr key={ci} className="border-t border-primary/6 hover:bg-primary/[0.02]">
                            <td className="px-4 py-3" dir="rtl">
                              <div className="font-semibold text-text-dark">{c.course_name}</div>
                              <div className="text-[11px] text-text-light font-mono mt-0.5">{c.course_code}</div>
                            </td>
                            <td className="px-4 py-3 text-center font-bold text-text-dark">{c.credit_hours}</td>
                            <td className="px-4 py-3 text-center text-text-gray">{c.theoretical_mark ?? '—'}</td>
                            <td className="px-4 py-3 text-center text-text-gray">{c.practical_mark ?? '—'}</td>
                            <td className="px-4 py-3 text-center font-bold text-text-dark">{c.final_mark ?? '—'}</td>
                            <td className={`px-4 py-3 text-center text-[16px] font-black ${gradeColor(c.letter_grade)}`}>{c.letter_grade || '—'}</td>
                            <td className="px-4 py-3 text-center">
                              <span className={`inline-block px-2.5 py-0.5 rounded-full text-[11px] font-bold border ${c.result_status?.status_code === 'passed' ? 'bg-green-500/10 text-green-700 border-green-500/25' : 'bg-red-500/10 text-red-600 border-red-500/25'}`} dir="rtl">
                                {c.result_status?.status_code === 'passed' ? 'ناجح' : (c.result_status?.status_name || '—')}
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
