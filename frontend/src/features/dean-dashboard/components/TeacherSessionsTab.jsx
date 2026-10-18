import { FaCalendarAlt } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { TabState } from './DeanStudentRecordPanels'
import {
  displayValue,
  formatClockRange,
  formatDisplayDate,
  sessionTypeLabel,
} from '../utils/teacherDisplay'
import CourseRequirementBadges from '../../../components/academic/CourseRequirementBadges'

export default function TeacherSessionsTab({
  loading,
  error,
  rows,
  page,
  totalPages,
  onPageChange,
  sessionType,
  onSessionTypeChange,
  onClearFilters,
  summary,
}) {
  const hasFilters = Boolean(sessionType)

  const columns = [
    {
      key: 'date',
      header: 'التاريخ',
      align: 'center',
      render: session => (
        <span className="text-[12.5px] text-text-dark whitespace-nowrap">
          {formatDisplayDate(session.session_date)}
        </span>
      ),
    },
    {
      key: 'course',
      header: 'المادة',
      align: 'right',
      dir: 'rtl',
      render: session => {
        const label = [session.course?.course_code, session.course?.course_name].filter(Boolean).join(' — ') || '—'
        return (
          <div className="min-w-0 max-w-[220px]">
            <span className="block truncate text-[13px] font-semibold text-text-dark" title={label}>{label}</span>
            <div className="mt-1">
              <CourseRequirementBadges classification={session.requirement_classification} compact />
            </div>
          </div>
        )
      },
    },
    {
      key: 'type',
      header: 'نوع الجلسة',
      align: 'center',
      dir: 'rtl',
      render: session => {
        const practical = String(session.session_type ?? '').toLowerCase() === 'practical'
        return (
          <span className={`inline-block px-2.5 py-[3px] rounded-full text-[11.5px] font-bold ${
            practical ? 'bg-amber-500/10 text-amber-700' : 'bg-sky-500/10 text-sky-700'
          }`}
          >
            {sessionTypeLabel(session.session_type)}
          </span>
        )
      },
    },
    {
      key: 'time',
      header: 'الوقت',
      align: 'center',
      render: session => (
        <span className="text-[12px] font-mono text-text-dark whitespace-nowrap">
          {formatClockRange(session.start_time, session.end_time)}
        </span>
      ),
    },
    {
      key: 'term',
      header: 'العام / الفصل',
      align: 'center',
      dir: 'rtl',
      render: session => {
        const label = [session.academic_year?.year_name, session.semester?.semester_name].filter(Boolean).join(' / ') || '—'
        return <span className="text-[12px] text-text-gray whitespace-nowrap">{label}</span>
      },
    },
    {
      key: 'topic',
      header: 'الموضوع',
      align: 'right',
      dir: 'rtl',
      render: session => {
        const topic = displayValue(session.notes)
        return <span className="block max-w-[200px] truncate text-[12px] text-text-gray" title={topic === '—' ? undefined : topic}>{topic}</span>
      },
    },
    {
      key: 'recorded',
      header: 'تسجيل الحضور',
      align: 'center',
      dir: 'rtl',
      render: session => {
        const count = Number(session.recorded_count) || 0
        return count > 0
          ? (
            <span className="inline-block px-2.5 py-[3px] rounded-full text-[11.5px] font-semibold bg-green-500/10 text-green-700">
              مسجّل ({count})
            </span>
          )
          : (
            <span className="inline-block px-2.5 py-[3px] rounded-full text-[11.5px] font-semibold bg-slate-500/10 text-slate-600">
              لم يُسجَّل
            </span>
          )
      },
    },
  ]

  return (
    <div>
      <div className="grid grid-cols-3 gap-3 mb-4">
        <div className="bg-primary/[0.04] border border-primary/12 rounded-[12px] px-3 py-2.5">
          <p className="text-[11px] text-text-light font-semibold">إجمالي الجلسات</p>
          <p className="text-[18px] font-black text-text-dark tabular-nums">{summary.total}</p>
        </div>
        <div className="bg-sky-500/[0.06] border border-sky-500/15 rounded-[12px] px-3 py-2.5">
          <p className="text-[11px] text-text-light font-semibold">جلسات نظري</p>
          <p className="text-[18px] font-black text-text-dark tabular-nums">{summary.theoretical}</p>
        </div>
        <div className="bg-amber-500/[0.06] border border-amber-500/15 rounded-[12px] px-3 py-2.5">
          <p className="text-[11px] text-text-light font-semibold">جلسات عملي</p>
          <p className="text-[18px] font-black text-text-dark tabular-nums">{summary.practical}</p>
        </div>
      </div>

      <FilterBar
        filters={[
          {
            key: 'session_type',
            value: sessionType,
            onChange: onSessionTypeChange,
            placeholder: 'جميع الأنواع',
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
        empty={!loading && !error && rows.length === 0 ? 'لا توجد جلسات مسجلة لهذا المدرس' : ''}
        emptyIcon={FaCalendarAlt}
      >
        <DataTable
          columns={columns}
          rows={rows}
          rowKey={session => session.attendance_session_id}
          loading={false}
          animationKey={`${sessionType}-${page}`}
          emptyIcon={FaCalendarAlt}
          emptyTitle="لا توجد جلسات مسجلة لهذا المدرس"
          page={page}
          totalPages={totalPages}
          onPageChange={onPageChange}
        />
      </TabState>
    </div>
  )
}
