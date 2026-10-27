// Actual React components/router in the existing no-layout DOM, with synthetic responses.
// This is not browser layout or live Laravel acceptance.
import assert from 'node:assert/strict'
import { after, afterEach, test } from 'node:test'
import { createServer } from 'vite'
import { installNoLayoutDom, allNodes } from '../support/no-layout-dom.mjs'
const host = installNoLayoutDom()
Object.assign(host.window, { atob: globalThis.atob, btoa: globalThis.btoa })
const { act, createElement } = await import('react')
const { createRoot } = await import('react-dom/client')
const { createMemoryRouter, RouterProvider } = await import('react-router-dom')
process.env.VITE_API_BASE_URL = 'http://127.0.0.1:9/api'
const vite = await createServer({ mode: 'test', server: { middlewareMode: true }, appType: 'custom' })
const { default: Page } = await vite.ssrLoadModule('/src/features/student-affairs/pages/DisciplinaryCasesPage.jsx')
const { default: Profile } = await vite.ssrLoadModule('/src/features/student-affairs/pages/StudentProfilePage.jsx')
const actor = { roles: ['registration_officer'], permissions: ['students.view', 'students.manage'], access_scopes: [{ type: 'program', id: 1 }] }
const student = { student_id: 11, student_number: 'SYN-11', full_name: 'طالب اصطناعي', first_name: 'طالب', last_name: 'اصطناعي', program: {}, college: {}, department: {}, academic_level: {}, student_status: {} }
const record = { case_id: 1, student_id: 11, student, case_status: 'active', violation_description: 'وصف اصطناعي', violation_type: { violation_name_ar: 'مخالفة اصطناعية' }, penalty_type: { penalty_name_ar: 'إنذار تأديبي' }, decision_date: '2026-10-02', capabilities: { record_appeal: true }, appeals: [], affected_courses: [] }
let root, router, container, requests, mutate, read, rejectList = false
function stub() {
  requests = []; mutate = () => ({ success: true, data: { appeal_id: 4, case_id: 1 } }); read = null; rejectList = false
  globalThis.fetch = async (url, options = {}) => {
    const parsed = new URL(url); assert.equal(parsed.hostname, '127.0.0.1')
    requests.push({ path: parsed.pathname, query: parsed.searchParams, method: options.method || 'GET', body: options.body })
    if (options.method === 'POST') return { ok: true, json: async () => mutate() }
    if (read) { const value = await read(parsed); if (value !== undefined) return { ok: true, json: async () => ({ success: true, data: value }) } }
    let data
    if (parsed.pathname.endsWith('/options')) data = { students: [student], student, offerings: [], violation_types: [], penalty_types: [], capabilities: { create: true } }
    else if (parsed.pathname.endsWith('/disciplinary-cases/1')) data = record
    else if (parsed.pathname.endsWith('/disciplinary-cases')) {
      if (rejectList) return { ok: false, status: 403, json: async () => ({ message: 'Forbidden' }) }
      data = { data: [record], meta: { last_page: 3, total: 40 } }
    } else if (parsed.pathname.endsWith('/profile')) data = student
    else if (parsed.pathname.endsWith('/cgpa')) data = { cgpa: null }
    else data = { data: [] }
    return { ok: true, json: async () => ({ success: true, data }) }
  }
}
async function settle(ms = 35) { await act(async () => { await new Promise(resolve => setTimeout(resolve, ms)) }) }
async function mount(path, profile = false, user = actor) {
  stub(); localStorage.setItem('user', JSON.stringify(user))
  container = document.createElement('div'); document.body.appendChild(container); root = createRoot(container)
  router = createMemoryRouter([{ path: profile ? '/student-affairs/students/:id' : '*', element: createElement(profile ? Profile : Page) }, { path: '/other', element: createElement('p', null, 'وجهة أخرى') }], { initialEntries: [path] })
  await act(async () => root.render(createElement(RouterProvider, { router }))); await settle()
}
function button(text) { return allNodes(container).find(n => n.localName === 'button' && n.textContent === text) }
async function click(node) { assert.ok(node); await act(async () => node.dispatchEvent(new host.Event('click'))); await settle() }
afterEach(async () => { if (root) await act(async () => root.unmount()); router?.dispose(); container?.parentNode?.removeChild(container); localStorage.clear(); root = null })
after(async () => { await vite.close() })

for (const path of ['/student-affairs/disciplinary-cases', '/vp/administrative/student-affairs/disciplinary-cases']) test('list and details keep filters and page in '+path, async () => {
  await mount(path + '?discipline_page=2&discipline_status=active')
  assert.ok(container.textContent.includes('الإنذارات والعقوبات'))
  await click(button('عرض السجل'))
  assert.equal(router.state.location.pathname, path)
  assert.equal(new URLSearchParams(router.state.location.search).get('discipline_page'), '2')
  assert.ok(container.textContent.includes('وصف اصطناعي'))
  assert.equal(requests.filter(r => r.method !== 'GET').length, 0)
})
test('actual student profile has the discipline tab immediately after transcript and keeps its student target', async () => {
  await mount('/student-affairs/students/11?tab=discipline', true)
  const buttons = allNodes(container).filter(n => n.localName === 'button').map(n => n.textContent)
  assert.equal(buttons[buttons.indexOf('كشف الدرجات') + 1], 'الإنذارات والعقوبات')
  assert.ok(requests.some(r => r.path.endsWith('/disciplinary-cases') && r.query.get('student_id') === '11'))
  assert.equal(requests.filter(r => r.method !== 'GET').length, 0)
})
test('an explicit backend denial clears the list and removes mutation controls', async () => {
  await mount('/student-affairs/disciplinary-cases')
  rejectList = true
  await act(async () => router.navigate('/student-affairs/disciplinary-cases?discipline_page=2')); await settle()
  assert.ok(container.textContent.includes('غير مصرح'))
  assert.equal(button('تسجيل عقوبة'), undefined)
  assert.ok(!container.textContent.includes('وصف اصطناعي'))
})
test('malformed successful write retains editor and never announces success or automatically retries', async () => {
  await mount('/student-affairs/disciplinary-cases?disciplinary_case=1')
  await click(button('تسجيل طعن'))
  const textarea = allNodes(container).find(n => n.localName === 'textarea')
  await act(async () => { textarea.value = 'سبب اصطناعي'; textarea.dispatchEvent(new host.Event('input', { bubbles: true })) })
  mutate = () => null
  const form = allNodes(container).find(n => n.localName === 'form')
  await act(async () => { form.dispatchEvent(new host.Event('submit', { bubbles: true, cancelable: true })); form.dispatchEvent(new host.Event('submit', { bubbles: true, cancelable: true })) }); await settle()
  assert.equal(requests.filter(r => r.method === 'POST').length, 1)
  assert.ok(container.textContent.includes('تعذر التأكد من نتيجة الحفظ'))
  assert.ok(!container.textContent.includes('تم حفظ التسجيل'))
  assert.ok(button('التحقق بقراءة السجل'))
  await click(button('التحقق بقراءة السجل'))
  assert.equal(requests.filter(r => r.method === 'POST').length, 1)
})
