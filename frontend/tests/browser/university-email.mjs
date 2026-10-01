// Existing Chrome, built React, synthetic API responses only; no Mailcow/Laravel live claim.
import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { join } from 'node:path'
const origin = process.env.EMAIL_PREVIEW_URL || 'http://localhost:5173'
const debug = process.env.EMAIL_DEBUG_URL || 'http://127.0.0.1:9244'
const output = process.env.EMAIL_BROWSER_OUTPUT
assert.ok(output)
assert.ok(['localhost', '127.0.0.1'].includes(new URL(origin).hostname))
const target = await (await fetch(debug+'/json/new?about:blank', { method: 'PUT' })).json()
const ws = new WebSocket(target.webSocketDebuggerUrl)
await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject })
let serial = 0, stored = null, forceConflict = false, loseResponse = false
const pending = new Map(), errors = [], writes = []
const actor = { user_id: 8, username: 'technical.synthetic', roles: ['technical_team'], permissions: ['technical_portal.access', 'user_accounts.view', 'university_email.view', 'university_email.manage', 'university_email.check_connection'], access_scopes: [{ type: 'university', id: 91 }] }
const student = { student_id: 1, full_name: 'أحمد اختبار', student_number: 'R24011002', college: 'كلية تجريبية', program: 'برنامج تجريبي' }
const detail = () => ({ data: { student, draft: stored, settings: { domain: 'alrowaduni.edu.sy', quota_mb: 50 } } })
function send(method, params = {}) {
  return new Promise((resolve, reject) => { const id = ++serial; const timer = setTimeout(() => { pending.delete(id); reject(Error(method+' timeout')) }, 12000); pending.set(id, { resolve, reject, timer }); ws.send(JSON.stringify({ id, method, params })) })
}
ws.onmessage = async event => {
  const m = JSON.parse(event.data)
  if (m.id) { const p = pending.get(m.id); if (!p) return; pending.delete(m.id); clearTimeout(p.timer); m.error ? p.reject(Error(JSON.stringify(m.error))) : p.resolve(m.result); return }
  if (m.method === 'Runtime.exceptionThrown') errors.push(m.params.exceptionDetails.text)
  if (m.method !== 'Fetch.requestPaused') return
  const { requestId, request } = m.params, url = new URL(request.url)
  try {
    if (!url.pathname.startsWith('/api/')) {
      if (url.origin === origin) await send('Fetch.continueRequest', { requestId })
      else await send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' })
      return
    }
    let data = { data: [] }, status = 200
    if (request.method === 'OPTIONS') {
      await send('Fetch.fulfillRequest', { requestId, responseCode: 204, responseHeaders: [{ name: 'Access-Control-Allow-Origin', value: origin }, { name: 'Access-Control-Allow-Headers', value: '*' }, { name: 'Access-Control-Allow-Methods', value: 'GET,PUT,OPTIONS' }] }); return
    }
    if (url.pathname.endsWith('/user')) data = { success: true, data: actor }
    else if (url.pathname.endsWith('/technical/accounts/options')) data = { data: { roles: [], statuses: [], actor: { can_manage: false } } }
    else if (url.pathname.endsWith('/technical/accounts')) data = { data: { data: [{ user_id: 8, username: actor.username, email: 'synthetic@example.invalid', roles: [], status: { code: 'active' } }], meta: { total: 1, per_page: 15, last_page: 1 } } }
    else if (url.pathname.endsWith('/university-email/students')) data = { data: [student], meta: { total: 1, per_page: 15, last_page: 1 } }
    else if (url.pathname.endsWith('/university-email/students/1')) data = detail()
    else if (url.pathname.endsWith('/university-email/students/1/draft')) {
      assert.equal(request.method, 'PUT'); const payload = JSON.parse(request.postData); writes.push(payload)
      if (forceConflict) { forceConflict = false; stored = { ...stored, english_first_name: 'omar', email_address: 'omar.r24011002@alrowaduni.edu.sy', revision: stored.revision + 1 }; status = 409; data = { error_code: 'university_email_stale', message: 'تغيرت المسودة' } }
      else { const name = payload.english_first_name.trim().toLowerCase(); stored = { english_first_name: name, email_address: name+'.r24011002@alrowaduni.edu.sy', revision: (stored?.revision || 0) + 1, quota_mb: 50, provisioning_status: 'draft', handover_status: 'not_delivered' }; data = detail() }
      if (loseResponse) { loseResponse = false; await send('Fetch.failRequest', { requestId, errorReason: 'ConnectionFailed' }); return }
    } else if (url.pathname.endsWith('/university-email/connection')) { status = 503; data = { error_code: 'mailcow_configuration_missing', message: 'إعدادات اتصال البريد غير مكتملة أو غير صالحة.' } }
    await send('Fetch.fulfillRequest', { requestId, responseCode: status, responseHeaders: [{ name: 'Content-Type', value: 'application/json' }, { name: 'Access-Control-Allow-Origin', value: origin }, { name: 'Access-Control-Allow-Headers', value: '*' }, { name: 'Access-Control-Allow-Methods', value: 'GET,PUT,OPTIONS' }], body: Buffer.from(JSON.stringify(data)).toString('base64') })
  } catch (e) { errors.push(e.message); await send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' }).catch(() => {}) }
}
async function evaluate(expression) { const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }); if (r.exceptionDetails) throw Error(JSON.stringify(r.exceptionDetails)); return r.result.value }
async function wait(expression) { for (let i = 0; i < 100; i++) { if (await evaluate(`Boolean(${expression})`)) return; await new Promise(r => setTimeout(r, 100)) } await screenshot('failure'); throw Error('UI timeout '+expression+' '+await evaluate('document.body.innerText.slice(-1800)')+' '+JSON.stringify(errors)) }
async function click(text) { await evaluate(`(()=>{const b=[...document.querySelectorAll('button')].filter(b=>b.textContent.trim()===${JSON.stringify(text)});if(b.length!==1)throw Error('ambiguous button');b[0].click()})()`) }
async function input(value) { await evaluate(`(()=>{const e=document.querySelector('#english-first-name');Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set.call(e,${JSON.stringify(value)});e.dispatchEvent(new Event('input',{bubbles:true}))})()`) }
async function screenshot(name) { await mkdir(output, { recursive: true }); await writeFile(join(output,name+'.png'), Buffer.from((await send('Page.captureScreenshot', { format: 'png' })).data, 'base64')) }
try {
  await send('Runtime.enable'); await send('Page.enable'); await send('Fetch.enable', { patterns: [{ urlPattern: '*' }] })
  await send('Page.addScriptToEvaluateOnNewDocument', { source: `localStorage.clear();localStorage.setItem('token','synthetic-only');localStorage.setItem('user',${JSON.stringify(JSON.stringify(actor))});` })
  for (const [size, width, height] of [['desktop', 1440, 1000], ['mobile', 390, 844]]) {
    await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: size === 'mobile' })
    await send('Page.navigate', { url: origin+'/technical/accounts' }); await wait("document.querySelector('h2')?.textContent.includes('الحسابات') && document.querySelector('tbody tr')")
    await screenshot('reference-'+size)
  }
  if (process.env.EMAIL_REFERENCE_ONLY !== '1') {
    for (const [size, width, height] of [['desktop',1440,1000],['mobile',390,844]]) {
      await send('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:size==='mobile'})
      await send('Page.navigate', { url: origin+'/technical/university-email' })
      await wait("document.querySelector('tbody tr')?.textContent.includes('R24011002')")
      await click('تجهيز البريد'); await wait("document.querySelector('#english-first-name')")
      await input('Ahmad'); await wait("document.querySelector('#email-preview')?.textContent.includes('ahmad.r24011002')")
      if (size === 'desktop') {
        await evaluate("(()=>{const a=document.querySelector('a[href=\"/technical/accounts\"]');if(!a)throw Error('missing sidebar link');a.click()})()")
        await wait("document.querySelector('dialog[open]')")
        await click('إلغاء')
        assert.equal(await evaluate("document.querySelector('#english-first-name').value"), 'Ahmad')
        assert.equal(await evaluate('location.pathname'), '/technical/university-email')
      }
      await click('حفظ المسودة'); await wait("document.querySelector('main')?.textContent.includes('حُفظت المسودة محليًا')")
      assert.equal(await evaluate("document.querySelector('#english-first-name').value"), 'ahmad')
      await screenshot('email-'+size)
      await evaluate("document.querySelector('#english-first-name').scrollIntoView({block:'center'})")
      await screenshot('editor-'+size)
      assert.ok(await evaluate('document.documentElement.scrollWidth <= innerWidth+2'))
    }
    await input('ali'); forceConflict = true; await click('حفظ المسودة')
    await wait("document.querySelector('main')?.textContent.includes('مراجعة النسخة المحفوظة')")
    assert.equal(await evaluate("document.querySelector('#english-first-name').value"), 'ali')
    const before = writes.length; await click('مراجعة النسخة المحفوظة')
    await wait("document.querySelector('main')?.textContent.includes('omar.r24011002')")
    assert.equal(await evaluate("document.querySelector('#english-first-name').value"), 'ali')
    assert.equal(writes.length, before)
    await click('اعتماد النسخة المحفوظة وإلغاء مسودتي'); await wait("document.querySelector('#english-first-name').value==='omar'")
    await input('sami'); loseResponse = true; await click('حفظ المسودة')
    await wait("document.querySelector('main')?.textContent.includes('مراجعة النسخة المحفوظة')")
    assert.equal(await evaluate("document.querySelector('#english-first-name').value"), 'sami')
    assert.equal(writes.length, before + 1)
    await click('مراجعة النسخة المحفوظة'); await wait("document.querySelector('main')?.textContent.includes('sami.r24011002')")
    await click('اعتماد النسخة المحفوظة وإلغاء مسودتي')
    await click('فحص اتصال Mailcow'); await wait("document.querySelector('main')?.textContent.includes('إعدادات اتصال البريد غير مكتملة')")
    await input('ahmad'); await click('حفظ المسودة'); await wait("document.querySelector('#english-first-name').value==='ahmad'")
    assert.ok(writes.every(p => Object.keys(p).sort().join(',') === 'english_first_name,revision'))
  }
  assert.deepEqual(errors, [])
  console.log(JSON.stringify({ passed: true, referenceOnly: process.env.EMAIL_REFERENCE_ONLY === '1', syntheticApi: true, writes: writes.length, output }))
} finally { ws.close() }
