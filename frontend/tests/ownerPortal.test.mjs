import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { ACCESS, canAccess, landingRoute } from '../src/features/auth/auth.js'

const source = path => readFile(new URL(`../src/${path}`, import.meta.url), 'utf8')
const OWNER_PERMISSIONS = ['owner_portal.access', 'owner_portal.home.view', 'owner_payroll.view', 'owner_payroll.employees.manage', 'owner_payroll.bodies.manage', 'owner_payroll.amounts.edit', 'owner_payroll.export']
const owner = { roles: ['university_owner'], permissions: OWNER_PERMISSIONS, access_scopes: [] }

test('the owner lands on the owner portal; every other landing is unchanged', () => {
  assert.equal(landingRoute(owner), '/owner')
  assert.equal(landingRoute({ ...owner, permissions: [] }), '/forbidden', 'role without the portal permission')
  assert.equal(landingRoute({ roles: [], permissions: OWNER_PERMISSIONS }), '/forbidden', 'permissions without the role')
  assert.equal(landingRoute({ roles: ['super_admin'], permissions: [] }), '/technical', 'central administrator keeps its landing')
  assert.equal(landingRoute({ roles: ['ministry_observer'], permissions: ['ministry_portal.access', 'ministry_portal.dashboard.view'] }), '/ministry')
  assert.equal(landingRoute({ roles: ['dean'], permissions: [] }), '/dean')
  assert.equal(landingRoute({ roles: ['vice_president_scientific'], permissions: [] }), '/vp/scientific')
  assert.equal(landingRoute({ roles: [], permissions: ['hr.view'] }), '/hr')
})

test('portal pages need the dedicated role AND the assigned permission; the president, VPs, HR and technical team get nothing', () => {
  for (const page of ['ownerPortal', 'ownerHome', 'ownerPayroll']) {
    assert.equal(canAccess(ACCESS[page], owner), true, page)
    assert.equal(canAccess(ACCESS[page], { roles: ['super_admin'], permissions: [] }), true, `${page}: central administrator authority is unchanged`)
    for (const role of ['university_president', 'vice_president_scientific', 'vice_president_administrative', 'hr_officer', 'technical_team', 'dean', 'ministry_observer']) {
      assert.equal(canAccess(ACCESS[page], { roles: [role], permissions: OWNER_PERMISSIONS }), false, `${page} must not open for ${role} even with the permissions mapped`)
    }
    assert.equal(canAccess(ACCESS[page], { roles: ['university_owner'], permissions: [] }), false, `${page} without permissions`)
  }
  const homeOnly = { roles: ['university_owner'], permissions: ['owner_portal.access', 'owner_portal.home.view'] }
  assert.equal(canAccess(ACCESS.ownerHome, homeOnly), true)
  assert.equal(canAccess(ACCESS.ownerPayroll, homeOnly), false, 'each page has its own permission')
})

test('the sidebar has exactly two feature items: Home and Payroll', async () => {
  const nav = await source('features/owner-portal/nav.js')
  const items = [...nav.matchAll(/\{ to: '([^']+)', Icon: \w+, ar: '([^']+)', en: '([^']+)'[^}]*\.\.\.ACCESS\.(\w+) \}/g)].map(match => [match[2], match[1], match[4]])
  assert.deepEqual(items, [['الرئيسية', '/owner', 'ownerHome'], ['الرواتب', '/owner/payroll', 'ownerPayroll']])
  assert.equal((nav.match(/label:/g) ?? []).length, 1, 'one section: no reports, no user guide, no extra items')
  assert.doesNotMatch(nav, /reportNav|GUIDE_/)
})

test('routes are guarded by the owner access rules and nothing else changed shape', async () => {
  const app = await source('app/App.jsx')
  assert.match(app, /<ProtectedRoute \{\.\.\.ACCESS\.ownerPortal\}><DashboardLayout nav=\{ownerNav\} appTitle="مالك الجامعة" \/>/)
  assert.match(app, /path="\/owner" element=\{protect\(<OwnerHome \/>, ACCESS\.ownerHome\)\}/)
  assert.match(app, /path="\/owner\/payroll" element=\{protect\(<OwnerPayroll \/>, ACCESS\.ownerPayroll\)\}/)
})

test('payroll data is isolated: the owner feature only talks to /v1/owner', async () => {
  const api = await source('features/owner-portal/lib/ownerApi.js')
  assert.match(api, /const BASE = '\/v1\/owner'/)
  assert.equal((api.match(/\/v1\//g) ?? []).length, 1, 'no other API prefix is used')
  for (const file of ['features/owner-portal/pages/OwnerPayroll.jsx', 'features/owner-portal/pages/OwnerHome.jsx', 'features/owner-portal/components/EmployeeDialog.jsx', 'features/owner-portal/components/BodiesDialog.jsx', 'features/owner-portal/components/PayrollGrid.jsx']) {
    const text = await source(file)
    assert.doesNotMatch(text, /apiRequest|fetch\(|\/v1\/(employees|hr|students|faculty|teaching|users|accounts|technical)/, `${file} goes through ownerApi only`)
  }
})

test('the grid keeps RevoGrid clipboard/autofill/range-edit disabled so nothing bypasses the validated paths', async () => {
  const grid = await source('features/owner-portal/components/PayrollGrid.jsx')
  assert.match(grid, /useClipboard=\{false\}/)
  assert.match(grid, /onBeforeautofill=\{onBeforeAutofill\}/)
  assert.match(grid, /onBeforerangeedit=\{onBeforeRangeEdit\}/)
  assert.match(grid, /rtl\b/)
  assert.match(grid, /planPaste\(/)
  assert.doesNotMatch(grid, /dangerouslySetInnerHTML|innerHTML\s*=/)
})

test('the exports, grid and totals scope are labelled, and exports wait for pending saves', async () => {
  const page = await source('features/owner-portal/pages/OwnerPayroll.jsx')
  assert.match(page, /await controller\.flush\(\)/)
  assert.match(page, /if \(!ok\)[\s\S]{0,200}لا يمكن التصدير/)
  assert.match(page, /الصفوف المطابقة للمرشحات الحالية/)
  assert.match(page, /كل الصفوف/)
  for (const label of ['إضافة موظف', 'إدارة الهيئات', 'تصدير Excel', 'تصدير PDF']) assert.ok(page.includes(label), label)
  assert.doesNotMatch(page, /فترة الرواتب|اختيار الشهر|إقفال|اعتماد الرواتب|صرف الرواتب|تنفيذ الدفع/, 'no monthly-cycle, approval, closing or payment features in phase 1')
})
