// Live browser verification of the university-owner payroll sheet, Home, configurable columns/formulas and exports (desktop + narrow screen).
//
// Real production-style React (Vite dev server) against the REAL Laravel API on a disposable MariaDB database,
// with synthetic data only. Nothing here is mocked except a Node relay that adds CORS headers (the API allows only
// the production origins) and can block requests to simulate failed saves.
//
// Setup (see docs/owner-payroll.md, "Browser verification"):
//   1. backend:  OWNER_PAYROLL_FIXTURE_CONFIRM=disposable DB_...=<throwaway db> php tests/Support/owner_payroll_browser_fixture.php tokens.json
//                php -S 127.0.0.1:8741 -t public public/index.php    (same DB_* env)
//   2. frontend: VITE_API_BASE_URL=http://127.0.0.1:8741/api npx vite --port 5188 --host 127.0.0.1
//   3. run:      OWNER_E2E_TOKENS=tokens.json PLAYWRIGHT_REQUIRE=/dir/with/node_modules/playwright-core/ node tests/browser/owner-payroll.mjs
// playwright-core is intentionally not a project dependency; Chromium comes from the environment (CHROME=/path).
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import { createRequire } from 'node:module'
import os from 'node:os'
import path from 'node:path'

const require = createRequire(path.resolve(process.env.PLAYWRIGHT_REQUIRE ?? '.', 'package.json'))
const { chromium } = require('playwright-core')
const API = process.env.OWNER_E2E_API ?? 'http://127.0.0.1:8741/api'
const APP = process.env.OWNER_E2E_APP ?? 'http://127.0.0.1:5188'
const OUT = process.env.OWNER_E2E_OUTPUT ?? fs.mkdtempSync(path.join(os.tmpdir(), 'owner-payroll-'))
const tokens = JSON.parse(fs.readFileSync(process.env.OWNER_E2E_TOKENS, 'utf8'))
fs.mkdirSync(OUT, { recursive: true })

const results = []
const check = (name, condition, detail = '') => { results.push({ name, ok: Boolean(condition), detail }); console.log(`${condition ? 'PASS' : 'FAIL'}  ${name}${condition ? '' : `  -> ${detail}`}`) }
const equal = (name, actual, expected) => check(name, JSON.stringify(actual) === JSON.stringify(expected), `expected ${JSON.stringify(expected)} got ${JSON.stringify(actual)}`)

async function api(token, method, route, body) {
  const response = await fetch(`${API}${route}`, { method, headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined })
  return { status: response.status, json: await response.json().catch(() => null) }
}
const owner = tokens.owner
const sheet = async (query = '') => (await api(owner, 'GET', `/v1/owner/payroll/sheet${query ? `?${query}` : ''}`)).json
const serverRow = async id => (await sheet()).data.find(row => row.id === id)
const val = (row, key) => row.cells[key].v
const amounts = row => [val(row, 'fixed_salary'), val(row, 'other_deductions'), val(row, 'compensation')]
const configOf = async () => (await api(owner, 'GET', '/v1/owner/payroll/config')).json.data
const syp = value => { const [w, f] = value.split('.'); return `${w.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${f} ل.س`.replace('-,', '-') }


// ── seed (synthetic) ────────────────────────────────────────────────────
const teaching = (await api(owner, 'POST', '/v1/owner/payroll/bodies', { name: 'هيئة التدريس' })).json.data
const admin = (await api(owner, 'POST', '/v1/owner/payroll/bodies', { name: 'الهيئة الإدارية' })).json.data
const names = ['سامر العلي', 'ليلى الحسن', 'نور الدين خالد', 'رامي يوسف', 'هدى مصطفى', 'عمر ناصر', 'ريم سليمان', 'خالد إبراهيم']
const places = ['afrin', 'jarablus', 'afrin_jarablus']
const ids = []
for (let i = 1; i <= 40; i += 1) {
  const created = await api(owner, 'POST', '/v1/owner/payroll/employees', {
    employee_number: String(i).padStart(4, '0'), full_name: `${names[i % 8]} ${i}`, job_title: i % 2 ? 'أستاذ مساعد' : 'محاسب', body_id: i % 2 ? teaching.id : admin.id,
    workplace: i === 5 ? 'other' : places[i % 3], workplace_other: i === 5 ? 'إدلب' : null, academic_level: i % 7 === 0 ? null : i % 3 ? 'دكتوراه' : '__blank__',
  })
  ids.push(created.json.data.id)
}
const id = n => ids[n - 1] // employee "n" (number 000n)
// Fixed salaries for 30 of them so Home has real figures, completeness is mixed, and a few rows carry warnings.
{
  const cfg = await configOf()
  const rows = (await sheet()).data
  const changes = rows.slice(0, 30).map((row, i) => ({ employee_id: row.id, expected_revision: row.entry_revision, values: { fixed_salary: String(20000 + i * 3100), compensation: i % 3 ? String(5000 + i * 100) : '0' } }))
  const seeded = await api(owner, 'PATCH', '/v1/owner/payroll/values', { changes, config_revision: cfg.revision })
  assert.equal(seeded.status, 200)
}

// ── browser plumbing ────────────────────────────────────────────────────
const patches = []
let blockPatches = false // fail before the request leaves the browser
let loseResponse = false // the request reaches the server but the answer is lost (uncertain failure)
let delayPatches = 0 // ms to hold a save before it is sent
async function openBrowser({ width, height, mobile = false, role = 'owner', dropPermissions = [] }) {
  const browser = await chromium.launch({ executablePath: process.env.CHROME ?? '/opt/pw-browsers/chromium', args: ['--no-sandbox'] })
  const context = await browser.newContext({ viewport: { width, height }, permissions: ['clipboard-read', 'clipboard-write'], acceptDownloads: true, isMobile: mobile, hasTouch: mobile })
  const me = await api(tokens[role], 'GET', '/user')
  // A view-only account is simulated by removing a permission from the user profile the SPA reads (the server still enforces its own).
  const user = { ...me.json.data, permissions: (me.json.data.permissions ?? []).filter(code => !dropPermissions.includes(code)) }
  await context.addInitScript(([token, signedIn]) => { localStorage.setItem('token', token); localStorage.setItem('user', JSON.stringify(signedIn)) }, [tokens[role], user])
  await context.route(`${API}/**`, async route => {
    const request = route.request()
    const cors = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*', 'access-control-allow-methods': '*', 'access-control-expose-headers': 'content-disposition' }
    if (request.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: cors })
    if (request.method() === 'PATCH' && request.url().endsWith('/payroll/values')) {
      patches.push(JSON.parse(request.postData()))
      if (delayPatches) await new Promise(resolve => setTimeout(resolve, delayPatches))
      if (blockPatches) return route.abort('failed')
    }
    const headers = { ...request.headers() }
    delete headers.host; delete headers['content-length']
    const upstream = await fetch(request.url(), { method: request.method(), headers, body: ['GET', 'HEAD'].includes(request.method()) ? undefined : request.postData() })
    let body = Buffer.from(await upstream.arrayBuffer())
    if (dropPermissions.length && /\/api\/user$/.test(request.url())) { const doc = JSON.parse(body.toString()); doc.data.permissions = (doc.data.permissions ?? []).filter(code => !dropPermissions.includes(code)); body = Buffer.from(JSON.stringify(doc)) }
    if (loseResponse && request.method() === 'PATCH' && request.url().endsWith('/payroll/values')) { loseResponse = false; return route.abort('failed') }
    const out = {}
    upstream.headers.forEach((value, key) => { if (!['content-encoding', 'transfer-encoding', 'content-length', 'connection'].includes(key)) out[key] = value })
    return route.fulfill({ status: upstream.status, headers: { ...out, ...cors }, body })
  })
  const page = await context.newPage()
  const errors = []
  page.on('pageerror', error => errors.push(error.message))
  page.on('console', message => { if (message.type() === 'error' && !/unique "key" prop/.test(message.text()) && !/Failed to load resource/.test(message.text())) errors.push(message.text().slice(0, 240)) })
  return { browser, context, page, errors }
}

