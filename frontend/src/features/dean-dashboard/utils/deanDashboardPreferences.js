const STORAGE_PREFIX = 'dean-dashboard-layout:'
const LAYOUT_VERSION = 1

export const KPI_WIDGETS = [
  { id: 'kpi_active_students', label: 'الطلاب النشطون' },
  { id: 'kpi_active_teaching_staff', label: 'الكادر التدريسي النشط' },
  { id: 'kpi_course_offerings', label: 'المواد المطروحة' },
  { id: 'kpi_open_registration', label: 'المواد المفتوحة للتسجيل' },
  { id: 'kpi_attendance_sessions', label: 'جلسات الحضور' },
  { id: 'kpi_average_final_mark', label: 'متوسط العلامة النهائية' },
  { id: 'kpi_incomplete_assignments', label: 'تكليفات غير مكتملة' },
]

export const SECTION_WIDGETS = [
  { id: 'kpi_strip', label: 'بطاقات المؤشرات' },
  { id: 'chart_students_by_program', label: 'طلاب الكلية حسب البرنامج' },
  { id: 'chart_students_by_level', label: 'توزيع الطلاب حسب السنة الدراسية' },
  { id: 'chart_offering_statuses', label: 'حالة المواد في الفصل' },
  { id: 'chart_teaching_assignments', label: 'اكتمال التكليفات التدريسية' },
  { id: 'chart_average_results_by_program', label: 'متوسط النتائج حسب البرنامج' },
  { id: 'attention', label: 'تحتاج إلى متابعة' },
]

const DEFAULT_WIDGET_ORDER = SECTION_WIDGETS.map(widget => widget.id)
const KNOWN_WIDGET_IDS = new Set([
  ...KPI_WIDGETS.map(widget => widget.id),
  ...SECTION_WIDGETS.map(widget => widget.id),
])

export function defaultDeanDashboardLayout() {
  return {
    version: LAYOUT_VERSION,
    widgetOrder: [...DEFAULT_WIDGET_ORDER],
    hiddenWidgets: [],
  }
}

function storageKey(userId) {
  return `${STORAGE_PREFIX}${userId}`
}

function sanitizeLayout(raw) {
  const defaults = defaultDeanDashboardLayout()
  if (!raw || typeof raw !== 'object') {
    return defaults
  }

  const hiddenWidgets = Array.isArray(raw.hiddenWidgets)
    ? raw.hiddenWidgets.filter(id => KNOWN_WIDGET_IDS.has(id))
    : []

  const requestedOrder = Array.isArray(raw.widgetOrder)
    ? raw.widgetOrder.filter(id => DEFAULT_WIDGET_ORDER.includes(id))
    : []

  const widgetOrder = [
    ...requestedOrder,
    ...DEFAULT_WIDGET_ORDER.filter(id => !requestedOrder.includes(id)),
  ]

  return {
    version: LAYOUT_VERSION,
    widgetOrder,
    hiddenWidgets: [...new Set(hiddenWidgets)],
  }
}

export function loadDeanDashboardLayout(userId) {
  if (userId === null || userId === undefined || userId === '') {
    return defaultDeanDashboardLayout()
  }

  try {
    const raw = localStorage.getItem(storageKey(userId))
    if (!raw) return defaultDeanDashboardLayout()
    return sanitizeLayout(JSON.parse(raw))
  } catch {
    return defaultDeanDashboardLayout()
  }
}

export function saveDeanDashboardLayout(userId, layout) {
  if (userId === null || userId === undefined || userId === '') {
    return sanitizeLayout(layout)
  }

  const next = sanitizeLayout(layout)
  try {
    localStorage.setItem(storageKey(userId), JSON.stringify(next))
  } catch {
    // Ignore quota / private-mode failures; in-memory layout still applies.
  }
  return next
}

export function resetDeanDashboardLayout(userId) {
  const next = defaultDeanDashboardLayout()
  if (userId === null || userId === undefined || userId === '') {
    return next
  }

  try {
    localStorage.removeItem(storageKey(userId))
  } catch {
    // Ignore storage failures and still return defaults.
  }
  return next
}

export function isWidgetVisible(layout, widgetId) {
  return !layout?.hiddenWidgets?.includes(widgetId)
}
