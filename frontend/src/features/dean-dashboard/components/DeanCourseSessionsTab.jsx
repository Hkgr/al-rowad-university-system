import { FaCalendarAlt } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { TabState } from './DeanStudentRecordPanels'
import {
  academicRankLabel,
  displayValue,
  formatClockRange,
  formatDisplayDate,
  sessionTypeLabel,
} from '../utils/teacherDisplay'

export default function DeanCourseSessionsTab({
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
      key: 'teacher',
      header: 'المدرس',
      align: 'right',
      dir: 'rtl',
      render: session => {
        const name = displayValue(session.teacher?.full_name)
        const rank = academicRankLabel(session.teacher?.academic_rank)
        return (
          <div className="min-w-0 max-w-[180px]">
            <p className="truncate text-[12.5px] font-semibold text-text-dark" title={name === '—' ? undefined : name}>{name}</p>
            {session.teacher?.academic_rank && rank !== '—' ? (
              <p className="truncate text-[10.5px] text-text-light">{rank}</p>
            ) : null}
          </div>
        )
      },
    },
    {
      key: 'topic',
      header: 'الموضوع',
      align: 'right',
      dir: 'rtl',
      render: session => {
        const topic = displayValue(session.notes)
        return (
          <span className="block max-w-[220px] truncate text-[12px] text-text-gray" title={topic === '—' ? undefined : topic}>
            {topic}
          </span>
        )
      },
    },
    {
      key: 'recorded',
      header: 'الحضور المسجّل',
      align: 'center',
      render: session => {
        const count = Number(session.recorded_attendance_count) || 0
        return (
          <span className="inline-flex min-w-[28px] justify-center px-2 py-[3px] border rounded-full text-[12px] font-bold bg-green-500/8 border-green-500/20 text-green-700">
            {count}
          </span>
        )
      },
    },
  ]

  return (
    <div>
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
        <div className="bg-primary/[0.04] border border-primary/12 rounded-[12px] px-3 py-2.5">
          <p className="text-[11px] text-text-light font-semibold">إجمالي الجلسات</p>
          <p className="text-[18px] font-black text-text-dark tabular-nums">{summary?.total ?? 0}</p>
        </div>
        <div className="bg-sky-500/[0.06] border border-sky-500/15 rounded-[12px] px-3 py-2.5">
          <p className="text-[11px] text-text-light font-semibold">نظري</p>
          <p className="text-[18px] font-black text-text-dark tabular-nums">{summary?.theoretical ?? 0}</p>
        </div>
        <div className="bg-amber-500/[0.06] border border-amber-500/15 rounded-[12px] px-3 py-2.5">
          <p className="text-[11px] text-text-light font-semibold">عملي</p>
          <p className="text-[18px] font-black text-text-dark tabular-nums">{summary?.practical ?? 0}</p>
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
        empty={!loading && !error && rows.length === 0 ? 'لا توجد جلسات مسجلة لهذه المادة' : ''}
        emptyIcon={FaCalendarAlt}
      >
        <DataTable
          columns={columns}
          rows={rows}
          rowKey={session => session.attendance_session_id}
          loading={false}
          animationKey={`${sessionType}-${page}`}
          emptyIcon={FaCalendarAlt}
          emptyTitle="لا توجد جلسات مسجلة لهذه المادة"
          page={page}
          totalPages={totalPages}
          onPageChange={onPageChange}
        />
      </TabState>
    </div>
  )
}
