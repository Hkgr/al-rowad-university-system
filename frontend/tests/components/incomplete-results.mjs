// Real ReactDOM/router events + synthetic API responses, NO layout/browser/live Laravel.
import assert from 'node:assert/strict'
import { after, afterEach, test } from 'node:test'
import { createServer } from 'vite'
import { installNoLayoutDom, allNodes, named } from '../support/no-layout-dom.mjs'
const host = installNoLayoutDom()
// React's controlled radio restoration queries its native group. This host has no selector engine.
document.querySelectorAll = selector => {
  assert.equal(selector, 'input[name="expiry"][type="radio"]')
  return allNodes(document).filter(node => node.localName === 'input' && node.getAttribute('name') === 'expiry' && node.type === 'radio')
}
const { act, createElement } = await import('react')
const { createRoot } = await import('react-dom/client')
const { createMemoryRouter, RouterProvider } = await import('react-router-dom')
process.env.VITE_API_BASE_URL = 'http://127.0.0.1:9/api'
const server = await createServer({ mode: 'test', server: { middlewareMode: true, hmr: false }, appType: 'custom' })
const { default: Page } = await server.ssrLoadModule('/src/features/exam-board/pages/IncompleteResultsPage.jsx')
const officer = { roles: ['exam_officer'], permissions: ['exams.manage', 'grades.manage', 'students.view'] }
const path = '/exam-board/incomplete-results/registrations/1'
const base = () => ({ registration_id: 1, schema_ready: true, student: { id: 1, number: 'SYN-1', full_name: 'سارة اصطناعية' }, course: { id: 1, name: 'مقرر اصطناعي', code: 'SYN101' }, academic_year: { id: 1, name: '2026' }, semester: { id: 1, name: 'الأول' }, offering: { id: 1 }, case: null, is_official: false, allowed_operations: { grant: true }, resolution: { available: false }, deadline_suggestion: { available: false }, components: [{ grade_component_id: 1, name: 'النظري', max_mark: 60, mark: null, editable: false }] })
const active = (revision = 1) => ({ ...base(), state: 'incomplete_active', case: { incomplete_case_id: 3, revision, state: 'active', expiry_outcome: 'F', reason: 'سبب موثق', requirements: 'متطلب', deadline_at: '2026-10-21T20:59:59Z' }, allowed_operations: { grant: false, deadline_update: true, resolve: true }, resolution: { available: true }, components: [{ grade_component_id: 1, name: 'النظري', max_mark: 60, mark: null, editable: true }, { grade_component_id: 2, name: 'العملي', max_mark: 40, mark: 0, editable: false, blocking_code: 'completed_component_locked' }] })
let root, router, container, requests
function api(handler) {
  requests = []
  globalThis.fetch = async (url, options = {}) => {
    const request = { path: new URL(url).pathname, search: new URL(url).search, method: options.method || 'GET', body: options.body, signal: options.signal }; requests.push(request)
    const result = await handler(request)
    return { ok: !result?.status || result.status < 400, status: result?.status || 200, json: async () => result?.status >= 400 ? { message: 'تعارض اصطناعي', error_code: result.code } : { success: true, data: result?.data || base() } }
  }
}
async function settle(ms = 20) { await act(async () => { await new Promise(resolve => setTimeout(resolve, ms)) }) }
async function mount(initial = path, user = officer) {
  localStorage.setItem('user', JSON.stringify(user)); container = document.createElement('div'); document.body.appendChild(container); root = createRoot(container)
  router = createMemoryRouter([
    ...['/exam-board/incomplete-results', '/vp/administrative/exams/incomplete-results'].flatMap(base => [{ path: base + '/registrations/:registrationId', element: createElement(Page) }, { path: base + '/:caseId', element: createElement(Page) }, { path: base, element: createElement(Page) }]),
  ], { initialEntries: ['/exam-board/incomplete-results', initial], initialIndex: 1 })
  await act(async () => root.render(createElement(RouterProvider, { router }))); await settle()
}
async function click(text, tag = 'button') {
  const matches = named(container, tag, text); assert.equal(matches.length, 1, text); assert.ok(!matches[0].disabled, `${text} enabled`)
  await act(async () => matches[0].dispatchEvent(new host.Event('click'))); await settle()
}
async function input(node, value, type = 'input') {
  assert.ok(node); await act(async () => { Object.getOwnPropertyDescriptor(Object.getPrototypeOf(node), 'value').set.call(node, value); node.dispatchEvent(new host.Event(type)) }); await settle()
}
const nodes = tag => allNodes(container).filter(node => node.localName === tag)
async function fillGrant() {
  await click('منح I'); await input(nodes('textarea')[0], 'سبب اصطناعي'); await input(nodes('textarea')[1], 'الامتحان المتبقي')
  const radio = nodes('input').find(node => node.type === 'radio')
  await act(async () => { radio.checked = true; radio.dispatchEvent(new host.Event('click')) }); await settle()
  await input(nodes('input').find(node => node.type === 'date'), '2026-10-21', 'change')
}
afterEach(async () => { if (root) await act(async () => root.unmount()); router?.dispose(); container?.parentNode?.removeChild(container); root = null; localStorage.clear() })
after(async () => server.close())

