import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { canAccessExecutiveReports, reportAccessForOffice, reportPathForOffice } from '../src/features/executive-reports/access.js'
import {
  REPORT_PRESETS, applyNavigationSeed, applyPreset, availableComparisons, buildReportPayload, clearDescendantSelections,
  initialReportConfig, normalizeDefinitions, removeIncompatibleHistoricalDraft, selectedScopeEligibility, validateReportConfig,
} from '../src/features/executive-reports/config.js'
import { draftAfterSuccessfulRequest, purgeReportLifecycle, resetReportLifecycle } from '../src/features/executive-reports/lifecycle.js'
import {
  alignComparison, classifyReportError, clearValidationErrors, formatMetric, isLatestResponse, metricValue,
  normalizeValidationErrors, reportPeriodText, reportRowKey, sortableFieldsForApplied, validationMessage,
} from '../src/features/executive-reports/presentation.js'

const read = path => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')
const limits = { ids_per_filter: 50, selected_ids: 200, metrics: 12, dimensions: 3, per_page: 100, points: 500 }
const rawDefinition = {
  label: 'الطلاب',
  metrics: [{ code: 'student_count', label: 'عدد الطلاب', unit: 'students' }, { code: 'official_gpa', label: 'المعدل', unit: 'gpa' }],
  dimensions: [{ code: 'college', label: 'الكلية' }, { code: 'academic_year', label: 'السنة' }, { code: 'semester', label: 'الفصل' }, { code: 'program', label: 'البرنامج' }, { code: 'day', label: 'اليوم' }],
  filters: ['college_ids', 'program_ids', 'academic_year_ids', 'semester_ids'], modes: ['summary', 'details', 'comparison'],
  sortable: ['student_count', 'college'], detail_sortable: ['student_count'],
  period_capabilities: { supported: ['none', 'academic'], date_range_metrics: [] }, history_availability: {},
}
const contract = { subjects: { students: rawDefinition }, comparisons: ['selected_scopes', 'previous_semester', 'previous_academic_year', 'custom'], limits }
const definition = normalizeDefinitions(contract).students

test('definitions are interpreted as metric and dimension objects', () => {
  assert.equal(definition.metrics[0].code, 'student_count')
  assert.deepEqual(definition.modes, ['summary', 'details', 'comparison'])
  assert.deepEqual(definition.supportedPeriods, ['none', 'academic'])
  assert.throws(() => normalizeDefinitions({ subjects: { unknown: {} }, comparisons: [], limits }), /contract_definition_invalid/)
})

test('malformed definition capabilities fail as a controlled contract error', () => {
  for (const malformed of [
    { ...rawDefinition, filters: null },
    { ...rawDefinition, sortable: 'student_count' },
    { ...rawDefinition, detail_sortable: null },
    { ...rawDefinition, metrics: [{ code: 'student_count' }] },
    { ...rawDefinition, period_capabilities: { supported: null } },
    { ...rawDefinition, period_capabilities: { supported: ['none'] } },
  ]) {
    assert.throws(() => normalizeDefinitions({ ...contract, subjects: { students: malformed } }), /executive_report_contract_definition_invalid/)
  }
  assert.throws(() => normalizeDefinitions({ ...contract, limits: { ...limits, points: null } }), /contract_definitions_invalid/)
  const classified = classifyReportError(new Error('executive_report_contract_definition_invalid:students'))
  assert.equal(classified.kind, 'contract')
})

test('successful response preserves a newer editable draft but aligns an unchanged draft', () => {
  const configA = { ...initialReportConfig(definition), filters: { college_ids: [1] } }
  const configB = { ...configA, filters: { college_ids: [2] } }
  const appliedA = structuredClone(configA)
  assert.deepEqual(draftAfterSuccessfulRequest(configB, configA, appliedA), configB)
  assert.deepEqual(draftAfterSuccessfulRequest(configA, configA, appliedA), appliedA)
  assert.deepEqual(draftAfterSuccessfulRequest(configB, configA, appliedA, true), configB)
})

