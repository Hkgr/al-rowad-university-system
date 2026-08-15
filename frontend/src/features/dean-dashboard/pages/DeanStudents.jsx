import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaEye, FaGraduationCap, FaPhone } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { apiRequest } from '../../../services/apiClient'

const PAGE_SIZE = 15
const API_PAGE_SIZE = 100
const YEAR_ORDINALS_AR = ['الأولى', 'الثانية', 'الثالثة', 'الرابعة', 'الخامسة', 'السادسة', 'السابعة']

const STATUS_MAP = {
  1: { ar: 'يدرس حاليًا', color: '#22c55e', bg: 'rgba(34,197,94,0.1)' },
  2: { ar: 'منقطع', color: '#3b82f6', bg: 'rgba(59,130,246,0.1)' },
  3: { ar: 'خريج', color: '#8b5cf6', bg: 'rgba(139,92,246,0.1)' },
  4: { ar: 'مسحوب', color: '#f59e0b', bg: 'rgba(245,158,11,0.1)' },
  5: { ar: 'مفصول', color: '#ef4444', bg: 'rgba(239,68,68,0.1)' },
  6: { ar: 'موقوف', color: '#f97316', bg: 'rgba(249,115,22,0.1)' },
}

let lookupPromise

function arabicYearLabel(order) {
  if (!order || order < 1) return null
  const word = YEAR_ORDINALS_AR[order - 1]
  return word ? `السنة ${word}` : `السنة ${order}`
}

function paginatedRows(response) {
  return response?.data?.data ?? []
}

async function fetchAllPages(path) {
  const firstResponse = await apiRequest(`/v1/${path}?per_page=${API_PAGE_SIZE}&page=1`)
  const rows = [...paginatedRows(firstResponse)]
  const lastPage = firstResponse?.data?.meta?.last_page ?? 1

  if (lastPage > 1) {
    const remainingResponses = await Promise.all(
      Array.from(
        { length: lastPage - 1 },
        (_, index) => apiRequest(`/v1/${path}?per_page=${API_PAGE_SIZE}&page=${index + 2}`),
      ),
    )
    remainingResponses.forEach(response => rows.push(...paginatedRows(response)))
  }

  return rows
}

function loadLookups() {
  if (!lookupPromise) {
    lookupPromise = Promise.all([
      fetchAllPages('academic-programs'),
      fetchAllPages('academic-levels'),
    ]).then(([programs, levels]) => {
      const programMap = Object.fromEntries(
        programs.map(program => [program.academic_program_id, program.program_name]),
      )
      const levelMap = Object.fromEntries(
        levels.map(level => [
          level.academic_level_id,
          { name: level.level_name, order: level.level_order },
        ]),
      )
      return { programMap, levelMap }
    }).catch(error => {
      lookupPromise = null
      throw error
    })
  }

  return lookupPromise
}

function StatusBadge({ statusId }) {
  const status = STATUS_MAP[statusId]
  if (!status) return <span className="text-[11px] text-text-light">—</span>

  return (
    <span
      className="inline-block px-2 py-[3px] rounded-full text-[11px] font-bold whitespace-nowrap"
      style={{ color: status.color, background: status.bg }}
    >
      {status.ar}
    </span>
  )
}

