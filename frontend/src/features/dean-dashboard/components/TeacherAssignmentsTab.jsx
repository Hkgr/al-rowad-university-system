import { FaBookOpen, FaCog, FaPlus } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { TabState } from './DeanStudentRecordPanels'
import {
  assignmentStatusLabel,
  componentState,
  displayValue,
  offeringStatusLabel,
  ownedRoleBadge,
} from '../utils/teacherDisplay'

function ComponentCell({ hours, slot }) {
  const state = componentState(hours, slot)
  const tones = {
    assigned: 'bg-green-500/10 border-green-500/20 text-green-700',
    inactive: 'bg-slate-500/10 border-slate-500/20 text-slate-600',
    unassigned: 'bg-amber-500/8 border-amber-500/20 text-amber-700',
    absent: 'bg-slate-500/6 border-slate-500/12 text-text-light',
  }

  return (
    <span
      className={`inline-flex justify-center min-w-[72px] px-2 py-[3px] border rounded-full text-[11.5px] font-bold ${tones[state.kind]}`}
      title={state.title}
    >
      {state.label}
    </span>
  )
}

export default function TeacherAssignmentsTab({
  loading,
  error,
  rows,
  page,
  totalPages,
  onPageChange,
  status,
  role,
  onStatusChange,
  onRoleChange,
  onClearFilters,
  canManage = false,
  onAddAssignment,
  onManageOffering,
}) {
  const grouped = rows
  const hasFilters = Boolean(status !== 'active' || role)

  const columns = [
    {
      key: 'course',
      header: 'المادة',
      align: 'right',
      dir: 'rtl',
      render: group => {
        const course = group.offering?.course
        const label = [course?.course_code, course?.course_name].filter(Boolean).join(' — ') || '—'
        return (
          <div className="min-w-0 max-w-[220px]">
            <span className="block truncate text-[13px] font-semibold text-text-dark" title={label}>{label}</span>
            <span className="inline-block mt-1 px-2 py-[2px] rounded-full text-[10.5px] font-bold bg-primary/8 text-primary-dark border border-primary/15">
              {ownedRoleBadge(group)}
            </span>
          </div>
        )
      },
    },
    {
      key: 'year',
      header: 'العام الدراسي',
      align: 'center',
      dir: 'rtl',
      render: group => (
        <span className="text-[12.5px] text-text-dark whitespace-nowrap">
          {displayValue(group.offering?.academic_year?.year_name)}
        </span>
      ),
    },
    {
      key: 'semester',
      header: 'الفصل',
      align: 'center',
      dir: 'rtl',
      render: group => (
        <span className="text-[12.5px] text-text-dark whitespace-nowrap">
          {displayValue(group.offering?.semester?.semester_name)}
        </span>
      ),
    },
    {
      key: 'program',
      header: 'البرنامج',
      align: 'right',
      dir: 'rtl',
      render: group => {
        const name = displayValue(group.offering?.academic_program?.program_name)
        return <span className="block max-w-[160px] truncate text-[12px] text-text-gray" title={name === '—' ? undefined : name}>{name}</span>
      },
    },
    {
      key: 'department',
      header: 'القسم',
      align: 'right',
      dir: 'rtl',
      render: group => {
        const name = displayValue(group.offering?.department?.department_name)
        return <span className="block max-w-[140px] truncate text-[12px] text-text-gray" title={name === '—' ? undefined : name}>{name}</span>
      },
    },
    {
      key: 'theoretical',
      header: 'النظري',
      align: 'center',
      render: group => (
        <ComponentCell
          hours={group.offering?.course?.theoretical_hours}
          slot={group.theoretical}
        />
      ),
    },
    {
      key: 'practical',
      header: 'العملي',
      align: 'center',
      render: group => (
        <ComponentCell
          hours={group.offering?.course?.practical_hours}
          slot={group.practical}
        />
      ),
    },
    {
      key: 'offering_status',
      header: 'حالة الطرح',
      align: 'center',
      dir: 'rtl',
      render: group => (
        <span className="inline-block px-2.5 py-[3px] rounded-full text-[11.5px] font-semibold bg-slate-500/8 text-slate-600">
          {offeringStatusLabel(group.offering?.status)}
        </span>
      ),
    },
    {
      key: 'assignment_status',
      header: 'حالة التكليف',
      align: 'center',
      dir: 'rtl',
      render: group => {
        const active = group.slots.some(slot => slot.is_active)
        return (
          <span className={`inline-block px-2.5 py-[3px] rounded-full text-[11.5px] font-semibold ${
            active ? 'bg-green-500/10 text-green-700' : 'bg-slate-500/10 text-slate-600'
          }`}
          >
            {assignmentStatusLabel(active)}
          </span>
        )
      },
    },
  ]

  if (canManage) {
    columns.push({
      key: 'manage',
      header: 'إدارة',
      align: 'center',
      render: group => (
        <button
          type="button"
          className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] mx-auto cursor-pointer transition-all duration-[180ms] text-primary border-primary/20 bg-primary/6 hover:bg-primary/14 hover:border-primary/35"
          title="إدارة التكليف"
          aria-label="إدارة التكليف"
          onClick={() => onManageOffering?.(group)}
        >
          <FaCog aria-hidden="true" />
        </button>
      ),
    })
  }

  return (
    <div>
      {canManage && (
        <div className="flex justify-start mb-4">
          <button
            type="button"
            className="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-[10px] text-[13px] font-bold hover:bg-primary-dark transition-colors"
            onClick={onAddAssignment}
            aria-label="إضافة تكليف تدريسي"
            title="إضافة تكليف تدريسي"
          >
            <FaPlus aria-hidden="true" className="text-[11px]" />
            إضافة تكليف تدريسي
          </button>
        </div>
      )}
      <FilterBar
        filters={[
          {
            key: 'status',
            value: status === 'active' ? '' : status,
            onChange: value => onStatusChange(value || 'active'),
            placeholder: 'التكليفات النشطة',
            minWidth: 150,
            options: [
              { value: 'inactive', label: 'غير نشط' },
              { value: 'all', label: 'جميع التكليفات' },
            ],
          },
          {
            key: 'role',
            value: role,
            onChange: onRoleChange,
            placeholder: 'جميع الأدوار',
            minWidth: 140,
            options: [
              { value: 'theoretical', label: 'نظري' },
              { value: 'practical', label: 'عملي' },
            ],
          },
        ]}
        hasActiveFilters={hasFilters}
        onClear={onClearFilters}
        disabled={loading}
      />

      <TabState
        loading={loading}
        error={error}
        empty={!loading && !error && grouped.length === 0
          ? (status === 'active'
            ? 'لا يوجد تكليف تدريسي حالي لهذا المدرس'
            : 'لا يوجد تكليف تدريسي لهذا المدرس')
          : ''}
        emptyIcon={FaBookOpen}
      >
        <DataTable
          columns={columns}
          rows={grouped}
          rowKey={group => group.course_offering_id}
          loading={false}
          animationKey={`${status}-${role}-${page}`}
          emptyIcon={FaBookOpen}
          emptyTitle="لا يوجد تكليف تدريسي حالي لهذا المدرس"
          page={page}
          totalPages={totalPages}
          onPageChange={onPageChange}
        />
      </TabState>
    </div>
  )
}
