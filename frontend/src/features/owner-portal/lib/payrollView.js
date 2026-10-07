// View-state helpers of the payroll page: filter/sort query, scope label, column definitions.

export const WORKPLACE_OPTIONS = [
  { value: 'afrin', label: 'عفرين' }, { value: 'jarablus', label: 'جرابلس' },
  { value: 'afrin_jarablus', label: 'عفرين وجرابلس' }, { value: 'other', label: 'أخرى' },
]

/** The ten grid columns in reading order (rightmost first in an RTL grid). */
export const COLUMNS = [
  { prop: 'employee_number', title: 'رقم الموظف', sort: 'employee_number', size: 125, pin: true },
  { prop: 'full_name', title: 'الاسم الكامل', sort: 'full_name', size: 200, pin: true },
  { prop: 'job_title', title: 'الصفة الوظيفية', sort: 'job_title', size: 150 },
  { prop: 'body_name', title: 'الهيئة', sort: 'body', size: 140 },
  { prop: 'workplace_label', title: 'مكان العمل', sort: 'workplace', size: 120 },
  { prop: 'academic_level', title: 'المستوى الأكاديمي', sort: 'academic_level', size: 140 },
  { prop: 'fixed_salary', title: 'الراتب المقطوع $', sort: 'fixed_salary', size: 140, money: true, editable: true },
  { prop: 'deduction', title: 'الاقتطاع $', sort: 'deduction', size: 120, money: true, editable: true },
  { prop: 'compensation', title: 'التعويض $', sort: 'compensation', size: 120, money: true, editable: true },
  { prop: 'payable', title: 'المستحق $', sort: 'payable', size: 140, money: true, computed: true },
]

export const emptyFilters = () => ({ search: '', body_id: '', workplace: '', academic_level: '', sort: 'employee_number', direction: 'asc' })

export const hasActiveFilters = f => Boolean(f.search || f.body_id || f.workplace || f.academic_level)

/** Same parameter names the API (grid and exports) understands. */
export function buildSheetQuery(filters) {
  const params = new URLSearchParams()
  for (const key of ['search', 'body_id', 'workplace', 'academic_level', 'sort', 'direction']) {
    if (filters[key]) params.set(key, filters[key])
  }
  return params.toString()
}

export const errorText = error => error?.message || 'تعذّر الاتصال بالخادم'

/** First message per field from a Laravel/PayrollException `errors` map. */
export const fieldErrors = details => Object.fromEntries(Object.entries(details && !Array.isArray(details) ? details : {}).map(([key, messages]) => [key, Array.isArray(messages) ? messages[0] : String(messages)]))
