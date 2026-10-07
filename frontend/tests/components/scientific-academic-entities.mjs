// Actual ReactDOM + memory-router state/events with synthetic API responses and
// a no-layout DOM host. NOT browser, visual, or live Laravel integration evidence.
import assert from 'node:assert/strict'
import { after, afterEach, test } from 'node:test'
import { createServer } from 'vite'
import { installNoLayoutDom, allNodes, named } from '../support/no-layout-dom.mjs'

const host = installNoLayoutDom()
const { act, createElement } = await import('react')
const { createRoot } = await import('react-dom/client')
const { createMemoryRouter, RouterProvider } = await import('react-router-dom')
process.env.VITE_API_BASE_URL = 'http://127.0.0.1:9/api'
const server = await createServer({ mode: 'test', server: { middlewareMode: true }, appType: 'custom' })
const { default: Page, LegacyAcademicEntityRoute: Legacy } = await server.ssrLoadModule('/src/features/scientific-programs/AcademicEntitiesPage.jsx')
const { default: CoursePage } = await server.ssrLoadModule('/src/features/scientific-programs/CourseEntityPage.jsx')
const { default: Details } = await server.ssrLoadModule('/src/features/scientific-courses/CourseDetails.jsx')
const { useState } = await import('react')
const rootPath = '/vp/scientific/programs-courses'
const officer = { roles: ['vice_president_scientific'], permissions: ['vice_presidency.scientific.access', 'vice_presidency.scientific.courses.view'], access_scopes: [{ type: 'university', id: 1 }] }
const broad = { ...officer, permissions: [...officer.permissions, 'academic_structure.view'] }
const current = (revision = '10') => ({ revision, data: { course_id: 1, course_name: 'مادة اصطناعية', course_code: 'SYN-1', credit_hours: 3, theoretical_hours: 3, practical_hours: 0, is_active: true, description: 'وصف اختباري', course_departments: [], course_prerequisites: [], program_courses: [] }, capabilities: { edit_text: true, edit_academic: true, edit_relationships: false, delete: true }, impact: { shared: false } })
let root, router, container, requests
function api(handler) {
  requests = []
  globalThis.fetch = async (url, options = {}) => {
    const path = new URL(url).pathname, method = options.method || 'GET', body = options.body && JSON.parse(options.body)
    requests.push({ path, method, body })
    const response = await handler(path, method, body)
    const status = response?.status || 200
    return { ok: status < 400, status, json: async () => status >= 400 ? { message: 'تغيرت البيانات', error_code: 'academic_catalog_stale' } : { success: true, data: response?.data || { data: [], meta: { current_page: 1, last_page: 1, total: 0 }, revision: '10', capabilities: { create: false } } } }
  }
}
async function settle(ms = 20) { await act(async () => { await new Promise(resolve => setTimeout(resolve, ms)) }) }
async function mount(path, routes, user = officer) {
  localStorage.setItem('user', JSON.stringify(user))
  container = document.createElement('div'); document.body.appendChild(container); root = createRoot(container)
  router = createMemoryRouter(routes, { initialEntries: [path] })
  await act(async () => root.render(createElement(RouterProvider, { router }))); await settle()
}
async function click(label, tag = 'button') {
  const nodes = named(container, tag, label); assert.equal(nodes.length, 1, `${tag}: ${label}`); assert.ok(!nodes[0].disabled, `${label} enabled`)
  await act(async () => nodes[0].dispatchEvent(new host.Event('click'))); await settle()
}
afterEach(async () => { if (root) await act(async () => root.unmount()); router?.dispose(); container?.parentNode?.removeChild(container); root = null; localStorage.clear() })
after(async () => { await server.close() })

test('catalog-only identity follows legacy program query into the authorized catalog, never a program', async () => {
  api(() => null)
  await mount(rootPath + '?program=12&version=4&tab=membership', [{ path: rootPath, element: createElement(Page) }, { path: rootPath + '/courses', element: createElement(Page, { kind: 'courses' }) }, { path: rootPath + '/programs/:id', element: createElement('p', null, 'unauthorized program destination') }])
  await settle(400)
  assert.equal(router.state.location.pathname, rootPath + '/courses')
  assert.ok(!container.textContent.includes('unauthorized program destination'))
  assert.ok(requests.every(r => !r.path.includes('/program-management')))
})