export default function DeanStudents() {
  const [allStudents, setAllStudents] = useState([])
  const [programMap, setProgramMap] = useState({})
  const [levelMap, setLevelMap] = useState({})
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [revealedPhones, setRevealedPhones] = useState(new Set())
  const [search, setSearch] = useState('')
  const [filterProgram, setFilterProgram] = useState('')
  const [filterLevel, setFilterLevel] = useState('')
  const [filterStatus, setFilterStatus] = useState('')
  const [filterGender, setFilterGender] = useState('')
  const [page, setPage] = useState(1)
  const navigate = useNavigate()

  useEffect(() => {
    let active = true

    async function load() {
      setLoading(true)
      setError('')

      try {
        const [students, lookups] = await Promise.all([
          fetchAllPages('students'),
          loadLookups(),
        ])
        if (!active) return

        setAllStudents(students)
        setProgramMap(lookups.programMap)
        setLevelMap(lookups.levelMap)
      } catch (requestError) {
        if (!active) return

        if (requestError.status === 401) {
          navigate('/login', { replace: true })
          return
        }

        setError(
          requestError.status === 403
            ? 'ليس لديك صلاحية لعرض بيانات طلاب الكلية.'
            : 'تعذّر تحميل بيانات الطلاب. يرجى المحاولة مرة أخرى.',
        )
      } finally {
        if (active) setLoading(false)
      }
    }

    load()
    return () => { active = false }
  }, [navigate])

  const filteredStudents = useMemo(() => {
    const query = search.trim().toLowerCase()

    return allStudents.filter(student => {
      if (filterProgram && String(student.academic_program_id) !== filterProgram) return false
      if (filterLevel && String(student.current_academic_level_id) !== filterLevel) return false
      if (filterStatus && String(student.student_status_id) !== filterStatus) return false
      if (filterGender && student.gender !== filterGender) return false

      if (query) {
        const fullName = `${student.first_name ?? ''} ${student.last_name ?? ''}`.toLowerCase()
        const studentNumber = String(student.student_number ?? '').toLowerCase()
        const email = String(student.email ?? '').toLowerCase()
        if (!fullName.includes(query) && !studentNumber.includes(query) && !email.includes(query)) {
          return false
        }
      }

      return true
    })
  }, [allStudents, filterGender, filterLevel, filterProgram, filterStatus, search])

  const totalPages = Math.max(1, Math.ceil(filteredStudents.length / PAGE_SIZE))
  const safePage = Math.min(page, totalPages)
  const pageStudents = filteredStudents.slice(
    (safePage - 1) * PAGE_SIZE,
    safePage * PAGE_SIZE,
  )
  const hasFilters = Boolean(search || filterProgram || filterLevel || filterStatus || filterGender)

  const programOptions = useMemo(() => {
    const availableProgramIds = new Set(
      allStudents.map(student => String(student.academic_program_id)),
    )

    return Object.entries(programMap)
      .filter(([id]) => availableProgramIds.has(id))
      .map(([value, label]) => ({ value, label }))
      .sort((a, b) => a.label.localeCompare(b.label, 'ar'))
  }, [allStudents, programMap])

  const levelOptions = useMemo(() => (
    Object.entries(levelMap)
      .map(([value, level]) => ({
        value,
        label: arabicYearLabel(level.order) ?? level.name,
        order: level.order ?? 0,
      }))
      .sort((a, b) => a.order - b.order)
  ), [levelMap])

  function updateFilter(setter, value) {
    setter(value)
    setPage(1)
  }

  function clearFilters() {
    setSearch('')
    setFilterProgram('')
    setFilterLevel('')
    setFilterStatus('')
    setFilterGender('')
    setPage(1)
  }

  function togglePhone(studentId) {
    setRevealedPhones(current => {
      const next = new Set(current)
      if (next.has(studentId)) next.delete(studentId)
      else next.add(studentId)
      return next
    })
  }

  function getLevelName(student) {
    const level = levelMap[student.current_academic_level_id]
    return level ? (arabicYearLabel(level.order) ?? level.name) : null
  }

  const columns = [
    {
      key: 'index',
      header: '#',
      align: 'center',
      cellClassName: 'text-[12px] text-text-light font-semibold w-10',
      render: (_, index) => (safePage - 1) * PAGE_SIZE + index + 1,
    },
    {
      key: 'student_number',
      header: 'رقم القيد',
      align: 'center',
      render: student => (
        <span className="inline-block px-2.5 py-[3px] bg-primary/8 border border-primary/15 rounded-[8px] text-[12px] font-bold text-primary-dark font-mono">
          {student.student_number}
        </span>
      ),
    },
    {
      key: 'name',
      header: 'الاسم الكامل',
      align: 'right',
      dir: 'rtl',
      cellClassName: 'text-[13.5px] font-semibold text-text-dark whitespace-nowrap',
      render: student => `${student.first_name ?? ''} ${student.last_name ?? ''}`.trim() || '—',
    },
    {
      key: 'program',
      header: 'التخصص',
      align: 'right',
      dir: 'rtl',
      render: student => {
        const programName = programMap[student.academic_program_id]
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
      render: student => {
        const levelName = getLevelName(student)
        return levelName
          ? <span className="inline-block px-2.5 py-[3px] bg-slate-500/8 rounded-full text-[11.5px] font-semibold text-slate-600 whitespace-nowrap">{levelName}</span>
          : <span className="text-[11px] text-text-light">—</span>
      },
    },
    {
      key: 'phone',
      header: 'الهاتف',
      align: 'center',
      render: student => (
        revealedPhones.has(student.student_id) ? (
          <button
            className="text-[12px] font-mono text-text-dark whitespace-nowrap cursor-pointer hover:text-primary transition-colors"
            title="إخفاء الرقم"
            onClick={() => togglePhone(student.student_id)}
          >
            {student.phone_number}
          </button>
        ) : (
          <button
            className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[12px] mx-auto cursor-pointer transition-all duration-[180ms] text-green-600 border-green-600/20 bg-green-600/6 hover:bg-green-600/14 hover:border-green-600/35 disabled:opacity-40 disabled:cursor-not-allowed"
            title={student.phone_number ? 'إظهار رقم الهاتف' : 'لا يوجد رقم هاتف'}
            onClick={() => togglePhone(student.student_id)}
            disabled={!student.phone_number}
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
      render: student => (
        student.enrollment_date
          ? new Date(student.enrollment_date).toLocaleDateString('ar-SY')
          : '—'
      ),
    },
    {
      key: 'status',
      header: 'الحالة',
      align: 'center',
      render: student => <StatusBadge statusId={student.student_status_id} />,
    },
    {
      key: 'view',
      header: 'عرض',
      align: 'center',
      render: student => (
        <button
          className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] cursor-pointer transition-all duration-[180ms] text-blue-500 border-blue-500/20 bg-blue-500/6 hover:bg-blue-500/14 hover:border-blue-500/35"
          title="عرض ملف الطالب"
          aria-label="عرض ملف الطالب"
          onClick={() => navigate(`/dean/students/${student.student_id}`)}
        >
          <FaEye />
        </button>
      ),
    },
  ]

  return (
    <div dir="rtl">
      <div className="flex items-center justify-between mb-5 gap-4 flex-wrap">
        <div>
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">طلاب الكلية</h2>
          <p className="text-[12.5px] text-text-light">
            {loading
              ? 'جاري التحميل…'
              : hasFilters
                ? `${filteredStudents.length} نتيجة من أصل ${allStudents.length} طالب`
                : `عرض ومتابعة طلاب الكلية — ${allStudents.length} طالب`}
          </p>
        </div>
      </div>

      <FilterBar
        search={{
          value: search,
          onChange: value => updateFilter(setSearch, value),
          placeholder: 'ابحث باسم الطالب، رقم القيد، البريد الإلكتروني…',
        }}
        filters={[
          {
            key: 'program',
            value: filterProgram,
            onChange: value => updateFilter(setFilterProgram, value),
            placeholder: 'جميع التخصصات',
            minWidth: 170,
            options: programOptions,
          },
          {
            key: 'level',
            value: filterLevel,
            onChange: value => updateFilter(setFilterLevel, value),
            placeholder: 'جميع السنوات',
            minWidth: 150,
            options: levelOptions,
          },
          {
            key: 'status',
            value: filterStatus,
            onChange: value => updateFilter(setFilterStatus, value),
            placeholder: 'جميع الحالات',
            minWidth: 140,
            options: Object.entries(STATUS_MAP).map(([value, status]) => ({
              value,
              label: status.ar,
            })),
          },
          {
            key: 'gender',
            value: filterGender,
            onChange: value => updateFilter(setFilterGender, value),
            placeholder: 'الجنس',
            minWidth: 110,
            options: [
              { value: 'male', label: 'ذكر' },
              { value: 'female', label: 'أنثى' },
            ],
          },
        ]}
        hasActiveFilters={hasFilters}
        onClear={clearFilters}
        disabled={loading}
      />

      {error && (
        <div className="flex items-center justify-between gap-3 bg-red-500/6 border border-red-500/25 rounded-[12px] px-[18px] py-3 mb-4 text-[13.5px] text-red-600">
          <span>⚠ {error}</span>
        </div>
      )}

      <DataTable
        columns={columns}
        rows={pageStudents}
        rowKey={student => student.student_id}
        loading={loading}
        animationKey={`${search}-${filterProgram}-${filterLevel}-${filterStatus}-${filterGender}-${safePage}`}
        emptyIcon={FaGraduationCap}
        emptyTitle={
          error
            ? 'تعذّر عرض بيانات الطلاب'
            : hasFilters
              ? 'لا توجد نتائج مطابقة'
              : 'لا يوجد طلاب ضمن الكلية حالياً'
        }
        emptySubtitle={error ? 'يرجى المحاولة مرة أخرى لاحقاً' : undefined}
        hasFilters={hasFilters}
        onClearFilters={clearFilters}
        page={safePage}
        totalPages={totalPages}
        onPageChange={setPage}
      />
    </div>
  )
}
