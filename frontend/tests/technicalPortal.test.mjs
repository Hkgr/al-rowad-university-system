import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { ACCESS, canAccess, landingRoute } from '../src/features/auth/auth.js'
import {
  accountErrorMessage, accountsViewState, assignableRolesFor, buildAccountsQuery, groupPermissionsByRole,
} from '../src/features/technical-portal/lib/accountsState.js'

const source = path => readFile(new URL(`../src/${path}`, import.meta.url), 'utf8')
const technical = { roles: ['technical_team'], permissions: ['technical_portal.access', 'user_accounts.view', 'user_accounts.manage'] }

test('technical team lands on its portal without changing existing portal precedence', () => {
  assert.equal(landingRoute(technical), '/technical')
  assert.equal(landingRoute({ ...technical, roles: ['dean', 'technical_team'] }), '/dean')
  assert.equal(landingRoute({ ...technical, roles: ['vice_president_scientific', 'technical_team'] }), '/vp/scientific')
  assert.equal(landingRoute({ ...technical, roles: ['vice_president_administrative', 'technical_team'] }), '/vp/administrative')
  assert.equal(landingRoute({ ...technical, roles: ['exam_officer', 'technical_team'] }), '/exam-board')
  assert.equal(landingRoute({ ...technical, roles: ['registration_officer', 'technical_team'] }), '/student-affairs')
  // super_admin passes every permission check but keeps its previous landing.
  assert.equal(landingRoute({ roles: ['super_admin'], permissions: [] }), '/exam-board')
  // Permissions without the role, or the role without the portal permission, do not redirect.
  assert.equal(landingRoute({ roles: [], permissions: technical.permissions }), '/forbidden')
  assert.equal(landingRoute({ roles: ['technical_team'], permissions: [] }), '/forbidden')
  assert.equal(landingRoute({ roles: [], permissions: ['hr.view'] }), '/hr')
})

test('portal and accounts access derive from role permissions only', () => {
  assert.equal(canAccess(ACCESS.technicalPortal, technical), true)
  assert.equal(canAccess(ACCESS.technicalAccounts, technical), true)
  assert.equal(canAccess(ACCESS.technicalAccountsManage, technical), true)
  const viewOnly = { roles: ['technical_team'], permissions: ['technical_portal.access', 'user_accounts.view'] }
  assert.equal(canAccess(ACCESS.technicalAccounts, viewOnly), true)
  assert.equal(canAccess(ACCESS.technicalAccountsManage, viewOnly), false)
  // Organizational placement alone (Technical Office unit) grants nothing.
  const placedOnly = { roles: [], permissions: [], organizational_unit: { code: '715', name: 'المكتب التقني' } }
  assert.equal(canAccess(ACCESS.technicalPortal, placedOnly), false)
  assert.equal(canAccess(ACCESS.technicalAccounts, { roles: [], permissions: ['user_accounts.view'] }), false)
  assert.equal(canAccess(ACCESS.technicalAccounts, { roles: ['super_admin'], permissions: [] }), true)
})