test('grant uses real form inputs and cancelled back navigation retains a savable proposal', async () => {
  let saved = false
  api(request => request.method === 'POST' ? (saved = true, { data: { changed: true } }) : { data: saved ? active() : base() })
  await mount(); await fillGrant()
  await act(async () => router.navigate(-1)); await settle(); assert.equal(router.state.location.pathname, path)
  await click('إلغاء'); assert.equal(nodes('textarea')[0].value, 'سبب اصطناعي')
  await click('مراجعة وتأكيد'); await click('تأكيد')
  const writes = requests.filter(r => r.method === 'POST'); assert.equal(writes.length, 1)
  assert.deepEqual({ ...JSON.parse(writes[0].body), request_id: 'uuid' }, { request_id: 'uuid', expected_revision: 0, reason: 'سبب اصطناعي', requirements: 'الامتحان المتبقي', expiry_outcome: 'F', deadline_at: '2026-10-21' })
  assert.ok(container.textContent.includes('نشطة')); assert.equal(nodes('textarea').length, 0)
  await click('العودة إلى الحالات', 'a'); assert.equal(router.state.location.pathname, '/exam-board/incomplete-results')
})
test('deadline update and settlement have independent explicit payloads and zero is read-only', async () => {
  api(request => ({ data: request.method !== 'GET' ? { changed: true } : active() }))
  await mount(); await click('تعديل المهلة'); await input(nodes('input')[0], '2026-11-01', 'change'); await click('مراجعة وتأكيد'); await click('تأكيد')
  const deadline = requests.find(r => r.method === 'PATCH'); assert.ok(deadline.path.endsWith('/3/deadline'))
  assert.equal(JSON.parse(deadline.body).deadline_at, '2026-11-01')
  await click('تسوية الحالة'); const marks = nodes('input'); assert.equal(marks[1].value, '0'); assert.ok(marks[1].disabled)
  await input(marks[0], '50'); await click('مراجعة وتأكيد'); await click('تأكيد')
  const writes = requests.filter(r => r.method !== 'GET'); assert.deepEqual(JSON.parse(writes[1].body).components, [{ grade_component_id: 1, mark: '50' }]); assert.ok(writes[1].path.endsWith('/3/resolve'))
})
test('409 preserves proposed values and baseline until an explicit review; refresh never retries a write', async () => {
  let stale = false
  api(request => request.method === 'POST' ? (stale = true, { status: 409, code: 'incomplete_stale' }) : { data: active(stale ? 2 : 1) })
  await mount(); await click('تسوية الحالة'); await input(nodes('input')[0], '50'); await click('مراجعة وتأكيد'); await click('تأكيد')
  assert.equal(nodes('input')[0].value, '50'); assert.ok(named(container, 'button', 'مراجعة وتأكيد')[0].disabled)
  assert.equal(requests.filter(r => r.method === 'POST').length, 1)
  await click('راجعت الحالة — إعادة إعداد مقترحي'); assert.equal(nodes('input')[0].value, '50')
  await click('مراجعة وتأكيد'); await click('تأكيد'); assert.equal(JSON.parse(requests.filter(r => r.method === 'POST')[1].body).expected_revision, 2)
})
test('lost response requires GET then an explicit exact UUID/content replay, never an automatic write', async () => {
  let lost = false
  api(request => { if (request.method === 'POST') { if (!lost) { lost = true; throw new TypeError('lost response') } return { data: { changed: true } } } return { data: lost ? active() : base() } })
  await mount(); await fillGrant(); await click('مراجعة وتأكيد'); await click('تأكيد')
  assert.equal(requests.filter(r => r.method === 'POST').length, 1); assert.ok(container.textContent.includes('لم تتأكد'))
  await click('قراءة الحالة الحالية'); assert.equal(requests.filter(r => r.method === 'POST').length, 1)
  await click('إعادة إرسال الطلب الأصلي بعد القراءة'); await click('تأكيد')
  const writes = requests.filter(r => r.method === 'POST'); assert.equal(writes.length, 2); assert.equal(writes[0].body, writes[1].body)
})
test('pending write blocks transitions and double confirmation cannot duplicate the request', async () => {
  let release
  api(request => request.method !== 'GET' ? new Promise(resolve => { release = resolve }) : { data: active() })
  await mount(); await click('تعديل المهلة'); await input(nodes('input')[0], '2026-11-01', 'change'); await click('مراجعة وتأكيد')
  const confirm = named(container, 'button', 'تأكيد')[0]
  await act(async () => { confirm.dispatchEvent(new host.Event('click')); confirm.dispatchEvent(new host.Event('click')) }); await settle()
  assert.equal(requests.filter(r => r.method !== 'GET').length, 1)
  await click('العودة إلى الحالات', 'a'); assert.equal(router.state.location.pathname, path); assert.ok(named(container, 'button', 'تجاهل المقترح والانتقال')[0].disabled)
  await click('إلغاء'); await act(async () => release({ data: { changed: true } })); await settle()
})
test('identity loss purges sensitive proposal immediately and late reads cannot repopulate it', async () => {
  api(() => ({ data: active() })); await mount(); await click('تسوية الحالة'); await input(nodes('input')[0], '50')
  await act(async () => { localStorage.setItem('user', JSON.stringify({ roles: ['student'] })); window.dispatchEvent(new host.Event('storage')) }); await settle()
  assert.ok(container.textContent.includes('الوصول غير متاح')); assert.ok(!container.textContent.includes('سارة')); assert.equal(nodes('input').length, 0)
})
test('obsolete registration response ignored even when transport ignores abort', async () => {
  let release
  api(request => request.path.endsWith('/registrations/1') ? new Promise(resolve => { release = resolve }) : { data: { ...base(), registration_id: 2, student: { ...base().student, full_name: 'طالب ثان' } } })
  await mount(); await act(async () => router.navigate('/exam-board/incomplete-results/registrations/2')); await settle()
  await act(async () => release({ data: base() })); await settle(); assert.ok(container.textContent.includes('طالب ثان')); assert.ok(!container.textContent.includes('سارة'))
})

