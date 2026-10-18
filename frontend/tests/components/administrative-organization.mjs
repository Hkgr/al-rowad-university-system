// ReactDOM/events and memory-router against a NO-LAYOUT host. Synthetic API data.
// This executes component behavior, NOT browser rendering or live Laravel integration.
import assert from 'node:assert/strict'
import { after, afterEach, test } from 'node:test'
import { createServer } from 'vite'
import { installNoLayoutDom, allNodes, named } from '../support/no-layout-dom.mjs'

const host = installNoLayoutDom()
Object.assign(host.window, { setInterval, clearInterval })
const { act, createElement } = await import('react')
const { createRoot } = await import('react-dom/client')
const { createMemoryRouter, RouterProvider } = await import('react-router-dom')
const { canAccess } = await import('../../src/features/auth/auth.js')
process.env.VITE_API_BASE_URL = 'http://127.0.0.1:9/api'
const server = await createServer({ mode: 'test', server: { middlewareMode: true }, appType: 'custom' })
const { default: Layout } = await server.ssrLoadModule('/src/components/layout/DashboardLayout.jsx')
const { default: Navigation } = await server.ssrLoadModule('/src/features/vice-presidency/components/AdministrativeNavigation.jsx')
const { default: Home } = await server.ssrLoadModule('/src/features/vice-presidency/pages/VicePresidentShell.jsx')
const { administrativeVicePresidentNav: nav, scientificVicePresidentNav: scientificNav } = await server.ssrLoadModule('/src/features/vice-presidency/nav.js')
const { adminPortalNav } = await server.ssrLoadModule('/src/features/auth/adminPortalNav.js')
const base = '/vp/administrative'
const broad = { username: 'حساب اصطناعي', roles: ['vice_president_administrative'], permissions: [
  'vice_presidency.administrative.access', 'vice_presidency.administrative.faculty.view',
  'vice_presidency.administrative.deans.view', 'teaching_assignments.view', 'course_offerings.exceptional_open.view',
], access_scopes: [{ type: 'university', id: 1 }] }
const snapshot = {
  generated_at: '2026-10-09T10:00:00Z',
  filter_options: { academic_years: [{ id: 3, label: 'سنة اختبار', is_current: true }], semesters: [{ id: 2, label: 'فصل اختبار' }], colleges: [{ id: 6, label: 'كلية اختبار' }] },
  students: { university_total: 120, by_college: [{ college_id: 6, total: 70 }] },
  faculty: { university_total: 25, without_college: 4, by_college: [{ college_id: 6, total: 12 }] },
  teaching_assignments: { available: true, totals: { pending: 9, returned: 3, approved: 8 }, by_college: [{ college_id: 6, pending: 4, returned: 1, approved: 2 }] },
}
let root, router, container, requests, response
function stubApi(data = snapshot) {
  response = data; requests = []
  globalThis.fetch = async (url, options = {}) => {
    const parsed = new URL(url); assert.equal(parsed.hostname, '127.0.0.1', 'no production request')
    assert.equal(options.method || 'GET', 'GET', 'organization causes no write')
    requests.push(parsed)
    const value = typeof response === 'function' ? response() : response
    return { ok: true, status: 200, json: async () => ({ success: true, data: value }) }
  }
}
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 30)) }) }
async function mount(path, user = broad, customized = true) {
  stubApi(); localStorage.setItem('user', JSON.stringify(user))
  container = document.createElement('div'); document.body.appendChild(container); root = createRoot(container)
  const properties = customized ? { nav, appTitle: 'الشؤون الإدارية', Navigation, portalClassName: 'administrative-portal' } : { nav: scientificNav, appTitle: 'نيابة الشؤون العلمية' }
  router = createMemoryRouter([{ element: createElement(Layout, properties), children: [
    { path: base, element: createElement(Home, { office: 'administrative' }) },
    { path: base + '/*', element: createElement('p', null, 'صفحة خدمة اصطناعية') },
    { path: '/vp/scientific', element: createElement('p', null, 'العلمي') },
  ] }], { initialEntries: [path] })
  await act(async () => root.render(createElement(RouterProvider, { router }))); await settle()
}
async function click(node) { assert.ok(node); await act(async () => node.dispatchEvent(new host.Event('click'))); await settle() }
function sidebar() { return allNodes(container).find(n => n.localName === 'nav') }
function group(code) { return document.getElementById('administrative-nav-' + code) }
function groupButton(code) { return allNodes(container).find(n => n.localName === 'button' && n.getAttribute('aria-controls') === 'administrative-nav-' + code) }
function isHidden(node) { return node.hasAttribute('hidden') }
function links(rootNode) { return allNodes(rootNode).filter(n => n.localName === 'a') }
afterEach(async () => { if (root) await act(async () => root.unmount()); router?.dispose(); container?.parentNode?.removeChild(container); root = null; localStorage.clear() })
after(async () => { await server.close() })

