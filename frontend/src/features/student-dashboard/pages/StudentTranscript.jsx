import { useState, useEffect } from 'react'
import { motion } from 'framer-motion'
import { FaGraduationCap, FaSpinner } from 'react-icons/fa'

const API = 'http://127.0.0.1:8000/api/v1'

function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

function getStudentId() {
  return JSON.parse(localStorage.getItem('user') || '{}').student_id
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

export default function StudentTranscript() {
  const [transcript, setTranscript] = useState(null)
  const [cgpa,       setCgpa]       = useState(null)
  const [loading,    setLoading]    = useState(true)
  const [error,      setError]      = useState('')

  useEffect(() => {
    const id = getStudentId()
    if (!id) return
    Promise.all([
      fetch(`${API}/students/${id}/transcript`, { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/students/${id}/cgpa`,       { headers: authHeaders() }).then(r => r.json()),
    ]).then(([trans, cgpaRes]) => {
      if (trans.success)   setTranscript(trans.data)
      else                 setError(trans.message || 'فشل تحميل كشف الدرجات')
      if (cgpaRes.success) setCgpa(cgpaRes.data)
    }).catch(() => setError('تعذّر الاتصال بالخادم'))
      .finally(() => setLoading(false))
  }, [])

  if (loading) return (
    <div className="flex items-center justify-center py-24 gap-3 text-primary-light">
      <FaSpinner className="text-[26px] animate-[spin_0.7s_linear_infinite]" />
    </div>
  )

  if (error) return (
    <div className="flex items-center justify-center py-20 text-red-600 text-[14px]" dir="rtl">⚠ {error}</div>
  )

  const terms = transcript?.terms ?? []

  return (
    <>
      <div className="flex items-center justify-between mb-5 flex-wrap gap-3" dir="rtl">
        <div>
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">كشف الدرجات</h2>
          <p className="text-[12.5px] text-text-light">{terms.length} فصل دراسي</p>
        </div>
        {cgpa && (
          <div className="flex items-center gap-3 bg-primary/[0.05] border border-primary/15 rounded-[12px] px-5 py-2.5" dir="rtl">
            <span className="text-[11px] text-text-light font-medium">المعدل التراكمي</span>
            <span className="text-[22px] font-black text-primary">{Number(cgpa.cgpa).toFixed(2)}</span>
          </div>
        )}
      </div>

      {terms.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-20 gap-2">
          <FaGraduationCap className="text-[48px] text-primary/15 mb-2" />
          <p className="text-[14px] font-semibold text-text-light" dir="rtl">لا توجد بيانات دراسية بعد</p>
        </div>
      ) : (
        <div className="space-y-5">
          {terms.map((term, ti) => {
            const termHours = term.courses.reduce((s, c) => s + (c.credit_hours || 0), 0)
            return (
              <motion.div
                key={ti}
                className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_12px_rgba(26,46,16,0.05)]"
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.3, delay: ti * 0.07 }}
              >
                <div className="flex items-center justify-between px-5 py-3.5 bg-primary/[0.05] border-b border-primary/10" dir="rtl">
                  <div className="flex items-center gap-2">
                    <span className="text-[14.5px] font-extrabold text-primary-dark">{term.academic_year?.year_name}</span>
                    <span className="text-primary/30">•</span>
                    <span className="text-[13px] font-semibold text-text-dark">{term.semester?.semester_name}</span>
                  </div>
                  <span className="text-[12px] text-text-light font-medium">{termHours} ساعة</span>
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
                        <tr key={ci} className="border-t border-primary/6 hover:bg-primary/[0.02] transition-colors">
                          <td className="px-4 py-3" dir="rtl">
                            <div className="font-semibold text-text-dark">{c.course_name}</div>
                            <div className="text-[11px] text-text-light font-mono mt-0.5">{c.course_code}</div>
                          </td>
                          <td className="px-4 py-3 text-center font-bold text-text-dark">{c.credit_hours}</td>
                          <td className="px-4 py-3 text-center text-text-gray">{c.theoretical_mark ?? '—'}</td>
                          <td className="px-4 py-3 text-center text-text-gray">{c.practical_mark ?? '—'}</td>
                          <td className="px-4 py-3 text-center font-bold text-text-dark">{c.final_mark ?? '—'}</td>
                          <td className={`px-4 py-3 text-center text-[16px] font-black ${gradeColor(c.letter_grade)}`}>
                            {c.letter_grade || '—'}
                          </td>
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
              </motion.div>
            )
          })}
        </div>
      )}
    </>
  )
}