test('sidebar has the accounts and activity items, each behind its own access rule', async () => {
  const nav = await source('features/technical-portal/nav.js')
  assert.equal((nav.match(/\bto: '/g) ?? []).length, 2)
  assert.match(nav, /to: '\/technical\/accounts', Icon: FaUserShield, ar: 'الحسابات والصلاحيات'.*\.\.\.ACCESS\.technicalAccounts/)
  assert.match(nav, /to: '\/technical\/activity', Icon: FaHistory, ar: 'سجل النشاط'.*\.\.\.ACCESS\.technicalActivity/)
  const layout = await source('components/layout/DashboardLayout.jsx')
  assert.match(layout, /items: section\.items\.filter\(item => canAccess\(item\)\)/)
})

test('App routes the portal home and accounts page behind their guards', async () => {
  const app = await source('app/App.jsx')
  assert.match(app, /<ProtectedRoute \{\.\.\.ACCESS\.technicalPortal\}>\s*<DashboardLayout nav=\{technicalNav\} appTitle="المكتب التقني" \/>/)
  assert.match(app, /<Route path="\/technical" element=\{<TechnicalHome \/>\} \/>/)
  assert.match(app, /<Route path="\/technical\/accounts" element=\{protect\(<AccountsPermissionsPage \/>, ACCESS\.technicalAccounts\)\} \/>/)
})

test('page view state covers loading, empty, filtered-empty, forbidden and error', () => {
  assert.equal(accountsViewState({ loading: true, rows: [] }), 'loading')
  assert.equal(accountsViewState({ loading: false, error: { status: 403 }, rows: [] }), 'forbidden')
  assert.equal(accountsViewState({ loading: false, error: { status: 0 }, rows: [] }), 'error')
  assert.equal(accountsViewState({ loading: false, rows: [], hasFilters: false }), 'empty')
  assert.equal(accountsViewState({ loading: false, rows: [], hasFilters: true }), 'emptyFiltered')
  assert.equal(accountsViewState({ loading: false, rows: [{ user_id: 1 }] }), 'ready')
})

test('API errors map to readable Arabic messages for 403, 409 and 422', () => {
  assert.equal(accountErrorMessage({ status: 403, errorCode: 'role_not_assignable' }).message, 'هذا الدور ليس ضمن الأدوار المسموح للفريق التقني بإسنادها أو سحبها.')
  assert.equal(accountErrorMessage({ status: 403, errorCode: 'last_super_admin' }).message, 'لا يمكن تعطيل آخر مدير نظام نشط أو سحب دوره.')
  assert.equal(accountErrorMessage({ status: 403, errorCode: 'forbidden' }).message, 'ليست لديك صلاحية لتنفيذ هذا الإجراء.')
  assert.equal(accountErrorMessage({ status: 409, errorCode: 'role_already_assigned' }).message, 'هذا الدور مُسند للحساب مسبقًا.')
  assert.match(accountErrorMessage({ status: 409, errorCode: 'unknown' }).message, /تعارض/)
  const invalid = accountErrorMessage({ status: 422, details: { username: ['اسم المستخدم مستخدم مسبقًا.'], password: ['قصيرة'] } })
  assert.deepEqual(invalid.fieldErrors, { username: 'اسم المستخدم مستخدم مسبقًا.', password: 'قصيرة' })
  assert.equal(accountErrorMessage(new Error('network')).message, 'تعذّر الاتصال بالخادم')
})

test('permissions are presented as derived from roles', () => {
  const groups = groupPermissionsByRole([
    { permission_code: 'exams.view', permission_name: 'Exams', granted_by_roles: [{ role_code: 'exam_officer', role_name: 'Exam Officer' }] },
    { permission_code: 'grades.view', permission_name: 'Grades', granted_by_roles: [{ role_code: 'exam_officer', role_name: 'Exam Officer' }, { role_code: 'dean', role_name: 'Dean' }] },
  ])
  assert.deepEqual(groups.map(g => [g.role_code, g.permissions.map(p => p.permission_code)]), [['exam_officer', ['exams.view', 'grades.view']], ['dean', ['grades.view']]])
  const roles = [{ role_id: 1, assignable: false }, { role_id: 3, assignable: true }, { role_id: 4, assignable: true }]
  assert.deepEqual(assignableRolesFor({ roles: [{ role_id: 3 }] }, roles).map(r => r.role_id), [4])
  assert.equal(buildAccountsQuery({ search: ' ali ', status: 'active', roleId: 4, page: 2 }), 'page=2&per_page=15&search=ali&status=active&role_id=4')
})

test('page never sends password_hash or local permission selections and refreshes the identity', async () => {
  const api = await source('features/technical-portal/lib/accountsApi.js')
  const page = await source('features/technical-portal/pages/AccountsPermissionsPage.jsx')
  const modal = await source('features/technical-portal/components/CreateAccountModal.jsx')
  const detail = await source('features/technical-portal/components/AccountDetailPanel.jsx')
  for (const file of [api, page, modal, detail]) {
    assert.doesNotMatch(file, /password_hash/)
    assert.doesNotMatch(file, /permission_ids|assigned_by_user_id/)
  }
  assert.match(api, /method: 'POST',\s*body: JSON\.stringify\(\{\s*username,\s*email,\s*password,/)
  assert.equal((modal.match(/type="password".*autoComplete="new-password"/g) ?? []).length, 2)
  assert.match(page, /fetchCurrentIdentity\(\)/)
  assert.match(page, /storeIdentity\(json\.data\)/)
  assert.match(page, /landingRoute\(json\.data\)/)
  assert.match(detail, /الصلاحيات تُكتسب من الأدوار المسندة فقط/)
})
