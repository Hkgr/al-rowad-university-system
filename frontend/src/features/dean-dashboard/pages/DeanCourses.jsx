import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaBookOpen, FaCog, FaEye } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { apiRequest } from '../../../services/apiClient'
import { hasPermission, PERMISSIONS } from '../../auth/auth'
import {
  OFFERING_STATUS_FILTER_FALLBACK,
  TEACHER_ASSIGNMENT_FILTER_OPTIONS,
  formatAverageMark,
  offeringStatusText,
  statusBadgeClass,
  teacherSlotLabel,
  teacherSlotRank,
} from '../utils/courseOfferingDisplay'
import { displayValue } from '../utils/teacherDisplay'

const PAGE_SIZE = 15
const SEARCH_DEBOUNCE_MS = 400

function paginatedRows(response) {
  return response?.data?.data ?? []
}

function SummaryCard({ label, value, loading }) {
  return (
    <div className="bg-white border border-primary/12 rounded-[14px] px-4 py-3 shadow-[0_2px_12px_rgba(26,46,16,0.05)] min-h-[76px]">
      <p className="text-[11.5px] text-text-light font-semibold mb-1">{label}</p>
      <p className="text-[20px] font-black text-text-dark tabular-nums">
        {loading ? '…' : value}
      </p>
    </div>
  )
}

function TeacherCell({ slot }) {
  const label = teacherSlotLabel(slot)
  const rank = teacherSlotRank(slot)

  if (!slot?.available) {
    return <span className="text-[11.5px] text-text-light font-semibold">غير موجود</span>
  }

  if (!slot.faculty_member_id && !slot.full_name) {
    return <span className="text-[11.5px] text-amber-700 font-semibold">بدون مدرس</span>
  }

  return (
    <div className="min-w-0 max-w-[150px]">
      <p className="truncate text-[12.5px] font-semibold text-text-dark" title={label}>{label}</p>
      {rank ? <p className="truncate text-[10.5px] text-text-light" title={rank}>{rank}</p> : null}
    </div>
  )
}

function buildQuery({
  page,
  search,
  academicYearId,
  semesterId,
  departmentId,
  programId,
  status,
  teacherAssignment,
}) {
  const params = new URLSearchParams({
    per_page: String(PAGE_SIZE),
    page: String(page),
  })
  if (search) params.set('search', search)
  if (academicYearId) params.set('academic_year_id', academicYearId)
  if (semesterId) params.set('semester_id', semesterId)
  if (departmentId) params.set('department_id', departmentId)
  if (programId) params.set('academic_program_id', programId)
  if (status) params.set('status', status)
  if (teacherAssignment) params.set('teacher_assignment', teacherAssignment)
  return params.toString()
}