test('reset clears loading and report state while invalidated responses remain obsolete', () => {
  const config = initialReportConfig(definition)
  const reset = resetReportLifecycle(config)
  assert.equal(reset.loading, false)
  assert.equal(reset.report, null)
  assert.equal(reset.applied, null)
  assert.deepEqual(reset.fieldErrors, {})
  const requestSequence = 4
  const sequenceAfterReset = 5
  assert.equal(isLatestResponse(requestSequence, sequenceAfterReset), false)
  assert.equal(isLatestResponse(6, 6), true)
})

test('details sorting uses only the detail allowlist and selected fields', () => {
  const applied = {
    config: { ...initialReportConfig(definition), mode: 'details', metrics: ['student_count'], dimensions: ['college'] },
    definition: { ...definition, sortable: ['student_count', 'college', 'official_gpa'], detail_sortable: ['student_count'] },
  }
  assert.deepEqual(sortableFieldsForApplied(applied), ['student_count'])
  assert.equal(sortableFieldsForApplied(applied).includes('college'), false)
  assert.deepEqual(sortableFieldsForApplied({ ...applied, config: { ...applied.config, mode: 'summary' } }), ['student_count', 'college'])
})

test('nested Laravel validation errors map to their actual safe control paths', () => {
  const normalized = normalizeValidationErrors({
    'filters.college_ids.0': ['The selected college is invalid.'],
    comparison: { baseline: { period: { date_to: ['The date must be after date from.'] } } },
    'sort.field': ['Unsupported sort field.'],
    exception: { trace: [{ file: '/secret/path' }] },
  })
  assert.match(normalized['filters.college_ids'], /^تحقق من هذا الحقل:/)
  assert.match(normalized['comparison.baseline.period.date_to'], /date must be after/)
  assert.match(validationMessage(normalized, 'sort'), /Unsupported sort field/)
  assert.equal(JSON.stringify(normalized).includes('/secret/path'), false)
  const cleared = clearValidationErrors(normalized, 'filters.college_ids')
  assert.equal(cleared['filters.college_ids'], undefined)
  assert.ok(cleared['sort.field'])
  const classified = classifyReportError({ status: 422, details: { 'period.academic_year_ids': ['Required.'] } })
  assert.ok(classified.details['period.academic_year_ids'])
})

test('historical grade workflow removes and rejects the current-only draft status', () => {
  const gradeRaw = {
    ...rawDefinition,
    metrics: [{ code: 'submitted_parts_count', label: 'مرسل', unit: 'parts' }],
    dimensions: [{ code: 'month', label: 'الشهر' }],
    filters: ['grade_workflow_statuses', 'academic_year_ids', 'semester_ids'],
    modes: ['summary', 'comparison', 'trend'], sortable: ['submitted_parts_count', 'month'], detail_sortable: ['month'],
    period_capabilities: { supported: ['none', 'academic', 'date_range'], date_range_metrics: ['submitted_parts_count'] },
  }
  const gradeDefinition = normalizeDefinitions({ ...contract, subjects: { grade_workflow: gradeRaw } }).grade_workflow
  const configuration = { ...initialReportConfig(gradeDefinition), filters: { grade_workflow_statuses: ['draft', 'submitted'] }, period: { type: 'date_range', date_from: '2026-01-01', date_to: '2026-02-01' }, dimensions: ['month'] }
  assert.match(validateReportConfig(configuration, gradeDefinition, limits)['filters.grade_workflow_statuses'], /المسودة/)
  const sanitized = removeIncompatibleHistoricalDraft(configuration)
  assert.deepEqual(sanitized.config.filters.grade_workflow_statuses, ['submitted'])
  assert.deepEqual(sanitized.removed, ['filters.grade_workflow_statuses'])
  const custom = { ...configuration, period: { type: 'none' }, comparison: { type: 'custom', baseline: { period: { type: 'date_range', date_from: '2026-01-01', date_to: '2026-02-01' }, filters: { grade_workflow_statuses: ['draft', 'approved'] } } } }
  const customSanitized = removeIncompatibleHistoricalDraft(custom)
  assert.deepEqual(customSanitized.config.comparison.baseline.filters.grade_workflow_statuses, ['approved'])
})

