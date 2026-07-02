import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaSpinner, FaChalkboardTeacher, FaEye, FaSearch } from 'react-icons/fa'

const API = 'http://127.0.0.1:8000/api/v1'
function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

const RANK_AR = {
  'Professor': 'أستاذ', 'Associate Professor': 'أستاذ مشارك',
  'Assistant Professor': 'أستاذ مساعد', 'Lecturer': 'محاضر', 'Instructor': 'مدرس',
}

export default function FacultyPage() {
  const [faculty,  setFaculty]  = useState([])
  const [loading,  setLoading]  = useState(true)
  const [error,    setError]    = useState('')
  const [search,   setSearch]   = useState('')
  const navigate = useNavigate()

  useEffect(() => {
    fetch(`${API}/faculty-members?per_page=100`, { headers: authHeaders() })
      .then(r => r.json())
      .then(json => {
        if (json.success) setFaculty(json.data?.data ?? json.data ?? [])
        else setError(json.message || 'فشل التحميل')
      })
      .catch(() => setError('تعذّر الاتصال بالخادم'))
      .finally(() => setLoading(false))
  }, [])

  const filtered = search
    ? faculty.filter(f => {
        const name = `${f.employee?.first_name ?? ''} ${f.employee?.last_name ?? ''}`.toLowerCase()
        const spec  = (f.specialization ?? '').toLowerCase()
        const rank  = (f.academic_rank ?? '').toLowerCase()
        const q = search.toLowerCase()
        return name.includes(q) || spec.includes(q) || rank.includes(q)
      })
    : faculty

  return (
    <>
      <div className="mb-5" dir="rtl">
        <h2 className="text-[20px] font-black text-text-dark mb-[3px]">هيئة التدريس</h2>
        <p className="text-[12.5px] text-text-light">{faculty.length} عضو مسجل</p>
      </div>

      <div className="relative mb-5">
        <FaSearch className="absolute left-[15px] top-1/2 -translate-y-1/2 text-primary-light text-[14px] pointer-events-none" />
        <input
          className="w-full py-[13px] pr-4 pl-[42px] border-[1.5px] border-primary/20 rounded-[13px] bg-white text-[14px] text-text-dark outline-none focus:border-primary focus:shadow-[0_0_0_4px_rgba(86,153,51,0.1)] placeholder:text-text-light"
          placeholder="ابحث بالاسم أو التخصص أو الرتبة…"
          value={search} onChange={e => setSearch(e.target.value)} dir="rtl"
        />
        {search && (
          <button onClick={() => setSearch('')}
            className="absolute right-3.5 top-1/2 -translate-y-1/2 w-6 h-6 flex items-center justify-center rounded-full text-[18px] text-text-light hover:text-red-500 hover:bg-red-50 transition-colors">×</button>
        )}
      </div>

      {error && <div className="bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 mb-4 text-[13px] text-red-600" dir="rtl">⚠ {error}</div>}

      {loading ? (
        <div className="flex flex-col items-center justify-center gap-3 py-20 text-primary">
          <FaSpinner className="text-[32px] animate-spin" />
          <span className="text-[14px] text-text-light">جاري التحميل…</span>
        </div>
      ) : filtered.length === 0 ? (
        <div className="flex flex-col items-center justify-center gap-2 py-20">
          <FaChalkboardTeacher className="text-[48px] text-[#d1eab8] mb-2" />
          <p className="text-[16px] font-bold text-text-gray" dir="rtl">لا يوجد أعضاء تدريس</p>
        </div>
      ) : (
        <div className="grid grid-cols-2 max-[700px]:grid-cols-1 gap-4">
          {filtered.map(f => {
            const emp  = f.employee ?? {}
            const rank = RANK_AR[f.academic_rank] ?? f.academic_rank ?? '—'
            return (
              <div key={f.faculty_member_id}
                className="bg-white border border-primary/12 rounded-[16px] p-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)] hover:-translate-y-[2px] hover:shadow-[0_6px_20px_rgba(86,153,51,0.1)] transition-all duration-[220ms]"
              >
                <div className="flex items-start gap-3.5" dir="rtl">
                  <div className="w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center text-[20px] font-black text-primary flex-shrink-0">
                    {emp.first_name?.[0]?.toUpperCase() ?? '?'}
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center justify-between gap-2">
                      <p className="font-extrabold text-[14px] text-text-dark truncate">{emp.first_name} {emp.last_name}</p>
                      <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full flex-shrink-0 ${f.is_active ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-600'}`}>
                        {f.is_active ? 'نشط' : 'غير نشط'}
                      </span>
                    </div>
                    <p className="text-[12px] text-primary font-semibold mt-0.5">{rank}</p>
                    {f.specialization && <p className="text-[11.5px] text-text-light mt-0.5 truncate">{f.specialization}</p>}
                    {f.office_location && <p className="text-[11px] text-text-light mt-0.5">📍 {f.office_location}</p>}
                    {emp.email && <p className="text-[11px] text-text-light mt-0.5">✉ {emp.email}</p>}
                  </div>
                </div>
                <div className="mt-3 pt-3 border-t border-primary/8 flex justify-end">
                  <button
                    onClick={() => navigate(`/hr/employees/${f.employee_id}`)}
                    className="flex items-center gap-1.5 px-3.5 py-1.5 text-[12px] font-bold text-blue-600 border border-blue-200 bg-blue-50 rounded-[8px] hover:bg-blue-100 transition-colors"
                  >
                    <FaEye className="text-[11px]" /> عرض الملف
                  </button>
                </div>
              </div>
            )
          })}
        </div>
      )}
    </>
  )
}
