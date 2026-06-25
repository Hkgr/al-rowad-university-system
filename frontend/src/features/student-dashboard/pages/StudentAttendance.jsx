import { useState, useEffect } from 'react'
import { FaCalendarCheck, FaSpinner } from 'react-icons/fa'

const API = 'http://127.0.0.1:8000/api/v1'

function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

function getStudentId() {
  return JSON.parse(localStorage.getItem('user') || '{}').student_id
}

export default function StudentAttendance() {
  const [attendance, setAttendance] = useState(null)
  const [loading,    setLoading]    = useState(true)
  const [error,      setError]      = useState('')

  useEffect(() => {
    const id = getStudentId()
    if (!id) return
    fetch(`${API}/students/${id}/attendance`, { headers: authHeaders() })
      .then(r => r.json())
      .then(json => {
        if (json.success) setAttendance(json.data)
        else setError(json.message || 'فشل تحميل بيانات الحضور')
      })
      .catch(() => setError('تعذّر الاتصال بالخادم'))
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

  const courses = attendance?.courses ?? []

  return (
    <>
      <div className="flex items-center justify-between mb-5 flex-wrap gap-3" dir="rtl">
        <div>
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">الحضور والغياب</h2>
          <p className="text-[12.5px] text-text-light">{courses.length} مقرر</p>
        </div>
        {courses.some(c => c.deprivation_status === 'deprived') && (
          <div className="flex items-center gap-2 bg-red-500/8 border border-red-500/25 rounded-[10px] px-3.5 py-2 text-[12.5px] text-red-600 font-bold" dir="rtl">
            ⚠ لديك مقررات محرومة — راجع شؤون الطلاب
          </div>
        )}
      </div>

      {courses.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-20 gap-2">
          <FaCalendarCheck className="text-[48px] text-primary/15 mb-2" />
          <p className="text-[14px] font-semibold text-text-light" dir="rtl">لا توجد بيانات حضور بعد</p>
        </div>
      ) : (
        <div className="space-y-4">
          {courses.map((c, i) => {
            const pct      = c.absence_percentage || 0
            const deprived = c.deprivation_status === 'deprived'
            const warning  = !deprived && pct > 10
            const presentPct = c.total_sessions > 0 ? ((c.present_count / c.total_sessions) * 100) : 0

            return (
              <div
                key={i}
                className={`bg-white border rounded-[16px] p-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)] ${deprived ? 'border-red-500/30' : warning ? 'border-amber-500/25' : 'border-primary/12'}`}
              >
                {/* Header */}
                <div className="flex items-start justify-between gap-3 mb-4" dir="rtl">
                  <div>
                    <div className="font-bold text-[14.5px] text-text-dark">{c.course_name}</div>
                    <div className="text-[11.5px] text-text-light font-mono mt-0.5">
                      {c.course_code} — {c.academic_year?.year_name} / {c.semester?.semester_name}
                    </div>
                  </div>
                  {deprived && <span className="flex-shrink-0 px-2.5 py-1 bg-red-500/10 border border-red-500/25 text-red-600 text-[11.5px] font-bold rounded-full">محروم</span>}
                  {warning  && <span className="flex-shrink-0 px-2.5 py-1 bg-amber-500/10 border border-amber-500/25 text-amber-700 text-[11.5px] font-bold rounded-full">تحذير غياب</span>}
                </div>

                {/* Attendance bar */}
                <div className="mb-3">
                  <div className="flex items-center justify-between text-[12px] mb-1.5" dir="rtl">
                    <span className="text-text-gray">نسبة الحضور</span>
                    <span className={`font-bold ${deprived ? 'text-red-600' : warning ? 'text-amber-600' : 'text-primary'}`}>
                      {presentPct.toFixed(1)}%
                    </span>
                  </div>
                  <div className="h-2.5 bg-gray-100 rounded-full overflow-hidden">
                    <div
                      className={`h-full rounded-full transition-all duration-500 ${deprived ? 'bg-red-400' : warning ? 'bg-amber-400' : 'bg-primary'}`}
                      style={{ width: `${Math.min(presentPct, 100)}%` }}
                    />
                  </div>
                </div>

                {/* Stats */}
                <div className="grid grid-cols-4 max-[500px]:grid-cols-2 gap-3 mt-3">
                  {[
                    { label: 'إجمالي', value: c.total_sessions, color: 'text-text-dark' },
                    { label: 'حضور',   value: c.present_count,  color: 'text-green-600' },
                    { label: 'غياب',   value: c.absent_count,   color: 'text-red-500'   },
                    { label: '% الغياب', value: `${Number(pct).toFixed(1)}%`, color: deprived ? 'text-red-600' : warning ? 'text-amber-600' : 'text-text-dark' },
                  ].map(stat => (
                    <div key={stat.label} className="flex flex-col items-center bg-gray-50 border border-gray-100 rounded-[10px] py-2.5" dir="rtl">
                      <span className={`text-[18px] font-black ${stat.color}`}>{stat.value}</span>
                      <span className="text-[10.5px] text-text-light mt-0.5">{stat.label}</span>
                    </div>
                  ))}
                </div>
              </div>
            )
          })}
        </div>
      )}
    </>
  )
}