export default function DeanCourses() {
  const navigate = useNavigate()
  const canManageTeachers = hasPermission(PERMISSIONS.teachingStaffManage)

  const [rows, setRows] = useState([])
  const [summary, setSummary] = useState(null)
  const [filterOptions, setFilterOptions] = useState({
    academic_years: [],
    semesters: [],
    departments: [],
    academic_programs: [],
    statuses: [],
  })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [search, setSearch] = useState('')
  const [appliedSearch, setAppliedSearch] = useState('')
  const [academicYearId, setAcademicYearId] = useState('')
  const [semesterId, setSemesterId] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [programId, setProgramId] = useState('')
  const [status, setStatus] = useState('')
  const [teacherAssignment, setTeacherAssignment] = useState('')
  const [page, setPage] = useState(1)
  const [totalPages, setTotalPages] = useState(1)

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      setAppliedSearch(search.trim())
      setPage(1)
    }, SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(timeout)
  }, [search])

  useEffect(() => {
    let active = true

    async function load() {
      setLoading(true)
      setError('')

      try {
        const query = buildQuery({
          page,
          search: appliedSearch,
          academicYearId,
          semesterId,
          departmentId,
          programId,
          status,
          teacherAssignment,
        })
        const response = await apiRequest(`/v1/dean/course-offerings?${query}`)
        if (!active) return

        setRows(paginatedRows(response))
        setSummary(response?.data?.summary ?? null)
        setFilterOptions({
          academic_years: response?.data?.filter_options?.academic_years ?? [],
          semesters: response?.data?.filter_options?.semesters ?? [],
          departments: response?.data?.filter_options?.departments ?? [],
          academic_programs: response?.data?.filter_options?.academic_programs ?? [],
          statuses: response?.data?.filter_options?.statuses ?? [],
        })
        setTotalPages(Math.max(1, Number(response?.data?.meta?.last_page) || 1))
      } catch (requestError) {
        if (!active) return
        if (requestError.status === 401) {
          navigate('/login', { replace: true })
          return
        }
        setRows([])
        setSummary(null)
        setError(
          requestError.status === 403
            ? 'ليس لديك صلاحية لعرض مواد الكلية.'
            : 'تعذّر تحميل مواد الكلية. يرجى المحاولة مرة أخرى.',
        )
      } finally {
        if (active) setLoading(false)
      }
    }

    load()
    return () => { active = false }
  }, [
    academicYearId,
    appliedSearch,
    departmentId,
    navigate,
    page,
    programId,
    semesterId,
    status,
    teacherAssignment,
  ])

  const hasFilters = Boolean(
    search || academicYearId || semesterId || departmentId || programId || status || teacherAssignment,
  )

  const yearOptions = useMemo(
    () => (filterOptions.academic_years ?? []).map(year => ({
      value: String(year.academic_year_id),
      label: year.year_name,
    })),
    [filterOptions.academic_years],
  )

  const semesterOptions = useMemo(
    () => (filterOptions.semesters ?? []).map(semester => ({
      value: String(semester.semester_id),
      label: semester.semester_name,
    })),
    [filterOptions.semesters],
  )

  const departmentOptions = useMemo(
    () => (filterOptions.departments ?? []).map(department => ({
      value: String(department.department_id),
      label: department.department_name,
    })),
    [filterOptions.departments],
  )

  const programOptions = useMemo(
    () => (filterOptions.academic_programs ?? []).map(program => ({
      value: String(program.academic_program_id),
      label: program.program_name,
    })),
    [filterOptions.academic_programs],
  )

  const statusOptions = useMemo(() => {
    const values = filterOptions.statuses?.length
      ? filterOptions.statuses
      : OFFERING_STATUS_FILTER_FALLBACK.map(option => option.value)
    const labels = Object.fromEntries(
      OFFERING_STATUS_FILTER_FALLBACK.map(option => [option.value, option.label]),
    )
    return values.map(value => ({
      value,
      label: labels[value] || offeringStatusText(value),
    }))
  }, [filterOptions.statuses])

  function updateFilter(setter, value) {
    setter(value)
    setPage(1)
  }

  function clearFilters() {
    setSearch('')
    setAppliedSearch('')
    setAcademicYearId('')
    setSemesterId('')
    setDepartmentId('')
    setProgramId('')
    setStatus('')
    setTeacherAssignment('')
    setPage(1)
  }

  const columns = [
    {
      key: 'index',
      header: '#',
      align: 'center',
      cellClassName: 'text-[12px] text-text-light font-semibold w-10',
      render: (_, index) => (page - 1) * PAGE_SIZE + index + 1,
    },
    {
      key: 'course_code',
      header: 'رمز المادة',
      align: 'center',
      render: offering => (
        <span className="inline-block px-2.5 py-[3px] bg-primary/8 border border-primary/15 rounded-[8px] text-[12px] font-bold text-primary-dark font-mono">
          {displayValue(offering.course?.course_code)}
        </span>
      ),
    },
    {
      key: 'course_name',
      header: 'اسم المادة',
      align: 'right',
      dir: 'rtl',
      render: offering => {
        const name = displayValue(offering.course?.course_name)
        return (
          <span className="block max-w-[180px] truncate text-[13px] font-semibold text-text-dark" title={name === '—' ? undefined : name}>
            {name}
          </span>
        )
      },
    },
    {
      key: 'year',
      header: 'العام الدراسي',
      align: 'center',
      dir: 'rtl',
      render: offering => (
        <span className="text-[12px] text-text-dark whitespace-nowrap">
          {displayValue(offering.academic_year?.year_name)}
        </span>
      ),
    },
    {
      key: 'semester',
      header: 'الفصل',
      align: 'center',
      dir: 'rtl',
      render: offering => (
        <span className="text-[12px] text-text-dark whitespace-nowrap">
          {displayValue(offering.semester?.semester_name)}
        </span>
      ),
    },
    {
      key: 'program',
      header: 'البرنامج',
      align: 'right',
      dir: 'rtl',
      render: offering => {
        const name = displayValue(offering.academic_program?.program_name)
        return (
          <span className="block max-w-[170px] truncate text-[12px] text-text-gray" title={name === '—' ? undefined : name}>
            {name}
          </span>
        )
      },
    },
    {
      key: 'department',
      header: 'القسم',
      align: 'right',
      dir: 'rtl',
      render: offering => {
        const name = displayValue(offering.department?.department_name)
        return (
          <span className="block max-w-[140px] truncate text-[12px] text-text-gray" title={name === '—' ? undefined : name}>
            {name}
          </span>
        )
      },
    },
    {
      key: 'theoretical',
      header: 'مدرس النظري',
      align: 'right',
      dir: 'rtl',
      render: offering => <TeacherCell slot={offering.teachers?.theoretical} />,
    },
    {
      key: 'practical',
      header: 'مدرس العملي',
      align: 'right',
      dir: 'rtl',
      render: offering => <TeacherCell slot={offering.teachers?.practical} />,
    },
    {
      key: 'students',
      header: 'الطلاب',
      align: 'center',
      render: offering => (
        <span className="inline-flex min-w-[28px] justify-center px-2 py-[3px] border rounded-full text-[12px] font-bold bg-primary/8 border-primary/15 text-primary-dark">
          {Number(offering.metrics?.registered_students_count) || 0}
        </span>
      ),
    },
    {
      key: 'sessions',
      header: 'الجلسات',
      align: 'center',
      render: offering => (
        <span className="inline-flex min-w-[28px] justify-center px-2 py-[3px] border rounded-full text-[12px] font-bold bg-sky-500/8 border-sky-500/20 text-sky-700">
          {Number(offering.metrics?.attendance_sessions_count) || 0}
        </span>
      ),
    },
    {
      key: 'average',
      header: 'متوسط العلامة',
      align: 'center',
      render: offering => (
        <span className="text-[12.5px] font-bold text-text-dark tabular-nums">
          {formatAverageMark(offering.metrics?.average_final_mark)}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'حالة الطرح',
      align: 'center',
      dir: 'rtl',
      render: offering => (
        <span className={`inline-block px-2.5 py-[3px] rounded-full text-[11.5px] font-bold ${statusBadgeClass(offering.status)}`}>
          {offeringStatusText(offering.status)}
        </span>
      ),
    },
    {
      key: 'actions',
      header: 'عرض / إدارة',
      align: 'center',
      render: offering => (
        <div className="flex items-center justify-center gap-1.5">
          <button
            type="button"
            className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] cursor-pointer transition-all duration-[180ms] text-blue-500 border-blue-500/20 bg-blue-500/6 hover:bg-blue-500/14 hover:border-blue-500/35"
            title="عرض المادة"
            aria-label="عرض المادة"
            onClick={() => navigate(`/dean/courses/${offering.course_offering_id}`)}
          >
            <FaEye aria-hidden="true" />
          </button>
          {canManageTeachers && (
            <button
              type="button"
              className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[12px] cursor-pointer transition-all duration-[180ms] text-primary border-primary/20 bg-primary/6 hover:bg-primary/14 hover:border-primary/35"
              title="إدارة التكليف"
              aria-label="إدارة التكليف"
              onClick={() => navigate(`/dean/courses/${offering.course_offering_id}`)}
            >
              <FaCog aria-hidden="true" />
            </button>
          )}
        </div>
      ),
    },
  ]

  return (
    <div dir="rtl">
      <div className="flex items-start sm:items-center justify-between mb-5 gap-4 flex-wrap">
        <div className="min-w-0">
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">مواد الكلية</h2>
          <p className="text-[12.5px] text-text-light">
            متابعة المواد المطروحة والمدرسين والطلاب والجلسات والنتائج
          </p>
        </div>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
        <SummaryCard label="إجمالي المواد المطروحة" value={summary?.total_offerings ?? 0} loading={loading} />
        <SummaryCard label="المواد المفتوحة" value={summary?.open_offerings ?? 0} loading={loading} />
        <SummaryCard label="الطلاب المسجلون" value={summary?.registered_students_count ?? 0} loading={loading} />
        <SummaryCard label="بدون تكليف مكتمل" value={summary?.incomplete_assignment_count ?? 0} loading={loading} />
      </div>

      <FilterBar
        search={{
          value: search,
          onChange: setSearch,
          placeholder: 'ابحث برمز المادة أو اسمها أو البرنامج...',
        }}
        filters={[
          {
            key: 'year',
            value: academicYearId,
            onChange: value => updateFilter(setAcademicYearId, value),
            placeholder: 'العام الدراسي',
            minWidth: 150,
            options: yearOptions,
          },
          {
            key: 'semester',
            value: semesterId,
            onChange: value => updateFilter(setSemesterId, value),
            placeholder: 'الفصل',
            minWidth: 140,
            options: semesterOptions,
          },
          {
            key: 'department',
            value: departmentId,
            onChange: value => updateFilter(setDepartmentId, value),
            placeholder: 'القسم',
            minWidth: 150,
            options: departmentOptions,
          },
          {
            key: 'program',
            value: programId,
            onChange: value => updateFilter(setProgramId, value),
            placeholder: 'البرنامج',
            minWidth: 170,
            options: programOptions,
          },
          {
            key: 'status',
            value: status,
            onChange: value => updateFilter(setStatus, value),
            placeholder: 'حالة الطرح',
            minWidth: 140,
            options: statusOptions,
          },
          {
            key: 'assignment',
            value: teacherAssignment,
            onChange: value => updateFilter(setTeacherAssignment, value),
            placeholder: 'حالة التكليف',
            minWidth: 160,
            options: TEACHER_ASSIGNMENT_FILTER_OPTIONS,
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
        rows={error ? [] : rows}
        rowKey={offering => offering.course_offering_id}
        loading={loading}
        animationKey={`${appliedSearch}-${academicYearId}-${semesterId}-${departmentId}-${programId}-${status}-${teacherAssignment}-${page}`}
        emptyIcon={FaBookOpen}
        emptyTitle={
          error
            ? 'تعذّر عرض مواد الكلية'
            : hasFilters
              ? 'لا توجد نتائج مطابقة للفلاتر الحالية'
              : 'لا توجد مواد مطروحة ضمن نطاق الكلية حاليًا'
        }
        emptySubtitle={error ? undefined : hasFilters ? 'جرّب تعديل البحث أو مسح الفلاتر' : undefined}
        hasFilters={!error && hasFilters}
        onClearFilters={clearFilters}
        page={page}
        totalPages={error ? 1 : totalPages}
        onPageChange={setPage}
      />
    </div>
  )
}