test('authorized legacy program and explicit saved plan survive actual router navigation', async () => {
  api(() => null)
  await mount(rootPath + '?program=12&version=4&tab=membership&catalog=1', [{ path: rootPath, element: createElement(Page) }, { path: rootPath + '/programs/:id', element: createElement('p', null, 'explicit program target') }, { path: rootPath + '/courses', element: createElement('p', null, 'catalog target') }], broad)
  assert.equal(router.state.location.pathname, rootPath + '/programs/12')
  assert.equal(new URLSearchParams(router.state.location.search).get('version'), '4')
  assert.equal(new URLSearchParams(router.state.location.search).get('tab'), 'membership')
})

test('old program alias routes a catalog-only identity safely, retaining context for a program reader', async () => {
  api(() => null)
  await mount('/vp/scientific/programs/12?version=4&advanced=1', [{ path: '/vp/scientific/programs/:programId', element: createElement(Legacy, { kind: 'programs' }) }, { path: rootPath + '/courses', element: createElement('p', null, 'catalog') }, { path: rootPath + '/programs/:id', element: createElement('p', null, 'program') }])
  assert.equal(router.state.location.pathname, rootPath + '/courses')
})

test('old program alias preserves the chosen program/plan for an authorized reader and drops only advanced', async () => {
  api(() => null)
  await mount('/vp/scientific/programs/12?version=4&tab=membership&advanced=1', [{ path: '/vp/scientific/programs/:programId', element: createElement(Legacy, { kind: 'programs' }) }, { path: rootPath + '/programs/:id', element: createElement('p', null, 'program') }], broad)
  assert.equal(router.state.location.pathname, rootPath + '/programs/12')
  const query = new URLSearchParams(router.state.location.search)
  assert.equal(query.get('version'), '4'); assert.equal(query.get('tab'), 'membership'); assert.equal(query.has('advanced'), false)
})

test('denied identity with a legacy query never redirects into a protected entity', async () => {
  api(() => null)
  await mount(rootPath + '?program=12&version=4', [{ path: rootPath, element: createElement(Page) }, { path: rootPath + '/programs/:id', element: createElement('p', null, 'program') }], { roles: ['student'] })
  assert.equal(router.state.location.pathname, rootPath); assert.ok(container.textContent.includes('انتهت الصلاحية')); assert.equal(requests.length, 0)
})

test('explicit deletion-conflict recovery resets details AND page guard, adopts inspected revision, and never retries', async () => {
  let getCount = 0
  api((path, method) => path.endsWith('/courses/1') ? method === 'DELETE' ? { status: 409 } : { data: current(++getCount === 1 ? '10' : '11') } : null)
  await mount(rootPath + '/courses/1', [{ path: rootPath + '/courses/1', element: createElement(CoursePage, { id: '1', onUnauthorized: () => assert.fail('unexpected denial') }) }, { path: rootPath + '/courses', element: createElement('p', null, 'back in catalog') }])
  await click('حذف المادة'); await click('تأكيد الحذف')
  assert.equal(requests.filter(r => r.method === 'DELETE').length, 1)
  assert.equal(named(container, 'button', 'تعديل بيانات المادة')[0].disabled, true)
  await click('مراجعة البيانات الحالية'); await click('تجاهل تعديلاتي وفتح البيانات الحالية')
  assert.equal(requests.filter(r => r.method === 'DELETE').length, 1, 'no automatic delete retry')
  assert.equal(named(container, 'button', 'تعديل بيانات المادة')[0].disabled, false)
  assert.equal(named(container, 'button', 'حذف المادة')[0].disabled, false)
  assert.ok(!container.textContent.includes('حماية التغييرات'))
  await click('تعديل بيانات المادة')
  assert.ok(!container.textContent.includes('حماية التغييرات'), 'page guard was cleared by the explicit recovery')
  assert.ok(allNodes(container).some(n => n.localName === 'dialog' && n.open))
  await click('إغلاق النافذة')
  await click('حذف المادة'); await click('تأكيد الحذف')
  assert.equal(requests.filter(r => r.method === 'DELETE')[1].body.revision, '11', 'only an explicit new attempt uses the inspected revision')
  await click('مراجعة البيانات الحالية'); await click('تجاهل تعديلاتي وفتح البيانات الحالية')
  await click('العودة إلى دليل المواد', 'a')
  assert.equal(router.state.location.pathname, rootPath + '/courses'); assert.ok(container.textContent.includes('back in catalog'))
})