const shot = (page, name) => page.screenshot({ path: path.join(OUT, `${name}.png`) })
const cellLoc = (page, who, prop) => page.locator(`.payroll-grid .rgCell[data-id="${who}"][data-f="${prop}"]`)
const rowsInGrid = page => page.evaluate(async () => (await document.querySelector('revo-grid').getSource()).map(r => r.id))
const reveal = (page, prop) => page.evaluate(p => document.querySelector('revo-grid').scrollToColumnProp(p), prop)
const rowIndex = (page, who) => page.evaluate(async target => (await document.querySelector('revo-grid').getSource()).findIndex(row => row.id === target), who)
async function select(page, who, prop) {
  await page.evaluate(async target => { const grid = document.querySelector('revo-grid'); await grid.scrollToRow((await grid.getSource()).findIndex(row => row.id === target)) }, who)
  await reveal(page, prop)
  await page.waitForTimeout(80)
  await cellLoc(page, who, prop).click()
}
const focused = page => page.evaluate(async () => {
  const grid = document.querySelector('revo-grid'); const f = await grid.getFocused()
  return f ? { prop: f.column?.prop, id: (await grid.getSource())[f.cell.y]?.id } : null
})
/** Press a navigation key and let RevoGrid's deferred focus change settle (it moves focus a tick later). */
const key = async (page, ...keys) => { for (const k of keys) { await page.keyboard.press(k); await page.waitForTimeout(140) } }
const showCell = async (page, who, prop) => {
  await page.evaluate(async target => { const grid = document.querySelector('revo-grid'); await grid.scrollToRow((await grid.getSource()).findIndex(row => row.id === target)) }, who)
  await reveal(page, prop)
  await page.waitForTimeout(120)
}
const text = async (page, who, prop) => { await showCell(page, who, prop); return (await cellLoc(page, who, prop).innerText()).trim() }
const savedStable = async page => { await page.waitForTimeout(250); await page.waitForFunction(() => !document.querySelector('[data-testid="payroll-toolbar-2"]')?.innerText.includes('جارٍ الحفظ'), null, { timeout: 8000 }); await page.waitForTimeout(150) }
const writeClipboard = (page, value) => page.evaluate(v => navigator.clipboard.writeText(v), value)
const readClipboard = page => page.evaluate(() => navigator.clipboard.readText())
// ═══ HOME + DESKTOP (wide) ═════════════════════════════════════════════
{
  const wide = await openBrowser({ width: 1900, height: 1000 })
  const { page } = wide
  await page.goto(`${APP}/owner`)
  await page.waitForSelector('aside nav a')
  equal('navigation has exactly two items (Home, Payroll)', await page.$$eval('aside nav a', els => els.map(e => e.textContent.replace(/Home|Payroll/, '').trim())), ['الرئيسية', 'الرواتب'])
  check('account controls (logout menu) remain in the shared header', await page.locator('header').first().innerText().then(t => t.includes('owner.synthetic')))
  await page.waitForSelector('[data-testid="home-net"]')
  await shot(page, '01-home-desktop')
  const homeServer = (await api(owner, 'GET', '/v1/owner/home')).json.data
  check('Home heading and primary action', (await page.locator('main h2').first().innerText()) === 'نظرة عامة' && (await page.getByRole('link', { name: /فتح كشف الرواتب/ }).count()) === 1)
  equal('Home principal figure is the total net payable in Syrian pounds', await page.locator('[data-testid="home-net"]').innerText(), syp(homeServer.totals.net_payable))
  check('Home states how many records are complete / need attention', (await page.locator('[data-testid="home-counts"]').innerText()).includes('30') && (await page.locator('[data-testid="home-counts"]').innerText()).includes('10 يحتاج إلى معالجة'))
  check('Home labels the total as excluding incomplete records (never zero-filled)', (await page.locator('[data-testid="home-excluded"]').innerText()).includes('10 سجلًا غير مكتمل'))
  check('Home has the five-figure breakdown, by-body and by-workplace tables', (await page.locator('[data-testid="home-breakdown"] dt').count()) === 5 && (await page.locator('[data-testid="by-body_id"] tbody tr').count()) === 2 && (await page.locator('[data-testid="by-workplace"] tbody tr').count()) >= 3)
  check('Home lists what needs attention', (await page.locator('[data-testid="attention-list"] li').count()) === 10)
  check('Home has no currency other than Syrian pounds', !(await page.locator('main').innerText()).match(/\$|USD|دولار/))
  check('Home adds no sidebar items, charts or month selector', (await page.locator('main canvas, main svg.recharts-surface, main select').count()) === 0)
  await page.locator('[data-testid="by-body_id"] a', { hasText: 'هيئة التدريس' }).click()
  await page.waitForURL(/\/owner\/payroll\?body_id=/)
  await page.waitForSelector('.payroll-grid .rgCell'); await page.waitForTimeout(900)
  equal('a Home body row opens Payroll already filtered by that body', await rowsInGrid(page), (await sheet(`body_id=${teaching.id}`)).data.map(r => r.id))
  equal('...and the filter control shows it', await page.getByLabel('الهيئة').inputValue(), String(teaching.id))
  await page.goto(`${APP}/owner`); await page.waitForSelector('[data-testid="home-net"]')
  await page.getByRole('link', { name: /10 يحتاج إلى معالجة/ }).click()
  await page.waitForSelector('.payroll-grid .rgCell'); await page.waitForTimeout(900)
  equal('"needs attention" opens Payroll filtered to incomplete records', await rowsInGrid(page), (await sheet('completeness=incomplete')).data.map(r => r.id))

  await page.goto(`${APP}/owner/payroll`)
  await page.waitForSelector('.payroll-grid .rgCell')
  await page.waitForTimeout(700)
  const GROUP_NAMES = ['بيانات الموظف', 'الراتب', 'التعويض', 'الاقتطاعات', 'الصافي']
  const reported = await page.evaluate(async () => (await document.querySelector('revo-grid').getColumns()).map(c => c.prop)) // RevoGrid reports an RTL grid from its left edge: pinned columns first
  const order = [...reported.slice(0, 2).reverse(), ...reported.slice(2).reverse()]
  equal('column order (reading order): identity, then salary, compensation, deductions, net groups', order, ['employee_number', 'full_name', 'job_title', 'body_name', 'workplace_label', 'academic_level', 'fixed_salary', 'salary_adjustment', 'salary_entitlement', 'salary_taxable_base', 'compensation', 'compensation_adjustment', 'compensation_entitlement', 'combined_taxable_base', 'insurance', 'salary_tax', 'compensation_tax', 'other_deductions', 'total_deductions', 'gross_entitlement', 'net_salary', 'net_compensation', 'total_net_payable'])
  const seenGroups = new Set()
  for (const prop of ['fixed_salary', 'compensation', 'insurance', 'total_net_payable']) {
    await reveal(page, prop); await page.waitForTimeout(250)
    for (const t of await page.$$eval('.payroll-grid .rgHeaderCell', els => els.map(e => e.textContent.trim()))) if (GROUP_NAMES.includes(t)) seenGroups.add(t)
  }
  await reveal(page, 'employee_number')
  check('columns are visually grouped (employee data, salary, compensation, deductions, net)', GROUP_NAMES.every(g => seenGroups.has(g)), JSON.stringify([...seenGroups]))
  const bar = await page.locator('main').innerText()
  for (const label of ['إضافة موظف', 'إدارة الهيئات', 'إدارة الأعمدة والمعادلات', 'عرض مختصر', 'عرض تفصيلي']) check(`toolbar: ${label}`, bar.includes(label))
  const gridBox = await page.locator('.payroll-grid').boundingBox()
  check('the grid gets most of the screen height', gridBox.height >= 1000 * 0.55, JSON.stringify(gridBox))
  check('a totals footer is pinned inside the grid', (await page.locator('.payroll-grid .rgCell.pg-footer').count()) > 5)
  await shot(page, '02-payroll-desktop-wide')
  const blank = await text(page, id(31), 'fixed_salary')
  const unavailable = await text(page, id(31), 'total_net_payable')
check('new financial inputs are blank (not zero) and the dependent results are shown as unavailable', blank === '' && val(await serverRow(id(31)), 'fixed_salary') === null && unavailable === '—', JSON.stringify({ blank, unavailable }))
  check('currency is Syrian pounds everywhere on the page (no $)', !(await page.locator('main').innerText()).includes('$'))
  await wide.browser.close()
  check('no uncaught page errors (wide)', wide.errors.length === 0, wide.errors.join(' | '))
}

const desk = await openBrowser({ width: 1440, height: 900 })
const { page } = desk
await page.goto(`${APP}/owner/payroll`)
await page.waitForSelector('.payroll-grid .rgCell')
await page.waitForTimeout(500)

// ── editing ────────────────────────────────────────────────────────────
await select(page, id(31), 'fixed_salary')
await page.keyboard.type('96600', { delay: 40 })
await page.keyboard.press('Enter')
await savedStable(page)
equal('typed edit saved (server)', amounts(await serverRow(id(31))), ['96600.00', null, null])
await reveal(page, 'total_net_payable')
equal('net payable recalculated for the row (blank optional inputs count as zero)', await text(page, id(31), 'total_net_payable'), '78,246.30')
await select(page, id(31), 'compensation')
await page.keyboard.type('55200'); await page.keyboard.press('Enter'); await savedStable(page)
await reveal(page, 'total_net_payable')
equal('reference case in the real UI: 125,166.30 net payable', await text(page, id(31), 'total_net_payable'), '125,166.30')
equal('server agrees (reference case)', val(await serverRow(id(31)), 'total_net_payable'), '125166.30')
check('saved state is shown', (await page.locator('[data-testid="payroll-toolbar-2"]').innerText()).includes('تم الحفظ'))

await select(page, id(32), 'other_deductions')
await page.keyboard.press('F2'); await page.waitForTimeout(250); await page.keyboard.type('99'); await page.waitForTimeout(150); await page.keyboard.press('Escape')
await page.waitForTimeout(400)
equal('F2 then Escape cancels the edit', [await text(page, id(32), 'other_deductions'), val(await serverRow(id(32)), 'other_deductions')], ['', null])
await page.keyboard.press('F2'); await page.waitForTimeout(250); await page.keyboard.type('25'); await page.keyboard.press('Enter'); await savedStable(page)
equal('F2 edit commits', val(await serverRow(id(32)), 'other_deductions'), '25.00')

await select(page, id(33), 'compensation'); await page.waitForTimeout(250)
await cellLoc(page, id(33), 'compensation').dblclick(); await page.waitForTimeout(500); await page.keyboard.type('7'); await page.keyboard.press('Enter'); await savedStable(page)
equal('double-click starts an edit', val(await serverRow(id(33)), 'compensation'), '7.00')

const patchesBefore = patches.length
await select(page, id(34), 'fixed_salary')
await page.keyboard.type('abc'); await page.keyboard.press('Enter'); await page.waitForTimeout(400)
check('invalid typed value is refused client-side (no request, message shown, editor stays on the value)', patches.length === patchesBefore && (await page.locator('main').innerText()).includes('قيمة غير مقبولة') && (await page.locator('revogr-edit input.pg-input-invalid').count()) === 1)
await page.keyboard.press('Escape'); await page.waitForTimeout(300)
await select(page, id(34), 'fixed_salary')
await page.keyboard.type('-5'); await page.keyboard.press('Enter'); await page.waitForTimeout(400)
check('negative typed value is refused in an unsigned column (no request)', patches.length === patchesBefore && val(await serverRow(id(34)), 'fixed_salary') === null)
await page.keyboard.press('Escape'); await page.waitForTimeout(300)
await select(page, id(34), 'salary_adjustment')
await page.keyboard.type('-1,500.50'); await page.keyboard.press('Enter'); await savedStable(page)
equal('a signed column accepts a negative adjustment with thousands separator', val(await serverRow(id(34)), 'salary_adjustment'), '-1500.50')
await select(page, id(34), 'fixed_salary')
await page.keyboard.type('0'); await page.keyboard.press('Enter'); await savedStable(page)
equal('explicit zero stored as 0.00 and shown', [val(await serverRow(id(34)), 'fixed_salary'), await text(page, id(34), 'fixed_salary')], ['0.00', '0.00'])
await shot(page, '03-after-edits')

// ── formula bar, calculated cells are read-only ────────────────────────
await select(page, id(31), 'salary_tax')
await page.waitForTimeout(500)
equal('the formula bar shows the selected calculated column\'s formula in readable names', await page.locator('[data-testid="formula-text"]').innerText(), 'ƒ = [الوعاء الضريبي للراتب] * [نسبة ضريبة الدخل]')
equal('...and the selected value', await page.locator('[data-testid="cell-value"]').innerText(), '11,591.70')
await page.keyboard.type('5'); await page.waitForTimeout(300)
check('typing in a calculated cell starts no edit', (await page.locator('revogr-edit input').count()) === 0)
await page.keyboard.press('Delete'); await page.waitForTimeout(300)
check('Delete on a calculated cell changes nothing', val(await serverRow(id(31)), 'salary_tax') === '11591.70' && (await page.locator('main').innerText()).includes('محمية'))

