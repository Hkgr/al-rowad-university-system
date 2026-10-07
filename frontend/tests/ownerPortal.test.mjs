import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { ACCESS, canAccess, landingRoute } from '../src/features/auth/auth.js'

const source = path => readFile(new URL(`../src/${path}`, import.meta.url), 'utf8')
const OWNER_PERMISSIONS = ['owner_portal.access', 'owner_portal.home.view', 'owner_payroll.view', 'owner_payroll.employees.manage', 'owner_payroll.bodies.manage', 'owner_payroll.amounts.edit', 'owner_payroll.export', 'owner_payroll.config.manage']
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

test('the exports describe the dataset on screen, wait for pending saves, and the page labels its scope', async () => {
  const page = await source('features/owner-portal/pages/OwnerPayroll.jsx')
  assert.match(page, /await controller\.flush\(\)/)
  assert.match(page, /if \(!ok\)[\s\S]{0,200}لا يمكن التصدير/)
  assert.match(page, /downloadPayrollExport\(kind, shown\.query\)/, 'the export uses the applied (on-screen) query, never the not-yet-applied controls')
  assert.match(page, /disabled=\{Boolean\(exporting\) \|\| load\.state !== 'ready' \|\| pendingQuery\}/, 'export is disabled while the controls are ahead of the grid')
  assert.match(page, /الصفوف المطابقة للمرشحات/)
  assert.match(page, /كل الصفوف/)
  for (const label of ['إضافة موظف', 'إدارة الهيئات', 'إدارة الأعمدة والمعادلات', 'عرض مختصر', 'عرض تفصيلي']) assert.ok(page.includes(label), label)
  assert.doesNotMatch(page, /فترة الرواتب|اختيار الشهر|إقفال|اعتماد الرواتب|صرف الرواتب|تنفيذ الدفع/, 'no monthly-cycle, approval, closing or payment features yet')
})

test('review: filter/sort changes made while a save is unresolved are applied after resolution, not dropped or half-applied', async () => {
  const page = await source('features/owner-portal/pages/OwnerPayroll.jsx')
  assert.match(page, /const \[desired, setDesired\]/)
  assert.match(page, /const \[shown, setShown\]/)
  assert.match(page, /\[desiredQuery, reloadKey, controller, blockedByError\]/, 'the dataset effect re-runs when the save problem is resolved')
  assert.match(page, /sort=\{gridSort\}/, 'the grid describes what is shown')
  assert.match(page, /gridSort = useMemo\(\(\) => \(\{ key: shownFilters\.sort/)
  assert.match(page, /data-testid="pending-query"/)
})

test('review: discarding unsaved values reloads the authoritative rows first', async () => {
  const controller = await source('features/owner-portal/lib/payrollSheetController.js')
  const useServer = controller.slice(controller.indexOf('async useServer()'))
  assert.ok(useServer.indexOf('await this.#reload()') < useServer.indexOf('this.#dropPending(op)'), 'reload happens before anything is discarded')
})

test('Home is an overview: one principal figure, deep links into the filtered payroll, no new sidebar items or invented metrics', async () => {
  const home = await source('features/owner-portal/pages/OwnerHome.jsx')
  for (const text of ['نظرة عامة', 'فتح كشف الرواتب', 'إجمالي الصافي المستحق', 'حسب الهيئة', 'حسب مكان العمل', 'ما يحتاج إلى معالجة']) assert.ok(home.includes(text), text)
  assert.match(home, /\/owner\/payroll\?\$\{param\}=/)
  assert.match(home, /completeness=incomplete/)
  assert.doesNotMatch(home, /Chart|recharts|trend|اتجاه|الشهر الماضي|<select/)
})

test('Syrian pounds only: no dollar sign or USD anywhere in the owner feature', async () => {
  for (const file of ['lib/payrollMoney.js', 'lib/payrollView.js', 'lib/payrollSheetController.js', 'pages/OwnerPayroll.jsx', 'pages/OwnerHome.jsx', 'components/PayrollGrid.jsx', 'components/ColumnsDialog.jsx']) {
    const text = await source(`features/owner-portal/${file}`)
    assert.doesNotMatch(text, /USD|دولار|['"]\$['"]|\$\$\{|\bformatMoney\b|\bcents\b/i, `${file} must not mention dollars or the old cents model`)
  }
})

test('the formula manager has the required controls and a separate permission', async () => {
  const dialog = await source('features/owner-portal/components/ColumnsDialog.jsx')
  for (const text of ['إدارة الأعمدة والمعادلات', 'الإعدادات العامة', 'أثر التغيير قبل الحفظ', 'حفظ الإعدادات', 'معاينة على الموظف', 'تعتمد عليه المعادلات']) assert.ok(dialog.includes(text), text)
  const auth = await source('features/auth/auth.js')
  assert.match(auth, /ownerPayrollConfigManage: 'owner_payroll\.config\.manage'/)
  const page = await source('features/owner-portal/pages/OwnerPayroll.jsx')
  assert.match(page, /canManage=\{canConfig\}/)
})
