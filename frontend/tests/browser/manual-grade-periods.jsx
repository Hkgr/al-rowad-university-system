// Real React/router regressions with period-dependent synthetic responses. Never calls a live API.
import { createRoot } from 'react-dom/client'
import { createBrowserRouter, RouterProvider } from 'react-router-dom'
import StudentManualGradePage from '../../src/features/exam-board/pages/StudentManualGradePage'
import { storeIdentity } from '../../src/features/auth/auth'

const tick = () => new Promise(resolve => setTimeout(resolve, 25))
const assert = (condition, message) => { if (!condition) throw new Error(message) }
async function until(test, message) {
  for (let n = 0; n < 160; n++) { if (test()) return; await tick() }
  throw new Error(message)
}
const button = (text, scope = document) => [...scope.querySelectorAll('button')].find(b => b.textContent.includes(text))
const row = id => [...document.querySelectorAll('tbody > tr')].find(r => r.textContent.includes(`PERIOD-${id}`))
const field = id => row(id)?.querySelector('input[type="text"]')
const editable = id => field(id) && !field(id).matches(':disabled')
const modal = () => document.querySelector('dialog[open]')
const input = (element, value) => {
  Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(element, value)
  element.dispatchEvent(new Event('input', { bubbles: true }))
}
const select = (index, value) => {
  const element = document.querySelectorAll('main select')[index]
  Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set.call(element, value)
  element.dispatchEvent(new Event('change', { bubbles: true }))
}