test('custom baseline date ranges use the same day week and month limits', () => {
  const dateRaw = { ...rawDefinition, dimensions: [...rawDefinition.dimensions, { code: 'week', label: 'الأسبوع' }, { code: 'month', label: 'الشهر' }], modes: ['summary', 'comparison'], period_capabilities: { supported: ['none', 'date_range'], date_range_metrics: ['student_count'] } }
  const dateDefinition = normalizeDefinitions({ ...contract, subjects: { students: dateRaw } }).students
  const withBaseline = (dimension, dateTo) => ({
    ...initialReportConfig(dateDefinition), mode: 'comparison', dimensions: dimension ? [dimension] : [],
    comparison: { type: 'custom', baseline: { filters: {}, period: { type: 'date_range', date_from: '2020-01-01', date_to: dateTo } } },
  })
  assert.match(validateReportConfig(withBaseline('day', '2021-01-02'), dateDefinition, limits)['comparison.baseline.period'], /الحد المسموح/)
  assert.equal(validateReportConfig(withBaseline('week', '2024-12-30'), dateDefinition, limits)['comparison.baseline.period'], undefined)
  assert.match(validateReportConfig(withBaseline('week', '2025-01-05'), dateDefinition, limits)['comparison.baseline.period'], /الحد المسموح/)
  assert.equal(validateReportConfig(withBaseline(null, '2029-12-31'), dateDefinition, limits)['comparison.baseline.period'], undefined)
  const reversed = withBaseline(null, '2019-12-31')
  assert.match(validateReportConfig(reversed, dateDefinition, limits)['comparison.baseline.period.date_to'], /يسبق/)
  const academicBaseline = { ...initialReportConfig(definition), mode: 'comparison', comparison: { type: 'custom', baseline: { filters: {}, period: { type: 'academic', academic_year_ids: [], semester_ids: [2] } } } }
  const academicErrors = validateReportConfig(academicBaseline, definition, limits)
  assert.match(academicErrors['comparison.baseline.period.academic_year_ids'], /سنة/)
  assert.match(academicErrors['comparison.baseline.period.semester_ids'], /سنة/)
})

test('academic year and semester live exclusively in period payload', () => {
  const config = { ...initialReportConfig(definition), period: { type: 'academic', academic_year_ids: [4], semester_ids: [2] }, filters: { college_ids: [7] } }
  const payload = buildReportPayload(config)
  assert.deepEqual(payload.period, { type: 'academic', academic_year_ids: [4], semester_ids: [2] })
  assert.deepEqual(payload.filters, { college_ids: [7] })
  assert.equal(Object.hasOwn(payload.filters, 'academic_year_ids'), false)
  assert.equal(Object.hasOwn(payload.filters, 'semester_ids'), false)
})

test('selected scope comparison is restricted to supported non-period filter dimensions', () => {
  for (const [dimension, filter] of Object.entries({ college: 'college_ids', department: 'department_ids', program: 'program_ids', academic_level: 'academic_level_ids', course: 'course_ids' })) {
    assert.equal(selectedScopeEligibility({ dimensions: [dimension], filters: { [filter]: [1, 2] } }).allowed, true)
  }
  for (const dimension of ['academic_year', 'semester', 'student_status', 'faculty_member']) {
    assert.equal(selectedScopeEligibility({ dimensions: [dimension], filters: {}, period: { academic_year_ids: [1, 2] } }).allowed, false)
  }
  assert.equal(selectedScopeEligibility({ dimensions: ['college'], filters: { college_ids: [1, 1] } }).allowed, false)
})

test('comparison capabilities obey their exact prerequisites', () => {
  let config = { ...initialReportConfig(definition), mode: 'comparison', dimensions: ['college'], filters: { college_ids: [1, 2] }, period: { type: 'none' } }
  assert.deepEqual(availableComparisons(config, definition, contract.comparisons).sort(), ['custom', 'selected_scopes'])
  config = { ...config, dimensions: ['academic_year'], filters: {}, period: { type: 'academic', academic_year_ids: [3], semester_ids: [2] } }
  assert.deepEqual(availableComparisons(config, definition, contract.comparisons).sort(), ['custom', 'previous_academic_year', 'previous_semester'])
  const payload = buildReportPayload({ ...config, comparison: { type: 'previous_semester' } })
  assert.equal(Object.hasOwn(payload.comparison, 'dimension'), false)
})

