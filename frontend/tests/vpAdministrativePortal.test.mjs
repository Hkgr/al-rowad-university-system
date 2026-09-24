import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { canAccess } from '../src/features/auth/auth.js'
import { ADMINISTRATIVE_ACCESS } from '../src/features/vice-presidency/utils/administrativeAccess.js'
import {
  appointPayload, collegeSeries, currentDeanId, dashboardFilters, facultyQuery, formatCount, governanceError, newTeacherPayload, queueLink, unavailableText,
} from '../src/features/vice-presidency/utils/administrativeGovernance.js'
import {
  APPROVAL_EFFECT_TEXT, BLOCKED_REASON_TEXT, decisionErrorText, eventLabel, queueApiQuery, queueParamsFromSearch, shouldReloadAfterError,
} from '../src/features/vice-presidency/utils/teachingAssignmentLabels.js'

const source = path => readFile(new URL(`../src/${path}`, import.meta.url), 'utf8')
const university = [{ type: 'university', id: 1 }]
const perms = {
  access: 'vice_presidency.administrative.access',
  facultyView: 'vice_presidency.administrative.faculty.view',
  facultyManage: 'vice_presidency.administrative.faculty.manage',
  deansView: 'vice_presidency.administrative.deans.view',
  deansManage: 'vice_presidency.administrative.deans.manage',
}
const USERS = {
  none: { roles: ['doctor_instructor'], permissions: [], access_scopes: [] },
  viewOnlyVp: { roles: ['vice_president_administrative'], permissions: [perms.access, perms.facultyView, perms.deansView], access_scopes: university },
  managerVp: { roles: ['vice_president_administrative'], permissions: Object.values(perms), access_scopes: university },
  vpWithoutScope: { roles: ['vice_president_administrative'], permissions: Object.values(perms), access_scopes: [] },
  permissionsWithoutRole: { roles: ['exam_officer'], permissions: Object.values(perms), access_scopes: university },
  dean: { roles: ['dean'], permissions: ['teaching_assignments.manage'], access_scopes: [{ type: 'college', id: 10 }] },
  multiRole: { roles: ['vice_president_administrative', 'exam_officer'], permissions: Object.values(perms), access_scopes: university },
  superAdmin: { roles: ['super_admin'], permissions: [], access_scopes: university },
  superAdminWithoutScope: { roles: ['super_admin'], permissions: [], access_scopes: [] },
}

test('access matrix mirrors the server rule: VP role + assigned permission + university scope, or super_admin with scope', () => {
  const expected = {
    none: [false, false, false, false, false],
    viewOnlyVp: [true, false, true, false, true],
    managerVp: [true, true, true, true, true],
    vpWithoutScope: [false, false, false, false, false],
    permissionsWithoutRole: [false, false, false, false, false],
    dean: [false, false, false, false, false],
    multiRole: [true, true, true, true, true],
    superAdmin: [true, true, true, true, true],
    superAdminWithoutScope: [false, false, false, false, false],
  }
  for (const [name, user] of Object.entries(USERS)) {
    const actual = ['facultyView', 'facultyManage', 'deansView', 'deansManage', 'dashboard'].map(key => canAccess(ADMINISTRATIVE_ACCESS[key], user))
    assert.deepEqual(actual, expected[name], name)
  }
})

test('queue URL state keeps only valid filters and maps to the API query', () => {
  const state = queueParamsFromSearch(new URLSearchParams('queue=returned&college_id=3&academic_year_id=2&semester_id=1&search=%20ACC%20&page=2&instructor_role=x'))
  assert.equal(state.queue, 'returned')
  assert.equal(state.instructor_role, '')
  assert.equal(queueApiQuery('administrative', state), 'authority=administrative&queue=returned&per_page=20&page=2&search=ACC&college_id=3&academic_year_id=2&semester_id=1')
  const orphanSemester = queueParamsFromSearch(new URLSearchParams('semester_id=1&queue=bogus&college_id=abc'))
  assert.equal(orphanSemester.semester_id, '')
  assert.equal(orphanSemester.queue, 'pending')
  assert.equal(orphanSemester.college_id, '')
})

test('dashboard indicators link to the queue with the same filters and never show a fake zero', () => {
  assert.equal(queueLink('pending', { academic_year_id: '2', semester_id: '1' }), '/vp/administrative/teaching-assignments?academic_year_id=2&semester_id=1')
  assert.equal(queueLink('returned', { college_id: '4' }), '/vp/administrative/teaching-assignments?queue=returned&college_id=4')
  assert.equal(queueLink('approved', { college_id: '4' }, 7), '/vp/administrative/teaching-assignments?queue=approved&college_id=7')
  assert.equal(formatCount(null), 'غير متاح')
  assert.equal(formatCount(undefined), 'غير متاح')
  assert.equal(formatCount(0), '٠')
  assert.match(unavailableText('review_permission_missing'), /صلاحية المراجعة الإدارية/)
  assert.deepEqual(dashboardFilters({ semester_id: '1', college_id: 'x' }), {})
  assert.deepEqual(collegeSeries([{ college_id: 1, total: 2 }, { college_id: 2, total: 5 }, { college_id: null, total: 1 }]).map(row => row.college), ['2', '1', ''])
})

