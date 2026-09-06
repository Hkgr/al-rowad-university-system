export const SUBJECT_LABELS = Object.freeze({
  students: 'الطلاب',
  enrollments: 'الالتحاق الجامعي',
  faculty: 'الهيئة التدريسية',
  course_offerings: 'الطروحات الأكاديمية',
  academic_performance: 'الأداء الأكاديمي',
  grade_workflow: 'سير عمل العلامات',
})

export const MODE_LABELS = Object.freeze({ summary: 'ملخص ورسوم', details: 'تفاصيل', comparison: 'مقارنة', trend: 'اتجاه زمني' })
export const PERIOD_LABELS = Object.freeze({ none: 'الوضع الحالي', academic: 'فترة أكاديمية', date_range: 'نطاق زمني' })
export const COMPARISON_LABELS = Object.freeze({
  selected_scopes: 'مقارنة النطاقات المحددة',
  previous_period: 'الفترة السابقة المساوية',
  previous_semester: 'الفصل السابق',
  previous_academic_year: 'السنة الأكاديمية السابقة',
  custom: 'خط أساس مخصص',
})

export const FILTERS = Object.freeze({
  college_ids: { resource: 'colleges', label: 'الكليات', dimension: 'college' },
  department_ids: { resource: 'departments', label: 'الأقسام', dimension: 'department', parent: 'college_id', parentFilter: 'college_ids' },
  program_ids: { resource: 'programs', label: 'البرامج', dimension: 'program', parent: 'department_id', parentFilter: 'department_ids' },
  academic_level_ids: { resource: 'academic_levels', label: 'المستويات', dimension: 'academic_level', parent: 'program_id', parentFilter: 'program_ids' },
  course_ids: { resource: 'courses', label: 'المقررات', dimension: 'course', parent: 'program_id', parentFilter: 'program_ids' },
  offering_ids: { resource: 'offerings', label: 'الطروحات', parent: 'program_id', parentFilter: 'program_ids' },
  student_status_codes: { resource: 'student_statuses', label: 'حالات الطلاب', dimension: 'student_status', codeValues: true },
  result_status_codes: { resource: 'result_statuses', label: 'حالات النتائج', dimension: 'result_status', codeValues: true },
  offering_statuses: { resource: 'offering_statuses', label: 'حالات الطرح', dimension: 'offering_status', codeValues: true },
  grade_workflow_statuses: { resource: 'grade_workflow_statuses', label: 'حالات سير العلامات', dimension: 'grade_workflow_status', codeValues: true },
})

export const SELECTED_SCOPE_FILTER = Object.freeze({
  college: 'college_ids',
  department: 'department_ids',
  program: 'program_ids',
  academic_level: 'academic_level_ids',
  course: 'course_ids',
})

export const DIMENSION_RESOURCES = Object.freeze({
  college: 'colleges', department: 'departments', program: 'programs', academic_level: 'academic_levels',
  academic_year: 'academic_years', semester: 'semesters', course: 'courses',
  student_status: 'student_statuses', result_status: 'result_statuses', offering_status: 'offering_statuses',
  grade_workflow_status: 'grade_workflow_statuses',
})

const DESCENDANTS = Object.freeze({
  college_ids: ['department_ids', 'program_ids', 'academic_level_ids', 'course_ids', 'offering_ids'],
  department_ids: ['program_ids', 'academic_level_ids', 'course_ids', 'offering_ids'],
  program_ids: ['academic_level_ids', 'course_ids', 'offering_ids'],
})

function codes(items) {
  return Array.isArray(items) ? items.map(item => typeof item === 'string' ? item : item?.code).filter(Boolean) : []
}

export function normalizeDefinition(subject, definition) {
  if (!definition || typeof definition !== 'object') return null
  const metrics = Array.isArray(definition.metrics) ? definition.metrics.filter(item => item && typeof item.code === 'string') : []
  const dimensions = Array.isArray(definition.dimensions) ? definition.dimensions.filter(item => item && typeof item.code === 'string') : []
  const modes = codes(definition.modes)
  const supportedPeriods = codes(definition.period_capabilities?.supported)
  if (!SUBJECT_LABELS[subject] || metrics.length === 0 || modes.length === 0) return null
  return { ...definition, subject, metrics, dimensions, modes, supportedPeriods }
}

export function normalizeDefinitions(contract) {
  const normalized = {}
  Object.entries(contract?.subjects ?? {}).forEach(([subject, definition]) => {
    const value = normalizeDefinition(subject, definition)
    if (value) normalized[subject] = value
  })
  if (Object.keys(normalized).length === 0) throw new Error('executive_report_definitions_empty')
  return normalized
}

