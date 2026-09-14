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
const pending = new Map(), failures = [], requests = [], cancelledInterceptions = []
let serial = 0
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
async function screenshot(name) { const r = await send('Page.captureScreenshot', { format: 'png' }); await writeFile(join(output, name + '.png'), Buffer.from(r.data, 'base64')) }
async function sizes(name) {
  await evaluate('document.fonts.ready.then(()=>true)')
  await screenshot(name + '-desktop')
  await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true })
  // Wait for the existing 300ms sidebar animation, not a half-open mobile shell.
  await pause(500); await screenshot(name + '-mobile')
  assert.ok(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'), 'No viewport overflow')
  if (await evaluate("Boolean(document.querySelector('main table')) && !document.querySelector('dialog[open]')")) {
    await evaluate("document.querySelector('main table').scrollIntoView({block:'center'})")
    await screenshot(name + '-table-mobile'); await evaluate('scrollTo(0,0)')
  }
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false }); await pause(500)
}
const base = live + '/api/v1/vice-presidency/scientific/program-management'
await send('Runtime.enable'); await send('Page.enable'); await send('Fetch.enable', { patterns: [{ urlPattern: '*' }] })
await send('Page.addScriptToEvaluateOnNewDocument', { source: `localStorage.setItem('user',${JSON.stringify(JSON.stringify(identity))});localStorage.setItem('token','local-fixture-only')` })
await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false })
try {
  const ids = JSON.parse(process.env.PROGRAM_LEGACY_IDS || '{}')
  assert.ok(ids.complete && ids.incomplete && ids.empty, 'Run guarded legacy-plan-fixture.php first')
  const get = async path => { const r = await fetch(base + path); assert.equal(r.status, 200); return (await r.json()).data }
  const clean = value => Array.isArray(value) ? value.map(clean) : value && typeof value === 'object'
    ? Object.fromEntries(Object.entries(value).filter(([k]) => !['academic_plan_version_id', 'plan_scope_key'].includes(k)).map(([k,v]) => [k,clean(v)])) : value
  await send('Page.navigate', { url: origin + '/vp/scientific/programs/' + ids.complete + '?tab=membership' })
  await wait("document.querySelector('main h2')?.textContent==='الإصدار الأول — الخطة الحالية'")
  const before = (await get('/' + ids.complete)).current_plan
  assert.equal(before.persisted, false); assert.equal(before.courses.length, 1); assert.equal(before.groups.length, 6)
  const courseLabel = before.courses[0].course.course_name
  assert.ok((await evaluate("document.querySelector('main tbody').textContent")).includes(courseLabel))
  const membershipText = await evaluate("document.querySelector('main tbody').textContent")
  await sizes('legacy-membership-before')
  await click('متطلبات التخرج')
  await wait("document.querySelectorAll('main tbody tr').length===6")
  const requirementText = await evaluate("document.querySelector('main tbody').textContent")
  assert.ok(requirementText.includes(courseLabel))
  await sizes('legacy-requirements-before')
  await click('الإصدارات'); await wait("document.querySelector('main').textContent.includes('معروض من البيانات الحالية')")
  assert.ok((await evaluate("document.querySelector('main').textContent")).includes(courseLabel))
  assert.equal(requests.filter(r=>r.method!=='GET').length, 0)
  assert.equal((await get('/' + ids.complete)).program.plan_state, 'legacy')
  await click('تثبيت الخطة الحالية'); await wait("document.querySelector('dialog[open]')")
  await sizes('legacy-fix-preview')
  await click('تأكيد الإجراء', 'dialog[open] button')
  await wait("!document.querySelector('dialog[open]') && document.querySelector('main').textContent.includes('رقم الإصدار: 1 — محفوظ')")
  const detail = await get('/' + ids.complete)
  assert.equal(detail.versions.length, 1); assert.equal(detail.current_plan, null)
  const version = detail.versions[0]
  assert.equal(version.status, 'transitional'); assert.equal(version.approved_at, null); assert.equal(detail.program.default_academic_plan_version_id, null)
  const after = await get('/' + ids.complete + '/versions/' + version.academic_plan_version_id)
  assert.deepEqual(clean(after.groups), clean(before.groups)); assert.deepEqual(clean(after.courses), clean(before.courses))
  assert.equal(after.version.total_credit_hours, before.version.total_credit_hours)
  await click('متطلبات التخرج'); await wait("document.querySelectorAll('main tbody tr').length===6")
  assert.equal(await evaluate("document.querySelector('main tbody').textContent"), requirementText)
  await sizes('legacy-requirements-after')
  await click('مواد الخطة'); assert.equal(await evaluate("document.querySelector('main tbody').textContent"), membershipText)
  await sizes('legacy-membership-after')
  for (const [caseName, id] of [['incomplete', ids.incomplete], ['empty', ids.empty]]) {
    await send('Page.navigate', {url: origin + '/vp/scientific/programs/' + id + '?tab=requirements'})
    await wait("document.querySelectorAll('main tbody tr').length===6")
    assert.ok((await evaluate("document.querySelector('main tbody').textContent")).includes('غير محدد'))
    await sizes('legacy-' + caseName)
    await click('مواد الخطة')
    await wait(caseName === 'empty' ? "document.querySelector('main').textContent.includes('لا توجد مواد في هذه الخطة')" : "document.querySelector('main tbody')")
    await click('الإصدارات'); await wait("document.querySelector('main').textContent.includes('معروض من البيانات الحالية')")
    assert.equal((await get('/' + id)).versions.length, 0)
  }
  // A fresh direct link resolves exactly the persisted source; no substitute draft.
  await send('Page.navigate', {url: origin + '/vp/scientific/programs/' + ids.complete + '?tab=membership&version=' + version.academic_plan_version_id})
  await wait("document.querySelector('main tbody')")
  assert.equal(await evaluate("document.querySelector('main tbody').textContent"), membershipText)
  assert.equal(requests.filter(r=>r.method!=='GET').length, 1)
  assert.deepEqual(failures, [])
  const result = {passed:true, requests:requests.length, writes:1, backend:'real Laravel/MariaDB, synthetic identity and legacy fixtures', cases:['legacy data in all three tabs before fixation','exact groups, membership IDs, classifications and totals retained after fixation','no GET writes or implicit admission change','missing groups/null/zero and no-course plan stay readable','explicit persisted version link']}
  await writeFile(join(output,'legacy-results.json'),JSON.stringify(result,null,2)); console.log(JSON.stringify(result,null,2))
} finally { await send('Fetch.disable').catch(()=>{}); await send('Target.closeTarget',{targetId:target.id}).catch(()=>{}); ws.close() }
