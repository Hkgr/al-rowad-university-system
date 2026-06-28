import { useState, useEffect } from 'react'
import { FaSearch, FaSpinner, FaUserGraduate } from 'react-icons/fa'

const API = 'http://127.0.0.1:8000/api/v1'
function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

export default function StudentPicker({ onSelect, selected }) {
  const [students, setStudents] = useState([])
  const [query,    setQuery]    = useState('')
  const [loading,  setLoading]  = useState(true)

  useEffect(() => {
    fetch(`${API}/students?per_page=200`, { headers: authHeaders() })
      .then(r => r.json())
      .then(json => {
        if (json.success) setStudents(json.data?.data ?? json.data ?? [])
      })
      .finally(() => setLoading(false))
  }, [])

  const filtered = students.filter(s => {
    const q = query.trim().toLowerCase()
    if (!q) return true
    const name = `${s.first_name} ${s.last_name}`.toLowerCase()
    return name.includes(q) || (s.student_number || '').toLowerCase().includes(q)
  })

  return (
    <div className="bg-white border border-primary/12 rounded-[18px] p-5 mb-6 shadow-[0_2px_12px_rgba(26,46,16,0.05)]">
      <div className="flex items-center gap-2 mb-4" dir="rtl">
        <FaUserGraduate className="text-primary text-[16px]" />
        <span className="text-[14.5px] font-extrabold text-text-dark">اختر الطالب</span>
        <span className="text-[11px] text-text-light">Select a student</span>
      </div>

      {/* Search */}
      <div className="relative mb-3">
        <FaSearch className="absolute right-3 top-1/2 -translate-y-1/2 text-text-light text-[13px] pointer-events-none" />
        <input
          type="text"
          placeholder="ابحث بالاسم أو رقم الطالب…"
          className="w-full pr-9 pl-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] text-text-dark outline-none focus:border-primary focus:shadow-[0_0_0_3px_rgba(86,153,51,0.1)]"
          value={query}
          onChange={e => setQuery(e.target.value)}
          dir="rtl"
        />
      </div>

      {loading ? (
        <div className="flex justify-center py-4 text-primary">
          <FaSpinner className="animate-spin text-[20px]" />
        </div>
      ) : (
        <div className="max-h-[220px] overflow-y-auto rounded-[10px] border border-primary/10 divide-y divide-primary/6">
          {filtered.length === 0 ? (
            <p className="text-center text-[13px] text-text-light py-5" dir="rtl">لا توجد نتائج</p>
          ) : filtered.map(s => {
            const isSelected = selected?.student_id === s.student_id
            return (
              <button
                key={s.student_id}
                onClick={() => onSelect(s)}
                className={`w-full flex items-center gap-3 px-4 py-3 text-right transition-colors hover:bg-primary/[0.04] ${isSelected ? 'bg-primary/[0.06] border-r-2 border-primary' : ''}`}
                dir="rtl"
              >
                <div className="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold text-[13px] flex-shrink-0">
                  {s.first_name?.[0]}
                </div>
                <div>
                  <div className="text-[13.5px] font-bold text-text-dark">{s.first_name} {s.last_name}</div>
                  <div className="text-[11px] text-text-light font-mono">{s.student_number}</div>
                </div>
                {isSelected && <span className="mr-auto text-primary text-[11px] font-bold">محدد ✓</span>}
              </button>
            )
          })}
        </div>
      )}
    </div>
  )
}
