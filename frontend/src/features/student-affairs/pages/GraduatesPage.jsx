import { useState, useEffect, useMemo, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaEye, FaGraduationCap, FaDownload, FaSpinner } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { exportRowsToPdf } from '../../../utils/pdfExport'

const API = `${import.meta.env.VITE_API_BASE_URL || 'https://rust.alrowaduni.edu.sy/api'}/v1`
const PAGE_SIZE = 15

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

export default function GraduatesPage() {
  const [allGraduates, setAllGraduates] = useState([])
  const [programMap, setProgramMap]     = useState({})
  const [deptMap, setDeptMap]           = useState({})
  const [colleges, setColleges]         = useState([])
  const [loading,   setLoading]         = useState(true)
  const [error,     setError]           = useState('')
  const [pdfLoading, setPdfLoading]     = useState(false)

  const [search,        setSearch]        = useState('')
  const [filterCollege, setFilterCollege] = useState('')
  const [filterProgram, setFilterProgram] = useState('')
  const [filterGender,  setFilterGender]  = useState('')
  const [page,          setPage]          = useState(1)

  const debounceRef = useRef(null)
  const navigate     = useNavigate()

  const load = async () => {
    setLoading(true)
    setError('')
    try {
      const [grads, lookups] = await Promise.all([
        fetchAllPages(`${API}/students?student_status_code=graduated&`),
        loadLookups(),
      ])

      if (grads?._unauthorized) { navigate('/login'); return }
      if (!Array.isArray(grads)) {
        setError('فشل تحميل البيانات')
      }

      setAllGraduates(Array.isArray(grads) ? grads : [])
      setProgramMap(lookups.programMap ?? {})
      setDeptMap(lookups.deptMap ?? {})
      setColleges(lookups.colleges ?? [])
    } catch {
      setError('تعذّر الاتصال بالخادم. تحقق من رابط الـ API أو صلاحية الاتصال بالسيرفر.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  function getCollegeId(student) {
    const prog = programMap[student.academic_program_id]
    if (!prog) return null
    return deptMap[prog.dept_id]?.college_id ?? null
  }

  function getCollegeName(student) {
    const id = getCollegeId(student)
    return colleges.find(c => c.college_id === id)?.college_name ?? null
  }

  function getProgramName(student) {
    return programMap[student.academic_program_id]?.name ?? null
  }

  // Debounced filters — just resets page
  useEffect(() => {
    clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(() => setPage(1), 300)
    return () => clearTimeout(debounceRef.current)
  }, [search, filterCollege, filterProgram, filterGender])

  // Client-side filtering
  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase()
    return allGraduates.filter(s => {
      if (filterCollege && String(getCollegeId(s)) !== filterCollege) return false
      if (filterProgram && String(s.academic_program_id) !== filterProgram) return false
      if (filterGender && s.gender !== filterGender) return false
      if (q) {
        const name  = `${s.first_name} ${s.last_name}`.toLowerCase()
        const num   = (s.student_number ?? '').toLowerCase()
        const email = (s.email ?? '').toLowerCase()
        if (!name.includes(q) && !num.includes(q) && !email.includes(q)) return false
      }
      return true
    })
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [allGraduates, search, filterCollege, filterProgram, filterGender, programMap, deptMap])

  const totalPages    = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE))
  const safePage       = Math.min(page, totalPages)
  const pageGraduates = filtered.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE)

  const hasFilters = search || filterCollege || filterProgram || filterGender

  const programOptions = useMemo(() => (
    Object.entries(programMap)
      .map(([id, p]) => ({ value: id, label: p.name }))
      .sort((a, b) => a.label.localeCompare(b.label, 'ar'))
  ), [programMap])

  const clearFilters = () => {
    setSearch(''); setFilterCollege(''); setFilterProgram(''); setFilterGender('')
    setPage(1)
  }

  const handleDownloadPdf = async () => {
    setPdfLoading(true)
    try {
      await exportRowsToPdf({
        title: 'قائمة الخريجين',
        subtitle: `${filtered.length} خريج${hasFilters ? ' (بعد تطبيق الفلاتر)' : ''}`,
        columns: [
          { header: 'رقم القيد', value: s => s.student_number },
          { header: 'الاسم الكامل', value: s => `${s.first_name} ${s.last_name}` },
          { header: 'الكلية', value: s => getCollegeName(s) },
          { header: 'التخصص', value: s => getProgramName(s) },
          { header: 'البريد الإلكتروني', value: s => s.email },
          { header: 'الهاتف', value: s => s.phone_number },
          { header: 'تاريخ القبول', value: s => s.enrollment_date ? new Date(s.enrollment_date).toLocaleDateString('ar-SY') : null },
        ],
        rows: filtered,
        filename: 'قائمة_الخريجين.pdf',
      })
    } finally {
      setPdfLoading(false)
    }
  }

  const columns = [
    {
      key: 'idx',
      header: '#',
      align: 'left',
      cellClassName: 'text-[12px] text-text-light font-semibold w-10',
      render: (s, idx) => (safePage - 1) * PAGE_SIZE + idx + 1,
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
            {loading ? 'جاري التحميل…' : (
              hasFilters
                ? `${filtered.length} نتيجة من أصل ${allGraduates.length} خريج`
                : (allGraduates.length > 0 ? `${allGraduates.length} خريج مسجّل` : 'لا يوجد خريجون بعد')
            )}
          </p>
        </div>
        <div className="flex items-center gap-2.5">
          <button
            className="flex items-center gap-2 px-4 py-2.5 bg-white border border-primary/25 text-primary-dark rounded-[12px] text-[13.5px] font-bold whitespace-nowrap transition-all duration-[220ms] hover:bg-primary/6 disabled:opacity-50 disabled:cursor-not-allowed"
            onClick={handleDownloadPdf}
            disabled={pdfLoading || loading || filtered.length === 0}
            dir="rtl"
          >
            {pdfLoading ? <FaSpinner className="animate-spin text-[12px]" /> : <FaDownload className="text-[12px]" />}
            <span>{pdfLoading ? 'جارٍ التجهيز…' : 'تنزيل'}</span>
          </button>
          <div className="flex items-center gap-2 px-4 py-2 bg-purple-50 border border-purple-200 rounded-[12px]" dir="rtl">
            <FaGraduationCap className="text-purple-500 text-[15px]" />
            <span className="text-[13px] font-bold text-purple-700">Graduates</span>
          </div>
        </div>
      </div>

      <FilterBar
        search={{ value: search, onChange: setSearch, placeholder: 'ابحث باسم الخريج، رقم القيد، البريد الإلكتروني…' }}
        filters={[
          {
            key: 'college',
            value: filterCollege,
            onChange: v => { setFilterCollege(v); setPage(1) },
            placeholder: 'جميع الكليات',
            minWidth: 160,
            options: colleges.map(c => ({ value: String(c.college_id), label: c.college_name })),
          },
          {
            key: 'program',
            value: filterProgram,
            onChange: v => { setFilterProgram(v); setPage(1) },
            placeholder: 'جميع التخصصات',
            minWidth: 170,
            options: programOptions,
          },
          {
            key: 'gender',
            value: filterGender,
            onChange: v => { setFilterGender(v); setPage(1) },
            placeholder: 'الجنس',
            minWidth: 110,
            options: [
              { value: 'male', label: 'ذكر' },
              { value: 'female', label: 'أنثى' },
            ],
          },
        ]}
        hasActiveFilters={!!hasFilters}
        onClear={clearFilters}
        disabled={loading}
      />

      {/* Error */}
      {error && (
        <div className="flex items-center justify-between gap-3 bg-red-500/6 border border-red-500/25 rounded-[12px] px-[18px] py-3 mb-4 text-[13.5px] text-red-600" dir="rtl">
          <span>⚠ {error}</span>
          <button
            className="px-3.5 py-1 border border-red-500/35 rounded-[8px] text-red-600 text-[12px] cursor-pointer transition-all duration-200 hover:bg-red-500/8"
            onClick={load}
          >
            إعادة المحاولة
          </button>
        </div>
      )}

      <DataTable
        columns={columns}
        rows={pageGraduates}
        rowKey={s => s.student_id}
        loading={loading}
        animationKey={`${search}-${filterCollege}-${filterProgram}-${filterGender}-${safePage}`}
        emptyIcon={FaGraduationCap}
        emptyTitle="لا يوجد خريجون"
        emptySubtitle="No graduates found"
        hasFilters={!!hasFilters}
        onClearFilters={clearFilters}
        page={safePage}
        totalPages={totalPages}
        onPageChange={setPage}
      />
    </>
  )
}