// ── keyboard navigation (reading order) ────────────────────────────────
await select(page, id(36), 'fixed_salary')
await key(page, 'ArrowLeft'); equal('ArrowLeft moves visually left', await focused(page), { prop: 'salary_adjustment', id: id(36) })
await key(page, 'ArrowRight'); equal('ArrowRight moves visually right', await focused(page), { prop: 'fixed_salary', id: id(36) })
await key(page, 'ArrowDown'); equal('ArrowDown', await focused(page), { prop: 'fixed_salary', id: id(37) })
await key(page, 'ArrowUp'); equal('ArrowUp', await focused(page), { prop: 'fixed_salary', id: id(36) })
await key(page, 'Tab'); equal('Tab follows the Arabic reading direction (to the left)', await focused(page), { prop: 'salary_adjustment', id: id(36) })
await key(page, 'Shift+Tab'); equal('Shift+Tab goes back (to the right)', await focused(page), { prop: 'fixed_salary', id: id(36) })
await key(page, 'Enter'); equal('Enter moves down', await focused(page), { prop: 'fixed_salary', id: id(37) })
await key(page, 'Shift+Enter'); equal('Shift+Enter moves up', await focused(page), { prop: 'fixed_salary', id: id(36) })
await select(page, id(36), 'total_net_payable')
await key(page, 'Tab'); equal('Tab from the last column wraps to the first column of the next row', await focused(page), { prop: 'employee_number', id: id(37) })
await select(page, id(36), 'other_deductions')
await page.keyboard.type('3'); await key(page, 'Tab'); await savedStable(page)
equal('Tab while editing commits and moves in reading order', [val(await serverRow(id(36)), 'other_deductions'), (await focused(page)).prop], ['3.00', 'total_deductions'])

// ── frozen columns, sticky header, horizontal scroll, column resizing ──
await page.evaluate(async () => { await document.querySelector('revo-grid').scrollToRow(0) })
await reveal(page, 'total_net_payable')
await page.waitForTimeout(300)
const gridBox = await page.locator('.payroll-grid').boundingBox()
const pinnedBox = await cellLoc(page, id(1), 'employee_number').boundingBox()
const pinnedNameBox = await cellLoc(page, id(1), 'full_name').boundingBox()
check('employee number and name stay visible while the sheet is scrolled horizontally to the last column', pinnedBox && pinnedNameBox && pinnedBox.x + pinnedBox.width <= gridBox.x + gridBox.width + 1 && pinnedNameBox.x >= gridBox.x - 1, JSON.stringify({ pinnedBox, pinnedNameBox, gridBox }))
check('the last column is reachable by horizontal scrolling', (await cellLoc(page, id(1), 'total_net_payable').count()) === 1)
await page.evaluate(async () => { const g = document.querySelector('revo-grid'); await g.scrollToRow(30) })
await page.waitForTimeout(250)
const headBox = await page.locator('.payroll-grid .rgHeaderCell').first().boundingBox()
check('header stays in place while the rows scroll vertically', headBox && headBox.y >= gridBox.y - 1 && headBox.y <= gridBox.y + 60, JSON.stringify(headBox))
const footerBox = await page.locator('.payroll-grid .rgCell.pg-footer').first().boundingBox()
check('the totals footer stays at the bottom of the grid while scrolling', footerBox && footerBox.y + footerBox.height <= gridBox.y + gridBox.height + 2 && footerBox.y >= gridBox.y + gridBox.height * 0.6, JSON.stringify({ footerBox, gridBox }))
await page.evaluate(async () => { await document.querySelector('revo-grid').scrollToRow(0) })
await reveal(page, 'salary_adjustment')
await page.waitForTimeout(250)
const resizeHead = page.locator('.payroll-grid .rgHeaderCell', { hasText: 'فروقات الراتب' })
const before = (await resizeHead.boundingBox()).width
const handle = await resizeHead.locator('.resizable-r').boundingBox()
await page.mouse.move(handle.x + 2, handle.y + handle.height / 2); await page.mouse.down(); await page.mouse.move(handle.x + 52, handle.y + handle.height / 2, { steps: 8 }); await page.mouse.up()
await page.waitForTimeout(300)
const after = (await page.locator('.payroll-grid .rgHeaderCell', { hasText: 'فروقات الراتب' }).boundingBox()).width
check('columns can be resized by dragging the header edge', Math.abs(after - before) >= 20, `${before} -> ${after}`)

// ── range selection, copy, paste, clear, undo/redo ─────────────────────
for (const n of [37, 38, 39]) await api(owner, 'PATCH', '/v1/owner/payroll/values', { changes: [{ employee_id: id(n), expected_revision: (await serverRow(id(n))).entry_revision, values: { fixed_salary: String(n * 1000), salary_adjustment: n === 38 ? '5' : null } }], config_revision: (await configOf()).revision })
await page.reload(); await page.waitForSelector('.payroll-grid .rgCell'); await page.waitForTimeout(700)
await select(page, id(37), 'fixed_salary')
await key(page, 'Shift+ArrowDown'); await key(page, 'Shift+ArrowDown'); await key(page, 'Shift+ArrowLeft')
const range = await page.evaluate(() => document.querySelector('revo-grid').getSelectedRange())
check('rectangular range selection (3 rows x 2 columns)', range && Math.abs(range.y1 - range.y) === 2 && Math.abs(range.x1 - range.x) === 1, JSON.stringify(range))
await page.keyboard.press('Control+c'); await page.waitForTimeout(250)
equal('copy puts tab/newline text on the clipboard (blank stays blank)', await readClipboard(page), '37000.00\t\n38000.00\t5.00\n39000.00\t')

patches.length = 0
await writeClipboard(page, '100\t5\n200.5\t\n300\t0')
await select(page, id(21), 'fixed_salary')
await page.keyboard.press('Control+v'); await savedStable(page)
equal('multi-cell paste is ONE request', patches.length, 1)
equal('that request carries the 3 employees by stable id and revision and the configuration revision', [patches[0].changes.map(c => c.employee_id), typeof patches[0].config_revision], [[id(21), id(22), id(23)], 'number'])
equal('pasted block saved (row 21)', [val(await serverRow(id(21)), 'fixed_salary'), val(await serverRow(id(21)), 'salary_adjustment')], ['100.00', '5.00'])
equal('pasted block saved (row 22: blank over blank untouched)', [val(await serverRow(id(22)), 'fixed_salary'), val(await serverRow(id(22)), 'salary_adjustment')], ['200.50', null])
equal('pasted block saved (row 23: explicit zero kept)', [val(await serverRow(id(23)), 'fixed_salary'), val(await serverRow(id(23)), 'salary_adjustment')], ['300.00', '0.00'])

patches.length = 0
await writeClipboard(page, '1\tabc\n-5\t2')
await select(page, id(24), 'fixed_salary')
const row24 = await serverRow(id(24)); const row25 = await serverRow(id(25))
await page.keyboard.press('Control+v'); await page.waitForTimeout(500)
check('invalid paste sends nothing', patches.length === 0)
equal('invalid paste commits nothing (server)', [amounts(await serverRow(id(24))), amounts(await serverRow(id(25)))], [amounts(row24), amounts(row25)])
const alertText = await page.locator('[role="alert"]', { hasText: 'لم يُلصق شيء' }).innerText()
check('invalid paste names the offending cells', alertText.includes('0024') && alertText.includes('0025') && alertText.includes('قيمة غير صالحة'), alertText)
check('offending cells are highlighted', await page.locator('.payroll-grid .rgCell.pg-invalid').count() >= 1)
await shot(page, '04-invalid-paste')

for (const [label, who, prop, clip] of [['metadata column', id(26), 'full_name', '9'], ['calculated column', id(26), 'salary_entitlement', '9'], ['block spilling into a calculated column', id(26), 'salary_adjustment', '1\t2']]) {
  patches.length = 0
  await writeClipboard(page, clip)
  await select(page, who, prop)
  await page.keyboard.press('Control+v'); await page.waitForTimeout(400)
  check(`paste into ${label} is refused and nothing is sent`, patches.length === 0 && (await page.locator('[role="alert"]', { hasText: 'لم يُلصق شيء' }).count()) > 0)
}
const protectedRow = await serverRow(id(26))
check('protected cells unchanged after refused pastes', protectedRow.full_name === `${names[26 % 8]} 26` && val(protectedRow, 'salary_adjustment') === null)
patches.length = 0
await writeClipboard(page, '5')
await select(page, id(27), 'fixed_salary')
await key(page, 'Shift+ArrowDown', 'Shift+ArrowDown', 'Shift+ArrowLeft')
await page.keyboard.press('Control+v'); await savedStable(page)
equal('a single copied value fills the selected range', [amounts(await serverRow(id(27))).slice(0, 1), val(await serverRow(id(29)), 'salary_adjustment')], [['5.00'], '5.00'])

// clear
patches.length = 0
await select(page, id(21), 'fixed_salary')
await key(page, 'Shift+ArrowDown', 'Shift+ArrowDown', 'Shift+ArrowLeft')
await page.keyboard.press('Delete'); await savedStable(page)
equal('Delete clears the selected range in ONE request', patches.length, 1)
equal('cleared cells are blank (null), not zero', [amounts(await serverRow(id(21))).slice(0, 1), val(await serverRow(id(23)), 'salary_adjustment')], [[null], null])
await select(page, id(38), 'other_deductions')
await page.keyboard.type('0'); await page.keyboard.press('Enter'); await savedStable(page)
await select(page, id(38), 'other_deductions'); await page.keyboard.press('Backspace'); await savedStable(page)
equal('Backspace clears one cell', val(await serverRow(id(38)), 'other_deductions'), null)

