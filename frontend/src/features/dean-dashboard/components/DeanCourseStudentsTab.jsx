import { FaEye, FaUsers } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { TabState } from './DeanStudentRecordPanels'
import {
  formatAverageMark,
  registrationStatusLabel,
  resultStatusLabel,
} from '../utils/courseOfferingDisplay'
import { displayValue, formatDisplayDate } from '../utils/teacherDisplay'

const REGISTRATION_STATUS_OPTIONS = [
  { value: 'registered', label: 'مسجّل حاليًا' },
  { value: 'completed', label: 'مكتمل' },
  { value: 'dropped', label: 'منسحب' },
  { value: 'withdrawn', label: 'منسحب إداري' },
  { value: 'all', label: 'جميع الحالات' },
]

export default function DeanCourseStudentsTab({
  loading,
  error,
  rows,
  page,
  totalPages,
  onPageChange,
  search,
  onSearchChange,
  registrationStatus,
  onRegistrationStatusChange,
  onClearFilters,
  includesGrades,
  onOpenStudent,
}) {
  const hasFilters = Boolean(search || (registrationStatus && registrationStatus !== 'registered'))

  const columns = [
    {
      key: 'index',
      header: '#',
      align: 'center',
      cellClassName: 'text-[12px] text-text-light font-semibold w-10',
      render: (_, index) => (page - 1) * 15 + index + 1,
    },
    {
      key: 'student_number',
      header: 'رقم الطالب',
      align: 'center',
      render: row => (
        <span className="inline-block px-2.5 py-[3px] bg-primary/8 border border-primary/15 rounded-[8px] text-[12px] font-bold text-primary-dark font-mono">
          {displayValue(row.student_number)}
        </span>
      ),
    },
    {
      key: 'name',
      header: 'اسم الطالب',
      align: 'right',
      dir: 'rtl',
      render: row => {
        const name = displayValue(row.full_name)
        return (
          <span className="block max-w-[220px] truncate text-[13px] font-semibold text-text-dark" title={name === '—' ? undefined : name}>
            {name}
          </span>
        )
      },
    },
    {
      key: 'registration_date',
      header: 'تاريخ التسجيل',
      align: 'center',
      render: row => (
        <span className="text-[12.5px] text-text-dark whitespace-nowrap">
          {formatDisplayDate(row.registration_date)}
        </span>
      ),
    },
    {
      key: 'registration_status',
      header: 'حالة التسجيل',
      align: 'center',
      dir: 'rtl',
      render: row => (
        <span className="inline-block px-2.5 py-[3px] rounded-full text-[11.5px] font-semibold bg-primary/8 text-primary-dark">
          {registrationStatusLabel(row.registration_status)}
        </span>
      ),
    },
  ]

  if (includesGrades) {
    columns.push(
      {
        key: 'final_mark',
        header: 'العلامة النهائية',
        align: 'center',
        render: row => (
          <span className="text-[12.5px] font-bold text-text-dark tabular-nums">
            {formatAverageMark(row.final_mark)}
          </span>
        ),
      },
      {
        key: 'result_status',
        header: 'حالة النتيجة',
        align: 'center',
        dir: 'rtl',
        render: row => (
          <span className="text-[12px] text-text-gray">
            {resultStatusLabel(row.result_status)}
          </span>
        ),
      },
    )
  }

  columns.push({
    key: 'view',
    header: 'عرض',
    align: 'center',
    render: row => (
      <button
        type="button"
        className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] mx-auto cursor-pointer transition-all duration-[180ms] text-blue-500 border-blue-500/20 bg-blue-500/6 hover:bg-blue-500/14 hover:border-blue-500/35"
        title="عرض ملف الطالب"
        aria-label="عرض ملف الطالب"
        onClick={() => onOpenStudent(row.student_id)}
        disabled={!row.student_id}
      >
        <FaEye aria-hidden="true" />
      </button>
    ),
  })

  return (
    <div>
      <FilterBar
        search={{
          value: search,
          onChange: onSearchChange,
          placeholder: 'ابحث برقم الطالب أو اسمه...',
        }}
        filters={[
          {
            key: 'registration_status',
            value: registrationStatus,
            onChange: onRegistrationStatusChange,
            placeholder: 'حالة التسجيل',
            minWidth: 160,
            options: REGISTRATION_STATUS_OPTIONS,
          },
        ]}
        hasActiveFilters={hasFilters}
        onClear={onClearFilters}
        disabled={loading}
      />

      <TabState
        loading={loading}
        error={error}
        empty={!loading && !error && rows.length === 0 ? 'لا يوجد طلاب مسجلون في هذه المادة حاليًا' : ''}
        emptyIcon={FaUsers}
      >
        <DataTable
          columns={columns}
          rows={rows}
          rowKey={row => row.student_course_registration_id || `${row.student_id}-${row.registration_date}`}
          loading={false}
          animationKey={`${search}-${registrationStatus}-${page}`}
          emptyIcon={FaUsers}
          emptyTitle="لا يوجد طلاب مسجلون في هذه المادة حاليًا"
          page={page}
          totalPages={totalPages}
          onPageChange={onPageChange}
        />
      </TabState>
    </div>
  )
}
