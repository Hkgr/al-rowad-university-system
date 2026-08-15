import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaChalkboardTeacher, FaEye, FaPhone } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { apiRequest } from '../../../services/apiClient'
import {
  ASSIGNMENT_TYPE_OPTIONS,
  academicRankLabel,
  displayValue,
  fullTeacherName,
  matchesAssignmentType,
  normalizeSearchText,
} from '../utils/teacherDisplay'

const PAGE_SIZE = 15
const API_PAGE_SIZE = 100

function paginatedRows(response) {
  return response?.data?.data ?? []
}

async function fetchAllTeachingStaff() {
  const firstResponse = await apiRequest(`/v1/teaching-staff?per_page=${API_PAGE_SIZE}&page=1`)
  const rows = [...paginatedRows(firstResponse)]
  const lastPage = firstResponse?.data?.meta?.last_page ?? 1

  if (lastPage > 1) {
    const remainingResponses = await Promise.all(
      Array.from(
        { length: lastPage - 1 },
        (_, index) => apiRequest(`/v1/teaching-staff?per_page=${API_PAGE_SIZE}&page=${index + 2}`),
      ),
    )
    remainingResponses.forEach(response => rows.push(...paginatedRows(response)))
  }

  return rows
}

function CountBadge({ value, tone = 'neutral' }) {
  const tones = {
    courses: 'bg-primary/8 border-primary/15 text-primary-dark',
    theoretical: 'bg-sky-500/8 border-sky-500/20 text-sky-700',
    practical: 'bg-amber-500/8 border-amber-500/20 text-amber-700',
    neutral: 'bg-slate-500/8 border-slate-500/15 text-slate-600',
  }

  return (
    <span
      className={`inline-flex min-w-[28px] justify-center px-2 py-[3px] border rounded-full text-[12px] font-bold ${tones[tone] ?? tones.neutral}`}
    >
      {Number(value) || 0}
    </span>
  )
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

export default function DeanTeachers() {
  const [allTeachers, setAllTeachers] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [revealedPhones, setRevealedPhones] = useState(new Set())
  const [search, setSearch] = useState('')
  const [filterRank, setFilterRank] = useState('')
  const [filterAssignment, setFilterAssignment] = useState('')
  const [filterSpecialization, setFilterSpecialization] = useState('')
  const [page, setPage] = useState(1)
  const navigate = useNavigate()

  useEffect(() => {
    let active = true

    async function load() {
      setLoading(true)
      setError('')
      setAllTeachers([])
      setRevealedPhones(new Set())

      try {
        const teachers = await fetchAllTeachingStaff()
        if (!active) return
        setAllTeachers(teachers)
      } catch (requestError) {
        if (!active) return

        if (requestError.status === 401) {
          navigate('/login', { replace: true })
          return
        }

        setAllTeachers([])
        setError(
          requestError.status === 403
            ? 'ليس لديك صلاحية لعرض مدرسي الكلية.'
            : 'تعذّر تحميل بيانات المدرسين. يرجى المحاولة مرة أخرى.',
        )
      } finally {
        if (active) setLoading(false)
      }
    }

    load()
    return () => { active = false }
  }, [navigate])

  const filteredTeachers = useMemo(() => {
    const query = normalizeSearchText(search)

    return allTeachers.filter(teacher => {
      if (filterRank && String(teacher.academic_rank ?? '') !== filterRank) return false
      if (filterAssignment && !matchesAssignmentType(teacher, filterAssignment)) return false
      if (filterSpecialization && String(teacher.specialization ?? '') !== filterSpecialization) return false

      if (query) {
        const employee = teacher.employee ?? {}
        const haystacks = [
          fullTeacherName(teacher),
          employee.first_name,
          employee.last_name,
          employee.employee_number,
          employee.email,
          teacher.specialization,
        ].map(normalizeSearchText)

        if (!haystacks.some(value => value.includes(query))) {
          return false
        }
      }

      return true
    })
  }, [allTeachers, filterAssignment, filterRank, filterSpecialization, search])

  const totalPages = Math.max(1, Math.ceil(filteredTeachers.length / PAGE_SIZE))
  const safePage = Math.min(page, totalPages)
  const pageTeachers = filteredTeachers.slice(
    (safePage - 1) * PAGE_SIZE,
    safePage * PAGE_SIZE,
  )
  const hasFilters = Boolean(search || filterRank || filterAssignment || filterSpecialization)

  const collegeName = useMemo(() => {
    const names = [...new Set(
      allTeachers.flatMap(teacher => (teacher.colleges ?? [])
        .map(college => college.college_name)
        .filter(Boolean)),
    )]
    return names.length === 1 ? names[0] : null
  }, [allTeachers])

  const rankOptions = useMemo(() => {
    const ranks = [...new Set(
      allTeachers
        .map(teacher => teacher.academic_rank)
        .filter(rank => String(rank ?? '').trim() !== ''),
    )]

    return ranks
      .map(value => ({ value, label: academicRankLabel(value) }))
      .sort((a, b) => a.label.localeCompare(b.label, 'ar'))
  }, [allTeachers])

  const specializationOptions = useMemo(() => {
    const values = [...new Set(
      allTeachers
        .map(teacher => teacher.specialization)
        .filter(value => String(value ?? '').trim() !== ''),
    )]

    return values
      .map(value => ({ value, label: value }))
      .sort((a, b) => a.label.localeCompare(b.label, 'ar'))
  }, [allTeachers])

  const summary = useMemo(() => ({
    total: allTeachers.length,
    theoretical: allTeachers.filter(teacher => (Number(teacher.theoretical_assignment_count) || 0) > 0).length,
    practical: allTeachers.filter(teacher => (Number(teacher.practical_assignment_count) || 0) > 0).length,
    unassigned: allTeachers.filter(teacher => (Number(teacher.active_assignment_count) || 0) === 0).length,
  }), [allTeachers])

  function updateFilter(setter, value) {
    setter(value)
    setPage(1)
  }

  function clearFilters() {
    setSearch('')
    setFilterRank('')
    setFilterAssignment('')
    setFilterSpecialization('')
    setPage(1)
  }

  function togglePhone(facultyMemberId) {
    setRevealedPhones(current => {
      const next = new Set(current)
      if (next.has(facultyMemberId)) next.delete(facultyMemberId)
      else next.add(facultyMemberId)
      return next
    })
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
      key: 'employee_number',
      header: 'الرقم الوظيفي',
      align: 'center',
      render: teacher => (
        <span className="inline-block px-2.5 py-[3px] bg-primary/8 border border-primary/15 rounded-[8px] text-[12px] font-bold text-primary-dark font-mono">
          {displayValue(teacher.employee?.employee_number)}
        </span>
      ),
    },
    {
      key: 'name',
      header: 'اسم المدرس',
      align: 'right',
      dir: 'rtl',
      cellClassName: 'text-[13.5px] font-semibold text-text-dark',
      render: teacher => {
        const name = fullTeacherName(teacher)
        return (
          <span className="block max-w-[200px] truncate" title={name}>
            {name}
          </span>
        )
      },
    },
    {
      key: 'academic_rank',
      header: 'الرتبة الأكاديمية',
      align: 'center',
      dir: 'rtl',
      render: teacher => {
        const label = academicRankLabel(teacher.academic_rank)
        return label === '—'
          ? <span className="text-[11px] text-text-light">—</span>
          : (
            <span
              className="inline-block max-w-[140px] truncate px-2.5 py-[3px] bg-slate-500/8 rounded-full text-[11.5px] font-semibold text-slate-600"
              title={label}
            >
              {label}
            </span>
          )
      },
    },
    {
      key: 'specialization',
      header: 'الاختصاص',
      align: 'right',
      dir: 'rtl',
      render: teacher => {
        const specialization = displayValue(teacher.specialization)
        return (
          <span
            className="block max-w-[160px] truncate text-[12px] font-medium text-text-gray"
            title={specialization === '—' ? undefined : specialization}
          >
            {specialization}
          </span>
        )
      },
    },
    {
      key: 'courses',
      header: 'المواد',
      align: 'center',
      render: teacher => <CountBadge value={teacher.active_course_count} tone="courses" />,
    },
    {
      key: 'theoretical',
      header: 'النظري',
      align: 'center',
      render: teacher => <CountBadge value={teacher.theoretical_assignment_count} tone="theoretical" />,
    },
    {
      key: 'practical',
      header: 'العملي',
      align: 'center',
      render: teacher => <CountBadge value={teacher.practical_assignment_count} tone="practical" />,
    },
    {
      key: 'phone',
      header: 'الهاتف',
      align: 'center',
      render: teacher => {
        const phone = teacher.employee?.phone_number
        const revealed = revealedPhones.has(teacher.faculty_member_id)

        if (revealed && phone) {
          return (
            <button
              type="button"
              className="text-[12px] font-mono text-text-dark whitespace-nowrap cursor-pointer hover:text-primary transition-colors"
              title="إخفاء الرقم"
              aria-label="إخفاء رقم الهاتف"
              onClick={() => togglePhone(teacher.faculty_member_id)}
            >
              {phone}
            </button>
          )
        }

        return (
          <button
            type="button"
            className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[12px] mx-auto cursor-pointer transition-all duration-[180ms] text-green-600 border-green-600/20 bg-green-600/6 hover:bg-green-600/14 hover:border-green-600/35 disabled:opacity-40 disabled:cursor-not-allowed"
            title={phone ? 'إظهار رقم الهاتف' : 'لا يوجد رقم هاتف'}
            aria-label={phone ? 'إظهار رقم الهاتف' : 'لا يوجد رقم هاتف'}
            onClick={() => togglePhone(teacher.faculty_member_id)}
            disabled={!phone}
          >
            <FaPhone />
          </button>
        )
      },
    },
    {
      key: 'view',
      header: 'عرض',
      align: 'center',
      render: teacher => (
        <button
          type="button"
          className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] mx-auto cursor-pointer transition-all duration-[180ms] text-blue-500 border-blue-500/20 bg-blue-500/6 hover:bg-blue-500/14 hover:border-blue-500/35"
          title="عرض ملف المدرس"
          aria-label="عرض ملف المدرس"
          onClick={() => navigate(`/dean/teachers/${teacher.faculty_member_id}`)}
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
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">مدرسو الكلية</h2>
          <p className="text-[12.5px] text-text-light">
            {collegeName
              ? `إدارة ومتابعة الكادر التدريسي وتكليفاته الأكاديمية — ${collegeName}`
              : 'إدارة ومتابعة الكادر التدريسي وتكليفاته الأكاديمية'}
          </p>
          <p className="text-[12.5px] text-text-gray mt-1">
            {loading
              ? 'جاري التحميل…'
              : hasFilters
                ? `عدد المدرسين: ${filteredTeachers.length} من أصل ${allTeachers.length}`
                : `عدد المدرسين: ${allTeachers.length}`}
          </p>
        </div>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
        <SummaryCard label="إجمالي المدرسين" value={summary.total} loading={loading} />
        <SummaryCard label="لديهم تكليف نظري" value={summary.theoretical} loading={loading} />
        <SummaryCard label="لديهم تكليف عملي" value={summary.practical} loading={loading} />
        <SummaryCard label="بدون تكليف حالي" value={summary.unassigned} loading={loading} />
      </div>

      <FilterBar
        search={{
          value: search,
          onChange: value => updateFilter(setSearch, value),
          placeholder: 'ابحث باسم المدرس، الرقم الوظيفي، البريد الإلكتروني أو الاختصاص...',
        }}
        filters={[
          {
            key: 'rank',
            value: filterRank,
            onChange: value => updateFilter(setFilterRank, value),
            placeholder: 'جميع الرتب',
            minWidth: 150,
            options: rankOptions,
          },
          {
            key: 'assignment',
            value: filterAssignment,
            onChange: value => updateFilter(setFilterAssignment, value),
            placeholder: 'جميع التكليفات',
            minWidth: 160,
            options: ASSIGNMENT_TYPE_OPTIONS,
          },
          ...(specializationOptions.length > 0
            ? [{
                key: 'specialization',
                value: filterSpecialization,
                onChange: value => updateFilter(setFilterSpecialization, value),
                placeholder: 'جميع الاختصاصات',
                minWidth: 170,
                options: specializationOptions,
              }]
            : []),
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
        rows={error ? [] : pageTeachers}
        rowKey={teacher => teacher.faculty_member_id}
        loading={loading}
        animationKey={`${search}-${filterRank}-${filterAssignment}-${filterSpecialization}-${safePage}`}
        emptyIcon={FaChalkboardTeacher}
        emptyTitle={
          error
            ? 'تعذّر عرض بيانات المدرسين'
            : hasFilters
              ? 'لا توجد نتائج مطابقة للفلاتر الحالية'
              : 'لا يوجد مدرسون مرتبطون بهذه الكلية حاليًا'
        }
        emptySubtitle={error ? undefined : hasFilters ? 'جرّب تعديل البحث أو مسح الفلاتر' : undefined}
        hasFilters={!error && hasFilters}
        onClearFilters={clearFilters}
        page={safePage}
        totalPages={error ? 1 : totalPages}
        onPageChange={setPage}
      />
    </div>
  )
}