// undo / redo
await key(page, 'Control+z'); await savedStable(page)
equal('undo #1 restores the cleared cell (0)', val(await serverRow(id(38)), 'other_deductions'), '0.00')
await key(page, 'Control+z'); await savedStable(page)
await key(page, 'Control+z'); await savedStable(page)
equal('undo of the clear restores the whole pasted range in one step', [val(await serverRow(id(21)), 'fixed_salary'), val(await serverRow(id(23)), 'salary_adjustment')], ['100.00', '0.00'])
await key(page, 'Control+z'); await savedStable(page) // the single-value fill of rows 27-29
equal('undo of the fill restores those cells', [val(await serverRow(id(29)), 'salary_adjustment')], [null])
await key(page, 'Control+y'); await savedStable(page)
equal('redo re-applies the fill', val(await serverRow(id(29)), 'salary_adjustment'), '5.00')
await page.getByRole('button', { name: /تراجع/ }).click(); await savedStable(page)
check('toolbar undo works too', val(await serverRow(id(29)), 'salary_adjustment') === null)
await page.getByRole('button', { name: /إعادة \(/ }).click(); await savedStable(page)
equal('toolbar redo works too', val(await serverRow(id(29)), 'salary_adjustment'), '5.00')

// ── sorting, filtering: stable ids, totals and scope ───────────────────
await reveal(page, 'fixed_salary'); await page.waitForTimeout(200)
await page.locator('.payroll-grid .rgHeaderCell', { hasText: 'الأجر المقطوع' }).click()
await page.waitForFunction(() => document.querySelector('.pg-head[data-sort="fixed_salary"]')?.textContent.includes('▲'))
await savedStable(page); await page.waitForTimeout(600)
const expectedOrder = (await sheet('sort=fixed_salary&direction=asc')).data.map(r => r.id)
equal('ascending sort by salary matches the server order (blanks last)', (await rowsInGrid(page)).slice(0, 12), expectedOrder.slice(0, 12))
const top = (await rowsInGrid(page))[0]
await select(page, top, 'compensation')
await page.keyboard.type('11'); await page.keyboard.press('Enter'); await savedStable(page)
equal('editing after sorting updates the right employee (stable id, not row index)', [val(await serverRow(top), 'compensation'), val(await serverRow((await rowsInGrid(page))[1]), 'compensation') === '11.00'], ['11.00', false])
await reveal(page, 'fixed_salary'); await page.waitForTimeout(200)
await page.locator('.payroll-grid .rgHeaderCell', { hasText: 'الأجر المقطوع' }).click()
await page.waitForFunction(() => document.querySelector('.pg-head[data-sort="fixed_salary"]')?.textContent.includes('▼'))
await page.waitForTimeout(700)
equal('second click sorts descending', (await rowsInGrid(page)).slice(0, 3), (await sheet('sort=fixed_salary&direction=desc')).data.slice(0, 3).map(r => r.id))
await reveal(page, 'total_net_payable'); await page.waitForTimeout(200)
await page.locator('.payroll-grid .rgHeaderCell', { hasText: 'إجمالي الصافي المستحق' }).click()
await page.waitForFunction(() => document.querySelector('.pg-head[data-sort="total_net_payable"]')?.textContent.includes('▲'))
await page.waitForTimeout(700)
equal('a calculated column sorts too (server order, unavailable last)', (await rowsInGrid(page)).slice(0, 5), (await sheet('sort=total_net_payable&direction=asc')).data.slice(0, 5).map(r => r.id))

await page.getByLabel('الهيئة').selectOption(String(teaching.id))
await page.waitForTimeout(900)
const filteredServer = await sheet(`body_id=${teaching.id}&sort=total_net_payable&direction=asc`)
equal('filtered net-payable total equals the server total for ALL matching rows', await page.locator('[data-testid="total-net"]').innerText(), syp(filteredServer.meta.totals.columns.total_net_payable.sum))
check('totals scope is labelled', (await page.locator('[data-testid="totals-scope"]').innerText()).includes('الصفوف المطابقة للمرشحات: 20'))
check('the exclusion of incomplete records is stated', (await page.locator('[data-testid="totals-excluded"]').innerText()).includes('غير مكتمل'))
equal('only matching rows are in the grid', (await rowsInGrid(page)).length, 20)
await select(page, filteredServer.data[0].id, 'other_deductions')
await page.keyboard.type('13.13'); await page.keyboard.press('Enter'); await savedStable(page)
equal('edit under a filter hits the right employee', val(await serverRow(filteredServer.data[0].id), 'other_deductions'), '13.13')
await page.getByLabel('مكان العمل').selectOption('jarablus'); await page.waitForTimeout(800)
check('filters combine', (await page.evaluate(async () => (await document.querySelector('revo-grid').getSource()).every(r => r.workplace_label === 'جرابلس'))) === true)
await page.getByRole('button', { name: 'مسح المرشحات' }).click(); await page.waitForTimeout(800)
equal('clearing filters restores all rows', (await rowsInGrid(page)).length, 40)
// Review finding: "no academic level" must not be confused with a level a user typed as __blank__.
await page.getByLabel('المستوى الأكاديمي').selectOption({ label: 'غير محدد' }); await page.waitForTimeout(800)
const sortedIds = list => [...list].sort((a, b) => a - b)
equal('the "not specified" level filter shows only employees without a level (not those whose level text is __blank__)', sortedIds(await rowsInGrid(page)), sortedIds((await sheet('academic_level_blank=1')).data.map(r => r.id)))
equal('...and that is exactly the employees with a NULL level', (await rowsInGrid(page)).length, 5)
await page.getByLabel('المستوى الأكاديمي').selectOption({ label: '__blank__' }); await page.waitForTimeout(800)
equal('a level literally named __blank__ is an ordinary filter value', (await rowsInGrid(page)).length, (await sheet('academic_level=__blank__')).data.length)
await page.getByRole('button', { name: 'مسح المرشحات' }).click(); await page.waitForTimeout(800)
await page.getByLabel('حالة الاكتمال').selectOption('incomplete'); await page.waitForTimeout(800)
equal('completeness filter shows the incomplete records', sortedIds(await rowsInGrid(page)), sortedIds((await sheet('completeness=incomplete')).data.map(r => r.id)))
await page.getByRole('button', { name: 'مسح المرشحات' }).click(); await page.waitForTimeout(800)
await page.locator('input[type="search"]').fill('0007'); await page.waitForTimeout(900)
equal('search narrows the grid', await page.evaluate(async () => (await document.querySelector('revo-grid').getSource()).map(r => r.employee_number)), ['0007'])
await page.locator('input[type="search"]').fill(''); await page.waitForTimeout(900)

// ── failed save, retry, conflict ───────────────────────────────────────
await select(page, id(20), 'other_deductions')
blockPatches = true
await page.keyboard.type('21'); await page.keyboard.press('Enter')
await page.locator('[role="alert"]', { hasText: 'فشل حفظ آخر عملية' }).waitFor({ timeout: 6000 }).catch(() => {})
check('failed save is announced', (await page.locator('[role="alert"]', { hasText: 'فشل حفظ آخر عملية' }).count()) === 1)
equal('entered value stays on screen (not lost, not shown as saved)', [await text(page, id(20), 'other_deductions'), await cellLoc(page, id(20), 'other_deductions').getAttribute('data-state')], ['21.00', 'failed'])
equal('server unchanged after the failure', val(await serverRow(id(20)), 'other_deductions'), '0.00' === val(await serverRow(id(20)), 'other_deductions') ? '0.00' : null)
check('"saved" indicator is not shown during failure', (await page.locator('[data-testid="payroll-toolbar-2"]').innerText()).includes('فشل الحفظ'))
await shot(page, '05-failed-save')
check('export is disabled/refused while an edit is unsaved', await page.getByRole('button', { name: 'Excel' }).isEnabled().then(async enabled => { if (!enabled) return true; await page.getByRole('button', { name: 'Excel' }).click(); await page.waitForTimeout(500); return (await page.locator('main').innerText()).includes('لا يمكن التصدير قبل حلّ مشكلة الحفظ') }))
// Review finding: a filter chosen while a save is failing must neither be dropped nor describe a different dataset than the grid.
check('while a save problem is open the filter controls are locked', await page.getByLabel('الهيئة').isDisabled())
blockPatches = false
await page.getByRole('button', { name: 'إعادة المحاولة' }).click(); await savedStable(page)
equal('retry saves the same value', val(await serverRow(id(20)), 'other_deductions'), '21.00')

// Review finding: a filter change made while a save is IN FLIGHT and then fails must be applied only after resolution.
await select(page, id(19), 'other_deductions')
delayPatches = 1200; blockPatches = true
await page.keyboard.type('19'); await page.keyboard.press('Enter')
await page.waitForTimeout(200)
const rowsBefore = await rowsInGrid(page)
await page.getByLabel('الهيئة').selectOption(String(admin.id)) // chosen while the save is in flight
await page.waitForTimeout(2000)
check('the save failed', (await page.locator('[role="alert"]', { hasText: 'فشل حفظ آخر عملية' }).count()) === 1)
equal('the grid still shows the dataset the unsaved value belongs to (previous filters)', await rowsInGrid(page), rowsBefore)
check('the page says the new filter is waiting for the save problem to be resolved', (await page.locator('[data-testid="pending-query"]').innerText()).includes('ستُطبَّق تلقائيًا'))
check('exports are disabled because the controls and the grid disagree', await page.getByRole('button', { name: 'Excel' }).isDisabled())
check('the scope label still describes the grid', (await page.locator('[data-testid="totals-scope"]').innerText()).includes('كل الصفوف: 40'))
blockPatches = false; delayPatches = 0
await page.getByRole('button', { name: 'إعادة المحاولة' }).click(); await savedStable(page); await page.waitForTimeout(1200)
equal('after the retry the new filter is applied automatically', sortedIds(await rowsInGrid(page)), sortedIds((await sheet(`body_id=${admin.id}`)).data.map(r => r.id)))
equal('...the value was saved', val(await serverRow(id(19)), 'other_deductions'), '19.00')
check('...and the pending notice is gone', (await page.locator('[data-testid="pending-query"]').count()) === 0)
await page.getByRole('button', { name: 'مسح المرشحات' }).click(); await page.waitForTimeout(900)

// Review finding: "discard" after an UNCERTAIN failure must show what the server holds (the request may have been saved).
await select(page, id(18), 'other_deductions')
loseResponse = true
await page.keyboard.type('18'); await page.keyboard.press('Enter')
await page.locator('[role="alert"]', { hasText: 'فشل حفظ آخر عملية' }).waitFor({ timeout: 6000 })
equal('the server DID save the value although the browser saw a failure', val(await serverRow(id(18)), 'other_deductions'), '18.00')
await page.getByRole('button', { name: 'تجاهل تعديلاتي غير المحفوظة' }).click(); await page.waitForTimeout(1200)
equal('discarding reloads first: the screen shows the saved server value, not the stale blank/old one', await text(page, id(18), 'other_deductions'), '18.00')
check('no failure banner remains', (await page.locator('[role="alert"]', { hasText: 'فشل حفظ آخر عملية' }).count()) === 0)
await select(page, id(18), 'other_deductions')
await page.keyboard.type('19'); await page.keyboard.press('Enter'); await savedStable(page)
equal('the next edit of that row saves (authoritative revision was adopted)', val(await serverRow(id(18)), 'other_deductions'), '19.00')

await select(page, id(17), 'other_deductions') // loads the row revision
await api(owner, 'PATCH', '/v1/owner/payroll/values', { changes: [{ employee_id: id(17), expected_revision: (await serverRow(id(17))).entry_revision, values: { other_deductions: '5' } }], config_revision: (await configOf()).revision })
await page.keyboard.type('11'); await page.keyboard.press('Enter'); await page.waitForTimeout(900)
check('stale edit produces an explicit conflict', (await page.locator('[role="alert"]', { hasText: 'تعارض' }).count()) >= 1)
equal('pending value preserved on screen, other editor value kept on server', [await text(page, id(17), 'other_deductions'), val(await serverRow(id(17)), 'other_deductions')], ['11.00', '5.00'])
await shot(page, '06-conflict')
await page.getByRole('button', { name: 'الاحتفاظ بقيمي وإعادة الحفظ' }).click(); await savedStable(page)
equal('"keep mine" saves on top of the latest revision', val(await serverRow(id(17)), 'other_deductions'), '11.00')

// Configuration changed by someone else while editing: explicit conflict, values kept, then adopted.
await select(page, id(16), 'other_deductions')
{
  const cfg = await configOf()
  const changed = await api(owner, 'PATCH', '/v1/owner/payroll/config/settings', { settings: { insurance_rate: '0.08' }, config_revision: cfg.revision })
  assert.equal(changed.status, 200)
}
await page.keyboard.type('16'); await page.keyboard.press('Enter'); await page.waitForTimeout(900)
check('a save made under an outdated configuration is an explicit conflict (nothing saved)', (await page.locator('[role="alert"]', { hasText: 'تغيّرت الأعمدة أو المعادلات أو الإعدادات' }).count()) === 1 && val(await serverRow(id(16)), 'other_deductions') !== '16.00')
equal('the pending value stays on screen', await text(page, id(16), 'other_deductions'), '16.00')
await shot(page, '06b-config-conflict')
await page.getByRole('button', { name: 'تحميل الإعدادات الحالية وإعادة قيمي' }).click(); await savedStable(page); await page.waitForTimeout(900)
equal('after adopting the new configuration the value is saved', val(await serverRow(id(16)), 'other_deductions'), '16.00')
equal('...and every row is recalculated under the new insurance rate (8%)', await text(page, id(31), 'insurance'), '7,728.00')
await api(owner, 'PATCH', '/v1/owner/payroll/config/settings', { settings: { insurance_rate: '0.07' }, config_revision: (await configOf()).revision })
await page.reload(); await page.waitForSelector('.payroll-grid .rgCell'); await page.waitForTimeout(700)

// ── compact / detailed views over the same data ────────────────────────
const detailedColumns = await page.evaluate(async () => (await document.querySelector('revo-grid').getColumns()).length)
await page.getByRole('radio', { name: 'عرض مختصر' }).click(); await page.waitForTimeout(900)
const compactColumns = await page.evaluate(async () => (await document.querySelector('revo-grid').getColumns()).length)
check('the compact view shows fewer columns over the same rows', compactColumns < detailedColumns && (await rowsInGrid(page)).length === 40, `${compactColumns} vs ${detailedColumns}`)
const compactHeads = await page.$$eval('.payroll-grid .rgHeaderCell', els => els.map(e => e.textContent.replace(/[▲▼ƒ]/g, '').trim()))
check('compact: major inputs, total deductions and total net payable are there; tax bases are not', ['الأجر المقطوع', 'إجمالي الاقتطاعات', 'إجمالي الصافي المستحق'].every(h => compactHeads.some(t => t.includes(h))) && !compactHeads.some(t => t.includes('الوعاء')))
await shot(page, '06c-compact')
check('the choice is remembered', await page.evaluate(() => localStorage.getItem('owner-payroll-view')) === 'compact')
await page.getByRole('radio', { name: 'عرض تفصيلي' }).click(); await page.waitForTimeout(900)
check('detailed view is restored', (await page.evaluate(async () => (await document.querySelector('revo-grid').getColumns()).length)) === detailedColumns)

// ── configurable columns and formulas ──────────────────────────────────
await page.getByRole('button', { name: 'إدارة الأعمدة والمعادلات' }).click()
const dlg0 = page.locator('dialog[open]')
await dlg0.getByRole('tab', { name: 'الأعمدة' }).waitFor()
check('the manager lists every column with its type, source and visibility', (await dlg0.locator('[data-testid="columns-table"] tbody tr[data-column]').count()) === 17)
await shot(page, '13-columns-manager')

// add a plain manual column (never changes net payable by itself)
const netBefore = val(await serverRow(id(1)), 'total_net_payable')
await dlg0.getByRole('button', { name: 'إضافة عمود' }).first().click()
await dlg0.locator('#col-label').fill('بدل نقل')
await dlg0.locator('#col-group').selectOption('compensation')
await dlg0.locator('#col-type').selectOption('amount')
await dlg0.locator('select[aria-label="التجميع"]').selectOption('sum')
await dlg0.getByRole('button', { name: 'إضافة العمود' }).click()
await dlg0.getByText('تمت إضافة العمود').waitFor()
const afterAdd = await configOf()
const transport = afterAdd.columns.find(c => c.label === 'بدل نقل')
check('the new column exists with a stable generated id and is a manual amount column', transport && /^c_[0-9a-f]{10}$/.test(transport.key) && transport.kind === 'input' && transport.value_type === 'amount')
equal('adding a column alone does not change net payable', val(await serverRow(id(1)), 'total_net_payable'), netBefore)

// a formula column with autocomplete and a live preview
await dlg0.getByRole('button', { name: 'إضافة عمود' }).first().click()
await dlg0.locator('#col-label').fill('ضعف بدل النقل')
await dlg0.locator('#col-group').selectOption('deductions')
await dlg0.locator('#col-kind').selectOption('formula')
const formula = dlg0.getByLabel('المعادلة')
await formula.click()
await page.keyboard.type('[بدل ن')
await dlg0.getByRole('listbox').waitFor()
check('autocomplete suggests matching columns by readable name', (await dlg0.getByRole('option').allInnerTexts()).some(t => t.includes('بدل نقل')))
await page.keyboard.press('Enter')
await page.keyboard.type(' * 2 + [الأجر ')
await dlg0.getByRole('listbox').waitFor()
await page.keyboard.press('ArrowDown'); await page.keyboard.press('Escape')
await formula.fill('[بدل نقل] * 2 + [الأجر المقطوع] * [نسبة التأمينات]')
await dlg0.locator('#preview-employee').selectOption(String(id(31)))
await dlg0.locator('[data-testid="impact-cell"]').waitFor({ timeout: 5000 })
check('the preview shows the value for the selected employee before saving (96600 x 7% = 6,762.00; transport blank = 0)', (await dlg0.locator('[data-testid="impact-cell"]').innerText()).includes('6,762.00'), await dlg0.locator('[data-testid="impact-cell"]').innerText())
check('...and the effect on totals', (await dlg0.locator('[data-testid="impact-summary"]').innerText()).includes('إجمالي الصافي المستحق'))
await shot(page, '14-formula-editor')
// invalid formula messages
for (const [bad, expected] of [['[غير موجود] + 1', 'غير'], ['[بدل نقل] +', ''], ['POWER(2; 3)', '']]) {
  await formula.fill(bad); await dlg0.locator('[data-testid="formula-error"]').waitFor({ timeout: 5000 })
  check(`an invalid formula is rejected with a reason (${bad})`, (await dlg0.locator('[data-testid="formula-error"]').innerText()).length > 5 && (await dlg0.getByRole('button', { name: 'إضافة العمود' }).isDisabled()))
}
await formula.fill('[بدل نقل] * 2 + [الأجر المقطوع] * [نسبة التأمينات]')
await dlg0.getByRole('button', { name: 'إضافة العمود' }).click()
await dlg0.getByText('تمت إضافة العمود').waitFor()
const double = (await configOf()).columns.find(c => c.label === 'ضعف بدل النقل')
equal('the formula is stored with stable ids and shown with readable names', [double.formula, double.formula_display], [`{${transport.key}} * 2 + {fixed_salary} * {insurance_rate}`, '[بدل نقل] * 2 + [الأجر المقطوع] * [نسبة التأمينات]'])
await dlg0.locator('button:has-text("إغلاق")').click()
await page.waitForTimeout(900)
await reveal(page, double.key); await page.waitForTimeout(300)
equal('the new calculated column is in the grid and evaluated for each employee', await text(page, id(31), double.key), '6,762.00')
check('...and it is read-only', !(await cellLoc(page, id(31), double.key).getAttribute('class')).includes('pg-input'))

// enter a value in the custom manual column; the dependent updates locally and on the server
await reveal(page, transport.key); await select(page, id(31), transport.key)
await page.keyboard.type('150.25'); await page.keyboard.press('Enter'); await savedStable(page)
equal('custom column value saved', val(await serverRow(id(31)), transport.key), '150.25')
await reveal(page, double.key)
equal('its dependent recalculated (150.25 x 2 + 6,762.00)', await text(page, id(31), double.key), '7,062.50')
equal('net payable unchanged by an unrelated custom column', val(await serverRow(id(31)), 'total_net_payable'), '125166.30')

// reorder / hide / rename never move or break values; deletion protection
await page.getByRole('button', { name: 'إدارة الأعمدة والمعادلات' }).click()
const dlg1 = page.locator('dialog[open]')
await dlg1.getByRole('button', { name: 'نقل بدل نقل للأعلى' }).click()
await dlg1.getByLabel('إظهار التأمينات الاجتماعية في الجدول').uncheck()
await dlg1.getByRole('button', { name: 'حفظ الترتيب والظهور' }).click()
await dlg1.getByText('تم حفظ الترتيب والظهور').waitFor()
const afterLayout = await configOf()
check('layout saved atomically: hidden column is no longer visible in the grid but still calculates', afterLayout.columns.find(c => c.key === 'insurance').visible_grid === false && val(await serverRow(id(31)), 'insurance') === '6762.00')
await dlg1.getByRole('button', { name: 'تعديل بدل نقل' }).click()
await dlg1.locator('#col-label').fill('بدل المواصلات')
await dlg1.getByRole('button', { name: 'حفظ التعديل' }).click()
await dlg1.getByText('تم حفظ التعديل').waitFor()
equal('renaming keeps the formula working and updates its readable display', (await configOf()).columns.find(c => c.key === double.key).formula_display, '[بدل المواصلات] * 2 + [الأجر المقطوع] * [نسبة التأمينات]')
equal('...and the value did not move', val(await serverRow(id(31)), transport.key), '150.25')
await dlg1.getByRole('button', { name: 'حذف بدل المواصلات' }).click()
check('deleting a referenced column warns about its dependents and offers no confirm', (await dlg1.locator('[data-testid="delete-confirm"]').innerText()).includes('ضعف بدل النقل') && (await dlg1.locator('[data-testid="delete-confirm"]').getByRole('button', { name: /تأكيد|حذف العمود/ }).count()) === 0)
await shot(page, '15-delete-protection')
await dlg1.getByRole('button', { name: 'إلغاء' }).click()
// restore the insurance column's visibility
await dlg1.getByLabel('إظهار التأمينات الاجتماعية في الجدول').check()
await dlg1.getByRole('button', { name: 'حفظ الترتيب والظهور' }).click()
await dlg1.getByText('تم حفظ الترتيب والظهور').waitFor()

// global settings with an impact preview before saving
await dlg1.getByRole('tab', { name: 'الإعدادات العامة' }).click()
await dlg1.locator('#setting-insurance_rate').fill('9')
await dlg1.locator('[data-testid="impact-summary"]').waitFor({ timeout: 5000 })
check('the settings tab previews the effect on the totals before saving', (await dlg1.locator('[data-testid="impact-summary"]').innerText()).includes('موظفون تتغير نتيجتهم'))
const rateBefore = (await configOf()).settings.find(s => s.key === 'insurance_rate').value
equal('nothing was saved by previewing', rateBefore, '0.07')
await shot(page, '16-settings-impact')
await dlg1.locator('#setting-insurance_rate').fill('abc')
check('an invalid rate is refused', (await dlg1.getByRole('button', { name: 'حفظ الإعدادات' }).isDisabled()))
await dlg1.locator('#setting-insurance_rate').fill('7')
check('reverting to the saved value leaves nothing to save', await dlg1.getByRole('button', { name: 'حفظ الإعدادات' }).isDisabled())
await dlg1.locator('button:has-text("إغلاق")').click()

// ── the final (net payable) formula is editable and restorable ─────────────
{
  const mk = async label => (await api(owner, 'POST', '/v1/owner/payroll/config/columns', { label, kind: 'input', value_type: 'amount', group: 'net', aggregation: 'sum', config_revision: (await configOf()).revision })).json.data.key
  const bonusKey = await mk('مكافأة إضافية')
  const extraKey = await mk('حسم إضافي')
  const target = await serverRow(id(31))
  const saved = await api(owner, 'PATCH', '/v1/owner/payroll/values', { changes: [{ employee_id: id(31), expected_revision: target.entry_revision, values: { [bonusKey]: '1000', [extraKey]: '250' } }], config_revision: (await configOf()).revision })
  assert.equal(saved.status, 200)
  equal('custom amounts alone leave the authoritative net payable unchanged', val(await serverRow(id(31)), 'total_net_payable'), '125166.30')
  await page.goto(`${APP}/owner/payroll`); await page.waitForSelector('.payroll-grid .rgCell'); await page.waitForTimeout(700)
  await page.getByRole('button', { name: 'إدارة الأعمدة والمعادلات' }).click()
  const manager = page.locator('dialog[open]')
  check('only the net-payable template column shows an editable formula', (await manager.getByRole('button', { name: 'تعديل إجمالي الصافي المستحق' }).count()) === 1)
  await manager.getByRole('button', { name: 'تعديل إجمالي الصافي المستحق' }).click()
  check('the default formula shown beside the restore action comes from the server', (await manager.locator('[data-testid="template-formula"]').innerText()) === '[صافي الراتب] + [صافي التعويض] - [حسميات أخرى]')
  check('restore is disabled while the formula is still the template default', await manager.getByRole('button', { name: 'استعادة معادلة القالب' }).isDisabled())
  check('type, group and aggregation stay locked for the net payable', await manager.locator('#col-type').isDisabled() && await manager.locator('#col-group').isDisabled() && await manager.locator('#col-kind').isDisabled())
  await manager.locator('#preview-employee').selectOption(String(id(31)))
  const finalFormula = '[صافي الراتب] + [صافي التعويض] - [حسميات أخرى] + [مكافأة إضافية] - [حسم إضافي]'
  await manager.getByLabel('المعادلة').fill(finalFormula)
  await manager.locator('[data-testid="impact-cell"]').waitFor({ timeout: 5000 })
  await page.waitForFunction(() => document.querySelector('dialog[open] [data-testid="impact-cell"]')?.innerText.includes('125,916.30'), null, { timeout: 8000 })
  check('the preview shows 125,916.30 for the reference employee before anything is saved', (await manager.locator('[data-testid="impact-cell"]').innerText()).includes('125,916.30') && val(await serverRow(id(31)), 'total_net_payable') === '125166.30')
  await shot(page, '17-net-formula-editor')
  for (const bad of ['[إجمالي الصافي المستحق] + 1', '[غير موجود] + 1']) {
    await manager.getByLabel('المعادلة').fill(bad); await manager.locator('[data-testid="formula-error"]').waitFor({ timeout: 5000 })
    check(`an invalid final formula is rejected with a reason (${bad})`, (await manager.locator('[data-testid="formula-error"]').innerText()).length > 5 && await manager.getByRole('button', { name: 'حفظ التعديل' }).isDisabled())
  }
  await manager.getByLabel('المعادلة').fill(finalFormula)
  await manager.locator('[data-testid="formula-error"]').waitFor({ state: 'detached', timeout: 5000 })
  await page.waitForFunction(() => document.querySelector('dialog[open] [data-testid="impact-cell"]')?.innerText.includes('125,916.30'), null, { timeout: 8000 })
  await manager.getByRole('button', { name: 'حفظ التعديل' }).click()
  await manager.getByText('تم حفظ التعديل').waitFor()
  equal('the server calculates the edited authoritative net payable', val(await serverRow(id(31)), 'total_net_payable'), '125916.30')
  check('the manager marks the formula as modified', (await manager.locator('[data-testid="formula-modified"]').count()) === 1)
  await manager.locator('button:has-text("إغلاق")').click(); await page.waitForTimeout(900)
  equal('the grid recalculated the edited net payable', await text(page, id(31), 'total_net_payable'), '125,916.30')
  await select(page, id(31), bonusKey); await page.keyboard.type('2000'); await page.keyboard.press('Enter')
  await page.waitForTimeout(150)
  equal('a pending edit of the custom column moves the net payable immediately (before the save returns)', await text(page, id(31), 'total_net_payable'), '126,916.30')
  await savedStable(page)
  equal('...and the saved value agrees', val(await serverRow(id(31)), 'total_net_payable'), '126916.30')
  await select(page, id(31), bonusKey); await page.keyboard.type('1000'); await page.keyboard.press('Enter'); await savedStable(page)
  const homeAfter = (await api(owner, 'GET', '/v1/owner/home')).json.data
  await page.goto(`${APP}/owner`); await page.waitForSelector('[data-testid="home-net"]')
  equal('Home shows the edited authoritative net payable', await page.locator('[data-testid="home-net"]').innerText(), syp(homeAfter.totals.net_payable))
  await page.goto(`${APP}/owner/payroll`); await page.waitForSelector('.payroll-grid .rgCell'); await page.waitForTimeout(700)
}

// ── exports through the real buttons ───────────────────────────────────
await page.goto(`${APP}/owner/payroll`); await page.waitForSelector('.payroll-grid .rgCell'); await page.waitForTimeout(700)
const gridNet = await page.locator('[data-testid="total-net"]').innerText()
const [xlsxDownload] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Excel' }).click()])
const xlsxPath = path.join(OUT, 'export.xlsx'); await xlsxDownload.saveAs(xlsxPath)
const [pdfDownload] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'PDF' }).click()])
const pdfPath = path.join(OUT, 'export.pdf'); await pdfDownload.saveAs(pdfPath)
check('download names carry the right extensions', xlsxDownload.suggestedFilename().endsWith('.xlsx') && pdfDownload.suggestedFilename().endsWith('.pdf'))
// Recalculate in LibreOffice from a copy with the cached results stripped, so what is checked is the real Excel formulas.
const stripScript = path.join(OUT, 'strip.php')
fs.writeFileSync(stripScript, `<?php require '${path.resolve('..', 'backend', 'vendor', 'autoload.php')}'; $b = PhpOffice\\PhpSpreadsheet\\IOFactory::load($argv[1]); $w = PhpOffice\\PhpSpreadsheet\\IOFactory::createWriter($b, 'Xlsx'); $w->setPreCalculateFormulas(false); $w->save($argv[2]);`)
const noCache = path.join(OUT, 'export-nocache.xlsx')
execFileSync('php', [stripScript, xlsxPath, noCache])
const csvDir = path.join(OUT, 'csv'); fs.mkdirSync(csvDir, { recursive: true })
execFileSync('soffice', ['--headless', '--convert-to', 'csv:Text - txt - csv (StarCalc):44,34,76,1', '--outdir', csvDir, noCache], { stdio: 'ignore', timeout: 120000 })
const csv = fs.readFileSync(path.join(csvDir, 'export-nocache.csv'), 'utf8').trim().split('\n')
const serverAfter = await sheet()
const totalLine = csv.find(line => line.startsWith('"الإجمالي') || line.startsWith('الإجمالي'))
check('Excel totals row (recalculated by LibreOffice from the real formulas) contains the grid net-payable total', totalLine && totalLine.includes(gridNet.replace(' ل.س', '')), `${totalLine} vs ${gridNet}`)
check('Excel has one data row per employee plus headings and totals', csv.length >= 7 + 41 + 1, String(csv.length))
check('Excel keeps employee numbers as text with leading zeros', csv.some(line => line.startsWith('"0001"')))
check('Excel shows Syrian pounds (ل.س) and no dollar sign', csv.join('\n').includes('ل.س') && !csv.join('\n').includes('$'))
check('Excel includes the custom columns and the labelled settings block', csv[3].includes('نسبة التأمينات') && csv[6].includes('بدل المواصلات') && csv[6].includes('ضعف بدل النقل'))
const pdfInfo = execFileSync('pdfinfo', [pdfPath]).toString()
check('PDF is A3 landscape', /Page size:\s+1190\.55 x 841\.89/.test(pdfInfo), pdfInfo)
execFileSync('pdftoppm', ['-r', '70', '-png', '-f', '1', '-l', '3', pdfPath, path.join(OUT, 'export-pdf')])
const pdfText = execFileSync('pdftotext', ['-layout', pdfPath, '-']).toString()
check('PDF shows the same net-payable total (thousands separators, two decimals)', pdfText.includes(gridNet.replace(' ل.س', '')), gridNet)
check('PDF has no dollar sign', !pdfText.includes('$'))
check('exports match the server for the saved snapshot', serverAfter.meta.totals.columns.total_net_payable.sum === (await sheet()).meta.totals.columns.total_net_payable.sum)

