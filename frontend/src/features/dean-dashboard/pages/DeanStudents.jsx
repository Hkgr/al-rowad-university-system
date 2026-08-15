import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  FaChevronDown, FaDownload, FaEye, FaFileExcel, FaFilePdf, FaGraduationCap, FaPhone, FaSpinner,
} from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { apiRequest } from '../../../services/apiClient'
import { exportRowsToExcel } from '../../../utils/excelExport'
import { exportRowsToPdf } from '../../../utils/pdfExport'
import StudentStatusBadge from '../components/StudentStatusBadge'
import {
  STUDENT_STATUS_FILTER_OPTIONS,
  arabicYearLabel,
  formatDate,
  fullStudentName,
  genderLabel,
  normalizeSearchText,
  studentStatusLabel,
} from '../utils/studentDisplay'

const PAGE_SIZE = 15
const API_PAGE_SIZE = 100

let lookupPromise

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
      fetchAllPages('colleges').catch(() => []),
    ]).then(([programs, levels, colleges]) => {
      const programMap = Object.fromEntries(
        programs.map(program => [program.academic_program_id, program.program_name]),
      )
      const levelMap = Object.fromEntries(
        levels.map(level => [
          level.academic_level_id,
          { name: level.level_name, order: level.level_order },
        ]),
      )
      return {
        programMap,
        levelMap,
        colleges: Array.isArray(colleges) ? colleges : [],
      }
    }).catch(error => {
      lookupPromise = null
      throw error
    })
  }

  return lookupPromise
}

function todayStamp() {
  return new Date().toISOString().slice(0, 10)
}

