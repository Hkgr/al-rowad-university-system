import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { motion, AnimatePresence } from 'framer-motion'
import {
  FaArchive, FaBoxOpen, FaSpinner,
} from 'react-icons/fa'

const API = 'http://127.0.0.1:8000/api/v1'

function authHeaders() {
  return {
    Authorization: `Bearer ${localStorage.getItem('token')}`,
    Accept: 'application/json',
  }
}

export default function ArchivedStudentsPage() {
  const [students, setStudents] = useState([])
  const [loading, setLoading]   = useState(true)
  const [error, setError]       = useState('')
  const navigate                = useNavigate()

  const fetchArchived = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const res  = await fetch(`${API}/students/deleted`, { headers: authHeaders() })
      if (res.status === 401) { navigate('/login'); return }
      const json = await res.json()
      if (json.success) {
        setStudents(Array.isArray(json.data) ? json.data : [])
      } else {
        setError(json.message || 'فشل تحميل البيانات')
      }
    } catch {
      setError('تعذّر الاتصال بالخادم. تأكد أن php artisan serve يعمل.')
    } finally {
      setLoading(false)
    }
  }, [navigate])

  useEffect(() => { fetchArchived() }, [fetchArchived])

  const handleRestore = async (id) => {
    if (!window.confirm('هل تريد استعادة هذا الطالب وإعادته للقائمة النشطة؟')) return
    try {
      const res  = await fetch(`${API}/students/${id}/restore`, { method: 'POST', headers: authHeaders() })
      const json = await res.json()
      if (json.success) {
        setStudents(prev => prev.filter(s => s.student_id !== id))
      } else {
        alert(json.message || 'فشلت الاستعادة')
      }
    } catch {
      alert('تعذّر الاتصال بالخادم')
    }
  }

  return (
    <>
      {/* Page header */}
      <div className="flex items-center justify-between mb-5 gap-4 flex-wrap">
        <div dir="rtl">
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">الطلاب المؤرشفون</h2>
          <p className="text-[12.5px] text-text-light">
            {loading ? 'جاري التحميل…' : `${students.length} طالب مؤرشف`}
          </p>
        </div>
      </div>

      {/* Info banner */}
      <div className="flex items-center gap-2.5 bg-slate-50 border border-slate-200 rounded-[12px] px-4 py-3 mb-5 text-[13px] text-slate-600" dir="rtl">
        <FaArchive className="text-slate-400 flex-shrink-0" />
        <span>الطلاب المؤرشفون محفوظون في قاعدة البيانات ولم يُحذفوا. يمكنك استعادة أي طالب لإعادته للقائمة النشطة.</span>
      </div>

      {/* Error */}
      {error && (
        <div className="flex items-center justify-between gap-3 bg-red-500/6 border border-red-500/25 rounded-[12px] px-[18px] py-3 mb-4 text-[13.5px] text-red-600" dir="rtl">
          <span>⚠ {error}</span>
          <button
            className="px-3.5 py-1 bg-transparent border border-red-500/35 rounded-[8px] text-red-600 text-[12px] cursor-pointer whitespace-nowrap transition-all duration-200 hover:bg-red-500/8"
            onClick={fetchArchived}
          >
            إعادة المحاولة
          </button>
        </div>
      )}

      {/* Table */}
      <div className="bg-white rounded-[16px] border border-slate-200 overflow-hidden shadow-[0_2px_16px_rgba(26,46,16,0.06)] min-h-[240px]">
        {loading ? (
          <div className="flex flex-col items-center justify-center gap-3.5 py-[60px] text-slate-400 text-[14px] font-medium">
            <FaSpinner className="text-[28px] animate-[spin_0.7s_linear_infinite]" />
            <span>جاري التحميل…</span>
          </div>
        ) : students.length === 0 ? (
          <div className="flex flex-col items-center justify-center gap-2 py-[60px]">
            <FaArchive className="text-[48px] text-slate-200 mb-2" />
            <p className="text-[16px] font-bold text-text-gray" dir="rtl">لا يوجد طلاب مؤرشفون</p>
            <p className="text-[12.5px] text-text-light">No archived students</p>
          </div>
        ) : (
          <table className="w-full border-collapse">
            <thead>
              <tr>
                <th className="px-4 py-3.5 text-left text-[12px] font-bold text-white/90 bg-slate-600 whitespace-nowrap">#</th>
                <th className="px-4 py-3.5 text-right text-[12px] font-bold text-white/90 bg-slate-600 whitespace-nowrap" dir="rtl">رقم القيد</th>
                <th className="px-4 py-3.5 text-right text-[12px] font-bold text-white/90 bg-slate-600 whitespace-nowrap" dir="rtl">الاسم الكامل</th>
                <th className="px-4 py-3.5 text-right text-[12px] font-bold text-white/90 bg-slate-600 whitespace-nowrap" dir="rtl">البريد الإلكتروني</th>
                <th className="px-4 py-3.5 text-right text-[12px] font-bold text-white/90 bg-slate-600 whitespace-nowrap" dir="rtl">رقم الهاتف</th>
                <th className="px-4 py-3.5 text-right text-[12px] font-bold text-white/90 bg-slate-600 whitespace-nowrap" dir="rtl">تاريخ القبول</th>
                <th className="px-4 py-3.5 text-right text-[12px] font-bold text-white/90 bg-slate-600 whitespace-nowrap" dir="rtl">الإجراءات</th>
              </tr>
            </thead>
            <AnimatePresence mode="wait">
              <motion.tbody
                key="archived"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                exit={{ opacity: 0 }}
                transition={{ duration: 0.18 }}
              >
                {students.map((s, idx) => (
                  <tr key={s.student_id} className="border-b border-slate-100 last:border-b-0 bg-slate-50/40 hover:bg-slate-100/60 transition-colors duration-150">
                    <td className="px-4 py-[13px] text-[12px] text-text-light font-semibold w-10">{idx + 1}</td>
                    <td className="px-4 py-[13px] align-middle">
                      <span className="inline-block px-2.5 py-[3px] bg-slate-100 border border-slate-200 rounded-[8px] text-[12px] font-bold text-slate-500 font-mono">
                        {s.student_number}
                      </span>
                    </td>
                    <td className="px-4 py-[13px] text-[13.5px] font-semibold text-text-gray align-middle" dir="rtl">
                      {s.first_name} {s.last_name}
                    </td>
                    <td className="px-4 py-[13px] text-[12.5px] text-text-gray align-middle">{s.email || '—'}</td>
                    <td className="px-4 py-[13px] text-[13.5px] text-text-gray align-middle">{s.phone_number || '—'}</td>
                    <td className="px-4 py-[13px] text-[13.5px] text-text-gray align-middle">
                      {s.enrollment_date ? new Date(s.enrollment_date).toLocaleDateString('ar-SY') : '—'}
                    </td>
                    <td className="px-4 py-[13px] align-middle">
                      <button
                        className="flex items-center gap-1.5 px-3 py-1.5 rounded-[8px] border text-[12.5px] font-bold cursor-pointer transition-all duration-[180ms] text-green-600 border-green-500/25 bg-green-500/6 hover:bg-green-500/14 hover:border-green-500/40"
                        onClick={() => handleRestore(s.student_id)}
                        dir="rtl"
                      >
                        <FaBoxOpen className="text-[12px]" />
                        استعادة
                      </button>
                    </td>
                  </tr>
                ))}
              </motion.tbody>
            </AnimatePresence>
          </table>
        )}
      </div>
    </>
  )
}