test('decision context and errors explain 403/409 and require a reload', () => {
  for (const reason of ['not_reviewer', 'superseded', 'already_effective', 'review_locked', 'same_reviewer']) assert.ok(BLOCKED_REASON_TEXT[reason], reason)
  for (const effect of ['effective_now', 'awaits_other_office', 'already_effective', 'not_applicable']) assert.ok(APPROVAL_EFFECT_TEXT[effect], effect)
  const mismatch = { status: 409, errorCode: 'teaching_assignment_version_mismatch', message: 'x' }
  assert.match(decisionErrorText(mismatch), /نسخة أحدث/)
  assert.equal(shouldReloadAfterError(mismatch), true)
  assert.equal(shouldReloadAfterError({ status: 403 }), true)
  assert.equal(shouldReloadAfterError({ status: 422 }), false)
  assert.equal(eventLabel('removal_stale'), 'إلغاء طلب إنهاء لم يعد مطابقًا')
  const stale = governanceError({ status: 409, errorCode: 'dean_state_stale', message: 'server' })
  assert.equal(stale.reload, true)
  const invalid = governanceError({ status: 422, errorCode: undefined, details: { 'account.password': ['ضعيفة'] } })
  assert.equal(invalid.reload, false)
  assert.equal(invalid.fieldErrors['account.password'], 'ضعيفة')
})

test('payload builders send only allowed fields and the expected current dean', () => {
  const link = newTeacherPayload({ mode: 'link', employee_id: '20', employee_number: ' E-20 ', last_name: 'X', first_name: 'ignored', email: 'ignored@x', college_id: '3', start_date: '2026-01-01' })
  assert.deepEqual(link, { mode: 'link', employee_number: 'E-20', last_name: 'X', employee_id: 20, college_id: 3, start_date: '2026-01-01' })
  const college = { college_id: 2, deans: [{ user_id: 9 }] }
  assert.equal(currentDeanId(college), 9)
  assert.equal(currentDeanId({ deans: [] }), null)
  const existing = appointPayload({ employeeMode: 'existing', employee_id: '8', employee_number: 'E-8', last_name: 'C', accountMode: 'existing', user_id: '8', password: 'Secret-123456', replace_current: true }, college)
  assert.deepEqual(existing, { college_id: 2, expected_current_dean_user_id: 9, employee: { mode: 'existing', employee_id: 8, employee_number: 'E-8', last_name: 'C' }, account: { mode: 'existing', user_id: 8 }, replace_current: true })
  for (const key of ['role_ids', 'scopes', 'scope_type', 'password_hash']) assert.equal(JSON.stringify(existing).includes(key), false, key)
  assert.deepEqual(facultyQuery({ college_id: 'none', is_active: '1', search: ' a ' }), { per_page: 15, page: 1, college_id: 0, is_active: '1', search: 'a' })
})

test('pages are guarded, capability-driven and keep the workflow as the only way to change assignments', async () => {
  const app = await source('app/App.jsx')
  assert.match(app, /path="\/vp\/administrative\/faculty" element=\{protect\(<AdministrativeFacultyPage \/>, ADMINISTRATIVE_ACCESS\.facultyView\)\}/)
  assert.match(app, /path="\/vp\/administrative\/deans" element=\{protect\(<AdministrativeDeansPage \/>, ADMINISTRATIVE_ACCESS\.deansView\)\}/)
  const nav = await source('features/vice-presidency/nav.js')
  assert.equal((nav.match(/ar: 'إدارة المدرسين'/g) ?? []).length, 1)
  assert.equal((nav.match(/ar: 'عمداء الكليات'/g) ?? []).length, 1)
  assert.equal((nav.match(/ar: 'الرئيسية'/g) ?? []).length, 2, 'no duplicate home item')

  const detail = await source('features/vice-presidency/pages/TeachingAssignmentDetail.jsx')
  assert.match(detail, /expected_submission_version: version/)
  assert.match(detail, /await load\(\)/)
  assert.match(detail, /context\?\.can_approve/)
  assert.doesNotMatch(detail, /hasPermission\(/, 'buttons follow the server viewer_context, not a local permission guess')
  const faculty = await source('features/vice-presidency/pages/AdministrativeFacultyPage.jsx')
  const deans = await source('features/vice-presidency/pages/AdministrativeDeansPage.jsx')
  for (const text of [faculty, deans]) {
    assert.doesNotMatch(text, /course-offering-instructors|teaching-assignments\/.*approve|\/slots/, 'no direct assignment writes')
    assert.doesNotMatch(text, /localStorage/)
  }
  assert.match(faculty, /capabilities\.faculty_manage && canUseAdministrative\('facultyManage'\)/)
  assert.match(deans, /capabilities\.deans_manage && canUseAdministrative\('deansManage'\)/)
  assert.match(deans, /type="password"/)
  assert.doesNotMatch(deans, /console\.log/)

  const dashboard = await source('features/vice-presidency/components/AdministrativeDashboard.jsx')
  assert.match(dashboard, /إجمالي الجامعة/)
  assert.match(dashboard, /التوزيع حسب الكلية/)
  assert.doesNotMatch(dashboard, /university_total \?\? 0|totals\?\.[a-z]+ \?\? 0/)
  const shell = await source('features/vice-presidency/pages/VicePresidentShell.jsx')
  assert.match(shell, /office === 'administrative'\s*\n?\s*\? canUseAdministrative\('dashboard', identity\) && <AdministrativeDashboard \/>/)

  const modal = await source('features/dean-dashboard/components/TeacherAssignmentManagerModal.jsx')
  assert.match(modal, /course_offering_id=\$\{encodeURIComponent\(contextOfferingId\)\}/)
  assert.match(modal, /<optgroup/)
})
