// Dependency-free harness: uses the application's existing React/Router/Vite, not a new test framework.
// Open /tests/browser/manual-grade-review.html on a local Vite server and click Run.
import { createRoot } from 'react-dom/client'
import { createBrowserRouter, NavLink, RouterProvider } from 'react-router-dom'
import ManualGradeEntryPage from '../../src/features/exam-board/pages/ManualGradeEntryPage'
import { storeIdentity } from '../../src/features/auth/auth'

const rootPath = '/tests/browser/manual-grade-review.html'
const destination = '/tests/browser/destination'
const assert = (condition, message) => { if (!condition) throw new Error(message) }
const tick = () => new Promise(resolve => setTimeout(resolve, 25))
async function until(predicate, message) {
  for (let i = 0; i < 160; i++) { if (predicate()) return; await tick() }
  throw new Error(message)
}
const input = (element, value) => {
  Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(element, value)
  element.dispatchEvent(new Event('input', { bubbles: true }))
}
const button = (text, scope = document) => [...scope.querySelectorAll('button')].find(b => b.textContent.includes(text))
const editor = id => [...document.querySelectorAll('article')].find(e => e.textContent.includes(`FIXTURE-${id}`))
const field = id => editor(id)?.querySelector('input[type="text"]')
const modal = () => document.querySelector('dialog[open]')
const editable = id => field(id) && !field(id).matches(':disabled')
const clone = value => JSON.parse(JSON.stringify(value))
const makeRow = id => ({ registration_id: id, course_code: `FIXTURE-${id}`, course_name: `Course ${id}`,
  revision: 'a'.repeat(64), registration_status: 'registered', section: id,
  academic_year: '2026', semester: '1', parts: { theoretical: { status: 'draft', can_edit: true, can_check_submission: true } },
  components: [{ grade_component_id: id, component_type: 'theoretical', name: `Mark ${id}`, mark: null, max_mark: 60 }] })

