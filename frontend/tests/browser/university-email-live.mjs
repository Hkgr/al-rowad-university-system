// Built React -> real local Laravel -> exported isolated synthetic SQLite.
// No Fetch interception, static API responses, live Mailcow or production data.
import assert from 'node:assert/strict'
import { readFile, mkdir, writeFile } from 'node:fs/promises'
import { join } from 'node:path'

const origin = 'http://localhost:5173', api = 'http://127.0.0.1:8099', debug = 'http://127.0.0.1:9244'
const directory = process.env.UNIVERSITY_EMAIL_BROWSER_DIR
assert.ok(directory, 'Export the test-only fixture first')
const actors = JSON.parse(await readFile(join(directory, 'identities.json'), 'utf8'))
const output = join(directory, 'screenshots')
await mkdir(output, { recursive: true })
const target = await (await fetch(debug+'/json/new?about:blank', { method: 'PUT' })).json()
const ws = new WebSocket(target.webSocketDebuggerUrl)
await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject })
let serial = 0, bootstrap
const pending = new Map(), errors = [], requests = [], responses = []
function send(method, params = {}) {
  return new Promise((resolve, reject) => {
    const id = ++serial, timer = setTimeout(() => { pending.delete(id); reject(Error(method+' timeout')) }, 15000)
    pending.set(id, { resolve, reject, timer }); ws.send(JSON.stringify({ id, method, params }))
  })
}
ws.onmessage = event => {
  const message = JSON.parse(event.data)
  if (message.id) {
    const item = pending.get(message.id)
    if (!item) return
    pending.delete(message.id); clearTimeout(item.timer)
    message.error ? item.reject(Error(JSON.stringify(message.error))) : item.resolve(message.result)
  }
  if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text)
  if (message.method === 'Network.requestWillBeSent' && message.params.request.url.startsWith(api)) requests.push(message.params.request)
  if (message.method === 'Network.responseReceived' && message.params.response.url.startsWith(api)) responses.push(message.params.response)
}
async function evaluate(expression) {
  const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true })
  if (result.exceptionDetails) throw Error(JSON.stringify(result.exceptionDetails))
  return result.result.value
}
async function wait(expression) {
  for (let count = 0; count < 120; count++) {
    if (await evaluate(`Boolean(${expression})`)) return
    await new Promise(resolve => setTimeout(resolve, 100))
  }
  await screenshot('failure')
  throw Error('UI timeout '+expression+' '+await evaluate("document.querySelector('main')?.innerText.slice(-1800)"))
}
async function input(selector, value) {
  await evaluate(`(()=>{const elements=document.querySelectorAll(${JSON.stringify(selector)});if(elements.length!==1)throw Error('ambiguous input');const e=elements[0];Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set.call(e,${JSON.stringify(value)});e.dispatchEvent(new Event('input',{bubbles:true}))})()`)
}
async function click(label) {
  await evaluate(`(()=>{const buttons=[...document.querySelectorAll('button')].filter(button=>button.textContent.trim()===${JSON.stringify(label)});if(buttons.length!==1)throw Error('ambiguous button '+${JSON.stringify(label)});buttons[0].click()})()`)
}
async function screenshot(name) {
  await evaluate('document.fonts.ready.then(()=>true)')
  await writeFile(join(output, name+'.png'), Buffer.from((await send('Page.captureScreenshot', { format: 'png' })).data, 'base64'))
}
async function identity(actor) {
  if (bootstrap) await send('Page.removeScriptToEvaluateOnNewDocument', { identifier: bootstrap })
  bootstrap = (await send('Page.addScriptToEvaluateOnNewDocument', { source: `localStorage.clear();localStorage.setItem('token',${JSON.stringify(actor.token)});localStorage.setItem('user',${JSON.stringify(JSON.stringify(actor.identity))});` })).identifier
}
async function request(path, actor = actors.technical, options = {}) {
  const response = await fetch(api+'/api/v1/technical/university-email'+path, { ...options, headers: { Authorization: 'Bearer '+actor.token, Accept: 'application/json', 'Content-Type': 'application/json' } })
  return { status: response.status, body: await response.json() }
}
const searchSelector = 'input[placeholder*="ابحث باسم الطالب"]'
try {
  await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable')
  await send('Network.setCacheDisabled', { cacheDisabled: true })
  await send('Network.setBlockedURLs', { urls: ['https://*'] })
  await identity(actors.technical)
  const all = await request('/students')
  assert.equal(all.status, 200); assert.ok(all.body.meta.total > 15)
  for (const query of ['?q=', '?q=%20%20%20']) {
    const response = await request('/students'+query)
    assert.equal(response.status, 200); assert.equal(response.body.meta.total, all.body.meta.total)
  }
  for (const [size, width, height] of [['desktop', 1440, 1000], ['mobile', 390, 844]]) {
    await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: size === 'mobile' })
    await send('Page.navigate', { url: origin+'/technical/university-email' })
    await wait("document.querySelector('tbody tr')?.textContent.includes('R24011002')")
    assert.equal(await evaluate("document.querySelector('[role=alert]')?.textContent || ''"), '')
    await screenshot(size+'-list')
    await input(searchSelector, 'أحمد')
    await wait("document.querySelectorAll('tbody tr').length===1 && document.querySelector('tbody tr').textContent.includes('R24011002')")
    await input(searchSelector, '  أحمد  ')
    await wait("document.querySelector('tbody tr')?.textContent.includes('R24011002')")
    await input(searchSelector, '')
    await wait("document.querySelectorAll('tbody tr').length===15")
    await click('التالي')
    await wait("document.querySelectorAll('tbody tr').length===15 && !document.querySelector('tbody tr').textContent.includes('R24011002')")
    await click('السابق')
    await wait("document.querySelector('tbody tr')?.textContent.includes('R24011002')")
    await input(searchSelector, 'R24011002')
    await wait("document.querySelectorAll('tbody tr').length===1")
    await click('تجهيز البريد'); await wait("document.querySelector('#english-first-name')")
    const name = size === 'desktop' ? 'ahmad' : 'omar'
    await input('#english-first-name', name); await click('حفظ المسودة')
    await wait(`document.querySelector('tbody tr')?.textContent.includes('${name}.r24011002@alrowaduni.edu.sy') && document.querySelector('tbody tr').textContent.includes('مسودة محفوظة')`)
    await wait("document.querySelector('[role=status]')?.textContent.includes('حُفظت المسودة محليًا') && !document.querySelector('#english-first-name').disabled")
    const saved = await request('/students/1')
    assert.equal(saved.status, 200); assert.equal(saved.body.data.draft.english_first_name, name)
    assert.equal(saved.body.data.draft.provisioning_status, 'draft'); assert.equal(saved.body.data.draft.handover_status, 'not_delivered')
    // Reopen using actual server state, not the component's in-memory draft.
    await send('Page.reload')
    await wait("document.querySelector('tbody tr')?.textContent.includes('R24011002')")
    await input(searchSelector, 'R24011002'); await wait("document.querySelectorAll('tbody tr').length===1")
    await click('تجهيز البريد'); await wait(`document.querySelector('#english-first-name')?.value==='${name}'`)
    await evaluate("document.querySelector('#english-first-name').scrollIntoView({block:'center'})")
    await screenshot(size+'-editor')
    assert.ok(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'), size+' viewport overflow')
  }
  const stale = await request('/students/1/draft', actors.technical, { method: 'PUT', body: JSON.stringify({ english_first_name: 'ali', revision: 0 }) })
  assert.equal(stale.status, 409); assert.equal(stale.body.error_code, 'university_email_stale')
  for (const [path, options] of [['/students', {}], ['/students/1', {}], ['/students/1/draft', { method:'PUT', body:JSON.stringify({english_first_name:'ali', revision:2}) }]]) {
    assert.equal((await request(path, actors.unauthorized, options)).status, 403)
  }
  await identity(actors.unauthorized)
  await send('Page.navigate', { url: origin+'/technical/university-email' })
  await wait("location.pathname==='/forbidden' || document.querySelector('main')?.textContent.includes('لا تملك')")
  assert.equal(await evaluate("document.querySelector('#english-first-name')"), null)
  assert.deepEqual(errors, [])
  const writes = requests.filter(request => request.method === 'PUT')
  assert.equal(writes.length, 2, 'Exactly one explicit save per viewport, no retries')
  assert.ok(responses.filter(response => response.status === 200).length > 10)
  assert.ok(responses.every(response => [200,204].includes(response.status)), 'Unexpected UI API response')
  assert.ok(requests.every(request => !new URL(request.url).pathname.includes('/connection')), 'No Mailcow per-student calls')
  console.log(JSON.stringify({ passed: true, api: 'real Laravel with isolated synthetic SQLite; no mocked application APIs', checks: ['blank/whitespace HTTP', 'first open', 'Arabic/number search', 'clear and pagination', 'local summary refreshed on save', 'save and reopen', 'draft/handover separation', 'unauthorized APIs/UI', 'stale save 409', 'desktop/mobile'], writes: writes.length, apiResponses: responses.length, output }))
} finally {
  await send('Target.closeTarget', { targetId: target.id }).catch(() => {})
  ws.close()
}
