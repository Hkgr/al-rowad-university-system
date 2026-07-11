import { useState, useEffect, useRef, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaEye, FaGraduationCap } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'

const API = `${import.meta.env.VITE_API_BASE_URL || 'https://rust.alrowaduni.edu.sy/api'}/v1`

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
setError('تعذّر الاتصال بالخادم. تحقق من رابط الـ API أو صلاحية الاتصال بالسيرفر.')
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

  const columns = [
    {
      key: 'idx',
      header: '#',
      align: 'left',
      cellClassName: 'text-[12px] text-text-light font-semibold w-10',
      render: (s, idx) => (page - 1) * meta.per_page + idx + 1,
    },
    {
      key: 'student_number',
      header: 'رقم القيد',
      render: s => (
        <span className="inline-block px-2.5 py-[3px] bg-primary/8 border border-primary/15 rounded-[8px] text-[12px] font-bold text-primary-dark font-mono">
          {s.student_number}
        </span>
      ),
    },
    {
      key: 'name',
      header: 'الاسم الكامل',
      dir: 'rtl',
      render: s => (
        <div className="flex items-center gap-2">
          <div className="w-7 h-7 rounded-full bg-purple-100 flex items-center justify-center text-purple-600 text-[11px] font-black flex-shrink-0">
            {s.first_name?.[0]?.toUpperCase() ?? '?'}
          </div>
          <span className="text-[13.5px] font-semibold text-text-dark">{s.first_name} {s.last_name}</span>
        </div>
      ),
    },
    {
      key: 'email',
      header: 'البريد الإلكتروني',
      cellClassName: 'text-[12.5px] text-text-gray',
      render: s => s.email || '—',
    },
    {
      key: 'phone',
      header: 'رقم الهاتف',
      cellClassName: 'text-[13px] text-text-dark',
      render: s => s.phone_number || '—',
    },
    {
      key: 'enrollment_date',
      header: 'تاريخ القبول',
      cellClassName: 'text-[13px] text-text-dark',
      render: s => s.enrollment_date ? new Date(s.enrollment_date).toLocaleDateString('ar-SY') : '—',
    },
    {
      key: 'actions',
      header: 'الإجراءات',
      render: s => (
        <button
          className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] cursor-pointer transition-all duration-[180ms] text-blue-500 border-blue-500/20 bg-blue-500/6 hover:bg-blue-500/14 hover:border-blue-500/35"
          title="عرض الملف"
          onClick={() => navigate(`/student-affairs/students/${s.student_id}`)}
        >
          <FaEye />
        </button>
      ),
    },
  ]

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

      <FilterBar
        search={{ value: search, onChange: setSearch, placeholder: 'ابحث باسم الخريج، رقم القيد، البريد الإلكتروني…' }}
      />

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

      <DataTable
        columns={columns}
        rows={graduates}
        rowKey={s => s.student_id}
        loading={loading}
        animationKey={`${search}-${page}`}
        emptyIcon={FaGraduationCap}
        emptyTitle="لا يوجد خريجون"
        emptySubtitle="No graduates found"
        hasFilters={!!search}
        onClearFilters={() => setSearch('')}
        page={page}
        totalPages={totalPages}
        onPageChange={setPage}
      />
    </>
  )
}
