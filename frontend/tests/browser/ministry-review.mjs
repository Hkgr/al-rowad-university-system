// Production React with synthetic API responses. Backend HTTP/SQL parity is tested separately.
// Run against a loopback Vite preview and a dedicated Chrome debugging profile. No dependencies.
import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { join } from 'node:path'
const origin = process.env.MINISTRY_PREVIEW_URL || 'http://127.0.0.1:4177'
const debug = process.env.MINISTRY_DEBUG_URL || 'http://127.0.0.1:9242'
const output = process.env.MINISTRY_TEST_OUTPUT
for (const url of [origin, debug]) assert.ok(['127.0.0.1', 'localhost'].includes(new URL(url).hostname))
assert.ok(output); await mkdir(output, { recursive: true })
const target = await (await fetch(debug + '/json/new?about:blank', { method: 'PUT' })).json()
const ws = new WebSocket(target.webSocketDebuggerUrl)
await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject })
let serial = 0
const pending = new Map(), failures = [], requests = []
function send(method, params = {}) { return new Promise((resolve, reject) => { const id = ++serial; const timer = setTimeout(() => reject(Error(method + ' timed out')), 15000); pending.set(id, { resolve, reject, timer }); ws.send(JSON.stringify({ id, method, params })) }) }
const permissions = ['access', ...['dashboard', 'colleges', 'deans', 'students', 'courses', 'faculty', 'leadership'].map(s => s + '.view')].map(p => 'ministry_portal.' + p)
const identity = { user_id: 999, roles: ['ministry_observer'], permissions }
const colleges = [1, 2, 3].map(id => ({ college_id: id, college_name: `كلية اختبار ${id}`, is_active: id !== 3, departments: 1, programs: 1, students: 0, active_students: 0, faculty: 0, courses: 0, deans: [] }))
const deans = [
  { person: 'employee-1', full_name: 'عميد تعارض اختباري', state: 'current', state_label: 'حالي — تعارض في السجل', role_active: true, college_scope_active: true, account_active: true, account_current: true, position_state: 'ended', position_state_label: 'قيد منصب منتهٍ', has_conflict: true, end_date: '2025-08-31', notes: ['تعارض في السجل: الحساب مخوّل لكن قيد المنصب منتهٍ.'] },
  { person: 'employee-2', full_name: 'عميد منصب فقط', state: 'current', state_label: 'حالي', role_active: false, college_scope_active: false, account_active: false, account_current: false, position_state: 'active', position_state_label: 'قيد منصب سارٍ', has_conflict: false, end_date: null, notes: ['منصب عميد مسجل دون حساب عميد فعّال لهذه الكلية.'] },
  { person: 'employee-3', full_name: 'عميد سابق اختباري', state: 'historical', state_label: 'سابق / غير حالي', role_active: false, college_scope_active: false, account_active: true, account_current: false, position_state: 'ended', position_state_label: 'قيد منصب منتهٍ', has_conflict: false, end_date: '2024-08-31', notes: [] },
].map(d => ({ ...d, college: { id: 1, name: 'كلية اختبار 1' }, start_date: '2023-09-01' }))
function fixture(url) {
  const q = url.searchParams, path = url.pathname
  if (path === '/api/user') return { success: true, data: identity }
  if (path.endsWith('/filters')) return { data: { colleges: colleges.map(c => ({ id: c.college_id, name: c.college_name })), academic_years: [], semesters: [], programs: [] } }
  if (path.endsWith('/colleges')) {
    const rows = colleges.filter(c => (!q.get('college_id') || String(c.college_id) === q.get('college_id')) && (!q.has('active') || c.is_active === (q.get('active') === '1')))
    return { data: rows, meta: { total: rows.length } }
  }
  if (path.endsWith('/dashboard')) {
    const count = colleges.filter(c => c.is_active && (!q.get('college_id') || String(c.college_id) === q.get('college_id'))).length
    return { data: { generated_at: '2026-09-25T09:00:00Z', counts: { ...Object.fromEntries(['programs', 'departments', 'students', 'active_students', 'faculty', 'vice_presidents', 'courses'].map(k => [k, { value: 0, link: null }])), colleges: { value: count, inactive: 1, link: '/ministry/colleges?' + new URLSearchParams({ ...(q.get('college_id') ? { college_id: q.get('college_id') } : {}), active: '1' }) }, deans: { value: 2, link: '/ministry/deans?state=current' } }, period: { available: false }, period_metrics: null, distributions: { by_college: [], by_status: [], by_level: [], by_program: [] }, trends: { intake_by_year: [], graduates_by_year: [], registrations_by_term: [], official_results_by_term: [] }, unavailable: [] } }
  }
  if (path.endsWith('/deans')) {
    const rows = deans.filter(d => !q.get('state') || d.state === q.get('state'))
    return { data: rows, meta: { total: rows.length, current_page: 1, last_page: 1 } }
  }
  const d = deans.find(d => path.endsWith('/deans/' + d.person))
  if (d) return { data: { full_name: d.full_name, dean_assignments: [d], positions: [], source_note: 'بيانات اختبار اصطناعية؛ التواريخ من قيد المنصب.' } }
  throw Error('Unexpected API: ' + path)
}
ws.onmessage = async event => {
  const m = JSON.parse(event.data)
  if (m.id) { const p = pending.get(m.id); if (!p) return; pending.delete(m.id); clearTimeout(p.timer); if (m.error) p.reject(Error(JSON.stringify(m.error))); else p.resolve(m.result); return }
  if (m.method === 'Runtime.exceptionThrown') failures.push(m.params.exceptionDetails)
  if (m.method !== 'Fetch.requestPaused') return
  const { requestId, request } = m.params, url = new URL(request.url)
  try {
    if (!url.pathname.startsWith('/api/')) { await send(url.origin === origin ? 'Fetch.continueRequest' : 'Fetch.failRequest', { requestId, ...(url.origin === origin ? {} : { errorReason: 'BlockedByClient' }) }); return }
    assert.ok(['GET', 'OPTIONS'].includes(request.method), 'Portal must not write')
    requests.push({ path: url.pathname, query: url.search, method: request.method })
    await send('Fetch.fulfillRequest', { requestId, responseCode: 200, responseHeaders: [{ name: 'Content-Type', value: 'application/json' }, { name: 'Access-Control-Allow-Origin', value: origin }, { name: 'Access-Control-Allow-Headers', value: 'authorization,content-type,accept' }], body: Buffer.from(JSON.stringify(request.method === 'OPTIONS' ? {} : fixture(url))).toString('base64') })
  } catch (e) { if (!e.message.includes('Invalid InterceptionId')) failures.push(e.message); await send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' }).catch(() => {}) }
}
const pause = ms => new Promise(r => setTimeout(r, ms))
async function evaluate(expression) { const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }); if (r.exceptionDetails) throw Error(JSON.stringify(r.exceptionDetails)); return r.result.value }
async function wait(expression) { for (let n = 0; n < 100; n++) { if (await evaluate(`Boolean(${expression})`)) return; await pause(100) } throw Error('UI timeout: ' + expression) }
async function select(label, value) { const q = `[...document.querySelectorAll('label')].find(l=>l.textContent.trim().startsWith(${JSON.stringify(label)}))?.querySelector('select')`; await wait(q); await evaluate(`(()=>{const e=${q};e.value=${JSON.stringify(value)};e.dispatchEvent(new Event('change',{bubbles:true}))})()`) }
async function capture(name) {
  await evaluate('document.fonts.ready.then(()=>true)'); await pause(500)
  for (const [suffix, width, height] of [['desktop', 1440, 1000], ['mobile', 390, 844]]) {
    await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: suffix === 'mobile' }); await pause(500)
    const r = await send('Page.captureScreenshot', { format: 'png' }); await writeFile(join(output, `${name}-${suffix}.png`), Buffer.from(r.data, 'base64'))
    assert.ok(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'))
  }
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false })
}
await send('Runtime.enable'); await send('Page.enable'); await send('Fetch.enable', { patterns: [{ urlPattern: '*' }] })
await send('Page.addScriptToEvaluateOnNewDocument', { source: `localStorage.setItem('user',${JSON.stringify(JSON.stringify(identity))});localStorage.setItem('token','synthetic-only')` })
await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false })
try {
  for (const query of ['', '?college_id=1']) {
    await send('Page.navigate', { url: origin + '/ministry' + query })
    const href = '/ministry/colleges?' + (query ? 'college_id=1&' : '') + 'active=1'
    const anchor = `document.querySelector('a[href="${href}"]')`
    await wait(anchor); await evaluate(anchor + '.click()')
    await wait(`document.querySelectorAll('tbody tr').length===${query ? 1 : 2}`)
    assert.equal(await evaluate("new URL(location.href).searchParams.get('active')"), '1')
    if (query) assert.equal(await evaluate("new URL(location.href).searchParams.get('college_id')"), '1')
  }
  await capture('colleges-active')
  await select('الكلية', ''); await select('حالة الكلية', '0')
  await wait("document.querySelector('tbody')?.textContent.includes('كلية اختبار 3')")
  await capture('colleges-inactive')
  await select('حالة الكلية', ''); await wait("document.querySelectorAll('tbody tr').length===3")
  await send('Page.navigate', { url: origin + '/ministry/deans' })
  await wait("document.querySelectorAll('tbody tr').length===3")
  assert.ok(await evaluate("document.querySelector('tbody').textContent.includes('تعارض') && document.querySelector('tbody').textContent.includes('قيد منصب منتهٍ') && document.querySelector('tbody').textContent.includes('قيد منصب سارٍ')"))
  await capture('deans-evidence')
  for (const d of deans) {
    await send('Page.navigate', { url: origin + '/ministry/deans/' + d.person })
    await wait(`document.querySelector('table')?.textContent.includes(${JSON.stringify(d.position_state_label)})`)
    assert.ok(await evaluate(`document.querySelector('table').textContent.includes(${JSON.stringify(d.state_label)})`))
    if (d.end_date) assert.ok(await evaluate(`document.querySelector('table').textContent.includes(${JSON.stringify(Number(d.end_date.slice(0, 4)).toLocaleString('ar-SY', {useGrouping:false}))})`))
    if (d.has_conflict) await capture('dean-ended-conflict')
  }
  assert.deepEqual(failures, [])
  console.log(JSON.stringify({ passed: true, api: 'synthetic responses, not live Laravel', requests: requests.length, cases: ['dashboard general/selected college drilldown', 'active/inactive/all filters', 'three dean states in list and detail', 'desktop/mobile RTL screenshots', 'zero writes'] }))
} catch (error) {
  console.error(JSON.stringify({ failures, requests, page: await evaluate('({url:location.href,text:document.body.innerText})') }, null, 2)); throw error
} finally { await send('Fetch.disable').catch(() => {}); await send('Target.closeTarget', { targetId: target.id }).catch(() => {}); ws.close() }