export function initialReportConfig(definition) {
  return {
    subject: definition.subject,
    mode: definition.modes.includes('summary') ? 'summary' : definition.modes[0],
    metrics: definition.metrics.slice(0, 2).map(metric => metric.code),
    dimensions: [], filters: {}, labels: {},
    period: { type: definition.supportedPeriods.includes('none') ? 'none' : definition.supportedPeriods[0] },
    comparison: null, page: 1, per_page: 25, sort: null,
  }
}

export function changeSubject(definition) {
  return initialReportConfig(definition)
}

export function clearDescendantSelections(config, changedFilter) {
  const filters = { ...config.filters }
  const labels = { ...config.labels }
  for (const key of DESCENDANTS[changedFilter] ?? []) {
    delete filters[key]
    delete labels[key]
  }
  return { ...config, filters, labels, page: 1 }
}

export function filterKeysForDefinition(definition) {
  return (definition?.filters ?? []).filter(key => FILTERS[key] && key !== 'academic_year_ids' && key !== 'semester_ids')
}

export function selectedScopeEligibility(config) {
  if (config.dimensions.length !== 1) return { allowed: false, reason: 'comparison_requires_one_dimension' }
  const filter = SELECTED_SCOPE_FILTER[config.dimensions[0]]
  if (!filter) return { allowed: false, reason: 'comparison_dimension_not_supported' }
  const values = [...new Set(config.filters[filter] ?? [])]
  if (values.length < 2) return { allowed: false, reason: 'comparison_requires_two_values', filter }
  return { allowed: true, filter, values }
}

export function availableComparisons(config, definition, serverComparisons = []) {
  const available = []
  if (serverComparisons.includes('selected_scopes') && selectedScopeEligibility(config).allowed) available.push('selected_scopes')
  if (serverComparisons.includes('previous_period') && config.period.type === 'date_range') available.push('previous_period')
  if (serverComparisons.includes('previous_semester') && config.period.type === 'academic' && config.period.academic_year_ids?.length === 1 && config.period.semester_ids?.length === 1) available.push('previous_semester')
  if (serverComparisons.includes('previous_academic_year') && config.period.type === 'academic' && config.period.academic_year_ids?.length === 1) available.push('previous_academic_year')
  if (serverComparisons.includes('custom') && definition.modes.includes('comparison')) available.push('custom')
  return available
}

function compactLists(object = {}) {
  return Object.fromEntries(Object.entries(object).filter(([, value]) => !Array.isArray(value) || value.length > 0))
}

function payloadPeriod(period = {}) {
  if (!period.type || period.type === 'none') return undefined
  if (period.type === 'academic') return compactLists({ type: 'academic', academic_year_ids: period.academic_year_ids ?? [], semester_ids: period.semester_ids ?? [] })
  return { type: 'date_range', date_from: period.date_from, date_to: period.date_to }
}

function selectionCount(config) {
  const lists = [config.filters, payloadPeriod(config.period), config.comparison?.baseline?.filters, payloadPeriod(config.comparison?.baseline?.period)]
  return lists.reduce((total, section) => total + Object.values(section ?? {}).reduce((count, value) => count + (Array.isArray(value) ? value.length : 0), 0), 0)
}