test('known selection limits are validated without predicting group count', () => {
  const config = { ...initialReportConfig(definition), filters: { college_ids: Array.from({ length: 51 }, (_, index) => index + 1) } }
  assert.match(validateReportConfig(config, definition, limits)['filters.college_ids'], /50/)
  const pointLimit = classifyReportError({ status: 422, message: 'The grouped report exceeds the 500-point safety limit.', details: { dimensions: ['too many'] } })
  assert.equal(pointLimit.kind, 'point_limit')
  assert.match(pointLimit.message, /ضيّق النطاق|قلّل/)
  const many = Array.from({ length: 50 }, (_, index) => index + 1)
  const combined = { ...initialReportConfig(definition), filters: { college_ids: many, program_ids: many }, period: { type: 'academic', academic_year_ids: many, semester_ids: many }, comparison: { type: 'custom', baseline: { filters: { college_ids: [1] }, period: { type: 'none' } } } }
  assert.match(validateReportConfig(combined, definition, limits).filters, /200/)
  const baselineTooLarge = { ...initialReportConfig(definition), mode: 'comparison', comparison: { type: 'custom', baseline: { filters: { college_ids: Array.from({ length: 51 }, (_, index) => index + 1) }, period: { type: 'none' } } } }
  assert.match(validateReportConfig(baselineTooLarge, definition, limits)['comparison.baseline.filters.college_ids'], /50/)
})

test('custom baseline stays explicit and uses period-only academic context', () => {
  const config = {
    ...initialReportConfig(definition), mode: 'comparison', metrics: ['student_count'], dimensions: ['college'],
    comparison: { type: 'custom', baseline: { filters: { college_ids: [9] }, period: { type: 'academic', academic_year_ids: [2], semester_ids: [1] } } },
  }
  const payload = buildReportPayload(config)
  assert.deepEqual(payload.comparison.baseline.filters, { college_ids: [9] })
  assert.deepEqual(payload.comparison.baseline.period.academic_year_ids, [2])
  assert.equal(Object.hasOwn(payload.comparison.baseline.filters, 'semester_ids'), false)
})

test('academic primary and comparison periods resolve authorized reporting labels', () => {
  const labels = { academic_year: { 4: '2025/2026', 3: '2024/2025' }, semester: { 2: 'الفصل الثاني', 1: 'الفصل الأول' } }
  assert.equal(reportPeriodText({ type: 'academic', academic_year_ids: [4], semester_ids: [2] }, labels), '2025/2026 — الفصل الثاني')
  assert.equal(reportPeriodText({ type: 'academic', academic_year_ids: [3], semester_ids: [1] }, labels), '2024/2025 — الفصل الأول')
  assert.equal(reportPeriodText({ type: 'academic', academic_year_ids: [9], semester_ids: [8] }, labels), 'السنة 9 — الفصل 8')
})

test('parent changes clear incompatible descendants without changing unrelated scope', () => {
  const current = { ...initialReportConfig(definition), filters: { college_ids: [2], department_ids: [3], program_ids: [4], student_status_codes: ['active'] }, labels: { department_ids: { 3: 'قسم' }, program_ids: { 4: 'برنامج' } } }
  const next = clearDescendantSelections(current, 'college_ids')
  assert.equal(next.filters.department_ids, undefined)
  assert.equal(next.filters.program_ids, undefined)
  assert.deepEqual(next.filters.student_status_codes, ['active'])
})

test('presets populate the editable capability model without inventing IDs', () => {
  const preset = REPORT_PRESETS.find(item => item.code === 'compare_colleges')
  const configured = applyPreset(definition, preset)
  assert.equal(configured.mode, 'comparison')
  assert.deepEqual(configured.dimensions, ['college'])
  assert.deepEqual(configured.filters, {})
})

test('home navigation transfers configuration in memory and revalidates it against definitions', () => {
  const seeded = applyNavigationSeed(definition, { subject: 'students', mode: 'summary', metrics: ['student_count', 'unknown'], dimensions: ['program', 'unknown'], filters: { college_ids: [7], unsupported_ids: [4] } })
  assert.deepEqual(seeded.metrics, ['student_count'])
  assert.deepEqual(seeded.dimensions, ['program'])
  assert.deepEqual(seeded.filters, { college_ids: [7] })
})

