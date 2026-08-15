import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { motion } from 'framer-motion'
import { FaCog, FaExclamationCircle } from 'react-icons/fa'
import { getIdentity, hasPermission, PERMISSIONS } from '../../auth/auth'
import { apiRequest } from '../../../services/apiClient'
import DashboardBarChart from '../components/DashboardBarChart'
import DashboardDonutChart from '../components/DashboardDonutChart'
import DeanDashboardCustomizer from '../components/DeanDashboardCustomizer'
import DeanKpiCard from '../components/DeanKpiCard'
import DeanQuickActions from '../components/DeanQuickActions'
import { formatAverageMark } from '../utils/courseOfferingDisplay'
import {
  isWidgetVisible,
  loadDeanDashboardLayout,
  resetDeanDashboardLayout,
  saveDeanDashboardLayout,
} from '../utils/deanDashboardPreferences'
import { deanHomeWelcome } from '../utils/deanPortalCopy'
import { formatDisplayDate } from '../utils/teacherDisplay'

const TERM_SELECTION_MESSAGE = 'اختر السنة الدراسية والفصل لعرض مؤشرات الفصل'

function formatCount(value) {
  if (value === null || value === undefined) return '—'
  const number = Number(value)
  if (!Number.isFinite(number)) return '—'
  return number.toLocaleString('ar-SY')
}

function formatPercent(value) {
  if (value === null || value === undefined || value === '') return '—'
  const number = Number(value)
  if (!Number.isFinite(number)) return '—'
  return `${number.toLocaleString('ar-SY', { maximumFractionDigits: 1 })}٪`
}

function offeringStatusColor(status) {
  if (status === 'open') return '#569933'
  if (status === 'closed') return '#64748b'
  if (status === 'cancelled') return '#ef4444'
  return '#f59e0b'
}

function assignmentColor(code) {
  if (code === 'complete') return '#569933'
  if (code === 'partial') return '#f59e0b'
  return '#ef4444'
}

function sessionTypeHint(sessionsByType, loading) {
  if (loading || !Array.isArray(sessionsByType) || sessionsByType.length === 0) return ''
  return sessionsByType
    .filter(item => item.count > 0)
    .map(item => `${item.label}: ${formatCount(item.count)}`)
    .join(' · ')
}

