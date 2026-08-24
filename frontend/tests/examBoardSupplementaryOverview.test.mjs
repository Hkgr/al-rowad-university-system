import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
  canOpenSupplementaryGrades,
  OVERVIEW_STAGE_LABELS,
  overviewEmptyMessage,
  overviewQuery,
  responseMatchesPeriod,
} from '../src/features/exam-board/lib/supplementaryOverview.js'
import { periodOperationalMessage } from '../src/features/supplementary-exams/supplementaryStatus.js'

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')

test('overview query is bounded and carries explicit server filters', () => {
  const query = overviewQuery({ periodId: 8, offeringId: 3, search: '  أحمد  ', page: 2 })
  assert.match(query, /^\/v1\/exams\/supplementary-overview\?/)
  for (const expected of ['period_id=8', 'offering_id=3', 'search=%D8%A3%D8%AD%D9%85%D8%AF', 'page=2', 'per_page=20']) {
    assert.ok(query.includes(expected), expected)
  }
})

test('all canonical stages and lifecycle messages are explicit', () => {
  assert.deepEqual(Object.keys(OVERVIEW_STAGE_LABELS), [
    'announcement', 'registration', 'roster_fixed', 'grading', 'review', 'publication', 'materialization',
  ])
  for (const status of ['announced', 'registration_open', 'registration_closed', 'grading_open', 'grading_submitted', 'results_approved', 'results_published', 'results_materialized']) {
    assert.doesNotMatch(periodOperationalMessage(status), /غير معروفة/)
  }
  assert.match(periodOperationalMessage('future_status'), /غير معروفة/)
})

test('empty states distinguish missing periods, offerings, and roster lifecycle', () => {
  assert.match(overviewEmptyMessage({ periods: [] }), /لا توجد دورات/)
  assert.match(overviewEmptyMessage({ periods: [{}], offerings: [] }), /لا توجد عروض/)
  assert.match(overviewEmptyMessage({ periods: [{}], offerings: [{}], selected_period: { status: 'announced' } }), /لم يبدأ التسجيل/)
  assert.match(overviewEmptyMessage({ periods: [{}], offerings: [{}], selected_period: { status: 'grading_open' } }), /التصحيح/)
})

test('grades CTA requires backend capability, actual role, and directly listed permission', () => {
  const payload = { capabilities: { can_access_grades: true } }
  const allowed = { roles: ['exam_officer'], permissions: ['supplementary_exams.grades.review'] }
  assert.equal(canOpenSupplementaryGrades(payload, allowed), true)
  assert.equal(canOpenSupplementaryGrades(payload, { ...allowed, roles: ['super_admin'] }), false)
  assert.equal(canOpenSupplementaryGrades(payload, { ...allowed, permissions: [] }), false)
  assert.equal(canOpenSupplementaryGrades({ capabilities: { can_access_grades: false } }, allowed), false)
})

test('stale period responses are rejected', () => {
  assert.equal(responseMatchesPeriod({ selected_period: { supplementary_exam_period_id: 8 } }, '8'), true)
  assert.equal(responseMatchesPeriod({ selected_period: { supplementary_exam_period_id: 7 } }, '8'), false)
})

test('page is read-only, debounced, and protects the last trusted snapshot', () => {
  const page = read('src/features/exam-board/pages/SupplementaryExamsPage.jsx')
  for (const required of ['apiRequest(overviewQuery(', 'requestSequenceRef', 'successfulPeriodRef', '350', 'setPeriodId(successfulPeriodRef.current)', 'overviewEmptyMessage(payload)']) {
    assert.ok(page.includes(required), required)
  }
  assert.equal(page.includes('fetch('), false)
  assert.equal(/method:\s*['"](?:POST|PUT|PATCH|DELETE)/.test(page), false)
})

test('route, navigation, and home card use registration-view permission', () => {
  const app = read('src/app/App.jsx')
  const nav = read('src/features/exam-board/nav.js')
  const home = read('src/features/exam-board/pages/ExamBoardHome.jsx')
  const auth = read('src/features/auth/auth.js')
  assert.ok(app.includes('protect(<SupplementaryExamsPage />, { permissions: [PERMISSIONS.supplementaryExamsRegistrationsView] })'))
  assert.ok(nav.includes('permissions: [PERMISSIONS.supplementaryExamsRegistrationsView]'))
  assert.ok(home.includes('permission: PERMISSIONS.supplementaryExamsRegistrationsView'))
  assert.ok(auth.includes("supplementaryExamsRegistrationsView: 'supplementary_exams.registrations.view'"))
})