export default function DeanStudents() {
  const [allStudents, setAllStudents] = useState([])
  const [programMap, setProgramMap] = useState({})
  const [levelMap, setLevelMap] = useState({})
  const [colleges, setColleges] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [exportError, setExportError] = useState('')
  const [exporting, setExporting] = useState(false)
  const [exportMenuOpen, setExportMenuOpen] = useState(false)
  const [revealedPhones, setRevealedPhones] = useState(new Set())
  const [search, setSearch] = useState('')
  const [filterProgram, setFilterProgram] = useState('')
  const [filterLevel, setFilterLevel] = useState('')
  const [filterStatus, setFilterStatus] = useState('')
  const [filterGender, setFilterGender] = useState('')
  const [page, setPage] = useState(1)
  const navigate = useNavigate()
  const exportMenuRef = useRef(null)

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
        setColleges(lookups.colleges ?? [])
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

  useEffect(() => {
    if (!exportMenuOpen) return undefined

    function handlePointerDown(event) {
      if (!exportMenuRef.current?.contains(event.target)) {
        setExportMenuOpen(false)
      }
    }

    function handleKeyDown(event) {
      if (event.key === 'Escape') setExportMenuOpen(false)
    }

    document.addEventListener('mousedown', handlePointerDown)
    document.addEventListener('keydown', handleKeyDown)
    return () => {
      document.removeEventListener('mousedown', handlePointerDown)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [exportMenuOpen])

  const filteredStudents = useMemo(() => {
    const query = normalizeSearchText(search)

    return allStudents.filter(student => {
      if (filterProgram && String(student.academic_program_id) !== filterProgram) return false
      if (filterLevel && String(student.current_academic_level_id) !== filterLevel) return false
      if (filterStatus && String(student.student_status_id) !== filterStatus) return false
      if (filterGender && student.gender !== filterGender) return false

      if (query) {
        const fullName = normalizeSearchText(fullStudentName(student))
        const studentNumber = normalizeSearchText(student.student_number)
        const email = normalizeSearchText(student.email)
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
  const canExport = !loading && !exporting && filteredStudents.length > 0
  const collegeName = colleges.length === 1
    ? colleges[0]?.college_name
    : null

  const programOptions = useMemo(() => {
    const availableProgramIds = new Set(
      allStudents
        .map(student => student.academic_program_id)
        .filter(id => id != null)
        .map(id => String(id)),
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

  function getProgramName(student) {
    return programMap[student.academic_program_id] ?? 'لم يتخصص بعد'
  }

  function getLevelName(student) {
    const level = levelMap[student.current_academic_level_id]
    return level ? (arabicYearLabel(level.order) ?? level.name) : null
  }

  function buildReportColumns() {
    return [
      { header: 'رقم القيد', value: student => student.student_number, text: true },
      { header: 'الاسم الكامل', value: student => fullStudentName(student) },
      { header: 'التخصص', value: student => getProgramName(student) },
      { header: 'السنة الدراسية', value: student => getLevelName(student) },
      { header: 'الهاتف', value: student => student.phone_number, text: true },
      { header: 'البريد الإلكتروني', value: student => student.email },
      { header: 'الجنس', value: student => genderLabel(student.gender) },
      { header: 'تاريخ القبول', value: student => formatDate(student.enrollment_date) },
      { header: 'الحالة', value: student => studentStatusLabel(student) },
    ]
  }

  function buildSubtitleParts() {
    return [
      collegeName ? `الكلية: ${collegeName}` : null,
      `عدد الطلاب: ${filteredStudents.length}`,
      hasFilters ? 'بعد تطبيق الفلاتر' : 'بدون فلاتر نشطة',
      `تاريخ الإنشاء: ${formatDate(new Date())}`,
    ].filter(Boolean)
  }

  async function handleExport(format) {
    if (exporting) return

    if (!filteredStudents.length) {
      setExportError('لا توجد بيانات مطابقة لتصديرها.')
      setExportMenuOpen(false)
      return
    }

    setExportMenuOpen(false)
    setExportError('')
    setExporting(true)

    try {
      const columns = buildReportColumns()
      const subtitleParts = buildSubtitleParts()
      const filenameBase = `تقرير_طلاب_الكلية_${todayStamp()}`

      if (format === 'pdf') {
        await exportRowsToPdf({
          title: 'تقرير طلاب الكلية',
          subtitle: subtitleParts.join(' — '),
          columns,
          rows: filteredStudents,
          filename: `${filenameBase}.pdf`,
        })
      } else {
        exportRowsToExcel({
          title: 'تقرير طلاب الكلية',
          subtitleLines: subtitleParts,
          sheetName: 'طلاب الكلية',
          columns,
          rows: filteredStudents,
          filename: `${filenameBase}.xlsx`,
        })
      }
    } catch {
      setExportError('تعذّر إنشاء التقرير. يرجى المحاولة مرة أخرى.')
    } finally {
      setExporting(false)
    }
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
          {student.student_number || '—'}
        </span>
      ),
    },
    {
      key: 'name',
      header: 'الاسم الكامل',
      align: 'right',
      dir: 'rtl',
      cellClassName: 'text-[13.5px] font-semibold text-text-dark',
      render: student => {
        const name = fullStudentName(student)
        return (
          <span className="block max-w-[200px] truncate" title={name}>
            {name}
          </span>
        )
      },
    },
    {
      key: 'program',
      header: 'التخصص',
      align: 'right',
      dir: 'rtl',
      render: student => {
        const programName = programMap[student.academic_program_id]
        return programName
          ? (
            <span
              className="block max-w-[180px] truncate text-[12px] font-medium text-text-gray"
              title={programName}
            >
              {programName}
            </span>
          )
          : (
            <span
              className="inline-block px-2 py-[3px] rounded-full text-[11px] font-bold whitespace-nowrap text-amber-600"
              style={{ background: 'rgba(245,158,11,0.1)' }}
            >
              لم يتخصص بعد
            </span>
          )
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
            type="button"
            className="text-[12px] font-mono text-text-dark whitespace-nowrap cursor-pointer hover:text-primary transition-colors"
            title="إخفاء الرقم"
            aria-label="إخفاء رقم الهاتف"
            onClick={() => togglePhone(student.student_id)}
          >
            {student.phone_number}
          </button>
        ) : (
          <button
            type="button"
            className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[12px] mx-auto cursor-pointer transition-all duration-[180ms] text-green-600 border-green-600/20 bg-green-600/6 hover:bg-green-600/14 hover:border-green-600/35 disabled:opacity-40 disabled:cursor-not-allowed"
            title={student.phone_number ? 'إظهار رقم الهاتف' : 'لا يوجد رقم هاتف'}
            aria-label={student.phone_number ? 'إظهار رقم الهاتف' : 'لا يوجد رقم هاتف'}
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
      render: student => formatDate(student.enrollment_date),
    },
    {
      key: 'status',
      header: 'الحالة',
      align: 'center',
      render: student => <StudentStatusBadge statusId={student.student_status_id} />,
    },
    {
      key: 'view',
      header: 'عرض',
      align: 'center',
      render: student => (
        <button
          type="button"
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
      <div className="flex items-start sm:items-center justify-between mb-5 gap-4 flex-wrap">
        <div className="min-w-0">
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">طلاب الكلية</h2>
          <p className="text-[12.5px] text-text-light">
            {loading
              ? 'جاري التحميل…'
              : hasFilters
                ? `${filteredStudents.length} نتيجة من أصل ${allStudents.length} طالب`
                : `عرض ومتابعة طلاب الكلية — ${allStudents.length} طالب`}
          </p>
        </div>

        <div className="relative" ref={exportMenuRef}>
          <button
            type="button"
            className="flex items-center gap-2 px-4 py-2.5 bg-white border border-primary/25 text-primary-dark rounded-[12px] text-[13.5px] font-bold whitespace-nowrap transition-all duration-[220ms] hover:bg-primary/6 disabled:opacity-50 disabled:cursor-not-allowed"
            onClick={() => {
              if (exporting) return
              if (!filteredStudents.length) {
                setExportError('لا توجد بيانات مطابقة لتصديرها.')
                setExportMenuOpen(false)
                return
              }
              setExportError('')
              setExportMenuOpen(open => !open)
            }}
            disabled={loading || exporting}
            aria-haspopup="menu"
            aria-expanded={exportMenuOpen}
            aria-label="تنزيل التقرير"
          >
            {exporting
              ? <FaSpinner className="animate-spin text-[12px]" aria-hidden="true" />
              : <FaDownload className="text-[12px]" aria-hidden="true" />}
            <span>{exporting ? 'جاري إنشاء التقرير…' : 'تنزيل التقرير'}</span>
            {!exporting && <FaChevronDown className="text-[10px] opacity-70" aria-hidden="true" />}
          </button>

          {exportMenuOpen && (
            <div
              className="absolute left-0 top-[calc(100%+6px)] z-20 min-w-[180px] bg-white border border-primary/15 rounded-[12px] shadow-[0_8px_24px_rgba(26,46,16,0.12)] overflow-hidden"
              role="menu"
            >
              <button
                type="button"
                className="w-full flex items-center gap-2.5 px-4 py-2.5 text-[13px] font-semibold text-text-dark hover:bg-primary/6 transition-colors disabled:opacity-45 disabled:cursor-not-allowed"
                onClick={() => handleExport('pdf')}
                disabled={!canExport}
                role="menuitem"
              >
                <FaFilePdf className="text-red-500 text-[13px]" aria-hidden="true" />
                <span>PDF</span>
              </button>
              <button
                type="button"
                className="w-full flex items-center gap-2.5 px-4 py-2.5 text-[13px] font-semibold text-text-dark hover:bg-primary/6 transition-colors border-t border-primary/8 disabled:opacity-45 disabled:cursor-not-allowed"
                onClick={() => handleExport('excel')}
                disabled={!canExport}
                role="menuitem"
              >
                <FaFileExcel className="text-green-600 text-[13px]" aria-hidden="true" />
                <span>Excel</span>
              </button>
            </div>
          )}
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
            options: STUDENT_STATUS_FILTER_OPTIONS.map(({ value, label }) => ({ value, label })),
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

      {exportError && (
        <div className="flex items-center justify-between gap-3 bg-amber-500/8 border border-amber-500/25 rounded-[12px] px-[18px] py-3 mb-4 text-[13.5px] text-amber-700">
          <span>⚠ {exportError}</span>
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