// ── restore the template net-payable formula ──────────────────────────
{
  const before = { values: (await sheet()).data.length, columns: (await configOf()).columns.filter(c => !c.is_system).length, rate: (await configOf()).settings.find(s => s.key === 'insurance_rate').value }
  await page.getByRole('button', { name: 'إدارة الأعمدة والمعادلات' }).click()
  const manager = page.locator('dialog[open]')
  await manager.getByRole('button', { name: 'تعديل إجمالي الصافي المستحق' }).click()
  await manager.getByRole('button', { name: 'استعادة معادلة القالب' }).click()
  await manager.getByText('تمت استعادة معادلة القالب').waitFor()
  const cfg = await configOf()
  equal('the template formula is restored', cfg.columns.find(c => c.key === 'total_net_payable').formula_display, '[صافي الراتب] + [صافي التعويض] - [حسميات أخرى]')
  equal('settings, employees and custom columns were not reset', { values: (await sheet()).data.length, columns: cfg.columns.filter(c => !c.is_system).length, rate: cfg.settings.find(s => s.key === 'insurance_rate').value }, before)
  equal('the reference employee is back to the template result', val(await serverRow(id(31)), 'total_net_payable'), '125166.30')
  equal('the custom amounts are still there', val(await serverRow(id(31)), cfg.columns.find(c => c.label === 'مكافأة إضافية').key), '1000.00')
  await manager.locator('button:has-text("إغلاق")').click(); await page.waitForTimeout(900)
  equal('the grid shows the template result again', await text(page, id(31), 'total_net_payable'), '125,166.30')
}