test('real home renders exact hierarchy plus existing API-driven dashboard without additional requests', async () => {
  await mount(base)
  for (const name of ['الشؤون الإدارية', 'نائب رئيس الجامعة للشؤون الإدارية', 'مكتب الديوان والأرشيف', 'أمين الصندوق', 'أمين المستودع', 'مكتب المنح والإيفاد والتبادل الطلابي', 'إدارة الامتحانات']) assert.ok(container.textContent.includes(name), name)
  for (const metric of ['الطلاب النشطون', 'المدرسون النشطون', 'تكليفات بانتظار مراجعتي', 'تكليفات معادة للعميد', 'تكليفات معتمدة', 'آخر تحديث', 'طلبات التكليف حسب الكلية', 'مجموعات قد تتداخل']) assert.ok(container.textContent.includes(metric), metric)
  assert.ok(container.textContent.includes('١٢٠') && container.textContent.includes('٢٥'))
  assert.equal(requests.length, 1); assert.equal(requests[0].pathname, '/api/v1/vice-presidency/administrative/dashboard')
  const rendered = links(sidebar()).map(n => n.getAttribute('href'))
  assert.deepEqual([...rendered].sort(), nav.flatMap(s => s.items).filter(item => canAccess(item, broad)).map(item => item.to).sort())
  assert.equal(rendered.length, 8)
  const top = sidebar().children[0].children.filter(n => n.localName === 'a' || n.localName === 'div')
  assert.deepEqual(top.map(n => n.localName === 'a' ? n.textContent : n.children[0].getAttribute('aria-label')), [
    'الرئيسية', 'مديرية الشؤون الإدارية — الرمز 71', 'مديرية الشؤون المالية — الرمز 72', 'مديرية شؤون الطلاب — الرمز 73', 'التقارير', 'طريقة الاستخدام',
  ])
  // Informational finance card has no anchor/placeholder service.
  const article = allNodes(container).find(n => n.localName === 'article' && n.getAttribute('aria-labelledby') === 'administrative-home-72')
  assert.equal(links(article).length, 0)
})

test('direct detail, navigation, back and refresh open the correct group; group buttons really toggle', async () => {
  await mount(base + '/teaching-assignments/31')
  assert.equal(groupButton('71').getAttribute('aria-expanded'), 'true'); assert.equal(isHidden(group('71')), false)
  assert.equal(links(sidebar()).find(n => n.getAttribute('href') === base + '/teaching-assignments').getAttribute('aria-current'), 'page')
  await click(groupButton('71')); assert.equal(isHidden(group('71')), true)
  await act(async () => router.navigate(base + '/calendar')); await settle()
  assert.equal(groupButton('73').getAttribute('aria-expanded'), 'true'); assert.equal(isHidden(group('73')), false)
  assert.equal(groupButton('71').getAttribute('aria-expanded'), 'false')
  await act(async () => router.navigate(-1)); await settle()
  assert.equal(groupButton('71').getAttribute('aria-expanded'), 'true')
  await act(async () => root.unmount()); router.dispose(); container.parentNode.removeChild(container)
  await mount(base + '/calendar')
  assert.equal(groupButton('73').getAttribute('aria-expanded'), 'true')
})