test('an incidental baseline refresh does not reset an unresolved CourseDetails conflict', async () => {
  api((_path, method) => method === 'DELETE' ? { status: 409 } : { data: current('11') })
  function RefreshFixture() {
    const [baseline, setBaseline] = useState(current()), [blocked, setBlocked] = useState(false)
    return createElement('div', null, createElement('button', { onClick: () => setBaseline(current('11')) }, 'incidental refresh'), createElement('p', null, blocked ? 'page blocked' : 'page clear'), createElement(Details, { baseline, busy: false, onBusy: () => {}, onBlocked: setBlocked, onUnauthorized: () => {}, onSaved: () => {}, onEdit: () => {}, onRestart: () => {} }))
  }
  await mount('/test', [{ path: '/test', element: createElement(RefreshFixture) }])
  await click('حذف المادة'); await click('تأكيد الحذف'); await click('incidental refresh')
  assert.ok(container.textContent.includes('page blocked')); assert.equal(named(container, 'button', 'حذف المادة')[0].disabled, true)
  assert.equal(requests.filter(r => r.method === 'DELETE').length, 1)
})

test('recovered course can edit/save with the new revision and navigate after cancelled dirty navigation', async () => {
  let savedSnapshot = current('10')
  api((path, method, body) => {
    if (!path.endsWith('/courses/1')) return null
    if (method === 'DELETE') { savedSnapshot = current('11'); return { status: 409 } }
    if (method === 'PUT') { assert.equal(body.revision, '11'); savedSnapshot = { ...current('12'), data: { ...current().data, description: body.description } } }
    return { data: savedSnapshot }
  })
  await mount(rootPath + '/courses/1', [{ path: rootPath + '/courses/1', element: createElement(CoursePage, { id: '1', onUnauthorized: () => assert.fail('unexpected denial') }) }, { path: rootPath + '/courses', element: createElement('p', null, 'back in catalog') }])
  await click('حذف المادة'); await click('تأكيد الحذف'); await click('مراجعة البيانات الحالية'); await click('تجاهل تعديلاتي وفتح البيانات الحالية'); await click('تعديل بيانات المادة')
  const input = allNodes(container).find(n => n.localName === 'textarea' && n.getAttribute('name') === 'description')
  assert.ok(input)
  await act(async () => { Object.getOwnPropertyDescriptor(Object.getPrototypeOf(input), 'value').set.call(input, 'تصحيح اصطناعي'); input.dispatchEvent(new host.Event('input')) })
  await click('العودة إلى دليل المواد', 'a'); assert.equal(router.state.location.pathname, rootPath + '/courses/1')
  await click('البقاء في المحرر')
  assert.equal(input.value, 'تصحيح اصطناعي', 'cancelled navigation retains the draft')
  const form = allNodes(container).find(n => n.localName === 'form')
  await act(async () => form.dispatchEvent(new host.Event('submit'))); await settle()
  const writes = requests.filter(r => r.method !== 'GET')
  assert.deepEqual(writes.map(r => r.method), ['DELETE', 'PUT'])
  assert.equal(writes[1].body.description, 'تصحيح اصطناعي')
  assert.ok(!container.textContent.includes('حماية التغييرات'))
  await click('العودة إلى دليل المواد', 'a'); assert.equal(router.state.location.pathname, rootPath + '/courses')
})