// ── visual hierarchy: what the rendered grid actually looks like ───────────
{
  const luminance = rgb => { const [r, g, b] = rgb.match(/\d+/g).map(Number); return 0.2126 * r + 0.7152 * g + 0.0722 * b }
  const cfg = await configOf()
  const netLabel = cfg.columns.find(c => c.key === 'total_net_payable').label
  const renamed = 'المستحق النهائي (اسم جديد)'
  const rename = async label => api(owner, 'PATCH', '/v1/owner/payroll/config/columns/total_net_payable', { label, config_revision: (await configOf()).revision })
  equal('renaming the net payable column is accepted', (await rename(renamed)).status, 200)
  const editor = await openBrowser({ width: 1900, height: 1000 })
  const view = editor.page
  await view.goto(`${APP}/owner/payroll`); await view.waitForSelector('.payroll-grid .rgCell'); await view.waitForTimeout(900)
  // The grid renders only the columns in view, so the net payable column is measured after scrolling to it.
  const measure = () => view.evaluate(() => {
    const bg = el => (el ? getComputedStyle(el).backgroundColor : null)
    const group = [...document.querySelectorAll('.payroll-grid .group-rgRow .rgHeaderCell')].map(el => bg(el))
    const heads = [...document.querySelectorAll('.payroll-grid .actual-rgRow .rgHeaderCell')]
    const byClass = name => heads.filter(el => el.classList.contains(name))
    const net = byClass('pg-h-net')
    const cell = (cls, extra = '') => document.querySelector(`.payroll-grid .rgCell.${cls}${extra}`)
    const plainNet = cell('pg-net', ':not(.pg-footer):not(.pg-unavailable-cell):not(.pg-error-cell)')
    return {
      group: group[0], groupColors: new Set(group).size, head: bg(heads.find(el => el.classList.contains('pg-h-gstart') && !el.classList.contains('pg-h-net'))),
      netCount: net.length, netText: net[0]?.innerText.trim(), netBg: bg(net[0]), netColor: net[0] ? getComputedStyle(net[0]).color : null,
      inputMarks: document.querySelectorAll('.payroll-grid .rgHeaderCell .pg-mark-input').length, inputHeads: byClass('pg-h-input').length,
      calcMarks: document.querySelectorAll('.payroll-grid .rgHeaderCell .pg-mark-calc').length, calcHeads: byClass('pg-h-computed').length,
      identityMarks: heads.filter(el => el.classList.contains('pg-h-gstart') && !el.classList.contains('pg-h-computed') && !el.classList.contains('pg-h-input') && el.querySelector('.pg-mark')).length,
      input: bg(cell('pg-input')), calc: bg(cell('pg-computed:not(.pg-net)')), netCell: bg(plainNet), netWeight: plainNet ? Number(getComputedStyle(plainNet).fontWeight) : 0,
      unavailableNet: bg(cell('pg-net', '.pg-unavailable-cell')), blank: document.querySelectorAll('.payroll-grid .rgCell.pg-input.pg-blank').length,
      footerNet: document.querySelector('.payroll-grid .rgCell.pg-footer.pg-net')?.innerText.trim(),
      legend: document.querySelector('[data-testid="payroll-legend"]')?.innerText.replace(/\s+/g, ' '),
    }
  })
  const first = await measure()
  await view.evaluate(() => document.querySelector('revo-grid').scrollToColumnProp('total_net_payable', 'rgCol')); await view.waitForTimeout(500)
  const atNet = await measure()
  const looks = { ...first, netCount: atNet.netCount, netText: atNet.netText, netBg: atNet.netBg, netColor: atNet.netColor, netCell: atNet.netCell, netWeight: atNet.netWeight, unavailableNet: atNet.unavailableNet, footerNet: atNet.footerNet }
  check('the group row and the column row have different fills, the group row being the dark one', looks.group !== looks.head && luminance(looks.group) < 90 && luminance(looks.head) > 215, `${looks.group} / ${looks.head}`)
  check('every group band shares one dark fill (separated by rules, not by colour)', looks.groupColors === 1)
  equal('the final net payable header is found by its stable key even though it was renamed', [looks.netCount >= 1, looks.netText?.includes('اسم جديد')], [true, true])
  check('the net payable header is a darker green than the group-neutral column headers, with white text', luminance(looks.netBg) < luminance(looks.head) - 80 && luminance(looks.netColor) > 240, `${looks.netBg} ${looks.netColor}`)
  check('input and calculated headers carry markers (pencil / ƒ) and identity headers none', looks.inputMarks === looks.inputHeads && looks.inputHeads > 0 && looks.calcMarks === looks.calcHeads && looks.calcHeads > 0 && looks.identityMarks === 0, JSON.stringify([looks.inputMarks, looks.inputHeads, looks.calcMarks, looks.calcHeads, looks.identityMarks]))
  check('input, calculated and final-payable cells have three different fills', new Set([looks.input, looks.calc, looks.netCell]).size === 3, `${looks.input} | ${looks.calc} | ${looks.netCell}`)
  check('final-payable figures are bold and an unavailable one is not dressed as a valid payable', looks.netWeight >= 700 && looks.unavailableNet !== looks.netCell, `${looks.netWeight} ${looks.unavailableNet}`)
  check('blank editable cells are identifiable without placeholder data', looks.blank > 0 && (await view.locator('.payroll-grid .rgCell.pg-input.pg-blank').first().innerText()).trim() === '')
  check('the pinned footer total of the net payable is emphasised', Boolean(looks.footerNet) && looks.footerNet.length > 3)
  check('the legend names the three styles', ['قابل للتعديل', 'محسوب تلقائيًا', 'الصافي النهائي'].every(text => looks.legend?.includes(text)), looks.legend)
  // Pending / failed / conflict states win over the ordinary input fill.
  const failedFill = await view.evaluate(() => { const el = document.querySelector('.payroll-grid .rgCell.pg-failed'); return el ? getComputedStyle(el).backgroundColor : null })
  check('no cell is stuck in a failed state at this point', failedFill === null)
  await shot(view, '19-visual-hierarchy-net-column')
  await view.evaluate(() => document.querySelector('revo-grid').scrollToColumnProp('fixed_salary', 'rgCol')); await view.waitForTimeout(400)
  await shot(view, '18-visual-hierarchy-editor')
  check('visual hierarchy: no uncaught page errors', editor.errors.length === 0, editor.errors.join(' | '))
  await editor.browser.close()

  // A user who may only view: inputs stay recognisable but are not presented as editable.
  const viewer = await openBrowser({ width: 1900, height: 1000, dropPermissions: ['owner_payroll.amounts.edit'] })
  const ro = viewer.page
  await ro.goto(`${APP}/owner/payroll`); await ro.waitForSelector('.payroll-grid .rgCell'); await ro.waitForTimeout(900)
  await ro.evaluate(() => document.querySelector('revo-grid').scrollToColumnProp('total_net_payable', 'rgCol')); await ro.waitForTimeout(500)
  const roLooks = await ro.evaluate(() => ({
    inputMarks: document.querySelectorAll('.payroll-grid .pg-mark-input').length, editableCells: document.querySelectorAll('.payroll-grid .rgCell.pg-input').length,
    sourceCells: document.querySelectorAll('.payroll-grid .rgCell.pg-source').length, calcMarks: document.querySelectorAll('.payroll-grid .pg-mark-calc').length,
    netHeads: document.querySelectorAll('.payroll-grid .rgHeaderCell.pg-h-net').length, legend: document.querySelector('[data-testid="payroll-legend"]')?.innerText.replace(/\s+/g, ' '),
  }))
  check('view-only user: no pencil markers, no editable-looking cells, but source inputs stay recognisable', roLooks.inputMarks === 0 && roLooks.editableCells === 0 && roLooks.sourceCells > 0, JSON.stringify(roLooks))
  check('view-only user: calculated and final-payable styling is unchanged', roLooks.calcMarks > 0 && roLooks.netHeads >= 1)
  check('view-only user: the legend does not promise editing', roLooks.legend?.includes('للعرض فقط') && !roLooks.legend.includes('قابل للتعديل'), roLooks.legend)
  await shot(ro, '20-visual-hierarchy-view-only')
  await ro.evaluate(() => document.querySelector('revo-grid').scrollToColumnProp('fixed_salary', 'rgCol')); await ro.waitForTimeout(400)
  check('view-only user: source input cells are present in view', (await ro.locator('.payroll-grid .rgCell.pg-source').count()) > 0)
  await viewer.browser.close()
  equal('the net payable column is restored to its previous name', (await rename(netLabel)).status, 200)
}