export function validateReportConfig(config, definition, limits) {
  const errors = {}
  const metricCodes = new Set(definition.metrics.map(item => item.code))
  const dimensionCodes = new Set(definition.dimensions.map(item => item.code))
  if (!definition.modes.includes(config.mode)) errors.mode = 'طريقة العرض غير مدعومة لهذا التقرير.'
  if (!config.metrics.length) errors.metrics = 'اختر مؤشرًا واحدًا على الأقل.'
  if (config.metrics.some(code => !metricCodes.has(code)) || config.metrics.length > limits.metrics) errors.metrics = `يمكن اختيار ${limits.metrics} مؤشرًا كحد أقصى.`
  if (config.dimensions.some(code => !dimensionCodes.has(code)) || config.dimensions.length > limits.dimensions) errors.dimensions = `يمكن اختيار ${limits.dimensions} أبعاد كحد أقصى.`
  for (const [key, values] of Object.entries(config.filters)) if (Array.isArray(values) && values.length > limits.ids_per_filter) errors[key] = `الحد الأقصى ${limits.ids_per_filter} قيمة.`
  for (const [key, values] of Object.entries(config.comparison?.baseline?.filters ?? {})) if (Array.isArray(values) && values.length > limits.ids_per_filter) errors[`comparison.baseline.${key}`] = `الحد الأقصى ${limits.ids_per_filter} قيمة في خط الأساس.`
  if (selectionCount(config) > limits.selected_ids) errors.filters = `تجاوز مجموع الاختيارات الحد المسموح (${limits.selected_ids}).`
  if (config.period.type === 'academic' && !config.period.academic_year_ids?.length) errors.period = 'اختر سنة أكاديمية.'
  if (config.period.semester_ids?.length && !config.period.academic_year_ids?.length) errors.semester_ids = 'اختيار الفصل يتطلب سنة أكاديمية.'
  if (config.period.type === 'date_range' && (!config.period.date_from || !config.period.date_to)) errors.period = 'حدد تاريخ البداية والنهاية.'
  if (config.period.type === 'date_range' && config.period.date_from && config.period.date_to) {
    const days = Math.floor((Date.parse(`${config.period.date_to}T00:00:00Z`) - Date.parse(`${config.period.date_from}T00:00:00Z`)) / 86400000) + 1
    const timeDimension = config.dimensions.find(value => ['day', 'week', 'month'].includes(value))
    const maximumDays = timeDimension === 'day' ? 366 : timeDimension === 'week' ? 366 * 5 : timeDimension === 'month' ? 366 * 10 : 366 * 10
    if (!Number.isFinite(days) || days <= 0 || days > maximumDays) errors.period = `النطاق أطول من الحد المسموح لبعد ${timeDimension === 'day' ? 'اليوم' : timeDimension === 'week' ? 'الأسبوع' : 'الشهر'}.`
  }
  const timeDimensions = config.dimensions.filter(value => ['day', 'week', 'month'].includes(value))
  if (timeDimensions.length > 0 && (config.period.type !== 'date_range' || timeDimensions.length !== 1)) errors.dimensions = 'بعد اليوم أو الأسبوع أو الشهر يتطلب نطاقًا زمنيًا وبُعدًا زمنيًا واحدًا.'
  if (config.mode === 'trend' && (config.period.type !== 'date_range' || config.dimensions.length !== 1 || !['day', 'week', 'month'].includes(config.dimensions[0]))) errors.dimensions = 'الاتجاه الزمني يتطلب نطاقًا زمنيًا وبُعد يوم أو أسبوع أو شهر واحدًا.'
  const comparison = config.comparison?.type
  if (config.mode === 'comparison' && !comparison) errors.comparison = 'اختر نوع المقارنة.'
  if (comparison === 'selected_scopes' && !selectedScopeEligibility(config).allowed) errors.comparison = 'تتطلب مقارنة النطاقات بُعدًا مدعومًا واحدًا وقيمتين محددتين على الأقل.'
  if (comparison === 'previous_period' && config.period.type !== 'date_range') errors.comparison = 'الفترة السابقة تتطلب نطاقًا زمنيًا.'
  if (comparison === 'previous_semester' && !(config.period.type === 'academic' && config.period.academic_year_ids?.length === 1 && config.period.semester_ids?.length === 1)) errors.comparison = 'الفصل السابق يتطلب سنة وفصلًا واحدًا.'
  if (comparison === 'previous_academic_year' && !(config.period.type === 'academic' && config.period.academic_year_ids?.length === 1)) errors.comparison = 'السنة السابقة تتطلب سنة واحدة.'
  if (comparison === 'custom') {
    const baseline = config.comparison?.baseline
    const hasScope = Object.values(baseline?.filters ?? {}).some(values => Array.isArray(values) && values.length > 0)
    const hasPeriod = baseline?.period?.type && baseline.period.type !== 'none'
    if (!baseline || (!hasScope && !hasPeriod)) errors.comparison = 'حدد نطاقًا أو فترة لخط الأساس.'
    if (baseline?.period?.type === 'academic' && !baseline.period.academic_year_ids?.length) errors.comparison = 'فترة خط الأساس الأكاديمية تتطلب سنة.'
    if (baseline?.period?.semester_ids?.length && !baseline.period.academic_year_ids?.length) errors.comparison = 'فصل خط الأساس يتطلب سنة أكاديمية.'
    if (baseline?.period?.type === 'date_range' && (!baseline.period.date_from || !baseline.period.date_to)) errors.comparison = 'حدد تاريخي خط الأساس.'
  }
  if (config.sort && (!config.metrics.includes(config.sort.field) && !config.dimensions.includes(config.sort.field))) errors.sort = 'يجب أن يكون حقل الترتيب ضمن المؤشرات أو الأبعاد المحددة.'
  return errors
}