test('collapsed group selection expands the actual shared sidebar instead of losing the office links', async () => {
  await mount(base + '/deans')
  await click(allNodes(container).find(n => n.localName === 'button' && n.getAttribute('title') === 'طي القائمة'))
  assert.equal(groupButton('71').getAttribute('aria-expanded'), 'false'); assert.equal(isHidden(group('71')), true)
  await click(groupButton('71'))
  assert.equal(groupButton('71').getAttribute('aria-expanded'), 'true'); assert.equal(isHidden(group('71')), false)
  assert.ok(allNodes(container).some(n => n.localName === 'button' && n.getAttribute('title') === 'طي القائمة'))
})

test('limited/mixed roles keep exactly the former authorized nav items and show no cross-portal service', async () => {
  const limited = { ...broad, roles: ['vice_president_administrative', 'dean'], permissions: ['vice_presidency.administrative.access', 'vice_presidency.administrative.faculty.view'], access_scopes: [] }
  await mount(base, limited)
  assert.deepEqual(links(sidebar()).map(n => n.getAttribute('href')).sort(), nav.flatMap(s => s.items).filter(item => canAccess(item, limited)).map(item => item.to).sort())
  assert.equal(requests.length, 0, 'no dashboard authority without university scope')
  const main = allNodes(container).find(n => n.localName === 'main')
  assert.ok(links(main).every(n => n.getAttribute('href').startsWith(base)))
  assert.ok(!links(main).some(n => n.getAttribute('href') === base + '/faculty'))
})

test('central super-admin authority and all 12 separate account portal shortcuts remain intact', async () => {
  await mount(base, { username: 'مدير اختبار', roles: ['super_admin'], permissions: [], access_scopes: [] })
  const rendered = links(sidebar()).map(n => n.getAttribute('href'))
  assert.equal(rendered.length, 10 + adminPortalNav.items.length) // Two explicitly authorized HR/accounting service links.
  for (const item of adminPortalNav.items) assert.ok(rendered.includes(item.to), item.to)
  assert.equal(rendered.filter(path => path === base).length, 2, 'existing administrative account shortcut retained separately')
  const articles = allNodes(container).filter(n => n.localName === 'article')
  assert.ok(articles.flatMap(links).every(n => n.getAttribute('href').startsWith(base)))
})

test('generic scientific layout still uses its flat nav, title and existing routes, without the administrative hierarchy', async () => {
  const scientific = { roles: ['vice_president_scientific'], permissions: ['vice_presidency.scientific.access'], access_scopes: [{ type: 'university', id: 1 }] }
  await mount('/vp/scientific', scientific, false)
  assert.ok(container.textContent.includes('نيابة الشؤون العلمية'))
  assert.equal(named(container, 'button', 'مديرية الشؤون المالية — الرمز 72').length, 0)
  assert.deepEqual(links(sidebar()).map(n => n.getAttribute('href')).sort(), scientificNav.flatMap(s => s.items).filter(item => canAccess(item, scientific)).map(item => item.to).sort())
  assert.equal(requests.length, 0)
})

test('existing unavailable assignments are not zeroed or linked, while actual student/faculty counts remain', async () => {
  await mount(base)
  response = { ...snapshot, teaching_assignments: { available: false, reason: 'review_permission_missing' } }
  const selects = allNodes(container).filter(n => n.localName === 'select')
  assert.equal(selects.length, 3)
  await act(async () => { selects[0].value = '3'; selects[0].dispatchEvent(new host.Event('change')) }); await settle()
  assert.equal(requests.length, 2)
  assert.equal(requests[1].searchParams.get('academic_year_id'), '3')
  assert.ok(container.textContent.includes('غير متاح') && container.textContent.includes('صلاحية المراجعة الإدارية'))
  const main = allNodes(container).find(n => n.localName === 'main')
  assert.equal(links(main).filter(n => n.getAttribute('aria-label')?.includes('فتح القائمة المطابقة')).length, 0)
  assert.ok(container.textContent.includes('١٢٠'))
})
