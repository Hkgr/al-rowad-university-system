import { useState, useEffect, useRef, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { motion, AnimatePresence } from 'framer-motion'
import {
  FaSearch, FaEye, FaSpinner, FaGraduationCap,
  FaChevronLeft, FaChevronRight,
} from 'react-icons/fa'

const API = 'http://127.0.0.1:8000/api/v1'

function authHeaders() {
  return {
    Authorization: `Bearer ${localStorage.getItem('token')}`,
    Accept: 'application/json',
  }
}

export default function GraduatesPage() {
  const [graduates, setGraduates] = useState([])
  const [loading,   setLoading]   = useState(true)
  const [error,     setError]     = useState('')
  const [search,    setSearch]    = useState('')
  const [page,      setPage]      = useState(1)
  const [meta,      setMeta]      = useState({ total: 0, last_page: 1, per_page: 15 })
  const debounceRef               = useRef(null)
  const navigate                  = useNavigate()

  const fetchGraduates = useCallback(async (q, p) => {
    setLoading(true)
    setError('')
    try {
      const url = q
        ? `${API}/students/search?q=${encodeURIComponent(q)}&student_status_id=3&per_page=15&page=${p}`
        : `${API}/students?student_status_id=3&page=${p}&per_page=15`

      const res  = await fetch(url, { headers: authHeaders() })
      if (res.status === 401) { navigate('/login'); return }
      const json = await res.json()

      if (json.success) {
        const payload = json.data
        setGraduates(payload.data ?? [])
        setMeta({
          total:     payload.meta?.total     ?? payload.total     ?? 0,
          last_page: payload.meta?.last_page ?? payload.last_page ?? 1,
          per_page:  payload.meta?.per_page  ?? payload.per_page  ?? 15,
        })
      } else {
        setError(json.message || 'فشل تحميل البيانات')
      }
    } catch {
      setError('تعذّر الاتصال بالخادم. تأكد أن php artisan serve يعمل.')
    } finally {
      setLoading(false)
    }
  }, [navigate])

  useEffect(() => {
    clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(() => {
      setPage(1)
      fetchGraduates(search, 1)
    }, 380)
    return () => clearTimeout(debounceRef.current)
  }, [search, fetchGraduates])

  useEffect(() => {
    fetchGraduates(search, page)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page])

  const totalPages = meta.last_page || 1

  return (
    <>
      {/* Header */}
      <div className="flex items-center justify-between mb-5 gap-4 flex-wrap">
        <div dir="rtl">
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">قائمة الخريجين</h2>
          <p className="text-[12.5px] text-text-light">
            {meta.total > 0 ? `${meta.total} خريج مسجّل` : 'لا يوجد خريجون بعد'}
          </p>
        </div>
        <div className="flex items-center gap-2 px-4 py-2 bg-purple-50 border border-purple-200 rounded-[12px]" dir="rtl">
          <FaGraduationCap className="text-purple-500 text-[15px]" />
          <span className="text-[13px] font-bold text-purple-700">Graduates</span>
        </div>
      </div>

      {/* Search */}
      <div className="relative mb-5">
        <FaSearch className="absolute left-[15px] top-1/2 -translate-y-1/2 text-primary-light text-[14px] pointer-events-none" />
        <input
          className="w-full py-[13px] pr-4 pl-[42px] border-[1.5px] border-primary/20 rounded-[13px] bg-white text-[14px] font-medium text-text-dark outline-none transition-all duration-[220ms] placeholder:text-text-light focus:border-primary focus:shadow-[0_0_0_4px_rgba(86,153,51,0.1)]"
          type="text"
          placeholder="ابحث باسم الخريج، رقم القيد، البريد الإلكتروني…"
          value={search}
          onChange={e => setSearch(e.target.value)}
          dir="rtl"
        />
        {search && (
          <button
            className="absolute right-3.5 top-1/2 -translate-y-1/2 bg-transparent border-none text-[18px] text-text-light cursor-pointer w-6 h-6 flex items-center justify-center rounded-full transition-all duration-200 hover:bg-red-500/8 hover:text-red-500"
            onClick={() => setSearch('')}
          >
            ×
          </button>
        )}
      </div>

      {/* Error */}
      {error && (
        <div className="flex items-center justify-between gap-3 bg-red-500/6 border border-red-500/25 rounded-[12px] px-[18px] py-3 mb-4 text-[13.5px] text-red-600" dir="rtl">
          <span>⚠ {error}</span>
          <button
            className="px-3.5 py-1 border border-red-500/35 rounded-[8px] text-red-600 text-[12px] cursor-pointer transition-all duration-200 hover:bg-red-500/8"
            onClick={() => fetchGraduates(search, page)}
          >
            إعادة المحاولة
          </button>
        </div>
      )}

      {/* Table */}
      <div className="bg-white rounded-[16px] border border-primary/12 overflow-hidden shadow-[0_2px_16px_rgba(26,46,16,0.06)] min-h-[240px]">
        {loading ? (
          <div className="flex flex-col items-center justify-center gap-3.5 py-[60px] text-primary-light text-[14px] font-medium">
            <FaSpinner className="text-[28px] animate-[spin_0.7s_linear_infinite]" />
            <span>جاري التحميل…</span>
          </div>
        ) : graduates.length === 0 ? (
          <div className="flex flex-col items-center justify-center gap-2 py-[60px]">
            <FaGraduationCap className="text-[48px] text-purple-200 mb-2" />
            <p className="text-[16px] font-bold text-text-gray" dir="rtl">لا يوجد خريجون</p>
            <p className="text-[12.5px] text-text-light">No graduates found</p>
            {search && (
              <button
                className="mt-2.5 px-5 py-2 bg-primary/8 border border-primary/20 rounded-[10px] text-primary-dark text-[13px] font-semibold cursor-pointer transition-all duration-200 hover:bg-primary/15"
                onClick={() => setSearch('')}
              >
                مسح البحث
              </button>
            )}
          </div>
        ) : (
          <table className="w-full border-collapse">
            <thead>
              <tr>
                <th className="px-4 py-3.5 text-left text-[12px] font-bold text-white/90 bg-text-dark whitespace-nowrap">#</th>
                <th className="px-4 py-3.5 text-right text-[12px] font-bold text-white/90 bg-text-dark whitespace-nowrap" dir="rtl">رقم القيد</th>
                <th className="px-4 py-3.5 text-right text-[12px] font-bold text-white/90 bg-text-dark whitespace-nowrap" dir="rtl">الاسم الكامل</th>
                <th className="px-4 py-3.5 text-right text-[12px] font-bold text-white/90 bg-text-dark whitespace-nowrap" dir="rtl">البريد الإلكتروني</th>
                <th className="px-4 py-3.5 text-right text-[12px] font-bold text-white/90 bg-text-dark whitespace-nowrap" dir="rtl">رقم الهاتف</th>
                <th className="px-4 py-3.5 text-right text-[12px] font-bold text-white/90 bg-text-dark whitespace-nowrap" dir="rtl">تاريخ القبول</th>
                <th className="px-4 py-3.5 text-right text-[12px] font-bold text-white/90 bg-text-dark whitespace-nowrap" dir="rtl">الإجراءات</th>
              </tr>
            </thead>
            <AnimatePresence mode="wait">
              <motion.tbody
                key={`${search}-${page}`}
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                exit={{ opacity: 0 }}
                transition={{ duration: 0.18 }}
              >
                {graduates.map((s, idx) => (
                  <tr key={s.student_id} className="border-b border-primary/7 last:border-b-0 transition-colors duration-150 hover:bg-purple-50/40">
                    <td className="px-4 py-[13px] text-[12px] text-text-light font-semibold w-10">
                      {(page - 1) * meta.per_page + idx + 1}
                    </td>
                    <td className="px-4 py-[13px] align-middle">
                      <span className="inline-block px-2.5 py-[3px] bg-primary/8 border border-primary/15 rounded-[8px] text-[12px] font-bold text-primary-dark font-mono">
                        {s.student_number}
                      </span>
                    </td>
                    <td className="px-4 py-[13px] align-middle" dir="rtl">
                      <div className="flex items-center gap-2">
                        <div className="w-7 h-7 rounded-full bg-purple-100 flex items-center justify-center text-purple-600 text-[11px] font-black flex-shrink-0">
                          {s.first_name?.[0]?.toUpperCase() ?? '?'}
                        </div>
                        <span className="text-[13.5px] font-semibold text-text-dark">{s.first_name} {s.last_name}</span>
                      </div>
                    </td>
                    <td className="px-4 py-[13px] text-[12.5px] text-text-gray align-middle">{s.email || '—'}</td>
                    <td className="px-4 py-[13px] text-[13px] text-text-dark align-middle">{s.phone_number || '—'}</td>
                    <td className="px-4 py-[13px] text-[13px] text-text-dark align-middle">
                      {s.enrollment_date ? new Date(s.enrollment_date).toLocaleDateString('ar-SY') : '—'}
                    </td>
                    <td className="px-4 py-[13px] align-middle">
                      <button
                        className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] cursor-pointer transition-all duration-[180ms] text-blue-500 border-blue-500/20 bg-blue-500/6 hover:bg-blue-500/14 hover:border-blue-500/35"
                        title="عرض الملف"
                        onClick={() => navigate(`/student-affairs/students/${s.student_id}`)}
                      >
                        <FaEye />
                      </button>
                    </td>
                  </tr>
                ))}
              </motion.tbody>
            </AnimatePresence>
          </table>
        )}
      </div>

      {/* Pagination */}
      {!loading && totalPages > 1 && (
        <div className="flex items-center justify-center gap-4 mt-5 py-1">
          <button
            className="flex items-center gap-1.5 px-4 py-2 border-[1.5px] border-primary/20 rounded-[10px] bg-white text-primary-dark text-[13px] font-semibold cursor-pointer transition-all duration-200 disabled:opacity-[0.38] disabled:cursor-not-allowed hover:not-disabled:bg-primary/8 hover:not-disabled:border-primary/40"
            disabled={page <= 1}
            onClick={() => setPage(p => p - 1)}
            dir="rtl"
          >
            <FaChevronRight />
            <span>السابق</span>
          </button>
          <div className="flex items-center gap-1.5 text-[13px] text-text-gray" dir="rtl">
            <span className="text-[17px] font-extrabold text-primary">{page}</span>
            <span className="text-text-light">من</span>
            <span className="font-semibold text-text-dark">{totalPages}</span>
          </div>
          <button
            className="flex items-center gap-1.5 px-4 py-2 border-[1.5px] border-primary/20 rounded-[10px] bg-white text-primary-dark text-[13px] font-semibold cursor-pointer transition-all duration-200 disabled:opacity-[0.38] disabled:cursor-not-allowed hover:not-disabled:bg-primary/8 hover:not-disabled:border-primary/40"
            disabled={page >= totalPages}
            onClick={() => setPage(p => p + 1)}
            dir="rtl"
          >
            <span>التالي</span>
            <FaChevronLeft />
          </button>
        </div>
      )}
    </>
  )
}