test('empty custom baselines and invalid time dimensions fail before submission', () => {
  let config = { ...initialReportConfig(definition), mode: 'comparison', comparison: { type: 'custom', baseline: { filters: {}, period: { type: 'none' } } } }
  assert.match(validateReportConfig(config, definition, limits).comparison, /خط الأساس/)
  config = { ...config, mode: 'summary', comparison: null, dimensions: ['day'], period: { type: 'none' } }
  assert.match(validateReportConfig(config, definition, limits).dimensions, /نطاقًا زمنيًا/)
})

test('metric formatting preserves zero, null, rates, and fractional GPA', () => {
  assert.equal(formatMetric({ unit: 'students' }, 0).value, 0)
  assert.equal(formatMetric({ unit: 'gpa', code: 'official_gpa' }, { value: 3.5, contributing_students: 1 }).value, 3.5)
  assert.equal(formatMetric({ unit: 'percent' }, { numerator: 0, denominator: 0, value: null, reason: 'zero_denominator' }).value, null)
  assert.equal(metricValue({}, null), null)
})

test('comparison alignment and row identities do not depend on array position', () => {
  const pairs = alignComparison([{ college: 2, student_count: 9 }, { college: 1, student_count: 3 }], [{ college: 1, student_count: 2 }], ['college'])
  assert.equal(pairs[0].primary.college, 1)
  assert.equal(pairs[0].baseline.student_count, 2)
  assert.equal(pairs[1].baseline, null)
  assert.notEqual(reportRowKey('faculty', { faculty_member_id: 1, college_id: 2 }), reportRowKey('faculty', { faculty_member_id: 1, college_id: 3 }))
  assert.notEqual(reportRowKey('grade_workflow', { course_offering_id: 1, component_type: 'theoretical' }), reportRowKey('grade_workflow', { course_offering_id: 1, component_type: 'practical' }))
  assert.notEqual(reportRowKey('academic_performance', { student_id: 7, student_course_result_id: 101 }), reportRowKey('academic_performance', { student_id: 7, student_course_result_id: 102 }))
  assert.equal(reportRowKey('students', { student_id: 7, student_course_result_id: 101 }), '7')
  assert.equal(reportRowKey('course_offerings', { course_offering_id: 55 }), '55')
})

test('latest response helper rejects obsolete responses', () => {
  assert.equal(isLatestResponse(3, 3), true)
  assert.equal(isLatestResponse(2, 3), false)
})

test('rapid asynchronous responses commit only the latest request', async () => {
  let current = 0; const committed = []
  const request = (sequence, delay) => new Promise(resolve => setTimeout(() => resolve(sequence), delay)).then(sequence => { if (isLatestResponse(sequence, current)) committed.push(sequence) })
  current = 1; const slow = request(1, 15)
  current = 2; const fast = request(2, 1)
  await Promise.all([slow, fast])
  assert.deepEqual(committed, [2])
})

test('authentication, authorization, validation, and network errors remain distinct', () => {
  assert.equal(classifyReportError({ status: 401 }).kind, 'authentication')
  assert.equal(classifyReportError({ status: 403 }).kind, 'authorization')
  assert.equal(classifyReportError({ status: 422, details: { metrics: ['invalid'] } }).kind, 'validation')
  assert.equal(classifyReportError({ status: 422, details: { dimensions: ['unsupported'] } }).kind, 'validation')
  assert.equal(classifyReportError(new Error('offline')).kind, 'network')
  assert.equal(classifyReportError({ name: 'AbortError' }).kind, 'aborted')
  const purged = purgeReportLifecycle({ subject: 'students', labels: { college_ids: { 1: 'كلية' } }, comparison: { type: 'custom', baseline: { labels: { program_ids: { 2: 'برنامج' } } } } }, classifyReportError({ status: 403 }))
  assert.equal(purged.report, null)
  assert.equal(purged.applied, null)
  assert.deepEqual(purged.dimensionLabels, {})
  assert.deepEqual(purged.draft.labels, {})
  assert.deepEqual(purged.draft.comparison.baseline.labels, {})
  assert.equal(purged.loading, false)
})

