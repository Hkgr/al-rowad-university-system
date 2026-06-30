import { useState, useEffect, useMemo, useRef } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { motion, AnimatePresence } from 'framer-motion'
import {
  FaUserPlus, FaSearch, FaEye, FaEdit, FaArchive,
  FaChevronLeft, FaChevronRight, FaSpinner, FaGraduationCap,
  FaFilter, FaTimes,
} from 'react-icons/fa'

const API = 'http://127.0.0.1:8000/api/v1'
const PAGE_SIZE = 15

const STATUS_MAP = {
  1: { ar: 'مقيّد',  color: '#22c55e', bg: 'rgba(34,197,94,0.1)'  },
  2: { ar: 'منقطع',  color: '#3b82f6', bg: 'rgba(59,130,246,0.1)' },
  3: { ar: 'خريج',   color: '#8b5cf6', bg: 'rgba(139,92,246,0.1)' },
  4: { ar: 'مسحوب',  color: '#f59e0b', bg: 'rgba(245,158,11,0.1)' },
  5: { ar: 'مفصول',  color: '#ef4444', bg: 'rgba(239,68,68,0.1)'  },
  6: { ar: 'موقوف',  color: '#f97316', bg: 'rgba(249,115,22,0.1)' },
}

function StatusBadge({ statusId }) {
  const cfg = STATUS_MAP[statusId]
  if (!cfg) return <span className="text-[11px] text-text-light">—</span>
  return (
    <span
      className="inline-block px-2 py-[3px] rounded-full text-[11px] font-bold whitespace-nowrap"
      style={{ color: cfg.color, background: cfg.bg }}
    >
      {cfg.ar}
    </span>
  )
}

function authHeaders() {
  return {
    Authorization: `Bearer ${localStorage.getItem('token')}`,
    Accept: 'application/json',
  }
}

async function fetchAll(url) {
  const res  = await fetch(url, { headers: authHeaders() })
  if (res.status === 401) return { _unauthorized: true }
  const json = await res.json()
  return json.success ? (json.data?.data ?? json.data ?? []) : []
}

async function fetchAllPages(baseUrl) {
  const first     = await fetch(`${baseUrl}&per_page=100&page=1`, { headers: authHeaders() })
  if (first.status === 401) return { _unauthorized: true }
  const firstJson = await first.json()
  if (!firstJson.success) return []
  const rows      = [...(firstJson.data?.data ?? [])]
  const lastPage  = firstJson.data?.meta?.last_page ?? 1
  for (let p = 2; p <= lastPage; p++) {
    const r = await fetch(`${baseUrl}&per_page=100&page=${p}`, { headers: authHeaders() })
    const j = await r.json()
    if (j.success) rows.push(...(j.data?.data ?? []))
  }
  return rows
}

// Module-level cache — fetched once per session, not on every page visit
const _cache = { programMap: null, deptMap: null, colleges: null }

async function loadLookups() {
  if (_cache.programMap) return _cache   // already loaded
  const [progs, depts, cols] = await Promise.all([
    fetchAll(`${API}/academic-programs?per_page=100`),
    fetchAll(`${API}/departments?per_page=100`),
    fetchAll(`${API}/colleges?per_page=50`),
  ])
  const pm = {}
  if (Array.isArray(progs)) progs.forEach(p => { pm[p.academic_program_id] = { name: p.program_name, dept_id: p.department_id } })
  const dm = {}
  if (Array.isArray(depts)) depts.forEach(d => { dm[d.department_id] = { college_id: d.college_id } })
  _cache.programMap = pm
  _cache.deptMap    = dm
  _cache.colleges   = Array.isArray(cols) ? cols : []
  return _cache
}