// ── employee and body management dialogs ───────────────────────────────
await page.getByRole('button', { name: 'إدارة الهيئات' }).click()
const bodiesDialog = page.locator('dialog[open]')
check('bodies dialog lists existing bodies with counts', (await bodiesDialog.innerText()).includes('هيئة التدريس') && (await bodiesDialog.innerText()).includes('20 موظفًا'))
check('referenced bodies offer no delete action', (await bodiesDialog.getByRole('button', { name: /حذف/ }).count()) === 0)
await bodiesDialog.getByLabel('اسم الهيئة الجديدة').fill('هيئة مؤقتة'); await bodiesDialog.getByRole('button', { name: 'إضافة هيئة' }).click()
await bodiesDialog.getByText('هيئة مؤقتة').waitFor()
await bodiesDialog.getByRole('button', { name: 'إعادة تسمية هيئة مؤقتة' }).click()
await bodiesDialog.getByLabel('اسم جديد للهيئة هيئة مؤقتة').fill('هيئة مؤقتة ٢'); await bodiesDialog.getByRole('button', { name: 'حفظ' }).click()
await bodiesDialog.getByText('هيئة مؤقتة ٢').waitFor()
await bodiesDialog.getByRole('button', { name: 'تعطيل هيئة مؤقتة ٢' }).click()
await bodiesDialog.getByText('معطّلة').waitFor()
await shot(page, '07-bodies-dialog')
await bodiesDialog.getByRole('button', { name: 'إغلاق' }).click()

