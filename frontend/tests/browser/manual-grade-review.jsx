// Dependency-free harness: uses the application's existing React/Router/Vite, not a new test framework.
// Open /tests/browser/manual-grade-review.html on a local Vite server and click Run.
import { createRoot } from 'react-dom/client'
import { createBrowserRouter, NavLink, RouterProvider } from 'react-router-dom'
import ManualGradeEntryPage from '../../src/features/exam-board/pages/ManualGradeEntryPage'
import StudentManualGradePage from '../../src/features/exam-board/pages/StudentManualGradePage'
import { storeIdentity } from '../../src/features/auth/auth'
import '../../src/styles/global.css'
import { runPeriodRegressions } from './manual-grade-periods'

const rootPath = '/tests/browser/manual-grade-review.html'
const gridPath = '/exam-board/manual-grade-entry/students/1'
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
const editor = id => [...document.querySelectorAll('tbody > tr')].find(e => e.textContent.includes(`FIXTURE-${id}`))
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
  const scenario = new URLSearchParams(location.search).get('scenario')
  if (scenario) return runPeriodRegressions(scenario)
  const run = document.querySelector('#run'); run.disabled = true
  const results = document.querySelector('#results'); results.textContent = 'Running component/router fixtures…\n'
  const log = name => { results.textContent += `PASS ${name}\n` }
  const previousFetch = window.fetch
  const previousIdentity = localStorage.getItem('user')
  const previousUrl = location.href
  const previousHistory = history.state
  let router, root, releaseWrite
  let conflictNext = false, holdNext = false, writes = 0
  let searchHold = null, catalogOverflow = true
  const unprepared = new Set([3, 4])
  let loseContextReply = true
  const searches = []
  const terms = [{ academic_year_id: 1, year_name: '2026', semester_id: 1, semester_name: 'First' }]
  const rows = [makeRow(1), makeRow(2)]
  const student = { student_id: 1, name: 'Fixture Student', student_number: 'FIXTURE', college: 'Fixture', program: 'Fixture' }
  const meta = { current_page: 1, last_page: 1, total: 2 }
  const response = (data, status = 200) => new Response(JSON.stringify({ success: status === 200, data }), { status, headers: { 'Content-Type': 'application/json' } })
  try {
    storeIdentity({ roles: ['exam_officer'], permissions: ['exams.manage', 'grades.manage', 'students.view'] })
    window.fetch = async (url, options = {}) => {
      // No call to the real fetch: even an unexpected request fails locally.
      const parsed = new URL(url, location.origin)
      const path = parsed.pathname
      if (path.endsWith('/students')) {
        searches.push(parsed.searchParams.get('q'))
        // Deliberately ignore abort to prove generation guards also reject late responses.
        if (searchHold) { const hold = searchHold; searchHold = null; await new Promise(resolve => { hold.release = resolve }) }
        return response({ students: [student], meta })
      }
      if (path.endsWith('/periods')) return response({ terms, academic_years: terms, semesters: terms })
      if (path.endsWith('/catalog')) {
        if (catalogOverflow && !parsed.searchParams.get('academic_year_id')) return response({}, 422)
        catalogOverflow = false
        return response({ student, terms, courses: clone(rows).map(r => ({
        course_id: r.registration_id, course_code: r.course_code, course_name: r.course_name, credit_hours: 3, own_program: true,
        offerings: [{ course_offering_id: r.registration_id, academic_year_id: 1, semester_id: 1, academic_year: '2026', semester: 'First', required_parts: ['theoretical'], registrations: [r] }],
      })).concat([...unprepared].map(id => ({ course_id: id, course_code: `FIXTURE-${id}`, course_name: `Course ${id}`, credit_hours: 3, own_program: true, offerings: [] }))), meta })
      }
      const context = path.match(/courses\/(3|4)\/context-(preview|save)$/)
      if (context) {
        const id = Number(context[1]), missing = unprepared.has(id)
        const current = rows.find(r => r.registration_id === id)
        const preview = { academic_year: '2026', semester: 'First', program: 'Fixture', revision: missing ? 'a'.repeat(64) : current.revision,
          create_offering: missing, create_registration: missing, create_components: missing,
          components: [{ key: missing ? 'new:theoretical' : String(id), component_type: 'theoretical', name: `Mark ${id}`, max_mark: 60, mark: missing ? null : current.components[0].mark }] }
        if (context[2] === 'preview') return response(preview)
        assert(options.method === 'POST', 'context save method')
        const payload = JSON.parse(options.body)
        assert(payload.confirmed && payload.acknowledged && payload.reason.trim(), 'unconfirmed context write')
        assert(payload.revision === preview.revision, 'stale preparation was silently retried')
        const row = current ?? makeRow(id)
        row.components[0].mark = payload.components[0].mark; row.revision = 'd'.repeat(64)
        if (missing) rows.push(row)
        unprepared.delete(id)
        if (id === 4 && loseContextReply) { loseContextReply = false; throw new TypeError('Fixture lost reply after commit') }
        return response(clone(row))
      }
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
      { path: '/exam-board/manual-grade-entry/students/:studentId', element: <><aside><NavLink to={destination}>Sidebar destination</NavLink></aside><StudentManualGradePage /></> },
      { path: destination, element: <h1>Destination</h1> },
      { path: '/exam-board/approvals', element: <h1>Approvals destination</h1> },
    ])
    root = createRoot(document.querySelector('#fixture')); root.render(<RouterProvider router={router} />)
    const loadStudent = async () => {
      await until(() => document.querySelector('main h1')?.textContent === 'إدخال العلامات اليدوي' && document.querySelector('main input'), 'search did not mount')
      input(document.querySelector('main input'), 'Fixture')
      await tick()
      await until(() => [...document.querySelectorAll('main a')].find(a => a.textContent.includes('Fixture Student')), 'student lookup failed')
      ;[...document.querySelectorAll('main a')].find(a => a.textContent.includes('Fixture Student')).click()
      await choosePeriod()
    }
    const choosePeriod = async () => {
      await until(() => document.querySelector('main select')?.options.length === 2, 'independent period choices missing')
      assert(!document.querySelector('tbody'), 'recording grid must wait for an explicit marks period')
      const year = document.querySelector('main select')
      assert(year.value === '', 'marks year was implicitly selected')
      Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set.call(year, '1')
      year.dispatchEvent(new Event('change', { bubbles: true }))
      await tick()
      const semester = document.querySelectorAll('main select')[1]
      assert(semester.value === '', 'marks semester was implicitly selected')
      Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set.call(semester, '1')
      semester.dispatchEvent(new Event('change', { bubbles: true }))
      await until(() => editable(1), 'explicit marks period did not recover catalog')
      log('marks period selected explicitly; independent choices recover catalog overflow')
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
      assert(location.pathname === gridPath, 'cancel left the page')
    }
    await until(() => document.querySelector('main input'), 'search did not mount')
    const searchInput = document.querySelector('main input')
    const resultLink = () => [...document.querySelectorAll('main a')].find(a => a.textContent.includes('Fixture Student'))
    input(searchInput, 'Fixture')
    await until(resultLink, 'initial search failed')
    const requestCount = searches.length
    input(searchInput, '  Fixture  '); await tick()
    assert(resultLink(), 'whitespace cleared existing results')
    input(searchInput, 'Fixture'); await tick()
    assert(resultLink() && searches.length === requestCount, 'normalized edit refetched or cleared results')
    log('whitespace-only edits retain applied results')

    const clearing = {}; searchHold = clearing
    input(searchInput, 'Held clear')
    await until(() => clearing.release, 'clear fixture request not in flight')
    input(searchInput, '')
    await tick()
    assert(!resultLink() && !document.querySelector('main [role="status"]') && !document.querySelector('main [role="alert"]'), 'clear retained results/error/loading')
    clearing.release(); await tick()
    assert(!resultLink(), 'cleared search was repopulated by delayed response')
    log('clearing in-flight search resets state and rejects late responses')

    const delayed = {}; searchHold = delayed
    input(searchInput, 'Old intent')
    await until(() => delayed.release, 'debounce fixture request not in flight')
    input(searchInput, 'New intent')
    delayed.release(); await tick()
    assert(!resultLink(), 'obsolete response populated results during debounce')
    await until(resultLink, 'new intent did not complete')
    assert(searches.at(-1) === 'New intent', 'applied query not associated with new results')
    log('intent invalidates old read immediately, before the next debounce completes')
    await loadStudent()
    await until(() => editable(3) && editable(4), 'unregistered draft cells did not resolve official limits')
    button('استعراض السجل — قراءة فقط').click()
    await until(() => document.querySelector('main select')?.value === '' && field(1)?.matches(':disabled'), 'history filters did not remain separate/read-only')
    button('إدخال العلامات').click()
    await until(() => editable(3) && editable(4), 'recording period was lost on history browse')
    assert(document.querySelector('main select').value === '1' && document.querySelectorAll('main select')[1].value === '1', 'history mutated the marks period')
    log('all-year history is read-only and does not replace the explicitly selected marks period')
    input(field(1), '24'); input(field(3), '28')
    const confirmContext = async id => {
      button('مراجعة وحفظ العلامات', editor(id)).click()
      await until(modal, 'integrated confirmation missing')
      const reason = modal().querySelector('textarea')
      Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value').set.call(reason, 'Fixture exceptional recording')
      reason.dispatchEvent(new Event('input', { bubbles: true }))
      modal().querySelector('input[type="checkbox"]').click()
      await until(() => button('تأكيد', modal()) && !button('تأكيد', modal()).disabled, 'context acknowledgment not accepted')
      if (id === 3 && new URLSearchParams(previousUrl.split('?')[1]).has('snapshot')) await new Promise(() => {})
      button('تأكيد', modal()).click()
    }
    await confirmContext(3)
    await until(() => !modal() && !unprepared.has(3) && editor(3)?.querySelector('input[type="checkbox"]') && editable(1), 'integrated save did not create the normal draft row')
    assert(field(1).value === '24' && location.pathname === gridPath, 'context save lost another draft or navigated away')
    log('no offering/registration: draft cells -> one confirmation -> normal grade row; unrelated draft retained')
    input(field(4), '29'); await confirmContext(4)
    await until(() => button('إبقاء المقترحات وإعادة المراجعة', editor(4)), 'uncertain context save did not reconcile')
    assert(field(4).value === '29' && !editable(4), 'uncertain save lost draft or silently unlocked it')
    assert(rows.filter(r => r.registration_id === 4).length === 1, 'lost reply duplicated context')
    button('إبقاء المقترحات وإعادة المراجعة', editor(4)).click()
    await until(() => editable(4), 'explicit context rebase failed')
    assert(field(4).value === '29', 'explicit rebase lost proposed mark')
    // Clear this fixture draft explicitly so subsequent router tests remain focused on row 1.
    input(field(4), '30'); await confirmContext(4)
    await until(() => !modal() && editor(4)?.querySelector('input[type="checkbox"]'), 'explicit retry did not finish')
    log('uncertain context response preserves proposals, reads committed state and requires explicit review; no duplicate')
    input(field(1), '25')
    document.querySelector('aside a').click()
    await cancelNavigation()
    assert(field(1).value === '25' && editable(1), 'sidebar cancellation lost or locked draft')
    holdNext = true
    await save(1)
    await until(() => releaseWrite, 'write was not held')
    button('استعراض السجل — قراءة فقط').click(); await tick()
    assert(button('إدخال العلامات').getAttribute('aria-pressed') === 'true' && field(1).value === '25', 'mode changed during pending write')
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
    await until(() => location.pathname === destination && document.querySelector('#fixture h1')?.textContent === 'Destination' && !modal(), 'confirmed destination was lost')
    await router.navigate(rootPath)
    await loadStudent(); input(field(1), '27')
    history.back()
    await cancelNavigation()
    assert(field(1).value === '27', 'back cancellation lost draft')
    log('browser back is blocked and cancellation retains drafts')
    document.querySelector('aside a').click()
    await until(modal, 'confirm before forward fixture missing')
    button('تجاهل المسودات', modal()).click()
    await until(() => location.pathname === destination && document.querySelector('#fixture h1')?.textContent === 'Destination' && !modal(), 'confirmed discard did not navigate')
    history.back()
    await until(() => location.pathname === gridPath, 'browser back to manual failed')
    await choosePeriod()
    await until(() => editable(2), 'direct grid reload failed'); input(field(2), '31')
    history.forward()
    await cancelNavigation()
    assert(field(2).value === '31', 'forward cancellation lost draft')
    log('browser forward is blocked and cancellation retains drafts')

    // Authority loss clears the page even with a dirty form and a blocked navigation.
    document.querySelector('aside a').click(); await until(modal, 'authorization fixture blocker missing')
    storeIdentity({ roles: [], permissions: [] }); window.dispatchEvent(new Event('storage'))
    await until(() => !document.querySelector('tbody > tr'), 'authorization loss retained sensitive state')
    log('authorization loss bypasses draft confirmation and clears sensitive state')
    results.textContent += 'Component/router fixtures completed. Laravel integration is NOT exercised.\n'
  } catch (error) {
    results.textContent += `FAIL ${error.stack ?? error.message}\n`
  } finally {
    releaseWrite?.(); root?.unmount(); router?.dispose(); window.fetch = previousFetch
    if (previousIdentity === null) localStorage.removeItem('user'); else localStorage.setItem('user', previousIdentity)
    history.replaceState(previousHistory, '', previousUrl)
    run.disabled = false
  }
}

// Headless local verification only; never contacts a real API.
if (new URLSearchParams(location.search).has('autorun')) document.querySelector('#run').click()
