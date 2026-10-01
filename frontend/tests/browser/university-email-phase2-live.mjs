// Production React -> real Laravel -> isolated synthetic SQLite; only Mailcow is faked upstream.
// Requires an explicitly launched local Chrome on 9244 and the test-only Laravel router on 8099.
import assert from 'node:assert/strict'
import { readFile, mkdir, writeFile, stat } from 'node:fs/promises'
import { join } from 'node:path'
const directory = process.env.UNIVERSITY_EMAIL_PHASE2_BROWSER_DIR
assert.ok(directory, 'Export a fresh isolated Phase 2 fixture first')
const actors = JSON.parse(await readFile(join(directory, 'identities.json'), 'utf8'))
const output = join(directory, 'phase2-browser'), origin = 'http://localhost:5173', api = 'http://127.0.0.1:8099'
await mkdir(output, { recursive: true })
const target = await (await fetch('http://127.0.0.1:9244/json/new?about:blank', { method: 'PUT' })).json()
const ws = new WebSocket(target.webSocketDebuggerUrl)
await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject })
let id = 0, bootstrap
const pending = new Map(), errors = [], requests = []
function send(method, params = {}) {
  return new Promise((resolve, reject) => {
    const requestId = ++id, timer = setTimeout(() => reject(Error(method+' timeout')), 15000)
    pending.set(requestId, { resolve, reject, timer }); ws.send(JSON.stringify({ id: requestId, method, params }))
  })
}
ws.onmessage = event => {
  const msg = JSON.parse(event.data)
  if (msg.id) {
    const item = pending.get(msg.id); if (!item) return
    pending.delete(msg.id); clearTimeout(item.timer)
    msg.error ? item.reject(Error(msg.error.message)) : item.resolve(msg.result)
  }
  if (msg.method === 'Runtime.exceptionThrown') errors.push(msg.params.exceptionDetails.text)
  if (msg.method === 'Network.requestWillBeSent' && msg.params.request.url.startsWith(api)) {
    // Do not capture postData/headers/credential response content.
    requests.push({ method: msg.params.request.method, url: msg.params.request.url })
  }
}
async function evaluate(expression) {
  const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true })
  if (result.exceptionDetails) throw Error('Browser expression failed')
  return result.result.value
}
async function wait(expression) {
  for (let count = 0; count < 150; count++) {
    if (await evaluate(`Boolean(${expression})`)) return
    await new Promise(resolve => setTimeout(resolve, 100))
  }
  throw Error('UI condition timeout') // never dump potentially secret DOM in an error
}
async function click(label) {
  await evaluate(`(()=>{const buttons=[...document.querySelectorAll('button')].filter(b=>b.textContent.trim()===${JSON.stringify(label)});if(buttons.length!==1 || buttons[0].disabled)throw Error('button not actionable');buttons[0].click()})()`)
}
async function input(selector, value) {
  await evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(selector)});if(!e)throw Error('missing input');Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set.call(e,${JSON.stringify(value)});e.dispatchEvent(new Event('input',{bubbles:true}))})()`)
}
async function identity(actor) {
  if (bootstrap) await send('Page.removeScriptToEvaluateOnNewDocument', { identifier: bootstrap })
  bootstrap = (await send('Page.addScriptToEvaluateOnNewDocument', { source: `localStorage.clear();localStorage.setItem('token',${JSON.stringify(actor.token)});localStorage.setItem('user',${JSON.stringify(JSON.stringify(actor.identity))});` })).identifier
}
async function screenshot(name) { await writeFile(join(output, name+'.png'), Buffer.from((await send('Page.captureScreenshot', { format: 'png' })).data, 'base64')) }
try {
  await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable')
  await send('Network.setBlockedURLs', { urls: ['https://*'] })
  await send('Network.setCacheDisabled', { cacheDisabled: true })
  await send('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: output })
  await identity(actors.technical)
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false })
  await send('Page.navigate', { url: origin+'/technical/university-email' })
  await wait("document.querySelector('tbody tr')?.textContent.includes('R24011002')")
  await input('input[placeholder*="ابحث باسم الطالب"]', 'R24011002')
  await wait("document.querySelectorAll('tbody tr').length===1")
  await click('تجهيز البريد'); await wait("document.querySelector('#english-first-name')")
  await input('#english-first-name', 'Ahmad'); await click('حفظ المسودة')
  await wait("document.querySelector('[role=status]')?.textContent.includes('حُفظت المسودة')")
  await wait("[...document.querySelectorAll('button')].some(b=>b.textContent==='توليد كلمة مرور'&&!b.disabled)")
  await click('توليد كلمة مرور')
  await wait("[...document.querySelectorAll('button')].some(b=>b.textContent==='إنشاء الصندوق على خادم البريد'&&!b.disabled)")
  await click('إنشاء الصندوق على خادم البريد'); await click('تأكيد العملية')
  await wait("[...document.querySelectorAll('button')].some(b=>b.textContent==='تنزيل الإيصال PDF'&&!b.disabled)")
  assert.equal(await evaluate("document.querySelector('#english-first-name').disabled"), true)
  await screenshot('desktop-confirmed-synthetic')
  await click('تنزيل الإيصال PDF')
  await wait("document.querySelector('[data-email-receipt]')")
  for (let attempt = 0; attempt < 100; attempt++) {
    if (await stat(join(output, 'university-email-receipt.pdf')).then(s=>s.size>1000).catch(()=>false)) break
    await new Promise(resolve => setTimeout(resolve, 100))
  }
  const pdf = await readFile(join(output, 'university-email-receipt.pdf'))
  assert.equal(pdf.subarray(0, 4).toString(), '%PDF')
  assert.equal((pdf.toString('latin1').match(/\/Type \/Page\b/g) || []).length, 1)
  assert.equal(await evaluate("[...document.querySelectorAll('button')].some(b=>b.textContent.includes('تأكيد استلام'))"), false)
  await click('إنهاء وإخفاء بيانات الدخول')
  await wait("!document.querySelector('[data-email-receipt]')")
  await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true })
  await send('Page.reload'); await wait("document.querySelector('tbody tr')?.textContent.includes('R24011002')")
  await input('input[placeholder*="ابحث باسم الطالب"]', 'R24011002'); await wait("document.querySelectorAll('tbody tr').length===1")
  await click('تجهيز البريد'); await wait("document.querySelector('#english-first-name')?.disabled")
  assert.equal(await evaluate("[...document.querySelectorAll('button')].some(b=>b.textContent==='تنزيل الإيصال PDF')"), false, 'A reload cannot recover passwords from storage')
  await screenshot('mobile-reopened')
  assert.ok(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'))
  await identity(actors.student); await send('Page.navigate', { url: origin+'/student/university-email' })
  await wait("document.querySelector('main')?.textContent.includes('ahmad.r24011002@alrowaduni.edu.sy')")
  await screenshot('mobile-student-self')
  await identity(actors.unauthorized); await send('Page.navigate', { url: origin+'/technical/university-email' })
  await wait("location.pathname==='/forbidden' || document.querySelector('main')?.textContent.includes('لا تملك')")
  assert.deepEqual(errors, [])
  assert.equal(requests.filter(r=>r.method==='POST' && r.url.endsWith('/execute')).length, 1)
  console.log(JSON.stringify({ passed: true, verification: 'React production build + real Laravel HTTP + isolated SQLite + upstream Mailcow fake, not production/MariaDB', output }))
} finally { await send('Target.closeTarget', { targetId: target.id }).catch(()=>{}); ws.close() }
