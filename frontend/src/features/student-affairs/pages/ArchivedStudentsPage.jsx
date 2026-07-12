import { useState, useEffect, useMemo, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaArchive, FaBoxOpen } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'

const API = 'https://rust.alrowaduni.edu.sy/api/v1'

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

export default function ArchivedStudentsPage() {
  const [students, setStudents]     = useState([])
  const [programMap, setProgramMap] = useState({})
  const [deptMap, setDeptMap]       = useState({})
  const [colleges, setColleges]     = useState([])
  const [loading, setLoading]       = useState(true)
  const [error, setError]           = useState('')
  const navigate                    = useNavigate()

  const [search,        setSearch]        = useState('')
  const [filterCollege, setFilterCollege] = useState('')
  const [filterProgram, setFilterProgram] = useState('')
  const [filterGender,  setFilterGender]  = useState('')

  const fetchArchived = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const [res, lookups] = await Promise.all([
        fetch(`${API}/students/deleted`, { headers: authHeaders() }),
        loadLookups(),
      ])
      if (res.status === 401) { navigate('/login'); return }
      const json = await res.json()
      if (json.success) {
        setStudents(Array.isArray(json.data) ? json.data : [])
      } else {
        setError(json.message || 'فشل تحميل البيانات')
      }
      setProgramMap(lookups.programMap ?? {})
      setDeptMap(lookups.deptMap ?? {})
      setColleges(lookups.colleges ?? [])
    } catch {
      setError('تعذّر الاتصال بالخادم. تأكد أن php artisan serve يعمل.')
    } finally {
      setLoading(false)
    }
  }, [navigate])

  useEffect(() => { fetchArchived() }, [fetchArchived])

  function getCollegeId(student) {
    const prog = programMap[student.academic_program_id]
    if (!prog) return null
    return deptMap[prog.dept_id]?.college_id ?? null
  }

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase()
    return students.filter(s => {
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
  }, [students, search, filterCollege, filterProgram, filterGender, programMap, deptMap])

  const hasFilters = search || filterCollege || filterProgram || filterGender

  const programOptions = useMemo(() => (
    Object.entries(programMap)
      .map(([id, p]) => ({ value: id, label: p.name }))
      .sort((a, b) => a.label.localeCompare(b.label, 'ar'))
  ), [programMap])

  const clearFilters = () => {
    setSearch(''); setFilterCollege(''); setFilterProgram(''); setFilterGender('')
  }

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

  const columns = [
    {
      key: 'idx',
      header: '#',
      align: 'left',
      cellClassName: 'text-[12px] text-text-light font-semibold w-10',
      render: (s, idx) => idx + 1,
    },
    {
      key: 'student_number',
      header: 'رقم القيد',
      render: s => (
        <span className="inline-block px-2.5 py-[3px] bg-slate-100 border border-slate-200 rounded-[8px] text-[12px] font-bold text-slate-500 font-mono">
          {s.student_number}
        </span>
      ),
    },
    {
      key: 'name',
      header: 'الاسم الكامل',
      dir: 'rtl',
      cellClassName: 'text-[13.5px] font-semibold text-text-gray',
      render: s => `${s.first_name} ${s.last_name}`,
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
      cellClassName: 'text-[13.5px] text-text-gray',
      render: s => s.phone_number || '—',
    },
    {
      key: 'enrollment_date',
      header: 'تاريخ القبول',
      cellClassName: 'text-[13.5px] text-text-gray',
      render: s => s.enrollment_date ? new Date(s.enrollment_date).toLocaleDateString('ar-SY') : '—',
    },
    {
      key: 'actions',
      header: 'الإجراءات',
      render: s => (
        <button
          className="flex items-center gap-1.5 px-3 py-1.5 rounded-[8px] border text-[12.5px] font-bold cursor-pointer transition-all duration-[180ms] text-green-600 border-green-500/25 bg-green-500/6 hover:bg-green-500/14 hover:border-green-500/40"
          onClick={() => handleRestore(s.student_id)}
          dir="rtl"
        >
          <FaBoxOpen className="text-[12px]" />
          استعادة
        </button>
      ),
    },
  ]

  return (
    <>
      {/* Page header */}
      <div className="flex items-center justify-between mb-5 gap-4 flex-wrap">
        <div dir="rtl">
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">الطلاب المؤرشفون</h2>
          <p className="text-[12.5px] text-text-light">
            {loading ? 'جاري التحميل…' : (
              hasFilters
                ? `${filtered.length} نتيجة من أصل ${students.length} طالب مؤرشف`
                : `${students.length} طالب مؤرشف`
            )}
          </p>
        </div>
      </div>

      {/* Info banner */}
      <div className="flex items-center gap-2.5 bg-slate-50 border border-slate-200 rounded-[12px] px-4 py-3 mb-5 text-[13px] text-slate-600" dir="rtl">
        <FaArchive className="text-slate-400 flex-shrink-0" />
        <span>الطلاب المؤرشفون محفوظون في قاعدة البيانات ولم يُحذفوا. يمكنك استعادة أي طالب لإعادته للقائمة النشطة.</span>
      </div>

      <FilterBar
        search={{ value: search, onChange: setSearch, placeholder: 'ابحث باسم الطالب، رقم القيد، البريد الإلكتروني…' }}
        filters={[
          {
            key: 'college',
            value: filterCollege,
            onChange: setFilterCollege,
            placeholder: 'جميع الكليات',
            minWidth: 160,
            options: colleges.map(c => ({ value: String(c.college_id), label: c.college_name })),
          },
          {
            key: 'program',
            value: filterProgram,
            onChange: setFilterProgram,
            placeholder: 'جميع التخصصات',
            minWidth: 170,
            options: programOptions,
          },
          {
            key: 'gender',
            value: filterGender,
            onChange: setFilterGender,
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
            className="px-3.5 py-1 bg-transparent border border-red-500/35 rounded-[8px] text-red-600 text-[12px] cursor-pointer whitespace-nowrap transition-all duration-200 hover:bg-red-500/8"
            onClick={fetchArchived}
          >
            إعادة المحاولة
          </button>
        </div>
      )}

      <DataTable
        columns={columns}
        rows={filtered}
        rowKey={s => s.student_id}
        loading={loading}
        animationKey={`archived-${search}-${filterCollege}-${filterProgram}-${filterGender}`}
        emptyIcon={FaArchive}
        emptyTitle="لا يوجد طلاب مؤرشفون"
        emptySubtitle="No archived students"
        hasFilters={!!hasFilters}
        onClearFilters={clearFilters}
        page={1}
        totalPages={1}
        onPageChange={() => {}}
        containerBorderClass="border-slate-200"
        headerBgClass="bg-slate-600"
        rowClassName="border-b border-slate-100 last:border-b-0 bg-slate-50/40 hover:bg-slate-100/60 transition-colors duration-150"
        emptyIconClass="text-slate-200"
      />
    </>
  )
}
