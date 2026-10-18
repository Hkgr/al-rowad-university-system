import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { ADMINISTRATIVE_HEAD, ADMINISTRATIVE_PORTAL_TITLE, ADMINISTRATIVE_DIRECTORATES, administrativeDirectorates, administrativeActiveDirectorate, partitionAdministrativeSections } from '../src/features/vice-presidency/utils/administrativeOrganization.js'

test('all 18 organizational names and codes are exact, distinct and not metrics', () => {
  assert.equal(ADMINISTRATIVE_PORTAL_TITLE, 'الشؤون الإدارية')
  const expected = [
    ['7', 'نائب رئيس الجامعة للشؤون الإدارية'],
    ['71', 'مديرية الشؤون الإدارية'], ['711', 'مكتب الموارد البشرية'], ['712', 'مكتب الديوان والأرشيف'], ['713', 'مكتب الرعاية الصحية'], ['714', 'مكتب الخدمات الإدارية'], ['715', 'المكتب التقني'],
    ['72', 'مديرية الشؤون المالية'], ['721', 'مكتب المحاسبة'], ['722', 'أمين الصندوق'], ['723', 'أمين المستودع'],
    ['73', 'مديرية شؤون الطلاب'], ['731', 'مكتب الإرشاد والتوجيه'], ['732', 'مكتب القبول والتسجيل'], ['733', 'مكتب الخدمات الطلابية'], ['734', 'مكتب المنح والإيفاد والتبادل الطلابي'], ['735', 'إدارة الامتحانات'], ['736', 'مكتب التوثيق والتدقيق'],
  ]
  const nodes = [ADMINISTRATIVE_HEAD, ...ADMINISTRATIVE_DIRECTORATES.flatMap(group => [group, ...group.offices])]
  assert.deepEqual(nodes.map(({ code, title }) => [code, title]), expected)
  assert.equal(new Set(nodes.map(unit => unit.code)).size, 18)
  assert.ok(Object.isFrozen(ADMINISTRATIVE_DIRECTORATES))
  assert.ok(nodes.every(Object.isFrozen))
})

test('classification preserves only the supplied authorized services, not another portal', () => {
  const faculty = { to: '/vp/administrative/faculty', ar: 'إدارة المدرسين', permissions: ['assigned.read'] }
  const groups = administrativeDirectorates([faculty, { to: '/technical/university-email' }, { to: '/owner/payroll' }])
  assert.deepEqual(groups[0].offices[0].services, [faculty])
  assert.equal(groups[0].offices[0].services[0], faculty, 'reuse the exact authorized nav item')
  assert.ok(groups.flatMap(group => group.offices).filter(unit => unit.code !== '711').every(unit => unit.services.length === 0))
  assert.deepEqual(administrativeDirectorates([]).flatMap(group => group.offices).flatMap(unit => unit.services), [])
})

test('detail routes open their active ancestors; similarly named paths do not', () => {
  const routes = ADMINISTRATIVE_DIRECTORATES.flatMap(group => group.offices.flatMap(unit => unit.servicePaths)).map(to => ({ to }))
  const groups = administrativeDirectorates(routes)
  for (const path of ['/vp/administrative/faculty/3', '/vp/administrative/deans', '/vp/administrative/teaching-assignments/11', '/vp/administrative/exceptional-openings/8']) assert.equal(administrativeActiveDirectorate(path, groups), '71')
  assert.equal(administrativeActiveDirectorate('/vp/administrative/calendar', groups), '73')
  for (const path of ['/vp/administrative', '/vp/administrative/reports', '/vp/administrative/teaching-assignments-other', '/vp/scientific/calendar']) assert.equal(administrativeActiveDirectorate(path, groups), null)
})

test('administrator portal shortcuts are preserved separately including their administrative shortcut', () => {
  const portal = { label: 'الشؤون الإدارية', items: [{ to: '/vp/administrative' }, { to: '/vp/administrative/faculty' }] }
  const shortcuts = { label: 'بوابات إدارة النظام', items: [{ to: '/technical' }, { to: '/vp/administrative', ar: 'النيابة الإدارية' }] }
  const split = partitionAdministrativeSections([portal, shortcuts])
  assert.deepEqual(split.items, portal.items)
  assert.equal(split.otherSections[0], shortcuts)
  assert.equal(split.otherSections[0].items.length, 2)
})

test('static integration is isolated, reuses existing authorization and metrics, and adds no data fetch', () => {
  const read = path => readFileSync(new URL('../src/' + path, import.meta.url), 'utf8')
  const app = read('app/App.jsx')
  assert.equal((app.match(/Navigation=\{AdministrativeNavigation\}/g) ?? []).length, 3)
  assert.match(app, /<ProtectedRoute \{\.\.\.hrAccess\(HR.view\)\}>/)
  assert.match(app, /<ProtectedRoute \{\.\.\.payrollAccess\(PERMISSIONS.ownerPayrollView\)\}>/)
  assert.match(app, /<ProtectedRoute \{\.\.\.ACCESS\.administrativeVicePresident\}>\s*<DashboardLayout nav=\{administrativeVicePresidentNav\} appTitle="الشؤون الإدارية"/)
  const home = read('features/vice-presidency/components/AdministrativeHome.jsx')
  assert.match(home, /filter\(item => canAccess\(item, identity\)\)/)
  assert.match(home, /canUseAdministrative\('dashboard', identity\) && <AdministrativeDashboard \/>/)
  for (const path of ['AdministrativeHome.jsx', 'AdministrativeNavigation.jsx', 'OrganizationCode.jsx']) assert.doesNotMatch(read('features/vice-presidency/components/' + path), /apiRequest|fetch\(|https?:|permissions\s*:/)
  assert.match(read('features/vice-presidency/components/AdministrativeNavigation.jsx'), /aria-expanded=\{!collapsed && open\} aria-controls=\{id\}/)
  assert.match(read('features/vice-presidency/components/OrganizationCode.jsx'), /dir="ltr"/)
  assert.match(read('features/vice-presidency/components/administrativePortal.css'), /prefers-reduced-motion/)
})
