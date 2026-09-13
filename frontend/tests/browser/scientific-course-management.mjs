// Built React + synthetic API, or explicitly selected isolated loopback Laravel fixture.
// Existing Node >=22 and Chrome only; no test dependencies. See docs for launch commands.
import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { join } from 'node:path'

const origin = process.env.CATALOG_PREVIEW_URL || 'http://127.0.0.1:4173'
const debug = process.env.CATALOG_DEBUG_URL || 'http://127.0.0.1:9223'
const output = process.env.CATALOG_TEST_OUTPUT
const live = process.env.CATALOG_LARAVEL_URL
const references = process.env.CATALOG_REFERENCE_URL
if (references) assert.ok(['127.0.0.1', 'localhost'].includes(new URL(references).hostname))
if (live) assert.ok(['127.0.0.1', 'localhost'].includes(new URL(live).hostname))
for (const url of [origin, debug]) assert.ok(['127.0.0.1', 'localhost'].includes(new URL(url).hostname))
assert.ok(output, 'Set CATALOG_TEST_OUTPUT to a temporary directory')
await mkdir(output, { recursive: true })
const target = (await (await fetch(`${debug}/json/list`)).json()).find(t => t.type === 'page' && t.url === 'about:blank')
assert.ok(target, 'Use a fresh temporary Chrome profile with about:blank')
const ws = new WebSocket(target.webSocketDebuggerUrl)
await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject })
const pending = new Map(), failures = [], writes = [], reads = [], evidence = []
let serial = 0, revision = 10, failNext = null, writeDelay = 0, denyReads = false
function send(method, params = {}) { return new Promise((resolve, reject) => { const id = ++serial; const timer = setTimeout(() => reject(new Error(`CDP timeout: ${method}`)), 10000); pending.set(id, { resolve, reject, timer }); ws.send(JSON.stringify({ id, method, params })) }) }
const sleep = ms => new Promise(r => setTimeout(r, ms))
const identity = { user_id: 991, username: 'حساب اختبار محلي', roles: ['vice_president_scientific'], permissions: ['vice_presidency.scientific.access', 'vice_presidency.scientific.courses.view', 'vice_presidency.scientific.courses.manage'], access_scopes: [{ type: 'university', id: 1 }] }
if (references) identity.permissions.push('exams.view', 'exams.manage', 'courses.view', 'courses.manage', 'academic_structure.view', 'system_settings.view', 'course_offerings.semester_governance.view')
const scopes = ['university', 'college', 'department']
const groups = scopes.flatMap((scope, i) => ['mandatory', 'elective'].map((type, j) => ({ requirement_group_id: 2 * i + j + 1, group_code: `G${i}${j}`, group_name: `${['متطلبات الجامعة', 'متطلبات الكلية', 'متطلبات القسم'][i]} ${j ? 'الاختيارية' : 'الإجبارية'}`, requirement_scope: scope, requirement_type: type, required_credit_hours: 3, available_credit_hours: 6, available_minus_required_hours: 3, course_count: 2, is_active: true })))
const courses = [1, 2, 3].map(id => ({ course_id: id, course_code: `C${id}`, course_name: ['علوم الحاسوب', 'مادة تاريخية', 'أصل مستقل'][id - 1], description: 'وصف اختباري', credit_hours: 3, theoretical_hours: 2, practical_hours: 1, is_active: true, course_departments: [{ department_id: 1, is_primary: true, department: { department_name: 'قسم الحاسوب' } }], course_prerequisites: [], program_courses: id === 3 ? [] : [{ program_course_id: id, academic_program_id: id, course_type: 'mandatory', is_active: true, academic_level_id: 1, recommended_semester_id: 1, academic_program: { program_name: id === 1 ? 'برنامج جديد' : 'برنامج مستخدم' }, academic_level: { level_name: 'المستوى الأول' }, recommended_semester: { semester_name: 'الفصل الأول' }, requirement_classification: { requirement_scope: 'university', requirement_type: 'mandatory' }, requirement_mapping: { requirement_group_id: 1 } }] }))
const meta = data => ({ current_page: 1, last_page: 1, per_page: 20, total: data.length })
const snapshot = id => ({ data: courses.find(c => c.course_id === id), revision: String(revision), capabilities: { edit_text: true, edit_academic: id !== 2, edit_relationships: id !== 2, delete: id === 3, academic_lock_reason: id === 2 ? 'البرنامج مستخدم؛ الرمز والساعات مقفلة والتصحيح النصي متاح.' : null, delete_reasons: id === 3 ? [] : ['مرتبط ببرنامج'] }, impact: { shared: false, linked_program_count: id === 3 ? 0 : 1 } })
const program = id => ({ data: { academic_program_id: id, program_name: id === 1 ? 'برنامج جديد' : 'برنامج مستخدم', total_credit_hours: 18 }, groups, configuration: { available: true }, revision: String(revision), capabilities: { edit_curriculum: id === 1, lock_reason: id === 2 ? 'البرنامج مستخدم أكاديميًا؛ ميزانية المتطلبات مقفلة.' : null } })
async function fixture(url, request) {
  if (url.pathname === '/api/user') return { data: identity }
  if (references && !url.pathname.includes('/course-management/')) {
    assert.equal(request.method, 'GET', 'Reference pages must not write')
    if (url.pathname.endsWith('/semester-offerings')) return { data: { data: [{ semester_offering_request_id: 1, course_type: 'mandatory', status: 'submitted', course_offering: { course: { course_code: 'C1', course_name: 'علوم الحاسوب' }, academic_program: { program_name: 'برنامج جديد' }, college: { college_name: 'كلية الهندسة' }, academic_year: { year_name: '2026 / 2027' }, semester: { semester_name: 'الأول' } } }], last_page: 1 } }
    const rows = { courses, colleges: [{ college_id: 1, college_name: 'كلية الهندسة' }], departments: [{ department_id: 1, college_id: 1, department_name: 'قسم الحاسوب' }], 'course-departments': courses.map(c => ({ course_department_id: c.course_id, course_id: c.course_id, department_id: 1, is_primary: true })) }[url.pathname.split('/').at(-1)] || []
    return { data: { data: rows, meta: meta(rows) } }
  }
  if (live) {
    assert.ok(url.pathname.startsWith('/api/v1/vice-presidency/scientific/course-management/'))
    const body = request.method === 'GET' ? undefined : request.postData
    if (body) writes.push({ path: url.pathname, method: request.method, body: JSON.parse(body) })
    else reads.push(url.pathname + url.search)
    const response = await fetch(live + url.pathname + url.search, { method: request.method, body, headers: { Accept: 'application/json', 'Content-Type': 'application/json' } })
    const payload = await response.json()
    return response.ok ? { data: payload.data } : { code: response.status, error: payload }
  }
  const path = url.pathname.split('/course-management')[1]
  if (!path) return { data: [] }
  if (request.method !== 'GET') {
    const body = JSON.parse(request.postData || '{}'); writes.push({ path, method: request.method, body })
    await sleep(writeDelay)
    if (failNext === 'network') { failNext = null; revision++; return { network: true } }
    if (failNext === 'stale' || String(body.revision) !== String(revision)) { failNext = null; revision++; return { code: 409, error: { message: 'تغير الدليل', error_code: 'academic_catalog_stale' } } }
    if (body.course_code === 'DUP') return { code: 422, error: { message: 'رمز مكرر', errors: { course_code: ['رمز المادة مستخدم بالفعل'] } } }
    revision++
    if (path.match(/^\/courses\/\d+$/)) {
      const id = Number(path.split('/')[2]), course = courses.find(c => c.course_id === id)
      if (request.method === 'DELETE') { courses.splice(courses.indexOf(course), 1); return { data: { deleted: true, revision: String(revision) } } }
      for (const field of ['course_name', 'course_code', 'description', 'credit_hours', 'is_active']) if (field in body) course[field] = body[field]
      return { data: snapshot(id) }
    }
    if (path === '/courses') { const id = 4; courses.push({ ...courses[0], ...body, course_id: id, program_courses: [] }); return { data: snapshot(id) } }
    return { data: program(1) }
  }
  reads.push(path + url.search)
  if (denyReads) return { code: 403, error: { message: 'تم سحب صلاحية قراءة الدليل' } }
  if (path === '/options') {
    const all = { colleges: [{ id: 1, label: 'كلية الهندسة' }], departments: [{ id: 1, label: 'قسم الحاسوب' }], programs: [{ id: 1, label: 'برنامج جديد' }, { id: 2, label: 'برنامج مستخدم' }], courses: courses.map(c => ({ id: c.course_id, label: c.course_name })), levels: [{ id: 1, label: 'المستوى الأول' }], semesters: [{ id: 1, label: 'الفصل الأول' }], result_statuses: [{ id: 1, label: 'ناجح' }] }[url.searchParams.get('resource')] || []
    return { data: { data: all, meta: meta(all), revision: String(revision) } }
  }
  if (/^\/courses\/\d+$/.test(path)) return { data: snapshot(Number(path.split('/')[2])) }
  if (/^\/programs\/\d+$/.test(path)) return { data: program(Number(path.split('/')[2])) }
  if (path === '/courses') {
    const q = url.searchParams.get('q'), pid = url.searchParams.get('academic_program_id')
    const selected = courses.filter(c => (!q || c.course_name.includes(q) || c.course_code.includes(q)) && (!pid || c.program_courses.some(p => String(p.academic_program_id) === pid)))
    const data = { data: structuredClone(selected), meta: meta(selected), revision: String(revision), summary: { catalog_count: selected.length, groups: [], hours_context: 'not_a_graduation_total' }, can_manage: true, can_create: true }
    if (q === 'C1') await sleep(1000)
    return { data }
  }
  throw new Error(`Unexpected fixture path ${path}`)
}
ws.onmessage = async event => {
  const message = JSON.parse(event.data)
  if (message.id) { const p = pending.get(message.id); if (!p) return; pending.delete(message.id); clearTimeout(p.timer); if (message.error) p.reject(new Error(JSON.stringify(message.error))); else p.resolve(message.result); return }
  if (message.method === 'Runtime.exceptionThrown') failures.push(message.params.exceptionDetails.text + JSON.stringify(message.params.exceptionDetails.exception))
  if (message.method !== 'Fetch.requestPaused') return
  const { requestId, request } = message.params, url = new URL(request.url)
  try {
    if (url.pathname.startsWith('/api/')) {
      const headers = [{ name: 'Access-Control-Allow-Origin', value: origin }, { name: 'Access-Control-Allow-Methods', value: 'GET,POST,PUT,DELETE,OPTIONS' }, { name: 'Access-Control-Allow-Headers', value: 'authorization,content-type,accept' }, { name: 'Content-Type', value: 'application/json' }]
      if (request.method === 'OPTIONS') { await send('Fetch.fulfillRequest', { requestId, responseCode: 204, responseHeaders: headers }); return }
      const response = await fixture(url, request)
      if (response.network) { await send('Fetch.failRequest', { requestId, errorReason: 'ConnectionClosed' }); return }
      await send('Fetch.fulfillRequest', { requestId, responseCode: response.code || 200, responseHeaders: headers, body: Buffer.from(JSON.stringify(response.error || { success: true, data: response.data })).toString('base64') })
    } else if (url.origin !== origin && url.origin !== references) await send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' })
    else await send('Fetch.continueRequest', { requestId })
  } catch (error) { if (!error.message.includes('Invalid InterceptionId')) failures.push(error.message); await send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' }).catch(() => {}) }
}
async function evaluate(expression) { const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }); assert.ok(!r.exceptionDetails, JSON.stringify(r.exceptionDetails)); return r.result.value }
async function wait(expression) { for (let n = 0; n < 100; n++) { if (await evaluate(`Boolean(${expression})`)) return; await sleep(100) } throw new Error(`UI timeout ${expression}\n${await evaluate('document.body.innerText')}`) }
async function click(text, root = 'main') { await wait(`[...document.querySelectorAll(${JSON.stringify(root + ' button')})].filter(e=>e.textContent.trim()===${JSON.stringify(text)}).length===1`); await evaluate(`(()=>{const list=[...document.querySelectorAll(${JSON.stringify(root + ' button')})].filter(e=>e.textContent.trim()===${JSON.stringify(text)});list[0].click()})()`); await sleep(80) }
async function action(label) { await wait(`document.querySelector('[aria-label="${label}"]')`); await evaluate(`document.querySelector('[aria-label="${label}"]').click()`); await sleep(80) }
async function fill(selector, value) { await evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(selector)});if(!e)throw Error('Missing field');Object.getOwnPropertyDescriptor(e.tagName==='TEXTAREA'?HTMLTextAreaElement.prototype:HTMLInputElement.prototype,'value').set.call(e,${JSON.stringify(value)});e.dispatchEvent(new Event('input',{bubbles:true}))})()`); await sleep(50) }
async function select(label, value) { await wait(`[...document.querySelector('select[aria-label="${label}"]')?.options || []].some(o=>o.value===${JSON.stringify(String(value))})`); await evaluate(`(()=>{const e=document.querySelector('select[aria-label="${label}"]');e.value=${JSON.stringify(String(value))};e.dispatchEvent(new Event('change',{bubbles:true}))})()`); await sleep(80) }
async function shot(name) {
  await sleep(300)
  const metrics = await send('Page.getLayoutMetrics')
  const fullMobileTable = /(?:catalog|table)-mobile$/.test(name)
  const r = await send('Page.captureScreenshot', { format: 'png', ...(fullMobileTable ? { captureBeyondViewport: true, clip: { x: 0, y: 0, width: metrics.cssLayoutViewport.clientWidth, height: Math.min(2500, metrics.cssContentSize.height), scale: 1 } } : {}) })
  await writeFile(join(output, name + '.png'), Buffer.from(r.data, 'base64'))
}
const field = 'input[name="course_name"]'
async function sizes(name) {
  await shot(name + '-desktop')
  await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true })
  await shot(name + '-mobile')
  assert.ok(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'), name + ': no page overflow')
  assert.ok(await evaluate("[...document.querySelectorAll('dialog[open]')].every(e=>{const r=e.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+2&&r.top>=0&&r.bottom<=innerHeight+2})"), name + ': dialog fits viewport')
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false })
}
async function runLive() {
  const state = () => fetch(live + '/__fixture/state').then(r => r.json())
  const initial = await state()
  const correctedName = `تصحيح اسم ضمن برنامج مستخدم ${initial.audit_count}`
  const newCode = `WEB${initial.courses.length}`
  await sizes('live-catalog')
  await action('تعديل C2'); await wait(`document.querySelector('${field}')`)
  assert.equal(await evaluate("document.querySelector('input[name=credit_hours]').matches(':disabled')"), true)
  await fill(field, correctedName); await sizes('live-edit')
  await click('حفظ التعديلات'); await wait(`!document.querySelector('${field}')`)
  assert.equal((await state()).courses.find(c => c.course_id === 2).course_name, correctedName)
  evidence.push('Laravel: used-program text correction persists; credit hours locked')
  await select('القسم', 1)
  // Repeated concurrency runs can move the fixture program beyond lookup page 1.
  // Use the actual searchable UI instead of assigning a non-existent option.
  const programResponse = await fetch(live + '/api/v1/vice-presidency/scientific/course-management/programs/1').then(r => r.json())
  await evaluate(`document.querySelector('[aria-label="بحث البرنامج"]').closest('details').querySelector('summary').click()`)
  await fill('[aria-label="بحث البرنامج"]', programResponse.data.data.program_name)
  await select('البرنامج', 1)
  await click('متطلبات التخرج'); await wait('document.querySelector("input[name=total_credit_hours]")')
  await sizes('live-requirements'); await action('إغلاق النافذة')
  await click('إضافة مادة للبرنامج'); await click('إنشاء مادة جديدة'); await wait(`document.querySelector('${field}')`)
  await fill(field, 'مادة متصفح Laravel'); await fill('input[name=course_code]', newCode); await sizes('live-add')
  await click('حفظ المادة'); await wait("document.body.innerText.includes('لم تُضف للبرنامج بعد')")
  const created = await state(), course = created.courses.find(c => c.course_code === newCode)
  assert.ok(course); assert.equal(created.memberships.length, initial.memberships.length)
  assert.deepEqual(created.groups, initial.groups)
  evidence.push('Laravel: new catalog course commits without membership or budget mutation; separate success shown')
  await click('متابعة إضافة المادة للبرنامج'); await wait("document.querySelector('[aria-label=\"إضافة مادة للبرنامج\"] form')")
  await select('المستوى الإرشادي', 1); await select('الفصل الإرشادي', 1)
  assert.equal(await evaluate("Boolean(document.querySelector('select[aria-label=\"مجموعة المتطلبات\"]'))"), false)
  await click('إضافة للبرنامج'); await click('تأكيد', 'dialog'); await wait("!document.querySelector('dialog[open]')")
  const linked = await state()
  assert.ok(linked.memberships.some(p => p.course_id === course.course_id && p.academic_program_id === 1))
  assert.deepEqual(linked.groups, initial.groups)
  assert.ok(!('requirement_group_id' in writes.at(-1).body))
  evidence.push('Laravel: separately confirmed link selects the sole group server-side; required hours unchanged')
  await action(`استعراض ${newCode}`); await wait("document.querySelector('[aria-label=\"استعراض المادة\"]')")
  await click('تصنيف البرنامج'); await wait("document.querySelector('[aria-label=\"إضافة مادة للبرنامج\"] form')")
  await click('إزالة من البرنامج'); await click('تأكيد', 'dialog'); await wait("!document.querySelector('dialog[open]')")
  const unlinked = await state()
  assert.ok(unlinked.courses.some(c => c.course_id === course.course_id))
  assert.equal(unlinked.memberships.some(p => p.course_id === course.course_id), false)
  assert.deepEqual(unlinked.groups, initial.groups)
  await click('إضافة مادة للبرنامج')
  await evaluate(`document.querySelector('[aria-label="بحث اختيار مادة موجودة"]').closest('details').querySelector('summary').click()`)
  await fill('[aria-label="بحث اختيار مادة موجودة"]', newCode)
  await select('اختيار مادة موجودة', course.course_id); await click('متابعة بالمادة المختارة')
  await select('المستوى الإرشادي', 1); await select('الفصل الإرشادي', 1)
  await click('إضافة للبرنامج'); await click('تأكيد', 'dialog'); await wait("!document.querySelector('dialog[open]')")
  assert.equal((await state()).courses.filter(c => c.course_code === newCode).length, 1)
  evidence.push('Laravel: unlink preserves catalog course and budgets; selecting it again reuses the same course without duplicate creation')
  await action('تعديل C1'); await wait(`document.querySelector('${field}')`)
  await fill(field, 'مسودة أمام تعديل آخر')
  const api = live + '/api/v1/vice-presidency/scientific/course-management'
  const current = await fetch(api + '/courses/1').then(r => r.json())
  const otherName = `تعديل مستخدم آخر ${initial.audit_count}`
  const changed = await fetch(api + '/courses/1', { method: 'PUT', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ revision: current.data.revision, course_name: otherName }) })
  assert.equal(changed.status, 200)
  await click('حفظ التعديلات'); await wait("document.body.innerText.includes('مراجعة البيانات الحالية')")
  assert.equal(await evaluate(`document.querySelector('${field}').value`), 'مسودة أمام تعديل آخر')
  assert.equal((await state()).courses.find(c => c.course_id === 1).course_name, otherName)
  await click('مراجعة البيانات الحالية'); await wait("document.body.innerText.includes('تجاهل تعديلاتي وفتح البيانات الحالية')")
  await sizes('live-conflict')
  evidence.push('Laravel: real epoch 409 retains proposed edit; no automatic overwrite/retry')
}
async function runReferences() {
  await send('Page.navigate', { url: origin + '/vp/scientific/semester-offerings' })
  await wait("document.body.innerText.includes('اعتماد الطروحات الفصلية') && document.querySelector('tbody')?.innerText.includes('C1')")
  await sizes('reference-vp-table')
  await send('Page.navigate', { url: origin + '/exam-board/courses' })
  await wait("document.body.innerText.includes('إدارة المواد الدراسية') && document.querySelector('tbody')?.innerText.includes('C1')")
  await sizes('reference-courses-table'); await click('إضافة مادة')
  await wait("document.querySelector('input[placeholder=\"اسم المادة\"]')")
  await sizes('reference-course-form')
  await send('Page.navigate', { url: references + '/tests/browser/catalog-reference.html' })
  await wait("document.querySelector('dialog[open]')")
  await sizes('reference-confirmation'); await click('إلغاء', 'dialog')
  await click('نافذة التحرير المرجعية'); await wait("document.querySelector('input[type=time]')")
  await sizes('reference-wide-dialog')
  evidence.push('Unchanged VP queue, Exam Board table/form, native confirmation and Dean wide editor captured at desktop/mobile')
}
try {
  for (const method of ['Page.enable', 'Runtime.enable', 'Network.enable', 'DOM.enable', 'CSS.enable']) await send(method)
  await send('Network.setCacheDisabled', { cacheDisabled: true }); await send('CSS.setLocalFontsEnabled', { enabled: false })
  await send('Fetch.enable', { patterns: [{ urlPattern: '*', requestStage: 'Request' }] })
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false })
  await send('Page.navigate', { url: origin + '/login' }); await wait('document.querySelector("form")')
  await evaluate(`localStorage.setItem('user',${JSON.stringify(JSON.stringify(identity))});localStorage.setItem('token','synthetic-catalog-test')`)
  await send('Page.navigate', { url: origin + '/vp/scientific/courses' }); await wait("Boolean(document.querySelector('[aria-label=\\\"استعراض C1\\\"]'))")
  await wait("document.fonts.status==='loaded'")
  const { root } = await send('DOM.getDocument', { depth: -1 })
  const { nodeId } = await send('DOM.querySelector', { nodeId: root.nodeId, selector: 'main h1' })
  const renderedFonts = (await send('CSS.getPlatformFontsForNode', { nodeId })).fonts
  assert.ok(renderedFonts.some(f => f.familyName.includes('Cairo') && f.isCustomFont && f.glyphCount > 0), 'Actual Arabic heading glyphs use self-hosted Cairo')
  await shot('catalog-desktop')
  if (references) await runReferences()
  else if (live) await runLive()
  else {
  await action('تعديل C2'); await wait(`document.querySelector('${field}')`)
  assert.equal(await evaluate("document.querySelector('input[name=course_code]').matches(':disabled')"), true)
  assert.equal(await evaluate(`document.querySelector('${field}').matches(':disabled')`), false)
  assert.match(await evaluate("document.querySelector('[aria-label=\"بيانات المادة\"]').textContent"), /التصحيح النصي متاح/)
  await shot('locked-fields')
  await fill(field, 'تصحيح نصي مشروع')
  await evaluate("document.querySelector('a[href=\"/vp/scientific\"]').click()")
  await wait('document.querySelector("dialog[open]")'); await click('البقاء في المحرر', 'dialog')
  assert.equal(await evaluate(`document.querySelector('${field}').value`), 'تصحيح نصي مشروع')
  await click('حفظ التعديلات'); await wait("!document.querySelector('input[name=course_name]')")
  assert.deepEqual(writes.at(-1).body, { revision: '10', course_name: 'تصحيح نصي مشروع' })
  evidence.push('used origin locks academic fields, sidebar cancel preserves draft, subsequent text save succeeds')

  await evaluate("document.querySelector('a[href=\"/vp/scientific\"]').click()")
  await wait("location.pathname==='/vp/scientific'")
  await evaluate("document.querySelector('a[href=\"/vp/scientific/courses\"]').click()")
  await wait("Boolean(document.querySelector('[aria-label=\\\"استعراض C1\\\"]'))")
  await action('تعديل C1'); await wait(`document.querySelector('${field}')`); await fill(field, 'مسودة الرجوع')
  await evaluate('history.back()'); await wait('document.querySelector("dialog[open]")'); await click('البقاء في المحرر', 'dialog')
  assert.equal(await evaluate(`document.querySelector('${field}').value`), 'مسودة الرجوع')
  await evaluate('history.back()'); await wait('document.querySelector("dialog[open]")'); await click('تجاهل التعديلات والمتابعة', 'dialog'); await wait("location.pathname==='/vp/scientific'")
  await evaluate('history.forward()'); await wait("Boolean(document.querySelector('[aria-label=\\\"استعراض C1\\\"]'))")
  evidence.push('SPA back cancellation retains drafts; confirmed back reaches intended route; forward reloads official context')

  await action('تعديل C1'); await wait(`document.querySelector('${field}')`); await fill(field, 'مسودة محفوظة محليًا')
  await click('تحديث القائمة'); await sleep(600)
  assert.equal(await evaluate(`document.querySelector('${field}').value`), 'مسودة محفوظة محليًا')
  failNext = 'stale'; const before = writes.length; await click('حفظ التعديلات'); await wait("document.body.innerText.includes('مراجعة البيانات الحالية')")
  assert.equal(await evaluate(`document.querySelector('${field}').value`), 'مسودة محفوظة محليًا'); await sleep(300); assert.equal(writes.length, before + 1)
  await click('مراجعة البيانات الحالية'); await wait("document.body.innerText.includes('تجاهل تعديلاتي وفتح البيانات الحالية')"); await shot('conflict-preserved')
  await click('تجاهل تعديلاتي وفتح البيانات الحالية'); await click('تجاهل التعديلات والمتابعة', 'dialog')
  await wait(`document.querySelector('${field}')?.value==='علوم الحاسوب'`)
  evidence.push('same-context refresh preserves draft; 409 preserves baseline/proposed values; explicit review/discard required')
  await fill('input[name=course_code]', 'DUP'); await click('حفظ التعديلات'); await wait("document.body.innerText.includes('رمز المادة مستخدم بالفعل')")
  assert.equal(await evaluate("document.querySelector('input[name=course_code]').value"), 'DUP')
  await fill('input[name=course_code]', 'C1'); await fill(field, 'اسم بعد التحقق'); writeDelay = 500
  await click('حفظ التعديلات'); await evaluate("document.querySelector('a[href=\"/vp/scientific\"]').click()")
  await wait('document.querySelector("dialog[open]")'); assert.equal(await evaluate("[...document.querySelectorAll('dialog button')].find(e=>e.textContent.includes('تجاهل')).disabled"), true)
  await click('البقاء في المحرر', 'dialog'); await wait("!document.querySelector('input[name=course_name]')"); writeDelay = 0
  evidence.push('422 keeps inputs editable; pending write blocks navigation until confirmed success')

  await action('تعديل C1'); await wait(`document.querySelector('${field}')`); await fill(field, 'حفظ غير مؤكد'); failNext = 'network'
  await click('حفظ التعديلات'); await wait("document.body.innerText.includes('مراجعة البيانات الحالية')")
  assert.equal(await evaluate(`document.querySelector('${field}').value`), 'حفظ غير مؤكد')
  const networkCount = writes.length; await sleep(400); assert.equal(writes.length, networkCount)
  await click('مراجعة البيانات الحالية'); await wait("document.body.innerText.includes('تجاهل تعديلاتي وفتح البيانات الحالية')"); await click('تجاهل تعديلاتي وفتح البيانات الحالية'); await click('تجاهل التعديلات والمتابعة', 'dialog'); await wait(`document.querySelector('${field}')?.value==='اسم بعد التحقق'`); await action('إغلاق النافذة')
  evidence.push('lost response blocks another write until official inspection, no automatic retry')

  await fill('input[placeholder="بحث بالاسم أو الرمز"]', 'C1'); await sleep(450)
  await fill('input[placeholder="بحث بالاسم أو الرمز"]', 'C2'); await wait(`document.querySelectorAll('tbody tr').length===1 && document.querySelector('[aria-label="استعراض C2"]')`)
  await sleep(1100); assert.equal(await evaluate("document.querySelectorAll('tbody tr').length"), 1); assert.equal(await evaluate("Boolean(document.querySelector('[aria-label=\\\"استعراض C1\\\"]'))"), false)
  await fill('input[placeholder="بحث بالاسم أو الرمز"]', ''); await wait("Boolean(document.querySelector('[aria-label=\\\"استعراض C1\\\"]'))")
  evidence.push('different filter responses reversed; stale results never restore old rows')
  await select('البرنامج', 2); await click('متطلبات التخرج'); await wait("document.querySelector('input[name=total_credit_hours]')")
  assert.equal(await evaluate("document.querySelector('input[name=total_credit_hours]').matches(':disabled')"), true)
  await action('إغلاق النافذة'); await select('البرنامج', 1); await click('متطلبات التخرج'); await wait("document.querySelector('input[name=total_credit_hours]')")
  assert.equal(await evaluate("document.querySelector('input[name=total_credit_hours]').matches(':disabled')"), false)
  await evaluate("document.querySelector('[aria-label=\"متطلبات التخرج\"]').scrollIntoView()")
  await shot('program-budgets'); await fill('input[name=total_credit_hours]', '19'); await click('حفظ متطلبات التخرج'); await wait('document.querySelector("dialog[open]")'); await click('إلغاء', 'dialog')
  const groupWrites = writes.length; await click('حفظ متطلبات التخرج'); await click('تأكيد', 'dialog'); await wait("!document.querySelector('input[name=total_credit_hours]')"); assert.equal(writes.length, groupWrites + 1); assert.ok(writes.at(-1).path.endsWith('/requirement-groups'))
  evidence.push('program budgets separate, locked early for used program, explicit confirmation for unused program')
  await click('إضافة مادة للبرنامج'); await select('اختيار مادة موجودة', 1); await click('متابعة بالمادة المختارة'); await wait("document.querySelector('[aria-label=\\\"إضافة مادة للبرنامج\\\"] form')")
  assert.equal(await evaluate("Boolean(document.querySelector('select[aria-label=\\\"مجموعة المتطلبات\\\"]'))"), false)
  await shot('membership'); await click('حفظ التصنيف'); await click('تأكيد', 'dialog'); await wait("!document.querySelector('dialog[open]')")
  assert.equal(writes.at(-1).path, '/programs/1/courses/1')
  assert.equal('requirement_group_id' in writes.at(-1).body, false)
  assert.equal('credit_hours' in writes.at(-1).body, false)
  evidence.push('existing course selected once; server chooses group; only classification written')
  await select('البرنامج', ''); await wait(`document.querySelector('[aria-label="استعراض C3"]')`)
  await click('إضافة مادة'); await wait(`document.querySelector('${field}')`)
  await fill(field, 'مادة بلا ارتباط'); await fill('input[name=course_code]', 'NEW1'); await click('حفظ المادة'); await wait("!document.querySelector('input[name=course_name]')")
  assert.equal(writes.at(-1).path, '/courses'); assert.equal('academic_program_id' in writes.at(-1).body, false)
  evidence.push('standalone course creation does not link a program or change budgets')
  await action('حذف C3'); await wait("document.querySelector('[aria-label=\\\"استعراض المادة\\\"]')"); const deleteCount = writes.length; await click('إلغاء', 'dialog'); assert.equal(writes.length, deleteCount)
  await click('حذف المادة'); await click('تأكيد الحذف', 'dialog'); await wait("!document.querySelector('dialog[open]')"); assert.equal(writes.length, deleteCount + 1); assert.equal(writes.at(-1).method, 'DELETE')
  evidence.push('delete is explicit, cancellation writes nothing')
  await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true }); await evaluate('window.scrollTo(0,0)'); await shot('catalog-mobile')
  assert.ok(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'), 'No document-level horizontal overflow')
  await action('تعديل C1'); await wait(`document.querySelector('${field}')`); await fill(field, 'مسودة الهاتف'); await action('إغلاق النافذة'); await shot('mobile-discard-dialog'); await click('البقاء في المحرر', 'dialog')
  await evaluate(`localStorage.setItem('user',JSON.stringify({roles:['vice_president_administrative'],permissions:[],access_scopes:[]}));window.dispatchEvent(new Event('storage'))`)
  await wait("!document.querySelector('input[name=course_name]')"); assert.ok(!await evaluate("document.body.innerText.includes('مسودة الهاتف')"))
  await evaluate(`localStorage.setItem('user',${JSON.stringify(JSON.stringify(identity))})`)
  await send('Page.navigate', { url: origin + '/vp/scientific/courses' }); await wait("Boolean(document.querySelector('[aria-label=\\\"استعراض C1\\\"]'))")
  await action('تعديل C1'); await wait(`document.querySelector('${field}')`); await fill(field, 'مسودة قبل سحب الصلاحية')
  denyReads = true; await click('تحديث القائمة'); await wait("document.body.innerText.includes('تم مسح البيانات')")
  assert.equal(await evaluate(`document.querySelector('${field}')`), null)
  assert.equal(await evaluate("Boolean(document.querySelector('[aria-label=\\\"استعراض C1\\\"]'))"), false)
  evidence.push('authoritative GET 403 immediately purges rows and drafts even when locally stored roles remain unchanged')
  }
  assert.deepEqual(failures, [])
  await writeFile(join(output, 'evidence.json'), JSON.stringify({ evidence, writes, reads, renderedFonts, realReact: true, syntheticApi: !live, liveLaravel: !!live, testAuthentication: true }, null, 2))
  console.log(`PASS ${evidence.length} recorded scenarios; ${live ? 'Laravel (isolated fixture; verify server engine separately)' : 'synthetic API'}; artifacts ${output}`)
} catch (e) { await shot('failure'); throw e } finally {
  // Close only this fresh fixture tab before detaching interception: it must never
  // resume requests to the configured API after this synthetic test ends.
  await fetch(`${debug}/json/close/${target.id}`).catch(() => {})
  ws.close()
}
