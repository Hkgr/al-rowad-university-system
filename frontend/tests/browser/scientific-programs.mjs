// Production React build -> real loopback Laravel -> guarded disposable MariaDB.
// Test identity/transport bridge only; report payloads and mutations are not mocked.
import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { join } from 'node:path'
const origin = process.env.PROGRAM_PREVIEW_URL || 'http://127.0.0.1:4175'
const live = process.env.PROGRAM_LARAVEL_URL || 'http://127.0.0.1:8186'
const debug = process.env.PROGRAM_DEBUG_URL || 'http://127.0.0.1:9235'
const output = process.env.PROGRAM_TEST_OUTPUT
for (const url of [origin, live, debug]) assert.ok(['127.0.0.1', 'localhost'].includes(new URL(url).hostname))
assert.ok(output, 'Set PROGRAM_TEST_OUTPUT to a disposable directory')
await mkdir(output, { recursive: true })
const target = (await (await fetch(debug + '/json/list')).json()).find(t => t.type === 'page')
  || await (await fetch(debug + '/json/new?about:blank', { method: 'PUT' })).json()
const ws = new WebSocket(target.webSocketDebuggerUrl)
await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject })
const pending = new Map(), failures = [], requests = [], evidence = [], cancelledInterceptions = []
let serial = 0, lostResponse = false
const identity = { user_id: 1, username: 'حساب اختبار محلي', roles: ['vice_president_scientific'], permissions: ['vice_presidency.scientific.access', 'academic_structure.view', 'academic_structure.manage', 'vice_presidency.scientific.courses.view', 'vice_presidency.scientific.courses.manage'], access_scopes: [{ type: 'university', id: 1 }] }
function send(method, params = {}) { return new Promise((resolve, reject) => { const id = ++serial; const timer = setTimeout(() => reject(Error(method + ' timed out')), 15000); pending.set(id, { resolve, reject, timer }); ws.send(JSON.stringify({ id, method, params })) }) }
ws.onmessage = async event => {
  const m = JSON.parse(event.data)
  if (m.id) { const p = pending.get(m.id); if (!p) return; pending.delete(m.id); clearTimeout(p.timer); if (m.error) p.reject(Error(JSON.stringify(m.error))); else p.resolve(m.result); return }
  if (m.method === 'Runtime.exceptionThrown') failures.push(m.params.exceptionDetails)
  if (m.method !== 'Fetch.requestPaused') return
  const { requestId, request } = m.params, url = new URL(request.url)
  try {
    if (!url.pathname.startsWith('/api/')) {
      await send(url.origin === origin ? 'Fetch.continueRequest' : 'Fetch.failRequest', { requestId, ...(url.origin === origin ? {} : { errorReason: 'BlockedByClient' }) }); return
    }
    const headers = [{ name: 'Access-Control-Allow-Origin', value: origin }, { name: 'Access-Control-Allow-Methods', value: 'GET,POST,PUT,PATCH,DELETE,OPTIONS' }, { name: 'Access-Control-Allow-Headers', value: 'authorization,content-type,accept' }, { name: 'Content-Type', value: 'application/json' }]
    let status = 200, data
    if (request.method === 'OPTIONS') status = 204
    else if (url.pathname === '/api/user') data = { success: true, data: identity }
    else {
      assert.match(url.pathname, /^\/api\/v1\/vice-presidency\/scientific\/(program-management|course-management)(\/|$)/)
      const response = await fetch(live + url.pathname + url.search, { method: request.method, body: request.postData, headers: { Accept: 'application/json', 'Content-Type': 'application/json' } })
      status = response.status; data = await response.json(); requests.push({ path: url.pathname, method: request.method, status })
      if (lostResponse && request.method !== 'GET') { lostResponse = false; await send('Fetch.failRequest', { requestId, errorReason: 'ConnectionClosed' }); return }
    }
    await send('Fetch.fulfillRequest', { requestId, responseCode: status, responseHeaders: headers, body: data ? Buffer.from(JSON.stringify(data)).toString('base64') : '' })
  } catch (e) {
    // React aborts obsolete lookups on unmount. Chrome has already cancelled
    // that specific interception; this is not a Laravel or rendering exception.
    if (e.message.includes('Invalid InterceptionId.')) cancelledInterceptions.push(requestId)
    else failures.push(e.message)
    await send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' }).catch(() => {})
  }
}
const pause = ms => new Promise(r => setTimeout(r, ms))
async function evaluate(expression) { const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }); if (r.exceptionDetails) throw Error(JSON.stringify(r.exceptionDetails)); return r.result.value }
async function wait(expression) { for (let n = 0; n < 100; n++) { if (await evaluate(`Boolean(${expression})`)) return; await pause(100) } throw Error('UI timeout: ' + expression + '\n' + await evaluate('document.body.innerText')) }
async function click(text, selector = 'main button') { const q = `[...document.querySelectorAll(${JSON.stringify(selector)})].filter(e=>e.textContent.trim()===${JSON.stringify(text)})`; await wait(q + '.length===1'); await evaluate(q + '[0].click()'); await pause(70) }
function field(label) { return `[...document.querySelectorAll('dialog[open] label')].find(e=>e.querySelector('span')?.textContent===${JSON.stringify(label)})?.querySelector('input')` }
async function fill(label, value) { const q = field(label); await wait(q); await evaluate(`(()=>{const e=${q};Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set.call(e,${JSON.stringify(value)});e.dispatchEvent(new Event('input',{bubbles:true}))})()`); await pause(40) }
async function select(label, value, dialog = false) { const q = label === 'الخطة التي تعمل عليها' ? `[...document.querySelectorAll('main label')].find(e=>e.querySelector('span')?.textContent===${JSON.stringify(label)})?.querySelector('select')` : `document.querySelector(${JSON.stringify((dialog ? 'dialog[open] ' : '') + 'select[aria-label="' + label + '"]')})`; await wait(`[...(${q})?.options||[]].some(o=>o.value===${JSON.stringify(String(value))})`); await evaluate(`(()=>{const e=${q};e.value=${JSON.stringify(String(value))};e.dispatchEvent(new Event('change',{bubbles:true}))})()`); await pause(100) }
async function screenshot(name) { const r = await send('Page.captureScreenshot', { format: 'png' }); await writeFile(join(output, name + '.png'), Buffer.from(r.data, 'base64')) }
async function sizes(name) { await screenshot(name + '-desktop'); await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true }); await pause(150); await screenshot(name + '-mobile'); assert.ok(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'), 'No viewport overflow'); await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false }) }
const base = live + '/api/v1/vice-presidency/scientific/program-management'
await send('Runtime.enable'); await send('Page.enable'); await send('Fetch.enable', { patterns: [{ urlPattern: '*' }] })
await send('Page.addScriptToEvaluateOnNewDocument', { source: `localStorage.setItem('user',${JSON.stringify(JSON.stringify(identity))});localStorage.setItem('token','local-fixture-only')` })
await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false })
try {
  await send('Page.navigate', { url: origin + '/vp/scientific/courses' }); await wait("document.querySelector('tbody tr')"); await sizes('course-management-reference')
  await send('Page.navigate', { url: origin + '/vp/scientific/programs' }); await wait("document.querySelector('tbody tr')"); await sizes('programs-list')
  await click('إضافة برنامج'); await fill('اسم البرنامج', 'برنامج متصفح اختباري'); await fill('رمز البرنامج', 'BROWSER-' + Date.now()); await fill('الدرجة العلمية', 'بكالوريوس'); await fill('المدة بالسنوات', '4'); await fill('إجمالي ساعات البرنامج', '3'); await select('القسم', '1', true)
  await sizes('program-create'); await click('حفظ بيانات البرنامج', 'dialog[open] button'); await wait("Number(location.pathname.split('/').at(-1)) > 0 && !document.querySelector('dialog[open]')")
  const programId = await evaluate('location.pathname.split("/").at(-1)')
  let detail = (await (await fetch(base + '/' + programId)).json()).data
  assert.equal(detail.program.plan_state, 'preparing'); assert.equal(detail.program.default_academic_plan_version_id, null)
  const versionId = detail.versions[0].academic_plan_version_id
  evidence.push('Real Laravel/MariaDB creation starts preparing with no default or implicit approval')
  await click('مواد الخطة'); await select('الخطة التي تعمل عليها', versionId); await click('إضافة مادة موجودة للخطة'); await select('المادة — الاسم والرمز', 1, true)
  await click('إعداد متطلبات التخرج', 'dialog[open] button')
  await wait(field('متطلبات الجامعة — إجباري')); assert.equal(await evaluate(field('متطلبات الجامعة — إجباري') + '.value'), '')
  for (const scope of ['الجامعة', 'الكلية', 'القسم']) for (const type of ['إجباري', 'اختياري']) await fill(`متطلبات ${scope} — ${type}`, scope === 'الجامعة' && type === 'إجباري' ? '3' : '0')
  await sizes('program-requirements'); await click('حفظ متطلبات التخرج', 'dialog[open] button'); await wait("!document.querySelector('dialog[open]')")
  await click('مراجعة اختيار المادة والمتابعة'); await wait("document.querySelector('select[aria-label=\"المادة — الاسم والرمز\"]')?.value==='1'")
  await click('حفظ ارتباط المادة', 'dialog[open] button'); await wait("!document.querySelector('dialog[open]') && document.querySelector('tbody')?.innerText.includes('C1')")
  evidence.push('Missing-group preparation retains the selected course and explicit plan, then requires separate confirmed membership save')
  await click('إنشاء مادة في الدليل ثم ربطها'); await fill('اسم المادة', 'مادة مستقلة لا تربط ضمنيًا'); await fill('رمز المادة', 'BROWSER-C-' + Date.now()); await fill('الساعات المعتمدة', '3'); await fill('الساعات النظرية', '2'); await fill('الساعات العملية', '0')
  await select('إضافة قسم', 1, true)
  await evaluate("[...document.querySelectorAll('dialog[open] label')].find(e=>e.textContent.includes('قسم أساسي')).querySelector('input').click()")
  await click('حفظ المادة', 'dialog[open] button'); await wait("document.querySelector('dialog[open]')?.innerText.includes('لم تُربط بالخطة بعد')")
  let planResponse = (await (await fetch(base + '/' + programId + '/versions/' + versionId)).json()).data
  assert.equal(planResponse.courses.length, 1, 'Course creation is not an implicit membership or budget write')
  await click('مراجعة تصنيف المادة في الخطة المختارة', 'dialog[open] button')
  await evaluate("(()=>{const e=[...document.querySelectorAll('dialog[open] label')].find(e=>e.querySelector('span')?.textContent==='نوع المتطلب').querySelector('select');e.value='elective';e.dispatchEvent(new Event('change',{bubbles:true}))})()")
  await click('حفظ ارتباط المادة', 'dialog[open] button'); await wait("!document.querySelector('dialog[open]')")
  planResponse = (await (await fetch(base + '/' + programId + '/versions/' + versionId)).json()).data
  assert.equal(planResponse.courses.length, 2); assert.equal(planResponse.version.total_credit_hours, 3)
  evidence.push('Catalog origin creation, explicit continuation and membership are separate real writes; plan hours remain unchanged')
  await click('الإصدارات'); await click('اعتماد الخطة'); await click('تأكيد الإجراء', 'dialog[open] button'); await wait("!document.querySelector('dialog[open]')")
  detail = (await (await fetch(base + '/' + programId)).json()).data; assert.equal(detail.versions[0].status, 'approved'); assert.equal(detail.program.default_academic_plan_version_id, null)
  await click('تعيين للطلاب الجدد'); await click('تأكيد الإجراء', 'dialog[open] button'); await wait("!document.querySelector('dialog[open]') && document.querySelector('main')?.innerText.includes('جاهز للطلاب الجدد')")
  detail = (await (await fetch(base + '/' + programId)).json()).data; assert.equal(detail.program.default_academic_plan_version_id, versionId)
  evidence.push('Six missing budgets preserve null until explicit save; explicit zero, course membership, approval and default are distinct real writes')
  await sizes('program-approved'); await click('بيانات البرنامج'); await click('تعديل البيانات'); await wait(field('اسم البرنامج'))
  assert.equal(await evaluate(field('رمز البرنامج') + '.disabled'), true); await fill('اسم البرنامج', 'مسودة لا تمحى')
  await evaluate("document.querySelector('a[href=\"/vp/scientific/programs\"]').click()"); await click('البقاء في المحرر', 'dialog[open] button'); assert.equal(await evaluate(field('اسم البرنامج') + '.value'), 'مسودة لا تمحى')
  const current = (await (await fetch(base + '/' + programId)).json()).data
  const other = await fetch(base + '/' + programId, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ revision: current.revision, program_name: 'تصحيح متزامن' }) }); assert.equal(other.status, 200)
  await click('حفظ بيانات البرنامج', 'dialog[open] button'); await wait("document.querySelector('dialog')?.innerText.includes('مراجعة البيانات الحالية')"); assert.equal(await evaluate(field('اسم البرنامج') + '.value'), 'مسودة لا تمحى')
  await click('مراجعة البيانات الحالية', 'dialog[open] button'); await wait("document.querySelector('dialog')?.innerText.includes('تجاهل تعديلاتي وفتح البيانات الحالية')"); await sizes('program-conflict')
  await click('تجاهل تعديلاتي وفتح البيانات الحالية', 'dialog[open] button'); await click('تجاهل التعديلات والمتابعة', 'dialog[open] button'); await wait(field('اسم البرنامج') + '.value === "تصحيح متزامن"')
  await fill('اسم البرنامج', 'حفظ نجح دون وصول الاستجابة'); lostResponse = true; await click('حفظ بيانات البرنامج', 'dialog[open] button'); await wait("document.querySelector('dialog')?.innerText.includes('مراجعة البيانات الحالية')")
  const count = requests.filter(r => r.method !== 'GET').length; await pause(400); assert.equal(requests.filter(r => r.method !== 'GET').length, count)
  assert.equal((await (await fetch(base + '/' + programId)).json()).data.program.program_name, 'حفظ نجح دون وصول الاستجابة')
  evidence.push('Dirty navigation cancellation retains draft; real epoch conflict retains values; dropped write response is not retried and requires explicit inspection')
  await evaluate("localStorage.setItem('user',JSON.stringify({user_id:2,roles:['vice_president_administrative'],permissions:[]}));window.dispatchEvent(new Event('storage'))")
  await wait("!document.querySelector('dialog[open]') && !document.querySelector('input')")
  evidence.push('Identity change immediately unmounts sensitive editor; Administrative VP cannot use Scientific feature')
  assert.deepEqual(failures, []); console.log(JSON.stringify({ passed: true, backend: 'real Laravel + guarded disposable MariaDB', identity: 'test bridge', evidence, requests: requests.length, screenshots: output }, null, 2))
  await writeFile(join(output, 'results.json'), JSON.stringify({ evidence, requests, cancelledInterceptions }, null, 2))
} finally { await fetch(`${debug}/json/close/${target.id}`).catch(() => {}); ws.close() }