document.querySelector('#run').onclick = async () => {
  if (!['localhost', '127.0.0.1', '[::1]'].includes(location.hostname)) throw new Error('Local fixtures only')
  const run = document.querySelector('#run'); run.disabled = true
  const results = document.querySelector('#results'); results.textContent = 'Running component/router fixtures…\n'
  const log = name => { results.textContent += `PASS ${name}\n` }
  const previousFetch = window.fetch
  const previousIdentity = localStorage.getItem('user')
  const previousUrl = location.href
  const previousHistory = history.state
  let router, root, releaseWrite
  let conflictNext = false, holdNext = false, lookupFailure = false, writes = 0
  const rows = [makeRow(1), makeRow(2)]
  const student = { student_id: 1, name: 'Fixture Student', student_number: 'FIXTURE', college: 'Fixture', program: 'Fixture' }
  const meta = { current_page: 1, last_page: 1, total: 2 }
  const response = (data, status = 200) => new Response(JSON.stringify({ success: status === 200, data }), { status, headers: { 'Content-Type': 'application/json' } })
  try {
    storeIdentity({ roles: ['exam_officer'], permissions: ['exams.manage', 'grades.manage', 'students.view'] })
    window.fetch = async (url, options = {}) => {
      // No call to the real fetch: even an unexpected request fails locally.
      const path = new URL(url, location.origin).pathname
      if (path.endsWith('/students')) return response({ students: [student], meta }, lookupFailure ? 500 : 200)
      if (path.endsWith('/registrations')) return response({ student, terms: [], registrations: clone(rows), meta })
      if (path.endsWith('/marks') && options.method === 'PUT') {
        writes++
        const id = Number(path.match(/registrations\/(\d+)\/marks/)[1])
        const row = rows.find(r => r.registration_id === id)
        const payload = JSON.parse(options.body)
        if (holdNext) { holdNext = false; await new Promise(resolve => { releaseWrite = resolve }) }
        if (conflictNext) {
          conflictNext = false; row.revision = 'c'.repeat(64); row.components[0].mark = 12
          return response({}, 409)
        }
        assert(payload.revision === row.revision, 'write silently reused a stale baseline')
        row.components[0].mark = payload.components[0].mark; row.revision = String(writes).padStart(64, '0')
        return response(clone(row))
      }
      throw new Error(`Unexpected fixture request: ${path}`)
    }
    router = createBrowserRouter([
      { path: rootPath, element: <><aside><NavLink to={destination}>Sidebar destination</NavLink></aside><ManualGradeEntryPage /></> },
      { path: destination, element: <h1>Destination</h1> },
      { path: '/exam-board/approvals', element: <h1>Approvals destination</h1> },
    ])
    root = createRoot(document.querySelector('#fixture')); root.render(<RouterProvider router={router} />)
    const loadStudent = async () => {
      await until(() => document.querySelector('main input'), 'search did not mount')
      input(document.querySelector('main input'), 'Fixture')
      await until(() => button('Fixture Student'), 'student lookup failed')
      button('Fixture Student').click()
      await until(() => editable(1), 'editors did not load')
    }
    const save = async id => {
      editor(id).querySelector('input[type="checkbox"]').click()
      await until(() => !button('حفظ العلامات', editor(id)).disabled, 'save remained disabled')
      button('حفظ العلامات', editor(id)).click()
    }
    const cancelNavigation = async () => {
      await until(modal, 'navigation was not blocked')
      button('إلغاء', modal()).click()
      await until(() => !modal(), 'cancellation did not close dialog')
      assert(location.pathname === rootPath, 'cancel left the page')
    }
    await loadStudent()
    input(field(1), '25')
    document.querySelector('aside a').click()
    await cancelNavigation()
    assert(field(1).value === '25' && editable(1), 'sidebar cancellation lost or locked draft')
    holdNext = true
    await save(1)
    await until(() => releaseWrite, 'write was not held')
    document.querySelector('aside a').click()
    await until(modal, 'pending write navigation was not blocked')
    assert(button('تجاهل المسودات', modal()).disabled, 'pending write allowed discard')
    await cancelNavigation()
    releaseWrite(); releaseWrite = null
    await until(() => rows[0].components[0].mark === 25 && editable(1), 'save after cancellation failed')
    log('sidebar cancellation preserves draft and permits save; pending writes prevent departure')

    input(field(1), '26'); input(field(2), '30')
    await save(2)
    await until(() => rows[1].components[0].mark === 30 && editable(1), 'B save refresh failed')
    assert(field(1).value === '26', 'B refresh discarded A')
    log('saving B refreshes without discarding A')

    lookupFailure = true
    input(document.querySelector('main input'), 'Failure')
    await until(() => document.body.textContent.includes('تعذر البحث'), 'lookup failure not shown')
    assert(editable(1), 'lookup error locked unrelated editing')
    lookupFailure = false
    conflictNext = true
    await save(1)
    await until(modal, 'correction confirmation missing')
    const reason = modal().querySelector('textarea')
    Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value').set.call(reason, 'Fixture correction')
    reason.dispatchEvent(new Event('input', { bubbles: true }))
    await until(() => !button('تأكيد', modal()).disabled, 'correction reason not accepted')
    button('تأكيد', modal()).click()
    await until(() => button('إبقاء المقترحات') && !button('إبقاء المقترحات').disabled, '409 conflict not loaded')
    assert(field(1).value === '26' && !editable(1), '409 discarded proposal or allowed silent retry')
    const attempts = writes
    button('إبقاء المقترحات').click()
    await until(() => editable(1), 'explicit rebase did not unlock draft')
    assert(field(1).value === '26' && writes === attempts, 'rebase changed proposal or automatically wrote')
    assert(!editor(1).querySelector('input[type="checkbox"]').checked, 'rebase retained old acknowledgment')
    log('409 preserves baseline/proposal/server until explicit rebase; no retry')

    // Build real browser history on each side of the manual page; test POP, not just Link clicks.
    document.querySelector('aside a').click()
    await until(modal, 'discard dialog missing')
    button('تجاهل المسودات', modal()).click()
    await until(() => location.pathname === destination, 'confirmed destination was lost')
    await router.navigate(rootPath)
    await loadStudent(); input(field(1), '27')
    history.back()
    await cancelNavigation()
    assert(field(1).value === '27', 'back cancellation lost draft')
    log('browser back is blocked and cancellation retains drafts')
    document.querySelector('aside a').click()
    await until(modal, 'confirm before forward fixture missing')
    button('تجاهل المسودات', modal()).click()
    await until(() => location.pathname === destination, 'confirmed discard did not navigate')
    history.back()
    await until(() => location.pathname === rootPath, 'browser back to manual failed')
    await loadStudent(); input(field(2), '31')
    history.forward()
    await cancelNavigation()
    assert(field(2).value === '31', 'forward cancellation lost draft')
    log('browser forward is blocked and cancellation retains drafts')

    // Authority loss clears the page even with a dirty form and a blocked navigation.
    document.querySelector('aside a').click(); await until(modal, 'authorization fixture blocker missing')
    storeIdentity({ roles: [], permissions: [] }); window.dispatchEvent(new Event('storage'))
    await until(() => !document.querySelector('article'), 'authorization loss retained sensitive state')
    log('authorization loss bypasses draft confirmation and clears sensitive state')
    results.textContent += 'Component/router fixtures completed. Laravel integration is NOT exercised.\n'
  } catch (error) {
    results.textContent += `FAIL ${error.message}\n`
  } finally {
    releaseWrite?.(); root?.unmount(); router?.dispose(); window.fetch = previousFetch
    if (previousIdentity === null) localStorage.removeItem('user'); else localStorage.setItem('user', previousIdentity)
    history.replaceState(previousHistory, '', previousUrl)
    run.disabled = false
  }
}
