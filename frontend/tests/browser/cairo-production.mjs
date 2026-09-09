// Node >=22 + existing Chrome only. Run against `npm run preview`, never production.
// Chrome must use a fresh temporary profile and --remote-debugging-port=9223.
import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { join } from 'node:path'

const origin = process.env.CAIRO_PREVIEW_URL || 'http://127.0.0.1:4173'
const debug = process.env.CAIRO_DEBUG_URL || 'http://127.0.0.1:9223'
for (const url of [origin, debug]) assert.ok(['localhost', '127.0.0.1'].includes(new URL(url).hostname), 'Local test targets only')
const output = process.env.CAIRO_TEST_OUTPUT
assert.ok(output, 'Set CAIRO_TEST_OUTPUT to a temporary artifact directory')
await mkdir(output, { recursive: true })
const targets = await (await fetch(`${debug}/json/list`)).json()
const target = targets.find(t => t.type === 'page' && t.url === 'about:blank')
assert.ok(target, 'Requires a fresh about:blank Chrome tab, not an existing user tab')
const socket = new WebSocket(target.webSocketDebuggerUrl)
await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject })
let id = 0, blockFonts = true
const pending = new Map(), fontResponses = [], blocked = [], evidence = [], requestErrors = [], cancelledRequests = []
function send(method, params = {}) {
  return new Promise((resolve, reject) => {
    const n = ++id, timer = setTimeout(() => { pending.delete(n); reject(new Error(`CDP timeout ${method}`)) }, 10000)
    pending.set(n, { resolve, reject, timer }); socket.send(JSON.stringify({ id: n, method, params }))
  })
}
const student = { student_id: 1, name: 'طالب تجريبي', student_number: 'TEST-123', college: 'كلية اختبار', program: 'برنامج اختبار' }
const operator = { username: 'موظف اختبار', roles: ['exam_officer'], permissions: ['exams.view', 'exams.manage', 'grades.manage', 'students.view'] }
function apiFixture(path) {
  if (path === '/api/user') return operator
  if (path.endsWith('/periods')) return { academic_years: [{ academic_year_id: 1, year_name: '2025 / 2026' }], semesters: [{ semester_id: 1, semester_name: 'الفصل الأول' }] }
  if (path.endsWith('/catalog')) return { student, courses: [{ course_id: 1, course_code: 'TEST-123', course_name: 'مقرر تجريبي', credit_hours: 3, own_program: true, offerings: [] }], meta: { current_page: 1, last_page: 1, total: 1 } }
  if (path.endsWith('/context-preview')) return { academic_year: '2025 / 2026', semester: 'الفصل الأول', program: 'برنامج اختبار', revision: 'a'.repeat(64), create_offering: true, create_registration: true, create_components: true,
    components: [{ key: 'new:theoretical', component_type: 'theoretical', name: 'العلامة النظرية', max_mark: 100, mark: null }] }
  return [] // chrome notifications/optional readonly data; no live backend.
}
socket.onmessage = async event => {
  const message = JSON.parse(event.data)
  if (message.id) {
    const p = pending.get(message.id); if (!p) return
    pending.delete(message.id); clearTimeout(p.timer)
    if (message.error) p.reject(new Error(JSON.stringify(message.error))); else p.resolve(message.result)
  } else if (message.method === 'Network.responseReceived' && message.params.type === 'Font') {
    const r = message.params.response; fontResponses.push({ url: r.url, status: r.status, mime: r.mimeType, cached: !!r.fromDiskCache })
  } else if (message.method === 'Fetch.requestPaused') {
    const { requestId, request } = message.params, url = new URL(request.url)
    try {
      if (url.pathname.startsWith('/api/')) {
        // Fulfilled within the debugger BEFORE network: even hardcoded production API URLs never leave Chrome.
        if (request.method === 'OPTIONS') {
          await send('Fetch.fulfillRequest', { requestId, responseCode: 204, responseHeaders: [
            { name: 'Access-Control-Allow-Origin', value: origin }, { name: 'Access-Control-Allow-Methods', value: 'GET' },
            { name: 'Access-Control-Allow-Headers', value: 'authorization,content-type,accept' },
          ] }); return
        }
        assert.equal(request.method, 'GET', 'This test must never submit a write')
        await send('Fetch.fulfillRequest', { requestId, responseCode: 200, responseHeaders: [{ name: 'Content-Type', value: 'application/json' }, { name: 'Access-Control-Allow-Origin', value: origin }],
          body: Buffer.from(JSON.stringify({ success: true, data: apiFixture(url.pathname) })).toString('base64') })
      } else if (url.origin !== origin || (blockFonts && /\.woff2(?:$|\?)/.test(url.href))) {
        blocked.push(url.href); await send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' })
      } else await send('Fetch.continueRequest', { requestId })
    } catch (e) {
      // Navigation can cancel an intercepted GET before CDP fulfils it.
      if (e.message.includes('Invalid InterceptionId')) cancelledRequests.push(request.url)
      else { requestErrors.push(e.message); console.error(e) }
      await send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' }).catch(() => {})
    }
  }
}
async function evaluate(expression) {
  const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true })
  assert.ok(!r.exceptionDetails, JSON.stringify(r.exceptionDetails)); return r.result.value
}
async function waitFor(expression) {
  for (let i = 0; i < 100; i++) {
    if (await evaluate(`Boolean(${expression})`)) return
    await new Promise(resolve => setTimeout(resolve, 100))
  }
  await screenshot('failure')
  throw new Error(`UI did not become ready: ${expression}\n${await evaluate('document.body.innerText')}`)
}
async function fonts(selector) {
  const { root } = await send('DOM.getDocument', { depth: -1, pierce: true })
  const { nodeId } = await send('DOM.querySelector', { nodeId: root.nodeId, selector })
  assert.ok(nodeId, `Missing node ${selector}`)
  const used = (await send('CSS.getPlatformFontsForNode', { nodeId })).fonts
  if (used.length) return used
  // Native textarea text lives in a UA shadow editor, not a light-DOM text child.
  const { node } = await send('DOM.describeNode', { nodeId, depth: -1, pierce: true })
  async function collect(parent) {
    for (const child of [...(parent.shadowRoots || []), ...(parent.children || [])]) {
      if (child.nodeType === 1 && child.nodeId) used.push(...(await send('CSS.getPlatformFontsForNode', { nodeId: child.nodeId })).fonts)
      await collect(child)
    }
  }
  await collect(node)
  return used
}
async function check(name, selector) {
  // Force layout for newly inserted text, then await any fonts that layout requested.
  await evaluate(`document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect();document.fonts.ready.then(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))))`)
  const used = await fonts(selector)
  assert.ok(used.some(f => f.familyName.includes('Cairo') && f.isCustomFont && f.glyphCount > 0), `${name}: no downloaded Cairo glyphs: ${JSON.stringify(used)}`)
  if (selector.startsWith('#font-')) assert.ok(used.every(f => f.familyName.includes('Cairo') && f.isCustomFont), `${name}: specimen has fallback glyphs`)
  evidence.push({ name, fonts: used }); console.log(`PASS rendered Cairo: ${name}`)
}
async function screenshot(name) {
  // The login's entrance animations are independent of font readiness.
  // Wait for their final visible frame; never alter application styling for a screenshot.
  await new Promise(resolve => setTimeout(resolve, 1200))
  const { data } = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false })
  await writeFile(join(output, `${name}.png`), Buffer.from(data, 'base64'))
}
try {
  await send('Page.enable'); await send('Runtime.enable'); await send('DOM.enable'); await send('CSS.enable'); await send('Network.enable')
  await send('Network.setCacheDisabled', { cacheDisabled: true }); await send('Network.clearBrowserCache')
  await send('CSS.setLocalFontsEnabled', { enabled: false })
  await send('Fetch.enable', { patterns: [{ urlPattern: '*', requestStage: 'Request' }] })
  await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] })
  await send('Page.navigate', { url: `${origin}/login` }); await waitFor("document.querySelector('form label') && document.fonts.status === 'loaded'")
  assert.equal(await evaluate(`new FontFace('BlockedExternalProbe', 'url(https://fonts.gstatic.com/s/cairo/v31/SLXVc1nY6HkvangtZmpQdkhzfH5lkSscQyyS4J0.woff2)').load().then(()=>false,()=>true)`), true)
  assert.ok(blocked.some(url => new URL(url).hostname === 'fonts.gstatic.com'), 'External-font blocking must actually be exercised')
  const fallback = await fonts('form label')
  assert.ok(!fallback.some(f => f.familyName.includes('Cairo')), 'Negative control found local/cached Cairo')
  evidence.push({ name: 'negative: local font files blocked', fonts: fallback })
  blockFonts = false
  for (const width of [1440, 390]) {
    await send('Emulation.setDeviceMetricsOverride', { width, height: width === 390 ? 844 : 1050, deviceScaleFactor: 1, mobile: width === 390 })
    await send('Page.navigate', { url: `${origin}/login` }); await waitFor("document.querySelector('form label') && document.fonts.status === 'loaded'")
    await check(`login label ${width}`, 'form label'); await check(`login button ${width}`, 'form button[type="submit"]')
    await screenshot(`login-${width}`)
    await evaluate(`localStorage.setItem('token','local-font-fixture-only');localStorage.setItem('user',${JSON.stringify(JSON.stringify(operator))})`)
    await send('Page.navigate', { url: `${origin}/exam-board` }); await waitFor("document.querySelector('.dashboard-header') && document.querySelector('main h2') && document.fonts.status === 'loaded'")
    await check(`dashboard header ${width}`, '.page-title-row h1'); await check(`dashboard bold ${width}`, 'main h2')
    await evaluate("document.querySelector('.user-trigger').click()")
    await waitFor("document.querySelector('.user-popover')")
    await check(`user menu ${width}`, '.user-popover > button'); await screenshot(`dashboard-menu-${width}`)
    await send('Page.navigate', { url: `${origin}/exam-board/manual-grade-entry/students/1` })
    await waitFor("document.querySelector('main select')?.options.length > 1")
    await evaluate(`document.querySelectorAll('main select').forEach(el=>{el.value='1';el.dispatchEvent(new Event('change',{bubbles:true}))})`)
    await waitFor("document.querySelector('tbody input') && !document.querySelector('tbody input').disabled && document.fonts.status === 'loaded'")
    await check(`grid normal label ${width}`, 'tbody label'); await check(`period select ${width}`, 'main select')
    await evaluate(`const el=document.querySelector('tbody input');Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set.call(el,'25');el.dispatchEvent(new Event('input',{bubbles:true}));`)
    await check(`input digits ${width}`, 'tbody input')
    await evaluate("[...document.querySelectorAll('tbody button')].find(b=>b.textContent.includes('مراجعة وحفظ')).click()")
    await waitFor("document.querySelector('dialog[open] textarea') && document.fonts.status === 'loaded'")
    await evaluate(`const area=document.querySelector('dialog textarea');area.scrollIntoView();Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype,'value').set.call(area,'اختبار الخط العربي Cairo 123 ١٢٣ ۱۲۳');area.dispatchEvent(new Event('input',{bubbles:true}));new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))`)
    await check(`dialog bold ${width}`, 'dialog h2'); await check(`dialog body ${width}`, 'dialog p'); await check(`textarea ${width}`, 'dialog textarea')
    await screenshot(`dialog-${width}`)
    // Isolated visible specimen exercises every used weight and both scripts under the production CSS.
    await evaluate(`document.querySelector('dialog').close(); const sample=document.createElement('section');sample.id='font-probes';sample.className='font-sans';sample.dir='rtl';sample.style='position:fixed;inset:0;background:white;z-index:9999;overflow:auto;padding:20px;';for(const w of [400,500,600,700,800,900]){const p=document.createElement('p');p.id='font-'+w;p.style.fontWeight=w;p.textContent='الجامعة العربية Cairo étudiant Łódź 0123456789 ٠١٢٣٤٥٦٧٨٩ ۰۱۲۳۴۵۶۷۸۹';sample.append(p)}document.body.append(sample)`)
    await waitFor("document.fonts.status === 'loaded'")
    for (const weight of [400, 500, 600, 700, 800, 900]) await check(`Arabic/Latin/digits ${weight} ${width}`, `#font-${weight}`)
    await screenshot(`weights-${width}`)
    await evaluate("localStorage.clear()")
  }
  assert.ok(fontResponses.length >= 3)
  assert.deepEqual(requestErrors, [])
  assert.ok(fontResponses.every(r => r.status === 200 && !r.cached && new URL(r.url).origin === origin))
  assert.ok(fontResponses.some(r => r.url.includes('latin-ext')))
  await writeFile(join(output, 'evidence.json'), JSON.stringify({ origin, cacheDisabled: true, localFontsDisabled: true, blocked, cancelledRequests, fontResponses, evidence }, null, 2))
  console.log(`PASS production font network + rendered glyph checks; artifacts: ${output}`)
} finally { socket.close() }