test('terminal resolved and expired histories remain read-only, including F/Z with missing marks', async () => {
  for (const state of ['resolved', 'expired']) {
    const data = { ...active(), state: `incomplete_${state}`, case: { ...active().case, state }, allowed_operations: { grant: false, deadline_update: false, resolve: false }, resolution: { available: false }, components: active().components.map(c => ({ ...c, editable: false })), current_result: { letter_grade: state === 'expired' ? 'F' : 'B', final_mark: state === 'expired' ? null : 80 } }
    api(() => ({ data })); await mount()
    assert.equal(named(container, 'button', 'منح I').length, 0); assert.equal(named(container, 'button', 'تسوية الحالة').length, 0); assert.equal(nodes('input').length, 0)
    assert.equal(requests.filter(r => r.method !== 'GET').length, 0)
    await act(async () => root.unmount()); router.dispose(); container.parentNode.removeChild(container); root = null
  }
})

test('list query, detail return context and paginated filters survive actual routing without writes', async () => {
  api(request => request.path.endsWith('/3') ? { data: active() } : { data: { data: [{ ...active().case, ...base(), state: 'active', incomplete_case_id: 3 }], current_page: 2, last_page: 3, total: 35, filter_options: { complete: true, terms: [{ academic_year_id: 1, year_name: '2026', semester_id: 1, semester_name: 'الأول' }] } } })
  await mount('/exam-board/incomplete-results?state=active&academic_year_id=1&page=2')
  await click('فتح الحالة', 'a'); assert.equal(router.state.location.search, '?state=active&academic_year_id=1&page=2')
  await click('العودة إلى الحالات', 'a'); assert.equal(router.state.location.search, '?state=active&academic_year_id=1&page=2')
  await click('مسح الفلاتر'); assert.equal(router.state.location.search, '')
  assert.ok(requests.every(request => request.method === 'GET'))
})

test('administrative office authority stays within office 735 paths without another assigned role', async () => {
  const vp = { roles: ['vice_president_administrative'], permissions: ['vice_presidency.administrative.access', 'exams.manage', 'grades.manage', 'students.view'], access_scopes: [{ type: 'university' }], administrative_offices: { role: 'vice_president_administrative', offices: ['735'], operator_roles: { exam_officer: '735' } } }
  api(() => ({ data: active() })); await mount('/vp/administrative/exams/incomplete-results/registrations/1', vp)
  assert.ok(container.textContent.includes('سارة')); assert.equal(named(container, 'a', 'العودة إلى الحالات')[0].getAttribute('href'), '/vp/administrative/exams/incomplete-results')
  assert.equal(requests[0].path, '/api/v1/exams/incomplete-results/registrations/1')
})
