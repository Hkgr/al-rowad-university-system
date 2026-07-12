import { useState, useEffect, useMemo, useRef } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { FaUserPlus, FaEye, FaEdit, FaArchive, FaGraduationCap, FaPhone, FaDownload, FaSpinner } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { exportRowsToPdf } from '../../../utils/pdfExport'

const API = 'https://rust.alrowaduni.edu.sy/api/v1'
const PAGE_SIZE = 15

// Arabic ordinal words keyed by the numeric level_order field (language-neutral,
// unlike level_name which is hardcoded English in the DB seed data)
const YEAR_ORDINALS_AR = ['الأولى', 'الثانية', 'الثالثة', 'الرابعة', 'الخامسة', 'السادسة', 'السابعة']

function arabicYearLabel(order) {
  if (!order || order < 1) return null
  const word = YEAR_ORDINALS_AR[order - 1]
  return word ? `السنة ${word}` : `السنة ${order}`
}

const STATUS_MAP = {
  1: { ar: 'يدرس حاليًا', color: '#22c55e', bg: 'rgba(34,197,94,0.1)'  },
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
const _cache = { programMap: null, deptMap: null, colleges: null, levelMap: null }

async function loadLookups() {
  if (_cache.programMap) return _cache   // already loaded
  const [progs, depts, cols, levels] = await Promise.all([
    fetchAll(`${API}/academic-programs?per_page=100`),
    fetchAll(`${API}/departments?per_page=100`),
    fetchAll(`${API}/colleges?per_page=50`),
    fetchAll(`${API}/academic-levels?per_page=100`),
  ])
  const pm = {}
  if (Array.isArray(progs)) progs.forEach(p => { pm[p.academic_program_id] = { name: p.program_name, dept_id: p.department_id } })
  const dm = {}
  if (Array.isArray(depts)) depts.forEach(d => { dm[d.department_id] = { college_id: d.college_id } })
  const lm = {}
  if (Array.isArray(levels)) levels.forEach(l => { lm[l.academic_level_id] = { name: l.level_name, order: l.level_order } })
  _cache.programMap = pm
  _cache.deptMap    = dm
  _cache.colleges   = Array.isArray(cols) ? cols : []
  _cache.levelMap   = lm
  return _cache
}

export default function StudentsPage() {
  const [allStudents, setAllStudents]   = useState([])
  const [programMap, setProgramMap]     = useState({})   // id -> { name, dept_id }
  const [deptMap, setDeptMap]           = useState({})   // id -> { name, college_id }
  const [colleges, setColleges]         = useState([])   // [{college_id, college_name}]
  const [levelMap, setLevelMap]         = useState({})   // id -> { name, order }
  const [loading, setLoading]           = useState(true)
  const [error, setError]               = useState('')
  const [revealedPhones, setRevealedPhones] = useState(new Set())
  const [pdfLoading, setPdfLoading]     = useState(false)

  // Filters
  const [search, setSearch]             = useState('')
  const [filterCollege, setFilterCollege] = useState('')
  const [filterStatus, setFilterStatus]   = useState('')
  const [filterProgram, setFilterProgram] = useState('')
  const [filterLevel, setFilterLevel]     = useState('')
  const [filterGender, setFilterGender]   = useState('')
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
        setLevelMap(lookups.levelMap ?? {})
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

  function getProgramName(student) {
    return programMap[student.academic_program_id]?.name ?? null
  }

  function getLevelName(student) {
    const lvl = levelMap[student.current_academic_level_id]
    if (!lvl) return null
    return arabicYearLabel(lvl.order) ?? lvl.name ?? null
  }

  function togglePhone(studentId) {
    setRevealedPhones(prev => {
      const next = new Set(prev)
      if (next.has(studentId)) next.delete(studentId)
      else next.add(studentId)
      return next
    })
  }

  // Debounced search — just resets page
  useEffect(() => {
    clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(() => setPage(1), 300)
    return () => clearTimeout(debounceRef.current)
  }, [search, filterCollege, filterStatus, filterProgram, filterLevel, filterGender])

  // Client-side filtering
  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase()
    return allStudents.filter(s => {
      if (filterStatus && String(s.student_status_id) !== filterStatus) return false
      if (filterCollege && String(getCollegeId(s)) !== filterCollege) return false
      if (filterProgram && String(s.academic_program_id) !== filterProgram) return false
      if (filterLevel && String(s.current_academic_level_id) !== filterLevel) return false
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
  }, [allStudents, search, filterCollege, filterStatus, filterProgram, filterLevel, filterGender, programMap, deptMap, colleges])

  const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE))
  const safePage   = Math.min(page, totalPages)
  const pageStudents = filtered.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE)

  const hasFilters = search || filterCollege || filterStatus || filterProgram || filterLevel || filterGender

  // Dropdown option lists derived from the already-loaded lookups
  const programOptions = useMemo(() => (
    Object.entries(programMap)
      .map(([id, p]) => ({ value: id, label: p.name }))
      .sort((a, b) => a.label.localeCompare(b.label, 'ar'))
  ), [programMap])

  const levelOptions = useMemo(() => (
    Object.entries(levelMap)
      .map(([id, l]) => ({ value: id, label: arabicYearLabel(l.order) ?? l.name, order: l.order ?? 0 }))
      .sort((a, b) => a.order - b.order)
  ), [levelMap])

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

  const clearFilters = () => {
    setSearch(''); setFilterCollege(''); setFilterStatus('')
    setFilterProgram(''); setFilterLevel(''); setFilterGender('')
    setPage(1)
  }

  const handleDownloadPdf = async () => {
    setPdfLoading(true)
    try {
      await exportRowsToPdf({
        title: 'قائمة الطلاب',
        subtitle: `${filtered.length} طالب${hasFilters ? ' (بعد تطبيق الفلاتر)' : ''}`,
        columns: [
          { header: 'رقم القيد', value: s => s.student_number },
          { header: 'الاسم الكامل', value: s => `${s.first_name} ${s.last_name}` },
          { header: 'الكلية', value: s => getCollegeName(s) },
          { header: 'التخصص', value: s => getProgramName(s) ?? 'لم يتخصص بعد' },
          { header: 'السنة الدراسية', value: s => getLevelName(s) },
          { header: 'الهاتف', value: s => s.phone_number },
          { header: 'تاريخ القبول', value: s => s.enrollment_date ? new Date(s.enrollment_date).toLocaleDateString('ar-SY') : null },
          { header: 'الحالة', value: s => STATUS_MAP[s.student_status_id]?.ar },
        ],
        rows: filtered,
        filename: 'قائمة_الطلاب.pdf',
      })
    } finally {
      setPdfLoading(false)
    }
  }

  // Column definitions for the shared DataTable — everything this page-specific
  // (rendering, alignment) lives here; DataTable only knows how to lay it out.
  const columns = [
    {
      key: 'idx',
      header: '#',
      align: 'center',
      cellClassName: 'text-[12px] text-text-light font-semibold w-10',
      render: (s, idx) => (safePage - 1) * PAGE_SIZE + idx + 1,
    },
    {
      key: 'student_number',
      header: 'رقم القيد',
      align: 'center',
      render: s => (
        <span className="inline-block px-2.5 py-[3px] bg-primary/8 border border-primary/15 rounded-[8px] text-[12px] font-bold text-primary-dark font-mono">
          {s.student_number}
        </span>
      ),
    },
    {
      key: 'name',
      header: 'الاسم الكامل',
      align: 'right',
      dir: 'rtl',
      cellClassName: 'text-[13.5px] font-semibold text-text-dark whitespace-nowrap',
      render: s => `${s.first_name} ${s.last_name}`,
    },
    {
      key: 'college',
      header: 'الكلية',
      align: 'right',
      dir: 'rtl',
      render: s => {
        const collegeName = getCollegeName(s)
        return collegeName
          ? <span className="text-[12px] font-medium text-text-gray whitespace-nowrap">{collegeName}</span>
          : <span className="text-[11px] text-text-light">—</span>
      },
    },
    {
      key: 'program',
      header: 'التخصص',
      align: 'right',
      dir: 'rtl',
      render: s => {
        const programName = getProgramName(s)
        return programName
          ? <span className="text-[12px] font-medium text-text-gray whitespace-nowrap">{programName}</span>
          : <span className="inline-block px-2 py-[3px] rounded-full text-[11px] font-bold whitespace-nowrap text-amber-600" style={{ background: 'rgba(245,158,11,0.1)' }}>لم يتخصص بعد</span>
      },
    },
    {
      key: 'level',
      header: 'السنة الدراسية',
      align: 'center',
      dir: 'rtl',
      render: s => {
        const levelName = getLevelName(s)
        return levelName
          ? <span className="inline-block px-2.5 py-[3px] bg-slate-500/8 rounded-full text-[11.5px] font-semibold text-slate-600 whitespace-nowrap">{levelName}</span>
          : <span className="text-[11px] text-text-light">—</span>
      },
    },
    {
      key: 'phone',
      header: 'الهاتف',
      align: 'center',
      render: s => (
        revealedPhones.has(s.student_id) ? (
          <button
            className="text-[12px] font-mono text-text-dark whitespace-nowrap cursor-pointer hover:text-primary transition-colors"
            title="إخفاء الرقم"
            onClick={() => togglePhone(s.student_id)}
          >
            {s.phone_number || '—'}
          </button>
        ) : (
          <button
            className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[12px] mx-auto cursor-pointer transition-all duration-[180ms] text-green-600 border-green-600/20 bg-green-600/6 hover:bg-green-600/14 hover:border-green-600/35 disabled:opacity-40 disabled:cursor-not-allowed"
            title={s.phone_number ? 'إظهار رقم الهاتف' : 'لا يوجد رقم هاتف'}
            onClick={() => togglePhone(s.student_id)}
            disabled={!s.phone_number}
          >
            <FaPhone />
          </button>
        )
      ),
    },
    {
      key: 'enrollment_date',
      header: 'تاريخ القبول',
      align: 'center',
      cellClassName: 'text-[13.5px] text-text-dark whitespace-nowrap',
      render: s => s.enrollment_date ? new Date(s.enrollment_date).toLocaleDateString('ar-SY') : '—',
    },
    {
      key: 'status',
      header: 'الحالة',
      align: 'center',
      render: s => <StatusBadge statusId={s.student_status_id} />,
    },
    {
      key: 'actions',
      header: 'الإجراءات',
      align: 'center',
      render: s => (
        <div className="flex items-center justify-center gap-1.5">
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
      ),
    },
  ]

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
          <Link
            to="/student-affairs/students/add"
            className="flex items-center gap-2 px-5 py-2.5 bg-gradient-to-br from-primary to-primary-dark text-white rounded-[12px] no-underline text-[14px] font-bold whitespace-nowrap shadow-[0_4px_16px_rgba(86,153,51,0.35)] transition-all duration-[220ms] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(86,153,51,0.45)]"
            dir="rtl"
          >
            <FaUserPlus />
            <span>إضافة طالب</span>
          </Link>
        </div>
      </div>

      <FilterBar
        search={{ value: search, onChange: setSearch, placeholder: 'ابحث باسم الطالب، رقم القيد، البريد الإلكتروني…' }}
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
            key: 'status',
            value: filterStatus,
            onChange: v => { setFilterStatus(v); setPage(1) },
            placeholder: 'جميع الحالات',
            minWidth: 140,
            options: Object.entries(STATUS_MAP).map(([id, { ar }]) => ({ value: id, label: ar })),
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
            key: 'level',
            value: filterLevel,
            onChange: v => { setFilterLevel(v); setPage(1) },
            placeholder: 'جميع السنوات',
            minWidth: 150,
            options: levelOptions,
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
        </div>
      )}

      <DataTable
        columns={columns}
        rows={pageStudents}
        rowKey={s => s.student_id}
        loading={loading}
        animationKey={`${search}-${filterCollege}-${filterStatus}-${filterProgram}-${filterLevel}-${filterGender}-${safePage}`}
        emptyIcon={FaGraduationCap}
        emptyTitle="لا يوجد طلاب"
        emptySubtitle="No students found"
        hasFilters={!!hasFilters}
        onClearFilters={clearFilters}
        page={safePage}
        totalPages={totalPages}
        onPageChange={setPage}
      />
    </>
  )
}
