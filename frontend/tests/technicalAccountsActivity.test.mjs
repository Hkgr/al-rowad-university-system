import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { ACCESS, canAccess } from '../src/features/auth/auth.js'
import { accountErrorMessage } from '../src/features/technical-portal/lib/accountsState.js'
import { EMPTY_FILTERS, actionsForModule, activityErrorMessage, buildActivityQuery, changeText, hasActiveFilters } from '../src/features/technical-portal/lib/activityState.js'

const source = path => readFile(new URL(`../src/${path}`, import.meta.url), 'utf8')
const tech = { roles: ['technical_team'], permissions: ['technical_portal.access', 'user_accounts.view', 'user_accounts.manage', 'user_accounts.holder_name.manage', 'system_activity.view'] }

test('activity log access needs the portal and its own view permission', () => {
  assert.equal(canAccess(ACCESS.technicalActivity, tech), true)
  assert.equal(canAccess(ACCESS.technicalActivity, { ...tech, permissions: ['technical_portal.access', 'user_accounts.view'] }), false)
  assert.equal(canAccess(ACCESS.technicalActivity, { roles: [], permissions: ['system_activity.view'] }), false)
  assert.equal(canAccess(ACCESS.technicalActivity, { roles: ['super_admin'], permissions: [] }), true)
})

test('activity query drops empty values, keeps valid dates and never sends a reversed period', () => {
  assert.equal(buildActivityQuery(EMPTY_FILTERS), 'page=1&per_page=25')
  assert.equal(buildActivityQuery({ search: ' reset ', module: 'users_permissions', action: '', actor: 'tech', from: '2026-01-01', to: '2026-02-01', page: 3 }, 10),
    'page=3&per_page=10&search=reset&module=users_permissions&actor=tech&from=2026-01-01&to=2026-02-01')
  assert.equal(buildActivityQuery({ from: '2026-03-01', to: '2026-01-01' }), 'page=1&per_page=25&from=2026-01-01&to=2026-03-01')
  assert.equal(buildActivityQuery({ from: 'yesterday' }), 'page=1&per_page=25')
  assert.equal(hasActiveFilters(EMPTY_FILTERS), false)
  assert.equal(hasActiveFilters({ ...EMPTY_FILTERS, actor: 'x' }), true)
  const actions = [{ code: 'a', module: 'm1' }, { code: 'b', module: 'm2' }]
  assert.deepEqual(actionsForModule(actions, 'm2').map(a => a.code), ['b'])
  assert.equal(actionsForModule(actions, '').length, 2)
})

test('changes show safe values and never invent hidden ones', () => {
  assert.equal(changeText({ label: 'البريد الإلكتروني', from: 'a@x.test', to: 'b@x.test', values_hidden: false }), 'البريد الإلكتروني: a@x.test ← b@x.test')
  assert.equal(changeText({ label: 'الهاتف', from: null, to: null, values_hidden: true }), 'الهاتف: تغيّر (القيمة غير معروضة)')
  assert.match(activityErrorMessage({ status: 403 }), /صلاحية عرض سجل النشاط/)
  assert.match(activityErrorMessage({ status: 422 }), /قيمة تصفية/)
})

test('account errors are readable Arabic for 403, 409 and 422', () => {
  assert.match(accountErrorMessage({ status: 409, errorCode: 'account_unchanged' }).message, /لم تتغير/)
  assert.match(accountErrorMessage({ status: 409, errorCode: 'holder_not_linked' }).message, /غير مرتبط/)
  assert.match(accountErrorMessage({ status: 403, errorCode: 'protected_account' }).message, /محمي/)
  const invalid = accountErrorMessage({ status: 422, details: { email: ['البريد الإلكتروني مستخدم لحساب آخر.'] } })
  assert.equal(invalid.fieldErrors.email, 'البريد الإلكتروني مستخدم لحساب آخر.')
})

test('password is sent once as password + confirmation and cleared from the form', async () => {
  const api = await source('features/technical-portal/lib/accountsApi.js')
  assert.match(api, /body: JSON\.stringify\(\{ password, password_confirmation: passwordConfirmation \}\)/)
  assert.doesNotMatch(api, /password_hash/)
  const sections = await source('features/technical-portal/components/AccountEditSections.jsx')
  assert.match(sections, /autoComplete="new-password"/)
  assert.match(sections, /setPassword\(''\); setConfirmation\(''\)/)
  assert.match(sections, /كلمة المرور الحالية غير قابلة للعرض أو الاسترجاع/)
  assert.match(sections, /سلّمها لصاحب الحساب بقناة آمنة/)
  assert.doesNotMatch(sections, /localStorage|console\.log/)
})

test('holder name UI explains the difference and the unlinked case; buttons follow server capabilities', async () => {
  const sections = await source('features/technical-portal/components/AccountEditSections.jsx')
  assert.match(sections, /«اسم المستخدم» هو معرّف الحساب/)
  assert.match(sections, /«اسم صاحب الحساب» هو الاسم الحقيقي للشخص/)
  assert.match(sections, /هذا الحساب غير مرتبط بموظف أو طالب/)
  const panel = await source('features/technical-portal/components/AccountDetailPanel.jsx')
  assert.match(panel, /capabilities\.can_edit_login && <LoginIdentitySection/)
  assert.match(panel, /capabilities\.can_reset_password && <PasswordResetSection/)
  const app = await source('app/App.jsx')
  assert.match(app, /<Route path="\/technical\/activity" element=\{protect\(<ActivityLogPage \/>, ACCESS\.technicalActivity\)\} \/>/)
  const page = await source('features/technical-portal/pages/ActivityLogPage.jsx')
  assert.doesNotMatch(page, /method: '(POST|PUT|PATCH|DELETE)'/, 'the activity page never writes')
})

test('activity search copy names only the safe fields the server searches', async () => {
  const state = await import('../src/features/technical-portal/lib/activityState.js')
  assert.match(state.SEARCH_PLACEHOLDER, /اسم مستخدم المنفّذ.*الحساب المتأثر.*اسم الإجراء.*عنوان IP/)
  assert.doesNotMatch(state.SEARCH_PLACEHOLDER, /تفاصيل الحدث|البريد/)
  assert.match(state.SEARCH_HELP, /لا يبحث في البريد الإلكتروني ولا في نص تفاصيل الحدث/)
  const page = await readFile(new URL('../src/features/technical-portal/pages/ActivityLogPage.jsx', import.meta.url), 'utf8')
  assert.match(page, /placeholder: SEARCH_PLACEHOLDER/)
  assert.match(page, /SEARCH_HELP/)
  assert.match(page, /البريد الإلكتروني مقنّعًا/)
})