test('reporting access requires the paired actual role, assigned permission, and university scope', () => {
  const scientific = { roles: ['vice_president_scientific'], permissions: ['vice_presidency.scientific.access'], access_scopes: [{ type: 'university' }] }
  assert.equal(canAccessExecutiveReports('scientific', scientific), true)
  assert.equal(canAccessExecutiveReports('administrative', scientific), false)
  assert.equal(canAccessExecutiveReports('scientific', { ...scientific, roles: ['super_admin'] }), false)
  assert.equal(canAccessExecutiveReports('scientific', { ...scientific, permissions: [] }), false)
  assert.equal(canAccessExecutiveReports('scientific', { ...scientific, access_scopes: [] }), false)
  assert.equal(canAccessExecutiveReports('unexpected', scientific), false)
  assert.deepEqual(reportAccessForOffice('administrative').allRoles, ['vice_president_administrative'])
  assert.equal(reportPathForOffice('scientific'), '/vp/scientific/reports')
})

test('routes, navigation, APIs, and dashboard query architecture are integrated without backend changes', () => {
  const app = read('src/app/App.jsx')
  const nav = read('src/features/vice-presidency/nav.js')
  const api = read('src/features/executive-reports/api.js')
  const page = read('src/features/executive-reports/pages/ExecutiveReportsPage.jsx')
  const overview = read('src/features/executive-reports/components/ExecutiveOverview.jsx')
  const picker = read('src/features/executive-reports/components/ReportOptionPicker.jsx')
  const results = read('src/features/executive-reports/components/ReportResults.jsx')
  for (const route of ['/vp/scientific/reports', '/vp/administrative/reports']) assert.ok(app.includes(route))
  assert.equal((nav.match(/actualUniversityScope: true/g) ?? []).length >= 2, true)
  for (const endpoint of ['/v1/vice-presidency/reports/definitions', '/v1/vice-presidency/reports/filters', '/v1/vice-presidency/reports/query', '/v1/vice-presidency/analytics/overview']) assert.ok(api.includes(endpoint))
  assert.equal(api.includes('/api/api'), false)
  assert.ok(page.includes('activeRequest.current?.abort()'))
  assert.ok(page.includes('draftAfterSuccessfulRequest'))
  assert.ok(page.includes('activeRequest.current = null'))
  assert.ok(page.includes('setLoading(state.loading)'))
  assert.ok(page.includes('setReport(null); setApplied(null)'))
  assert.ok(picker.includes('onAuthorizationFailure?.(requestError)'))
  assert.ok(results.includes('sortableFieldsForApplied(applied)'))
  assert.ok(page.includes("'academic_year', ...(period.semester_ids?.length ? ['semester'] : [])"))
  assert.equal(/localStorage|sessionStorage/.test(page), false)
  assert.equal((overview.match(/executeExecutiveReport\(/g) ?? []).length, 2)
  assert.ok(overview.includes("students:") && overview.includes("faculty:") && overview.includes("performance:"))
  assert.equal(overview.includes('forEach(async college'), false)
})

test('enrollment semester suppression and in-memory scoped navigation are explicit', () => {
  const page = read('src/features/executive-reports/pages/ExecutiveReportsPage.jsx')
  const overview = read('src/features/executive-reports/components/ExecutiveOverview.jsx')
  assert.ok(page.includes("definition.subject !== 'enrollments'"))
  assert.ok(page.includes('location.state?.executiveReportPreset'))
  assert.ok(overview.includes('state={{ executiveReportPreset:'))
  assert.equal(/searchParams|URLSearchParams/.test(page), false)
})

test('charts preserve missing values as gaps and provide table alternatives', () => {
  const charts = read('src/features/executive-reports/components/ReportCharts.jsx')
  assert.ok(charts.includes("value === null"))
  assert.ok(charts.includes('segments.push(current)'))
  assert.ok(charts.includes('عرض البيانات كجدول'))
  assert.equal(charts.includes('Math.max(6'), false)
  assert.equal(charts.includes('|| 0'), false)
})
