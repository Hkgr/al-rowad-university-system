import { useState } from 'react'
import { FaSpinner, FaCalendarCheck } from 'react-icons/fa'
import StudentPicker from '../components/StudentPicker'

const API = 'http://127.0.0.1:8000/api/v1'
function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

export default function DeprivationPage() {
  const [selected,   setSelected]   = useState(null)
  const [attendance, setAttendance] = useState(null)
  const [loading,    setLoading]    = useState(false)
  const [error,      setError]      = useState('')

  function handleSelect(student) {
    setSelected(student)
    setAttendance(null)
    setError('')
    setLoading(true)
    fetch(`${API}/students/${student.student_id}/attendance`, { headers: authHeaders() })
      .then(r => r.json())
      .then(json => {
        if (json.success) setAttendance(json.data)
        else setError(json.message || 'فشل تحميل بيانات الحضور')
      })
      .catch(() => setError('تعذّر الاتصال بالخادم'))
      .finally(() => setLoading(false))
  }

  const courses = attendance?.courses ?? []

  return (
    <>
      <div className="mb-5" dir="rtl">
        <h2 className="text-[20px] font-black text-text-dark mb-[3px]">الحضور والحرمان</h2>
        <p className="text-[12.5px] text-text-light">Attendance & Deprivation</p>
      </div>

      <StudentPicker onSelect={handleSelect} selected={selected} />

      {loading && (
        <div className="flex justify-center py-16 text-primary">
          <FaSpinner className="animate-spin text-[28px]" />
        </div>
      )}

      {error && <p className="text-center text-red-600 text-[13px] py-8" dir="rtl">⚠ {error}</p>}

      {attendance && !loading && (
        <>
          <div className="flex items-center justify-between mb-4 flex-wrap gap-3" dir="rtl">
            <div>
              <span className="text-[15px] font-extrabold text-text-dark">{selected.first_name} {selected.last_name}</span>
              <span className="mr-2 text-[12px] text-text-light font-mono">{selected.student_number}</span>
            </div>
            {courses.some(c => c.deprivation_status === 'deprived') && (
              <div className="flex items-center gap-2 bg-red-500/8 border border-red-500/25 rounded-[10px] px-3.5 py-2 text-[12.5px] text-red-600 font-bold" dir="rtl">
                ⚠ لديه مقررات محرومة
              </div>
            )}
          </div>

          {courses.length === 0 ? (
            <div className="flex flex-col items-center py-16 gap-2">
              <FaCalendarCheck className="text-[42px] text-primary/15" />
              <p className="text-[13px] text-text-light" dir="rtl">لا توجد بيانات حضور</p>
            </div>
          ) : (
            <div className="space-y-4">
              {courses.map((c, i) => {
                const pct        = c.absence_percentage || 0
                const deprived   = c.deprivation_status === 'deprived'
                const warning    = !deprived && pct > 10
                const presentPct = c.total_sessions > 0 ? (c.present_count / c.total_sessions) * 100 : 0

                return (
                  <div
                    key={i}
                    className={`bg-white border rounded-[16px] p-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)] ${deprived ? 'border-red-500/30' : warning ? 'border-amber-500/25' : 'border-primary/12'}`}
                  >
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

                    <div className="grid grid-cols-4 max-[500px]:grid-cols-2 gap-3">
                      {[
                        { label: 'إجمالي',     value: c.total_sessions,            color: 'text-text-dark'   },
                        { label: 'حضور',       value: c.present_count,             color: 'text-green-600'   },
                        { label: 'غياب',       value: c.absent_count,              color: 'text-red-500'     },
                        { label: '% الغياب',   value: `${Number(pct).toFixed(1)}%`, color: deprived ? 'text-red-600' : warning ? 'text-amber-600' : 'text-text-dark' },
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
      )}
    </>
  )
}
