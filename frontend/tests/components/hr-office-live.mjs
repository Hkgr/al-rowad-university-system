// Actual ReactDOM/events + REAL local Laravel HTTP + isolated MariaDB; no layout/browser/visual proof.
// No fetch interception. Start the guarded backend fixture/router before running this suite.
import assert from 'node:assert/strict'
import { after, afterEach, test } from 'node:test'
import { readFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { createServer } from 'vite'
import { installNoLayoutDom, allNodes, named } from '../support/no-layout-dom.mjs'
const endpoint = process.env.HR_TEST_HTTP || 'http://127.0.0.1:18171'
assert.equal(new URL(endpoint).hostname, '127.0.0.1')
const credential = JSON.parse(readFileSync(new URL('../../../.superdesign/tmp/hr-office-test-token.json', import.meta.url), 'utf8'))
assert.match(credential.database, /^codex_hr_office_test_/)
const host = installNoLayoutDom(); Object.assign(host.window, { setInterval, clearInterval, confirm: () => false })
const { act, createElement } = await import('react'); const { createRoot } = await import('react-dom/client'); const { createMemoryRouter, RouterProvider } = await import('react-router-dom')
process.env.VITE_API_BASE_URL = `${endpoint}/api`
const vite = await createServer({ mode: 'test', server: { middlewareMode: true, hmr: false }, appType: 'custom' })
const { default: Page } = await vite.ssrLoadModule('/src/features/hr-office/pages/HrOfficePage.jsx')
let root, router, container
const settle = async () => { await act(async () => { await new Promise(r => setTimeout(r, 100)) }) }
const click = async node => { assert.ok(node); await act(async () => node.dispatchEvent(new host.Event('click'))); await settle() }
const button = text => { const nodes = named(container, 'button', text); assert.equal(nodes.length, 1, text); return nodes[0] }
function field(title) { const label = allNodes(container).find(n => n.localName === 'label' && n.textContent.startsWith(title)); assert.ok(label, title); return allNodes(label).find(n => ['input', 'select'].includes(n.localName)) }
async function fill(title, value) { const node = field(title); await act(async () => { Object.getOwnPropertyDescriptor(Object.getPrototypeOf(node), 'value').set.call(node, value); node.dispatchEvent(new host.Event(node.localName === 'select' ? 'change' : 'input')) }); await settle() }
async function mount() {
  localStorage.setItem('token', credential.token); localStorage.setItem('user', JSON.stringify({ roles: ['super_admin'], permissions: [], access_scopes: [], is_super_admin: true }))
  container = document.createElement('div'); document.body.appendChild(container); root = createRoot(container)
  router = createMemoryRouter([{ path: '/vp/administrative/hr', element: createElement(Page) }, { path: '/next', element: createElement('p', null, 'الوجهة') }], { initialEntries: ['/vp/administrative/hr?section=needs'] })
  await act(async () => root.render(createElement(RouterProvider, { router })))
  for (let i = 0; i < 40 && !container.textContent.includes('Synthetic approval race'); i++) await settle()
  assert.ok(container.textContent.includes('Synthetic approval race'), 'actual MariaDB need appears through real HTTP')
}
afterEach(async () => { if (root) await act(async () => root.unmount()); router?.dispose(); container?.parentNode?.removeChild(container); localStorage.clear() })
after(async () => vite.close())
const actorState = state => execFileSync('php', ['tests/Support/hr_office_mariadb.php', 'actor-state', state], { cwd: fileURLToPath(new URL('../../../backend/', import.meta.url)), env: { ...process.env, HR_TEST_DATABASE: credential.database }, stdio: 'pipe' })
test('real Laravel multi-item save/reopen through React; cancelled navigation keeps draft and saving works', async () => {
  const title = `React HTTP synthetic ${Date.now()}`
  await mount(); await click(button('إضافة احتياج')); await fill('عنوان الاحتياج', title); await fill('الكلية', '1'); await fill('الصفة الوظيفية', 'React position one'); await fill('التعليم المطلوب', 'Independent qualification one'); await click(button('إضافة منصب مطلوب'))
  const legends = allNodes(container).filter(n => n.localName === 'fieldset'); assert.equal(legends.length, 2)
  const input = allNodes(legends[1]).filter(n => n.localName === 'input')[0]
  await act(async () => { Object.getOwnPropertyDescriptor(Object.getPrototypeOf(input), 'value').set.call(input, 'React position two'); input.dispatchEvent(new host.Event('input')) }); await settle()
  await click(button('الاحتياجات')); assert.equal(field('عنوان الاحتياج').value, title, 'active section is a no-op with drafts')
  await act(async () => router.navigate('/next')); await settle(); assert.equal(router.state.blockers.size, 1)
  await click(button('البقاء ومتابعة العمل')); assert.ok(container.textContent.includes('إضافة منصب مطلوب'))
  await act(async () => allNodes(container).find(n => n.localName === 'form').dispatchEvent(new host.Event('submit')))
  for (let i = 0; i < 80 && allNodes(container).some(n => n.localName === 'form'); i++) await settle()
  assert.equal(allNodes(container).filter(n => n.localName === 'form').length, 0, container.textContent)
  for (let i = 0; i < 80 && !container.textContent.includes(title); i++) await settle()
  assert.ok(container.textContent.includes('React position one')); assert.ok(container.textContent.includes('React position two'))
  const target = allNodes(container).find(n => n.localName === 'button' && n.getAttribute('aria-label') === `عرض ${title}`)
  await click(target); assert.ok(container.textContent.includes('Independent qualification one'), container.textContent); assert.ok(container.textContent.includes('React position two'))
})
test('real HTTP denies no token; ordinary role guard exposes no office state', async () => {
  const response = await fetch(`${endpoint}/api/v1/vice-presidency/administrative/hr/workers`, { headers: { Accept: 'application/json' } }); assert.equal(response.status, 401)
  localStorage.setItem('user', JSON.stringify({ roles: ['dean'], permissions: ['administrative_hr.view'], access_scopes: [{ type: 'university', id: 1 }] }))
  container = document.createElement('div'); document.body.appendChild(container); root = createRoot(container)
  await act(async () => root.render(createElement(Page)))
  assert.ok(container.textContent.includes('لا تملك صلاحية')); assert.ok(!container.textContent.includes('Synthetic'))
})
test('actual Laravel denial after authorization loss immediately clears dirty sensitive forms without discard confirmation', async () => {
  await mount(); await click(button('إضافة احتياج')); await fill('عنوان الاحتياج', 'Sensitive synthetic draft')
  actorState('disable')
  try {
    await act(async () => allNodes(container).find(n => n.localName === 'form').dispatchEvent(new host.Event('submit')))
    for (let i = 0; i < 80 && allNodes(container).some(n => n.localName === 'form'); i++) await settle()
    assert.equal(allNodes(container).filter(n => n.localName === 'form').length, 0)
    assert.ok(!container.textContent.includes('Synthetic approval race'))
    assert.equal(named(container, 'button', 'إضافة احتياج').length, 0)
    assert.ok(container.textContent.includes('لا تملك الصلاحية'))
  } finally { actorState('restore') }
})