export default function DeanHome() {
  const navigate = useNavigate()
  const identity = getIdentity()
  const userId = identity?.user_id
  const requestSeq = useRef(0)

  const [dashboard, setDashboard] = useState(null)
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState('')
  const [yearId, setYearId] = useState('')
  const [semesterId, setSemesterId] = useState('')
  const [layout, setLayout] = useState(() => loadDeanDashboardLayout(userId))
  const [customizerOpen, setCustomizerOpen] = useState(false)
  const hasLoadedRef = useRef(false)
  const layoutUserIdRef = useRef(userId)

  useEffect(() => {
    if (layoutUserIdRef.current === userId) return
    layoutUserIdRef.current = userId
    setLayout(loadDeanDashboardLayout(userId))
  }, [userId])

  const busy = loading || refreshing

  const capabilities = dashboard?.capabilities ?? {}
  const kpis = dashboard?.kpis ?? {}
  const charts = dashboard?.charts ?? {}
  const filterOptions = dashboard?.filter_options ?? { academic_years: [], semesters: [] }
  const selectedYearId = yearId || String(dashboard?.context?.academic_year?.academic_year_id || '')
  const selectedSemesterId = semesterId || String(dashboard?.context?.semester?.semester_id || '')
  const termResolved = dashboard?.term_resolved === true

  useEffect(() => {
    let active = true
    const seq = ++requestSeq.current

    async function load() {
      if (hasLoadedRef.current) setRefreshing(true)
      else setLoading(true)
      setError('')

      const params = new URLSearchParams()
      if (yearId) params.set('academic_year_id', yearId)
      if (semesterId) params.set('semester_id', semesterId)
      const query = params.toString()

      try {
        const response = await apiRequest(`/v1/dean/dashboard${query ? `?${query}` : ''}`)
        if (!active || seq !== requestSeq.current) return
        setDashboard(response?.data ?? null)
        hasLoadedRef.current = true
      } catch (requestError) {
        if (!active || seq !== requestSeq.current) return
        if (requestError.status === 401) {
          navigate('/login', { replace: true })
          return
        }
        setError(
          requestError.status === 403
            ? 'ليس لديك صلاحية لعرض لوحة متابعة الكلية.'
            : 'تعذّر تحميل لوحة المتابعة. يرجى المحاولة مرة أخرى.',
        )
      } finally {
        if (active && seq === requestSeq.current) {
          setLoading(false)
          setRefreshing(false)
        }
      }
    }

    load()
    return () => { active = false }
  }, [yearId, semesterId, navigate])

  const visibleKpis = useMemo(() => {
    const currentCapabilities = dashboard?.capabilities ?? {}
    const currentKpis = dashboard?.kpis ?? {}
    const sessionsByType = dashboard?.charts?.sessions_by_type
    const termReady = dashboard?.term_resolved === true
    const passHint = currentCapabilities.grades && currentKpis.graded_students_count
      ? `نسبة النجاح: ${formatPercent(currentKpis.pass_rate)}`
      : ''

    return [
      {
        id: 'kpi_active_students',
        label: 'الطلاب النشطون',
        value: formatCount(currentKpis.active_students),
        unavailable: currentCapabilities.students === false,
        accent: '#569933',
        hint: 'ضمن نطاق الكلية',
      },
      {
        id: 'kpi_active_teaching_staff',
        label: 'الكادر التدريسي النشط',
        value: formatCount(currentKpis.active_teaching_staff),
        unavailable: currentCapabilities.teaching_staff === false,
        accent: '#3b82f6',
        hint: 'عضوية الكلية وليس التكليف الحالي فقط',
      },
      {
        id: 'kpi_course_offerings',
        label: 'المواد المطروحة',
        value: formatCount(currentKpis.course_offerings),
        unavailable: currentCapabilities.courses === false,
        accent: '#0f766e',
        hint: termReady ? 'حسب السنة والفصل المحددين' : TERM_SELECTION_MESSAGE,
      },
      {
        id: 'kpi_open_registration',
        label: 'مفتوحة للتسجيل',
        value: formatCount(currentKpis.open_registration_offerings),
        unavailable: currentCapabilities.courses === false,
        accent: '#7ab356',
        hint: !termReady
          ? TERM_SELECTION_MESSAGE
          : (currentKpis.closed_registration_offerings != null
            ? `مغلقة: ${formatCount(currentKpis.closed_registration_offerings)}`
            : ''),
      },
      {
        id: 'kpi_attendance_sessions',
        label: 'جلسات الحضور',
        value: formatCount(currentKpis.attendance_sessions),
        unavailable: currentCapabilities.attendance === false,
        accent: '#f59e0b',
        hint: termReady ? sessionTypeHint(sessionsByType, busy) : TERM_SELECTION_MESSAGE,
      },
      {
        id: 'kpi_average_final_mark',
        label: 'متوسط العلامة النهائية',
        value: formatAverageMark(currentKpis.average_final_mark),
        unavailable: currentCapabilities.grades === false,
        accent: '#8b5cf6',
        hint: currentCapabilities.grades === false
          ? 'يتطلب صلاحية عرض النتائج'
          : (!termReady
            ? TERM_SELECTION_MESSAGE
            : (currentKpis.average_final_mark == null
              ? 'لا توجد نتائج نهائية متاحة للفصل المحدد'
              : passHint)),
      },
      {
        id: 'kpi_incomplete_assignments',
        label: 'تكليفات غير مكتملة',
        value: formatCount(currentKpis.incomplete_assignments),
        unavailable: currentCapabilities.courses === false,
        accent: '#ef4444',
        hint: termReady ? 'المواد التي ينقصها شق نظري أو عملي مطلوب' : TERM_SELECTION_MESSAGE,
      },
    ].filter(card => isWidgetVisible(layout, card.id))
  }, [busy, dashboard, layout])

  function persistLayout(next) {
    setLayout(saveDeanDashboardLayout(userId, next))
  }

  function toggleWidget(widgetId) {
    const hidden = new Set(layout.hiddenWidgets)
    if (hidden.has(widgetId)) hidden.delete(widgetId)
    else hidden.add(widgetId)
    persistLayout({ ...layout, hiddenWidgets: [...hidden] })
  }

  function moveWidget(widgetId, direction) {
    const order = [...layout.widgetOrder]
    const index = order.indexOf(widgetId)
    const nextIndex = index + direction
    if (index < 0 || nextIndex < 0 || nextIndex >= order.length) return
    ;[order[index], order[nextIndex]] = [order[nextIndex], order[index]]
    persistLayout({ ...layout, widgetOrder: order })
  }

  const attentionItems = dashboard?.attention ?? []
  const recentActivity = dashboard?.recent_activity ?? []
  const resultsChart = charts.average_results_by_program ?? []
  const showResultsChart = capabilities.grades !== false && resultsChart.length > 0

  const widgets = {
    kpi_strip: isWidgetVisible(layout, 'kpi_strip') ? (
      <div
        key="kpi_strip"
        className="grid grid-cols-4 max-[1280px]:grid-cols-3 max-[900px]:grid-cols-2 max-[560px]:grid-cols-1 gap-4"
      >
        {(busy && visibleKpis.length === 0 ? [1, 2, 3, 4, 5, 6, 7] : visibleKpis).map((card, index) => (
          typeof card === 'number' ? (
            <DeanKpiCard key={card} label="..." value="" loading />
          ) : (
            <motion.div
              key={card.id}
              initial={{ opacity: 0, y: 12 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.35, delay: index * 0.04 }}
            >
              <DeanKpiCard
                label={card.label}
                value={card.value}
                hint={card.hint}
                accent={card.accent}
                loading={busy && !dashboard}
                unavailable={card.unavailable}
              />
            </motion.div>
          )
        ))}
      </div>
    ) : null,

    chart_students_by_program: isWidgetVisible(layout, 'chart_students_by_program') ? (
      <DashboardBarChart
        key="chart_students_by_program"
        title="طلاب الكلية حسب البرنامج"
        loading={busy && !dashboard}
        emptyText="لا توجد بيانات طلاب ضمن نطاق الكلية"
        items={(charts.students_by_program ?? []).map(row => ({
          key: row.academic_program_id ?? row.program_name,
          label: row.program_name,
          value: row.students_count,
        }))}
      />
    ) : null,

    chart_students_by_level: isWidgetVisible(layout, 'chart_students_by_level') ? (
      <DashboardBarChart
        key="chart_students_by_level"
        title="توزيع الطلاب حسب السنة الدراسية"
        loading={busy && !dashboard}
        emptyText="لا توجد بيانات طلاب ضمن نطاق الكلية"
        items={(charts.students_by_level ?? []).map(row => ({
          key: row.academic_level_id ?? row.level_name,
          label: row.level_name,
          value: row.students_count,
        }))}
      />
    ) : null,

    chart_offering_statuses: isWidgetVisible(layout, 'chart_offering_statuses') ? (
      <DashboardDonutChart
        key="chart_offering_statuses"
        title="حالة المواد في الفصل"
        loading={busy && !dashboard}
        emptyText={termResolved ? 'لا توجد مواد مطروحة للفصل المحدد' : TERM_SELECTION_MESSAGE}
        items={(charts.offering_statuses ?? []).map(row => ({
          key: row.status,
          label: row.label,
          value: row.count,
          color: offeringStatusColor(row.status),
        }))}
      />
    ) : null,

    chart_teaching_assignments: isWidgetVisible(layout, 'chart_teaching_assignments') ? (
      <DashboardDonutChart
        key="chart_teaching_assignments"
        title="اكتمال التكليفات التدريسية"
        loading={busy && !dashboard}
        emptyText={termResolved ? 'لا توجد مواد مطروحة للفصل المحدد' : TERM_SELECTION_MESSAGE}
        items={(charts.teaching_assignment_status ?? []).map(row => ({
          key: row.code,
          label: row.label,
          value: row.count,
          color: assignmentColor(row.code),
        }))}
      />
    ) : null,

    chart_average_results_by_program: isWidgetVisible(layout, 'chart_average_results_by_program') && (!termResolved || showResultsChart || (busy && !dashboard)) ? (
      <DashboardBarChart
        key="chart_average_results_by_program"
        title="متوسط النتائج حسب البرنامج"
        loading={busy && !dashboard}
        emptyText={termResolved ? 'لا توجد نتائج نهائية متاحة للفصل المحدد' : TERM_SELECTION_MESSAGE}
        valueSuffix=""
        items={resultsChart.map(row => ({
          key: row.academic_program_id ?? row.program_name,
          label: row.program_name,
          value: row.average_final_mark,
        }))}
      />
    ) : null,

    attention: isWidgetVisible(layout, 'attention') ? (
      <section
        key="attention"
        className="bg-white border border-primary/12 rounded-[18px] p-5 shadow-[0_2px_12px_rgba(26,46,16,0.05)]"
        dir="rtl"
      >
        <h3 className="text-[15px] font-black text-text-dark mb-4">تحتاج إلى متابعة</h3>
        {busy && !dashboard ? (
          <div className="space-y-3" aria-hidden="true">
            <div className="h-12 rounded-xl bg-primary/8 animate-pulse" />
            <div className="h-12 rounded-xl bg-primary/8 animate-pulse" />
          </div>
        ) : attentionItems.length === 0 ? (
          <p className="text-[13px] text-text-light leading-7">
            {!termResolved
              ? TERM_SELECTION_MESSAGE
              : (kpis.course_offerings
                ? 'لا توجد مواد تحتاج إلى استكمال التكليف التدريسي'
                : 'لا توجد مواد مطروحة للفصل المحدد')}
          </p>
        ) : (
          <ul className="space-y-2">
            {attentionItems.map(item => (
              <li key={item.code}>
                <Link
                  to={item.href || '/dean/courses'}
                  className="flex items-start gap-3 rounded-[14px] border border-amber-200 bg-amber-50 px-4 py-3 text-amber-900 no-underline hover:bg-amber-100"
                >
                  <FaExclamationCircle className="mt-0.5 shrink-0" aria-hidden="true" />
                  <span className="text-[13.5px] font-semibold leading-6">{item.label}</span>
                </Link>
              </li>
            ))}
          </ul>
        )}
        {recentActivity.length > 0 ? (
          <div className="mt-5 pt-4 border-t border-primary/10">
            <h4 className="text-[13px] font-black text-text-dark mb-3">أحدث جلسات الحضور</h4>
            <ul className="space-y-2">
              {recentActivity.map(item => (
                <li key={item.attendance_session_id} className="flex items-center justify-between gap-3 text-[12.5px]">
                  <span className="font-semibold text-text-dark truncate">
                    {[item.course_code, item.course_name].filter(Boolean).join(' — ') || 'مادة'}
                  </span>
                  <span className="text-text-light shrink-0">
                    {item.session_type_label} · {formatDisplayDate(item.session_date)}
                  </span>
                </li>
              ))}
            </ul>
          </div>
        ) : null}
      </section>
    ) : null,
  }

  const orderedWidgets = layout.widgetOrder.map(id => widgets[id]).filter(Boolean)

  const renderedSections = []
  let chartBuffer = []
  function flushCharts() {
    if (chartBuffer.length === 0) return
    renderedSections.push(
      <div key={`charts-${renderedSections.length}`} className="grid grid-cols-2 max-[900px]:grid-cols-1 gap-4">
        {chartBuffer}
      </div>,
    )
    chartBuffer = []
  }

  orderedWidgets.forEach(widget => {
    const isChart = typeof widget?.key === 'string' && widget.key.startsWith('chart_')
    if (isChart) {
      chartBuffer.push(widget)
      return
    }
    flushCharts()
    renderedSections.push(widget)
  })
  flushCharts()

  return (
    <div className="space-y-5" dir="rtl">
      <motion.header
        className="bg-[linear-gradient(135deg,rgba(86,153,51,0.12),rgba(255,255,255,0.9))] border border-primary/12 rounded-[20px] px-6 py-6 shadow-[0_2px_16px_rgba(26,46,16,0.06)]"
        initial={{ opacity: 0, y: 10 }}
        animate={{ opacity: 1, y: 0 }}
      >
        <div className="flex flex-col xl:flex-row xl:items-end justify-between gap-5">
          <div>
            <p className="text-[12px] font-bold text-primary mb-1">لوحة المتابعة التنفيذية</p>
            <h1 className="text-[24px] font-black text-text-dark leading-9">
              {deanHomeWelcome(identity)}
            </h1>
            <p className="mt-2 text-[13.5px] text-text-light leading-7 max-w-[640px]">
              نظرة تنفيذية على أداء الكلية والطلاب والمواد والكادر التدريسي
            </p>
          </div>
          <button
            type="button"
            className="inline-flex items-center justify-center gap-2 rounded-[12px] border border-primary/20 bg-white px-4 py-2.5 text-[13px] font-bold text-primary-dark hover:bg-primary/8"
            onClick={() => setCustomizerOpen(true)}
          >
            <FaCog aria-hidden="true" />
            تخصيص اللوحة
          </button>
        </div>

        <div className="mt-5 flex flex-wrap items-end gap-3">
          <label className="flex flex-col gap-1 text-[12px] font-semibold text-text-light">
            السنة الدراسية
            <select
              className="min-w-[220px] py-2.5 px-3 border-[1.5px] border-primary/20 rounded-[12px] bg-white text-[13.5px] text-text-dark"
              value={selectedYearId}
              onChange={event => setYearId(event.target.value)}
              disabled={busy && !dashboard}
            >
              <option value="">اختر السنة الدراسية</option>
              {(filterOptions.academic_years ?? []).map(year => (
                <option key={year.academic_year_id} value={year.academic_year_id}>
                  {year.year_name}{year.is_current ? ' (الحالية)' : ''}
                </option>
              ))}
            </select>
          </label>
          <label className="flex flex-col gap-1 text-[12px] font-semibold text-text-light">
            الفصل الدراسي
            <select
              className="min-w-[200px] py-2.5 px-3 border-[1.5px] border-primary/20 rounded-[12px] bg-white text-[13.5px] text-text-dark"
              value={selectedSemesterId}
              onChange={event => setSemesterId(event.target.value)}
              disabled={busy && !dashboard}
            >
              <option value="">اختر الفصل الدراسي</option>
              {(filterOptions.semesters ?? []).map(semester => (
                <option key={semester.semester_id} value={semester.semester_id}>
                  {semester.semester_name}{semester.is_active ? ' (نشط)' : ''}
                </option>
              ))}
            </select>
          </label>
          {refreshing ? (
            <span className="text-[12px] text-text-light pb-2">جاري تحديث بيانات الفصل…</span>
          ) : null}
        </div>
        {!busy && dashboard && !termResolved ? (
          <p className="mt-4 rounded-[12px] border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] font-semibold text-amber-900">
            {TERM_SELECTION_MESSAGE}
          </p>
        ) : null}
      </motion.header>

      {error ? (
        <div className="flex items-center gap-3 bg-red-500/6 border border-red-500/25 rounded-[12px] px-5 py-3.5 text-[13.5px] text-red-600">
          <span>⚠ {error}</span>
        </div>
      ) : null}

      <DeanQuickActions
        canManageTeachers={hasPermission(PERMISSIONS.teachingStaffManage) || capabilities.teaching_staff_manage}
        canManageRegistration={hasPermission(PERMISSIONS.courseOfferingsManage) || hasPermission(PERMISSIONS.coursesManage) || capabilities.course_offerings_manage}
      />

      {renderedSections}

      {customizerOpen ? (
        <DeanDashboardCustomizer
          layout={layout}
          onToggle={toggleWidget}
          onMove={moveWidget}
          onReset={() => persistLayout(resetDeanDashboardLayout(userId))}
          onClose={() => setCustomizerOpen(false)}
        />
      ) : null}
    </div>
  )
}