await page.getByRole('button', { name: 'إضافة موظف' }).click()
const dlg = page.locator('dialog[open]')
check('Add employee dialog fields', await Promise.all(['رقم الموظف', 'الاسم الكامل', 'الصفة الوظيفية', 'الهيئة', 'مكان العمل', 'المستوى الأكاديمي'].map(l => dlg.getByLabel(l, { exact: false }).count())).then(c => c.every(n => n >= 1)))
check('inactive bodies are not offered for a new employee', !(await dlg.locator('#emp-body_id').innerText()).includes('هيئة مؤقتة ٢'))
check('workplace choices', (await dlg.locator('#emp-workplace').innerText()).replace(/\s+/g, ' ').includes('عفرين وجرابلس'))
check('custom location field hidden until "other"', (await dlg.locator('#emp-workplace_other').count()) === 0)
await dlg.locator('#emp-workplace').selectOption('other'); await dlg.locator('#emp-workplace_other').fill('حلب')
await dlg.locator('#emp-workplace').selectOption('jarablus')
check('switching away from "other" removes the custom field', (await dlg.locator('#emp-workplace_other').count()) === 0)
await dlg.locator('#emp-workplace').selectOption('other')
equal('obsolete custom location was cleared', await dlg.locator('#emp-workplace_other').inputValue(), '')
await dlg.getByRole('button', { name: 'إضافة', exact: true }).click()
check('required fields are reported', (await dlg.locator('[role="alert"]').count()) >= 4)
await dlg.locator('#emp-employee_number').fill('0001'); await dlg.locator('#emp-full_name').fill('مكرر'); await dlg.locator('#emp-job_title').fill('x')
await dlg.locator('#emp-body_id').selectOption(String(admin.id)); await dlg.locator('#emp-workplace_other').fill('حلب')
await dlg.getByRole('button', { name: 'إضافة', exact: true }).click()
await dlg.getByText('رقم الموظف مستخدم لموظف آخر').waitFor()
check('duplicate employee number is rejected by the server and shown', true)
await dlg.locator('#emp-employee_number').fill('  00099  '); await dlg.locator('#emp-full_name').fill('موظف جديد تجريبي'); await dlg.locator('#emp-job_title').fill('مهندس')
await dlg.locator('#emp-academic_level').fill('إجازة جامعية')
await shot(page, '08-employee-dialog')
await dlg.getByRole('button', { name: 'إضافة', exact: true }).click()
await page.waitForFunction(() => !document.querySelector('dialog[open]'))
await page.waitForTimeout(800)
const created = (await sheet('search=00099')).data[0]
equal('new employee: manual number trimmed, leading zeros kept, blank amounts', [created.employee_number, amounts(created), created.workplace_label, created.academic_level], ['00099', [null, null, null], 'حلب', 'إجازة جامعية'])
check('new employee is shown in the grid immediately', (await page.evaluate(async () => (await document.querySelector('revo-grid').getSource()).some(r => r.employee_number === '00099'))))
await page.evaluate(async number => { const g = document.querySelector('revo-grid'); await g.scrollToRow((await g.getSource()).findIndex(r => r.employee_number === number)) }, '00099')
await page.waitForTimeout(400)
await page.locator(`[data-edit-id="${created.id}"]`).click()
const edit = page.locator('dialog[open]')
equal('edit dialog is prefilled', [await edit.locator('#emp-employee_number').inputValue(), await edit.locator('#emp-workplace_other').inputValue()], ['00099', 'حلب'])
await edit.locator('#emp-job_title').fill('مهندس أول'); await edit.getByRole('button', { name: 'حفظ التعديلات' }).click()
await page.waitForFunction(() => !document.querySelector('dialog[open]')); await page.waitForTimeout(800)
equal('metadata edit saved', (await serverRow(created.id)).job_title, 'مهندس أول')
await shot(page, '09-after-add-and-edit')

// ── access: the other accounts cannot use the portal ──────────────────
for (const role of ['president', 'hr']) {
  const response = await api(tokens[role], 'GET', '/v1/owner/payroll/sheet')
  check(`${role} account gets 403 from the payroll API`, response.status === 403)
  check(`${role} account cannot mutate or export`, [(await api(tokens[role], 'PATCH', '/v1/owner/payroll/values', { changes: [], config_revision: 1 })).status, (await api(tokens[role], 'GET', '/v1/owner/payroll/export/xlsx')).status].every(s => s === 403))
}
check('administrator (central authority) can open the sheet', (await api(tokens.admin, 'GET', '/v1/owner/payroll/sheet')).status === 200)
check('no uncaught page errors (desktop)', desk.errors.length === 0, desk.errors.join(' | '))
await desk.browser.close()

// ═══ NARROW SCREEN ═════════════════════════════════════════════════════
{
  const small = await openBrowser({ width: 390, height: 844, mobile: true })
  const { page: phone } = small
  await phone.goto(`${APP}/owner/payroll`)
  await phone.waitForSelector('.payroll-grid .rgCell'); await phone.waitForTimeout(700)
  check('narrow: no page-level horizontal overflow', await phone.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1))
  check('narrow: the grid scrolls horizontally inside its own viewport', await phone.evaluate(() => { const v = document.querySelector('.payroll-grid revogr-viewport-scroll.rgCol, .payroll-grid revogr-viewport-scroll'); return [...document.querySelectorAll('.payroll-grid revogr-viewport-scroll')].some(e => e.scrollWidth > e.clientWidth + 5) }))
  await shot(phone, '10-payroll-mobile')
  await phone.evaluate(() => document.querySelector('revo-grid').scrollToColumnProp('fixed_salary'))
  await phone.waitForTimeout(300)
  const salaryBox = await phone.locator('.payroll-grid .rgCell[data-f="fixed_salary"]').first().boundingBox()
  check('narrow: a money column can be scrolled into view and is usable', salaryBox && salaryBox.x >= 0 && salaryBox.x + salaryBox.width <= 391 && salaryBox.width >= 80, JSON.stringify(salaryBox))
  const pinnedCount = await phone.locator('.payroll-grid .rgCell[data-f="employee_number"]').first().isVisible()
  check('narrow: the employee number stays frozen while scrolled', pinnedCount)
  await phone.locator('.payroll-grid').scrollIntoViewIfNeeded(); await phone.waitForTimeout(300)
  await shot(phone, '10b-payroll-mobile-grid')
  await phone.getByRole('button', { name: 'إضافة موظف' }).click()
  const box = await phone.locator('dialog[open]').boundingBox()
  check('narrow: dialog fits the viewport', box.x >= 0 && box.x + box.width <= 391 && box.y >= 0 && box.y + box.height <= 845, JSON.stringify(box))
  await shot(phone, '11-employee-dialog-mobile')
  await phone.keyboard.press('Escape')
  await phone.getByRole('button', { name: 'المرشحات' }).click()
  check('narrow: the filters open from one button', await phone.getByLabel('الهيئة').isVisible())
  await phone.getByRole('button', { name: 'إدارة الأعمدة والمعادلات' }).click()
  const colBox = await phone.locator('dialog[open]').boundingBox()
  check('narrow: the column manager dialog fits the viewport', colBox.x >= 0 && colBox.x + colBox.width <= 391 && colBox.y >= 0 && colBox.y + colBox.height <= 845, JSON.stringify(colBox))
  check('narrow: the column manager has no page-level horizontal overflow', await phone.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1))
  await shot(phone, '11b-columns-dialog-mobile')
  await phone.keyboard.press('Escape')
  await phone.getByRole('button', { name: 'إدارة الهيئات' }).click()
  await shot(phone, '12-bodies-dialog-mobile')
  check('narrow: no uncaught page errors', small.errors.length === 0, small.errors.join(' | '))
  await small.browser.close()
}

const failed = results.filter(r => !r.ok)
fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(results, null, 2))
console.log(`\n${results.length - failed.length}/${results.length} checks passed. Output: ${OUT}`)
assert.equal(failed.length, 0, `${failed.length} browser checks failed`)