export default function StudentsPage() {
  const [allStudents, setAllStudents]   = useState([])
  const [programMap, setProgramMap]     = useState({})   // id -> { name, dept_id }
  const [deptMap, setDeptMap]           = useState({})   // id -> { name, college_id }
  const [colleges, setColleges]         = useState([])   // [{college_id, college_name}]
  const [loading, setLoading]           = useState(true)
  const [error, setError]               = useState('')

  // Filters
  const [search, setSearch]             = useState('')
  const [filterCollege, setFilterCollege] = useState('')
  const [filterStatus, setFilterStatus]   = useState('')
  const [page, setPage]                 = useState(1)

  const debounceRef = useRef(null)
  const navigate    = useNavigate()

  // Load all data — lookups cached at module level, students always fresh
  useEffect(() => {
    async function load() {
      setLoading(true)
      setError('')
      try {
        const [studs, lookups] = await Promise.all([
          fetchAllPages(`${API}/students?`),
          loadLookups(),
        ])

        if (studs?._unauthorized) { navigate('/login'); return }
        if (!Array.isArray(studs) || studs.length === 0) {
          setError('فشل تحميل بيانات الطلاب')
        }

        setAllStudents(Array.isArray(studs) ? studs : [])
        setProgramMap(lookups.programMap ?? {})
        setDeptMap(lookups.deptMap ?? {})
        setColleges(lookups.colleges ?? [])
      } catch {
        setError('تعذّر الاتصال بالخادم. تأكد أن php artisan serve يعمل.')
      } finally {
        setLoading(false)
      }
    }
    load()
  }, [navigate])

  // Derive college for a student
  function getCollegeName(student) {
    const prog   = programMap[student.academic_program_id]
    if (!prog) return null
    const dept   = deptMap[prog.dept_id]
    if (!dept) return null
    const col    = colleges.find(c => c.college_id === dept.college_id)
    return col?.college_name ?? null
  }

  function getCollegeId(student) {
    const prog = programMap[student.academic_program_id]
    if (!prog) return null
    return deptMap[prog.dept_id]?.college_id ?? null
  }

  // Debounced search — just resets page
  useEffect(() => {
    clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(() => setPage(1), 300)
    return () => clearTimeout(debounceRef.current)
  }, [search, filterCollege, filterStatus])

  // Client-side filtering
  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase()
    return allStudents.filter(s => {
      if (filterStatus && String(s.student_status_id) !== filterStatus) return false
      if (filterCollege && String(getCollegeId(s)) !== filterCollege) return false
      if (q) {
        const name  = `${s.first_name} ${s.last_name}`.toLowerCase()
        const num   = (s.student_number ?? '').toLowerCase()
        const email = (s.email ?? '').toLowerCase()
        if (!name.includes(q) && !num.includes(q) && !email.includes(q)) return false
      }
      return true
    })
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [allStudents, search, filterCollege, filterStatus, programMap, deptMap, colleges])

  const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE))
  const safePage   = Math.min(page, totalPages)
  const pageStudents = filtered.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE)

  const hasFilters = search || filterCollege || filterStatus

  const handleArchive = async (id) => {
    if (!window.confirm('سيتم أرشفة هذا الطالب وإخفاؤه من القائمة.\nهل أنت متأكد؟')) return
    try {
      const res  = await fetch(`${API}/students/${id}`, { method: 'DELETE', headers: authHeaders() })
      const json = await res.json()
      if (json.success) {
        setAllStudents(prev => prev.filter(s => s.student_id !== id))
      } else {
        alert(json.message || 'فشلت الأرشفة')
      }
    } catch {
      alert('تعذّر الاتصال بالخادم')
    }
  }

  const clearFilters = () => { setSearch(''); setFilterCollege(''); setFilterStatus(''); setPage(1) }

  return (
    <>
      {/* Page header */}
      <div className="flex items-center justify-between mb-5 gap-4 flex-wrap">
        <div dir="rtl">
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">قائمة الطلاب</h2>
          <p className="text-[12.5px] text-text-light">
            {loading ? 'جاري التحميل…' : (
              hasFilters
                ? `${filtered.length} نتيجة من أصل ${allStudents.length} طالب`
                : `${allStudents.length} طالب مسجّل`
            )}
          </p>
        </div>
        <Link
          to="/student-affairs/students/add"
          className="flex items-center gap-2 px-5 py-2.5 bg-gradient-to-br from-primary to-primary-dark text-white rounded-[12px] no-underline text-[14px] font-bold whitespace-nowrap shadow-[0_4px_16px_rgba(86,153,51,0.35)] transition-all duration-[220ms] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(86,153,51,0.45)]"
          dir="rtl"
        >
          <FaUserPlus />
          <span>إضافة طالب</span>
        </Link>
      </div>

      {/* Search + Filters */}
      <div className="flex flex-col gap-3 mb-5">
        {/* Search bar */}
        <div className="relative">
          <FaSearch className="absolute left-[15px] top-1/2 -translate-y-1/2 text-primary-light text-[14px] pointer-events-none" />
          <input
            className="w-full py-[13px] pr-4 pl-[42px] border-[1.5px] border-primary/20 rounded-[13px] bg-white text-[14px] font-medium text-text-dark outline-none transition-all duration-[220ms] placeholder:text-text-light focus:border-primary focus:shadow-[0_0_0_4px_rgba(86,153,51,0.1)]"
            type="text"
            placeholder="ابحث باسم الطالب، رقم القيد، البريد الإلكتروني…"
            value={search}
            onChange={e => setSearch(e.target.value)}
            dir="rtl"
          />
          {search && (
            <button
              className="absolute right-3.5 top-1/2 -translate-y-1/2 bg-transparent border-none text-[18px] text-text-light cursor-pointer leading-none w-6 h-6 flex items-center justify-center rounded-full transition-all duration-200 hover:bg-red-500/8 hover:text-red-500"
              onClick={() => setSearch('')}
            >×</button>
          )}
        </div>

        {/* Filter row */}
        <div className="flex items-center gap-3 flex-wrap">
          <div className="flex items-center gap-1.5 text-[12.5px] text-text-light font-semibold" dir="rtl">
            <FaFilter className="text-primary-light text-[11px]" />
            <span>تصفية:</span>
          </div>

          {/* College filter */}
          <select
            className="py-2 px-3 border-[1.5px] border-primary/20 rounded-[10px] bg-white text-[13px] text-text-dark outline-none cursor-pointer transition-all duration-200 focus:border-primary min-w-[160px]"
            value={filterCollege}
            onChange={e => { setFilterCollege(e.target.value); setPage(1) }}
            dir="rtl"
            disabled={loading}
          >
            <option value="">جميع الكليات</option>
            {colleges.map(c => (
              <option key={c.college_id} value={String(c.college_id)}>
                {c.college_name}
              </option>
            ))}
          </select>

          {/* Status filter */}
          <select
            className="py-2 px-3 border-[1.5px] border-primary/20 rounded-[10px] bg-white text-[13px] text-text-dark outline-none cursor-pointer transition-all duration-200 focus:border-primary min-w-[140px]"
            value={filterStatus}
            onChange={e => { setFilterStatus(e.target.value); setPage(1) }}
            dir="rtl"
            disabled={loading}
          >
            <option value="">جميع الحالات</option>
            {Object.entries(STATUS_MAP).map(([id, { ar }]) => (
              <option key={id} value={id}>{ar}</option>
            ))}
          </select>

          {/* Clear filters */}
          {hasFilters && (
            <button
              className="flex items-center gap-1.5 py-2 px-3 border-[1.5px] border-red-400/30 rounded-[10px] bg-red-50 text-red-500 text-[12.5px] font-semibold cursor-pointer transition-all duration-200 hover:bg-red-100"
              onClick={clearFilters}
              dir="rtl"
            >
              <FaTimes className="text-[10px]" />
              <span>مسح الفلاتر</span>
            </button>
          )}
        </div>
      </div>

      {/* Error */}
      {error && (
        <div className="flex items-center justify-between gap-3 bg-red-500/6 border border-red-500/25 rounded-[12px] px-[18px] py-3 mb-4 text-[13.5px] text-red-600" dir="rtl">
          <span>⚠ {error}</span>
        </div>
      )}

      {/* Table */}
      <div className="bg-white rounded-[16px] border border-primary/12 overflow-hidden shadow-[0_2px_16px_rgba(26,46,16,0.06)] min-h-[240px]">
        {loading ? (
          <div className="flex flex-col items-center justify-center gap-3.5 py-[60px] text-primary-light text-[14px] font-medium">
            <FaSpinner className="text-[28px] animate-[spin_0.7s_linear_infinite]" />
            <span>جاري التحميل…</span>
          </div>
        ) : pageStudents.length === 0 ? (
          <div className="flex flex-col items-center justify-center gap-2 py-[60px]">
            <FaGraduationCap className="text-[48px] text-[#d1eab8] mb-2" />
            <p className="text-[16px] font-bold text-text-gray" dir="rtl">لا يوجد طلاب</p>
            <p className="text-[12.5px] text-text-light">No students found</p>
            {hasFilters && (
              <button
                className="mt-2.5 px-5 py-2 bg-primary/8 border border-primary/20 rounded-[10px] text-primary-dark text-[13px] font-semibold cursor-pointer transition-all duration-200 hover:bg-primary/15"
                onClick={clearFilters}
                dir="rtl"
              >
                مسح الفلاتر
              </button>
            )}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full border-collapse">
              <thead>
                <tr>
                  <th className="px-4 py-3.5 text-left   text-[12px] font-bold text-white/90 bg-text-dark whitespace-nowrap">#</th>
                  <th className="px-4 py-3.5 text-right  text-[12px] font-bold text-white/90 bg-text-dark whitespace-nowrap" dir="rtl">رقم القيد</th>
                  <th className="px-4 py-3.5 text-right  text-[12px] font-bold text-white/90 bg-text-dark whitespace-nowrap" dir="rtl">الاسم الكامل</th>
                  <th className="px-4 py-3.5 text-right  text-[12px] font-bold text-white/90 bg-text-dark whitespace-nowrap" dir="rtl">الكلية</th>
                  <th className="px-4 py-3.5 text-right  text-[12px] font-bold text-white/90 bg-text-dark whitespace-nowrap" dir="rtl">البريد الإلكتروني</th>
                  <th className="px-4 py-3.5 text-right  text-[12px] font-bold text-white/90 bg-text-dark whitespace-nowrap" dir="rtl">رقم الهاتف</th>
                  <th className="px-4 py-3.5 text-right  text-[12px] font-bold text-white/90 bg-text-dark whitespace-nowrap" dir="rtl">تاريخ القبول</th>
                  <th className="px-4 py-3.5 text-right  text-[12px] font-bold text-white/90 bg-text-dark whitespace-nowrap" dir="rtl">الحالة</th>
                  <th className="px-4 py-3.5 text-right  text-[12px] font-bold text-white/90 bg-text-dark whitespace-nowrap" dir="rtl">الإجراءات</th>
                </tr>
              </thead>
              <AnimatePresence mode="wait">
                <motion.tbody
                  key={`${search}-${filterCollege}-${filterStatus}-${safePage}`}
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  exit={{ opacity: 0 }}
                  transition={{ duration: 0.18 }}
                >
                  {pageStudents.map((s, idx) => {
                    const collegeName = getCollegeName(s)
                    return (
                      <tr key={s.student_id} className="border-b border-primary/7 last:border-b-0 transition-colors duration-150 hover:bg-primary/[0.035]">
                        <td className="px-4 py-[13px] text-[12px] text-text-light font-semibold w-10">
                          {(safePage - 1) * PAGE_SIZE + idx + 1}
                        </td>
                        <td className="px-4 py-[13px] align-middle">
                          <span className="inline-block px-2.5 py-[3px] bg-primary/8 border border-primary/15 rounded-[8px] text-[12px] font-bold text-primary-dark font-mono">
                            {s.student_number}
                          </span>
                        </td>
                        <td className="px-4 py-[13px] text-[13.5px] font-semibold text-text-dark align-middle whitespace-nowrap" dir="rtl">
                          {s.first_name} {s.last_name}
                        </td>
                        <td className="px-4 py-[13px] align-middle" dir="rtl">
                          {collegeName
                            ? <span className="text-[12px] font-medium text-text-gray whitespace-nowrap">{collegeName}</span>
                            : <span className="text-[11px] text-text-light">—</span>
                          }
                        </td>
                        <td className="px-4 py-[13px] text-[12.5px] text-text-gray align-middle">{s.email || '—'}</td>
                        <td className="px-4 py-[13px] text-[13.5px] text-text-dark align-middle whitespace-nowrap">{s.phone_number || '—'}</td>
                        <td className="px-4 py-[13px] text-[13.5px] text-text-dark align-middle whitespace-nowrap">
                          {s.enrollment_date ? new Date(s.enrollment_date).toLocaleDateString('ar-SY') : '—'}
                        </td>
                        <td className="px-4 py-[13px] align-middle">
                          <StatusBadge statusId={s.student_status_id} />
                        </td>
                        <td className="px-4 py-[13px] align-middle">
                          <div className="flex items-center gap-1.5">
                            <button
                              className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] cursor-pointer transition-all duration-[180ms] text-blue-500 border-blue-500/20 bg-blue-500/6 hover:bg-blue-500/14 hover:border-blue-500/35"
                              title="عرض الملف"
                              onClick={() => navigate(`/student-affairs/students/${s.student_id}`)}
                            >
                              <FaEye />
                            </button>
                            <button
                              className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] cursor-pointer transition-all duration-[180ms] text-amber-500 border-amber-500/20 bg-amber-500/6 hover:bg-amber-500/14 hover:border-amber-500/35"
                              title="تعديل"
                              onClick={() => navigate(`/student-affairs/students/${s.student_id}/edit`)}
                            >
                              <FaEdit />
                            </button>
                            <button
                              className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] cursor-pointer transition-all duration-[180ms] text-slate-500 border-slate-400/20 bg-slate-400/6 hover:bg-slate-400/14 hover:border-slate-400/35"
                              title="أرشفة"
                              onClick={() => handleArchive(s.student_id)}
                            >
                              <FaArchive />
                            </button>
                          </div>
                        </td>
                      </tr>
                    )
                  })}
                </motion.tbody>
              </AnimatePresence>
            </table>
          </div>
        )}
      </div>

      {/* Pagination */}
      {!loading && totalPages > 1 && (
        <div className="flex items-center justify-center gap-4 mt-5 py-1">
          <button
            className="flex items-center gap-1.5 px-4 py-2 border-[1.5px] border-primary/20 rounded-[10px] bg-white text-primary-dark text-[13px] font-semibold cursor-pointer transition-all duration-200 disabled:opacity-[0.38] disabled:cursor-not-allowed hover:not-disabled:bg-primary/8 hover:not-disabled:border-primary/40"
            disabled={safePage <= 1}
            onClick={() => setPage(p => p - 1)}
            dir="rtl"
          >
            <FaChevronRight />
            <span>السابق</span>
          </button>

          <div className="flex items-center gap-1.5 text-[13px] text-text-gray" dir="rtl">
            <span className="text-[17px] font-extrabold text-primary">{safePage}</span>
            <span className="text-text-light">من</span>
            <span className="font-semibold text-text-dark">{totalPages}</span>
          </div>

          <button
            className="flex items-center gap-1.5 px-4 py-2 border-[1.5px] border-primary/20 rounded-[10px] bg-white text-primary-dark text-[13px] font-semibold cursor-pointer transition-all duration-200 disabled:opacity-[0.38] disabled:cursor-not-allowed hover:not-disabled:bg-primary/8 hover:not-disabled:border-primary/40"
            disabled={safePage >= totalPages}
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