export async function runPeriodRegressions(scenario) {
  const results = document.querySelector('#results'), run = document.querySelector('#run')
  const saved = { fetch: window.fetch, identity: localStorage.getItem('user'), url: location.href, state: history.state }
  const log = text => { results.textContent += `PASS ${text}\n` }
  results.textContent = `Running ${scenario}…\n`; run.disabled = true
  let root, router, hold = null
  const requests = [], writes = [], releases = []
  const student = { student_id: 1, name: 'Period Fixture', student_number: 'TEST', program: 'Fixture' }
  const record = (id, course, year, mark) => ({ registration_id: id, course_code: `PERIOD-${course}`, course_name: `Course ${course}`,
    academic_year: year, semester: 'First', section: id, registration_status: 'registered', revision: String(id).padStart(64, 'a'),
    parts: { theoretical: { status: 'draft', can_edit: true } },
    components: [{ grade_component_id: id, name: `Mark ${course}`, component_type: 'theoretical', max_mark: 60, mark }] })
  const records = { A: [record(1, 1, 'A', 41), record(2, 2, 'A', 42)], B: [record(22, 2, 'B', 52)] }
  const response = (data, status = 200) => new Response(JSON.stringify({ success: status === 200, data }), { status, headers: { 'Content-Type': 'application/json' } })
  const choose = async year => { select(0, year); await tick(); select(1, '1'); await tick() }
  const delayNext = () => { hold = {}; return hold }
  try {
    storeIdentity({ roles: ['exam_officer'], permissions: ['exams.manage', 'grades.manage', 'students.view'] })
    window.fetch = async (url, options = {}) => {
      const parsed = new URL(url, location.origin), path = parsed.pathname
      if (path.endsWith('/periods')) return response({ academic_years: [{ academic_year_id: 'A', year_name: 'A' }, { academic_year_id: 'B', year_name: 'B' }], semesters: [{ semester_id: 1, semester_name: 'First' }] })
      if (path.endsWith('/catalog')) {
        const year = parsed.searchParams.get('academic_year_id') || 'A'
        const items = JSON.parse(JSON.stringify(records[year]))
        const request = { year, signal: options.signal, query: parsed.search }; requests.push(request)
        // Capture at request time, ignore abort, and explicitly release in either order.
        if (hold) {
          const delayed = hold; hold = null; await new Promise(resolve => { delayed.release = resolve; releases.push(resolve) })
          if (delayed.fail) return response({}, 500)
        }
        return response({ student, courses: [1, 2].map(id => ({ course_id: id, course_code: `PERIOD-${id}`, course_name: `Course ${id}`, credit_hours: 3,
          offerings: items.filter(r => r.course_code === `PERIOD-${id}`).map(r => ({ course_offering_id: r.registration_id, academic_year_id: year, semester_id: 1,
            required_parts: ['theoretical'], registrations: [r] })) })), meta: { current_page: Number(parsed.searchParams.get('page')), last_page: 2, total: 4 } })
      }
      if (path.endsWith('/context-preview')) {
        assert(parsed.searchParams.get('academic_year_id') === 'B', 'preview bound to previous period')
        assert(!parsed.searchParams.has('registration_id'), 'old attempt leaked into preparation')
        return response({ academic_year: 'B', semester: 'First', program: 'Fixture', revision: 'b'.repeat(64), create_offering: true, create_registration: true, create_components: true,
          components: [{ key: 'new:theoretical', name: 'Mark 1', component_type: 'theoretical', max_mark: 60, mark: null }] })
      }
      if (path.endsWith('/marks') && options.method === 'PUT') {
        const id = Number(path.match(/registrations\/(\d+)\/marks/)[1]), payload = JSON.parse(options.body)
        writes.push({ id, payload }); assert(id === 22, 'save targeted a different period')
        const target = records.B[0]; target.components[0].mark = payload.components[0].mark; target.revision = 'c'.repeat(64)
        return response(target)
      }
      throw new Error(`Unexpected request ${options.method || 'GET'} ${path}`)
    }
    history.replaceState(null, '', '/exam-board/manual-grade-entry/students/1')
    router = createBrowserRouter([{ path: '/exam-board/manual-grade-entry/students/:studentId', element: <StudentManualGradePage /> }])
    root = createRoot(document.querySelector('#fixture')); root.render(<RouterProvider router={router} />)
    await until(() => document.querySelector('main select')?.options.length === 3, 'period choices missing')
    await choose('B'); await until(() => editable(1) && editable(2), 'B editors missing')
    assert(field(1).value === '' && field(2).value === '52', 'B must have a new context and its own saved registration')

    if (scenario === 'active') {
      const delayed = delayNext(); button('التالي').click()
      await until(() => delayed.release, 'page request missing')
      const request = requests.at(-1), count = requests.length
      button('إدخال العلامات').click(); await tick()
      assert(!request.signal.aborted, 'active mode cancelled the in-flight catalog')
      assert(requests.length === count && !modal(), 'active mode started a request or discard confirmation')
      delayed.release(); await until(() => editable(1) && document.querySelector('main').textContent.includes('صفحة 2 من 2'), 'active mode stuck loading or reset pagination')
      input(field(1), '29'); button('إدخال العلامات').click(); await tick()
      assert(!modal() && field(1).value === '29' && requests.length === count, 'active mode discarded or questioned a draft')
      log('REGRESSION active mode is a no-op during loading and dirty editing; pagination retained')
      const historyRequest = delayNext(); button('استعراض السجل — قراءة فقط').click()
      await until(modal, 'real mode transition lost draft protection'); button('تجاهل التغييرات', modal()).click()
      await until(() => historyRequest.release, 'confirmed mode transition did not load')
      const historySignal = requests.at(-1).signal
      button('استعراض السجل — قراءة فقط').click(); await tick()
      assert(!historySignal.aborted && !modal(), 'active history mode cancelled its request')
      historyRequest.release(); await until(() => field(1)?.value === '41', 'active history mode stuck loading')
      const failed = delayNext(); failed.fail = true; button('إدخال العلامات').click()
      await until(() => failed.release, 'failed transition not started'); failed.release()
      await until(() => button('إعادة التحميل مع الاحتفاظ بالمسودات'), 'catalog failure not presented')
      assert(!document.querySelector('main').textContent.includes('جاري تحميل الحالة الرسمية'), 'failure left loading active')
      button('إعادة التحميل مع الاحتفاظ بالمسودات').click(); await until(() => editable(1), 'failure retry did not recover')
      log('active history no-op, real discard confirmation, failed transition and explicit read retry')
    } else {
      const delayedA = delayNext(); button('استعراض السجل — قراءة فقط').click()
      await until(() => delayedA.release, 'history request missing')
      delayedA.release(); await until(() => field(1)?.value === '41', 'A history mark missing after B preparation editor')
      assert(field(1).matches(':disabled') && field(2).value === '42', 'A history is not read-only/correct')
      const delayedB = delayNext(); button('إدخال العلامات').click()
      await until(() => delayedB.release, 'B return request missing')
      assert(!editable(1), 'new-period editor initialized before matching catalog')
      delayedB.release(); await until(() => editable(1) && field(1).value === '' && field(2).value === '52', 'B preparation disappeared or inherited A')
      log('REGRESSION B preparation -> A history -> B preparation preserves distinct editor identities')
      // Reverse direction, including responses delivered after abort and out of order.
      button('استعراض السجل — قراءة فقط').click(); await until(() => field(1)?.value === '41', 'A reload missing')
      const obsoleteB = delayNext(); button('إدخال العلامات').click(); await until(() => obsoleteB.release, 'obsolete B not started')
      button('استعراض السجل — قراءة فقط').click(); await until(() => field(1)?.value === '41', 'new A not loaded')
      obsoleteB.release(); await tick(); await tick()
      assert(field(1)?.value === '41' && field(1).matches(':disabled'), 'late B replaced A history')
      assert(writes.length === 0, 'history wrote data')
      button('إدخال العلامات').click(); await until(() => editable(1) && field(2).value === '52', 'B return failed')
      // Period selector intent also invalidates a delayed old catalog, not only mode changes.
      const obsoleteA = delayNext(); await choose('A'); await until(() => obsoleteA.release, 'A period request missing')
      await choose('B'); await until(() => editable(1) && field(2).value === '52', 'B period request missing')
      obsoleteA.release(); await tick(); await tick()
      assert(field(1)?.value === '' && field(2).value === '52', 'late A replaced selected B')
      input(field(1), '29'); input(field(2), '53')
      row(2).querySelector('input[type="checkbox"]').click(); button('حفظ العلامات', row(2)).click()
      await until(modal, 'correction dialog missing')
      const reason = modal().querySelector('textarea')
      Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value').set.call(reason, 'Period fixture correction')
      reason.dispatchEvent(new Event('input', { bubbles: true })); await tick(); button('تأكيد', modal()).click()
      await until(() => writes.length === 1 && !modal() && editable(1) && editable(2), 'save/reload did not finish')
      assert(writes[0].id === 22 && field(1).value === '29' && field(2).value === '53', 'same-context refresh lost unrelated draft or saved wrong attempt')
      log('reverse/delayed catalog, read-only history, B-only save and unrelated draft retention')
    }
    results.textContent += 'Period component fixtures completed; synthetic fetch, not Laravel integration.\n'
    if (new URL(saved.url).searchParams.has('snapshot')) await new Promise(() => {})
  } catch (error) { results.textContent += `FAIL ${error.stack || error.message}\n` }
  finally {
    releases.forEach(release => release()); root?.unmount(); router?.dispose(); window.fetch = saved.fetch
    if (saved.identity === null) localStorage.removeItem('user'); else localStorage.setItem('user', saved.identity)
    history.replaceState(saved.state, '', saved.url); run.disabled = false
  }
}
