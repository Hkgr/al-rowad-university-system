// View-state helpers of the payroll page: filter/sort query, scope label, grid column definitions built from the saved configuration.

export const WORKPLACE_OPTIONS = [
  { value: 'afrin', label: 'عفرين' }, { value: 'jarablus', label: 'جرابلس' },
  { value: 'afrin_jarablus', label: 'عفرين وجرابلس' }, { value: 'other', label: 'أخرى' },
]

/** Identity/classification columns (always present; not configurable). `sort` is the server sort key. */
export const IDENTITY_COLUMNS = [
  { prop: 'employee_number', title: 'رقم الموظف', sort: 'employee_number', size: 125, pin: true, group: 'employee' },
  { prop: 'full_name', title: 'الاسم الكامل', sort: 'full_name', size: 200, pin: true, group: 'employee' },
  { prop: 'job_title', title: 'الصفة الوظيفية', sort: 'job_title', size: 150, group: 'employee' },
  { prop: 'body_name', title: 'الهيئة', sort: 'body', size: 140, group: 'employee' },
  { prop: 'workplace_label', title: 'مكان العمل', sort: 'workplace', size: 120, group: 'employee' },
  { prop: 'academic_level', title: 'المستوى الأكاديمي', sort: 'academic_level', size: 140, group: 'employee' },
]

/** Stable key of the final net payable column. Emphasis never follows the (renameable, reorderable) label. */
export const TOTAL_KEY = 'total_net_payable'

export const GROUP_LABELS = { employee: 'بيانات الموظف', salary: 'الراتب', compensation: 'التعويض', deductions: 'الاقتطاعات', net: 'الصافي' }
export const GROUP_ORDER = ['employee', 'salary', 'compensation', 'deductions', 'net']

const DEFAULT_SIZES = { amount: 150, number: 120, percent: 120, text: 170 }
const COMPACT_SIZES = { employee_number: 100, full_name: 170, body_name: 120, workplace_label: 105 }
/** The compact view keeps the classification that identifies a record; job title and academic level belong to the detailed view. */
const COMPACT_IDENTITY = new Set(['employee_number', 'full_name', 'body_name', 'workplace_label'])

/**
 * Grid columns for the current configuration and view: identity columns, then every configured column shown in the grid
 * (detailed view) or marked compact (compact view). Order follows the saved order within the fixed group order.
 */
export function buildColumns(config, { compact = false } = {}) {
  const configured = (config?.columns ?? [])
    .filter(column => column.visible_grid && (!compact || column.compact))
    .map(column => ({
      prop: column.key, key: column.key, title: column.label, sort: column.key, size: DEFAULT_SIZES[column.value_type] ?? 140, group: column.group,
      type: column.value_type, kind: column.kind, editable: column.kind === 'input', computed: column.kind === 'formula', definition: column,
      aggregation: column.aggregation,
    }))
  const identity = IDENTITY_COLUMNS.filter(column => !compact || COMPACT_IDENTITY.has(column.prop))
    .map(column => ({ ...column, size: compact ? COMPACT_SIZES[column.prop] : column.size, type: 'text', identity: true }))
  return [...identity, ...configured.map(column => (compact ? { ...column, size: Math.min(column.size, 125) } : column))]
}

/**
 * Entries of the grid legend, derived from the columns actually displayed (the list `buildColumns` returns, i.e. after the
 * visible_grid and compact-view filters). `input` is the editor wording, `source` the view-only one; `computed` is the ordinary
 * calculated styling (the final net payable has its own entry and is recognised by its stable key, never its label).
 */
export function legendEntries(columns, canEdit) {
  const shown = columns.filter(column => !column.identity)
  const entries = []
  if (shown.some(column => column.editable)) entries.push(canEdit ? 'input' : 'source')
  if (shown.some(column => column.computed && column.prop !== TOTAL_KEY)) entries.push('computed')
  if (shown.some(column => column.prop === TOTAL_KEY)) entries.push('net')
  return entries
}

export const emptyFilters = () => ({ search: '', payroll_employee_id: '', body_id: '', workplace: '', academic_level: '', academic_level_blank: false, completeness: '', sort: 'employee_number', direction: 'asc' })

export const hasActiveFilters = f => Boolean(f.search || f.payroll_employee_id || f.body_id || f.workplace || f.academic_level || f.academic_level_blank || f.completeness)

/** Same parameter names the API (grid and exports) understands. */
export function buildSheetQuery(filters) {
  const params = new URLSearchParams()
  for (const key of ['search', 'payroll_employee_id', 'body_id', 'workplace', 'academic_level', 'completeness', 'sort', 'direction']) {
    if (filters[key]) params.set(key, filters[key])
  }
  if (filters.academic_level_blank) params.set('academic_level_blank', '1')
  return params.toString()
}

/** Filters from page URL parameters (Home links open the payroll page with them). Unknown values are ignored. */
export function filtersFromParams(search) {
  const params = new URLSearchParams(search)
  const f = emptyFilters()
  if (/^[1-9]\d*$/.test(params.get('payroll_employee_id') ?? '')) f.payroll_employee_id = params.get('payroll_employee_id')
  if (/^\d+$/.test(params.get('body_id') ?? '')) f.body_id = params.get('body_id')
  if (WORKPLACE_OPTIONS.some(option => option.value === params.get('workplace'))) f.workplace = params.get('workplace')
  if (['complete', 'incomplete', 'warning'].includes(params.get('completeness'))) f.completeness = params.get('completeness')
  return f
}

export const errorText = error => error?.message || 'تعذّر الاتصال بالخادم'

/** First message per field from a Laravel/PayrollException `errors` map. */
export const fieldErrors = details => Object.fromEntries(Object.entries(details && !Array.isArray(details) ? details : {}).map(([key, messages]) => [key, Array.isArray(messages) ? messages[0] : String(messages)]))

/** Completeness of a row from its total net payable cell (same rule as the server). */
export function rowStatus(row, totalKey = TOTAL_KEY) {
  const cell = row.cells[totalKey]
  if (!cell) return 'complete'
  if (cell.st === 'missing' || cell.st === 'error') return 'incomplete'
  return Object.values(row.cells).some(c => c.st === 'warning') ? 'warning' : 'complete'
}