export function buildReportPayload(config) {
  const filters = compactLists(config.filters)
  const payload = {
    subject: config.subject, mode: config.mode, metrics: [...config.metrics], dimensions: [...config.dimensions],
    ...(Object.keys(filters).length ? { filters } : {}), ...(payloadPeriod(config.period) ? { period: payloadPeriod(config.period) } : {}),
    page: config.page, per_page: config.per_page,
  }
  if (config.sort) payload.sort = { ...config.sort }
  if (config.comparison?.type) {
    payload.comparison = { type: config.comparison.type }
    if (config.comparison.type === 'custom') {
      const baselineFilters = compactLists(config.comparison.baseline?.filters ?? {})
      const baselinePeriod = payloadPeriod(config.comparison.baseline?.period)
      payload.comparison.baseline = { ...(Object.keys(baselineFilters).length ? { filters: baselineFilters } : {}), ...(baselinePeriod ? { period: baselinePeriod } : {}) }
    }
  }
  return payload
}

export function applyPreset(definition, preset) {
  const metricSet = new Set(definition.metrics.map(item => item.code))
  const dimensionSet = new Set(definition.dimensions.map(item => item.code))
  const config = initialReportConfig(definition)
  return {
    ...config,
    mode: definition.modes.includes(preset.mode) ? preset.mode : config.mode,
    metrics: preset.metrics.filter(code => metricSet.has(code)).slice(0, 12),
    dimensions: preset.dimensions.filter(code => dimensionSet.has(code)).slice(0, 3),
    period: { type: definition.supportedPeriods.includes(preset.periodType) ? preset.periodType : config.period.type },
  }
}

export function applyNavigationSeed(definition, seed = {}) {
  const config = initialReportConfig(definition)
  const supportedFilters = new Set(filterKeysForDefinition(definition))
  const metrics = (seed.metrics ?? []).filter(code => definition.metrics.some(item => item.code === code)).slice(0, 12)
  const dimensions = (seed.dimensions ?? []).filter(code => definition.dimensions.some(item => item.code === code)).slice(0, 3)
  const filters = Object.fromEntries(Object.entries(seed.filters ?? {}).filter(([key, values]) => supportedFilters.has(key) && Array.isArray(values)).map(([key, values]) => [key, [...new Set(values)].slice(0, 50)]))
  const period = seed.period && definition.supportedPeriods.includes(seed.period.type) ? cloneSeedPeriod(seed.period) : config.period
  return { ...config, mode: definition.modes.includes(seed.mode) ? seed.mode : config.mode, metrics: metrics.length ? metrics : config.metrics, dimensions, filters, labels: seed.labels ?? {}, period }
}

function cloneSeedPeriod(period) {
  if (period.type === 'academic') return { type: 'academic', academic_year_ids: [...new Set(period.academic_year_ids ?? [])].slice(0, 50), semester_ids: [...new Set(period.semester_ids ?? [])].slice(0, 50) }
  if (period.type === 'date_range') return { type: 'date_range', date_from: period.date_from ?? '', date_to: period.date_to ?? '' }
  return { type: 'none' }
}

export const REPORT_PRESETS = Object.freeze([
  { code: 'college_students', subject: 'students', label: 'طلاب كلية', mode: 'summary', metrics: ['student_count', 'active_student_count'], dimensions: ['program'], periodType: 'none' },
  { code: 'compare_colleges', subject: 'students', label: 'مقارنة كليتين أو أكثر', mode: 'comparison', metrics: ['student_count'], dimensions: ['college'], periodType: 'none' },
  { code: 'term_students', subject: 'students', label: 'طلاب فصل وسنة محددين', mode: 'summary', metrics: ['student_count', 'official_gpa'], dimensions: ['program'], periodType: 'academic' },
  { code: 'college_performance', subject: 'academic_performance', label: 'الأداء الأكاديمي للكليات', mode: 'summary', metrics: ['official_average', 'official_gpa', 'pass_rate'], dimensions: ['college'], periodType: 'academic' },
  { code: 'faculty_workload', subject: 'faculty', label: 'الهيئة التدريسية والتكليفات', mode: 'summary', metrics: ['active_faculty_count', 'assigned_sections_count', 'students_per_assigned_faculty'], dimensions: ['college'], periodType: 'none' },
  { code: 'monthly_enrollments', subject: 'enrollments', label: 'تطور الالتحاق عبر الأشهر', mode: 'trend', metrics: ['enrollment_count'], dimensions: ['month'], periodType: 'date_range' },
])
