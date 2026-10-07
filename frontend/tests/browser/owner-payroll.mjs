// Live browser verification of the university-owner payroll sheet (desktop + narrow screen).
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

// ── seed (synthetic) ────────────────────────────────────────────────────
const teaching = (await api(owner, 'POST', '/v1/owner/payroll/bodies', { name: 'هيئة التدريس' })).json.data
const admin = (await api(owner, 'POST', '/v1/owner/payroll/bodies', { name: 'الهيئة الإدارية' })).json.data
const names = ['سامر العلي', 'ليلى الحسن', 'نور الدين خالد', 'رامي يوسف', 'هدى مصطفى', 'عمر ناصر', 'ريم سليمان', 'خالد إبراهيم']
const places = ['afrin', 'jarablus', 'afrin_jarablus']
const ids = []
for (let i = 1; i <= 40; i += 1) {
  const created = await api(owner, 'POST', '/v1/owner/payroll/employees', {
    employee_number: String(i).padStart(4, '0'), full_name: `${names[i % 8]} ${i}`, job_title: i % 2 ? 'أستاذ مساعد' : 'محاسب', body_id: i % 2 ? teaching.id : admin.id,
    workplace: i === 5 ? 'other' : places[i % 3], workplace_other: i === 5 ? 'إدلب' : null, academic_level: i % 3 ? 'دكتوراه' : null,
  })
  ids.push(created.json.data.id)
}
const id = n => ids[n - 1] // employee "n" (number 000n)

// ── browser plumbing ────────────────────────────────────────────────────
const patches = []
let blockPatches = false
async function openBrowser({ width, height, mobile = false, role = 'owner' }) {
  const browser = await chromium.launch({ executablePath: process.env.CHROME ?? '/opt/pw-browsers/chromium', args: ['--no-sandbox'] })
  const context = await browser.newContext({ viewport: { width, height }, permissions: ['clipboard-read', 'clipboard-write'], acceptDownloads: true, isMobile: mobile, hasTouch: mobile })
  const me = await api(tokens[role], 'GET', '/user')
  await context.addInitScript(([token, user]) => { localStorage.setItem('token', token); localStorage.setItem('user', JSON.stringify(user)) }, [tokens[role], me.json.data])
  await context.route(`${API}/**`, async route => {
    const request = route.request()
    const cors = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*', 'access-control-allow-methods': '*', 'access-control-expose-headers': 'content-disposition' }
    if (request.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: cors })
    if (request.method() === 'PATCH' && request.url().endsWith('/payroll/amounts')) {
      patches.push(JSON.parse(request.postData()))
      if (blockPatches) return route.abort('failed')
    }
    const headers = { ...request.headers() }
    delete headers.host; delete headers['content-length']
    const upstream = await fetch(request.url(), { method: request.method(), headers, body: ['GET', 'HEAD'].includes(request.method()) ? undefined : request.postData() })
    const body = Buffer.from(await upstream.arrayBuffer())
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
const text = (page, who, prop) => cellLoc(page, who, prop).innerText().then(value => value.trim())
const savedStable = async page => { await page.waitForFunction(() => !document.querySelector('[data-testid="payroll-totals"]')?.innerText.includes('جارٍ الحفظ'), null, { timeout: 8000 }); await page.waitForTimeout(150) }
const writeClipboard = (page, value) => page.evaluate(v => navigator.clipboard.writeText(v), value)
const readClipboard = page => page.evaluate(() => navigator.clipboard.readText())
const money = value => (value === null ? null : value)
const amounts = row => [row.fixed_salary, row.deduction, row.compensation]

// ═══ DESKTOP ═══════════════════════════════════════════════════════════
{
  const wide = await openBrowser({ width: 1900, height: 1000 })
  const { page } = wide
  await page.goto(`${APP}/owner`)
  await page.waitForSelector('aside nav a')
  equal('navigation has exactly two items (Home, Payroll)', await page.$$eval('aside nav a', els => els.map(e => e.textContent.replace(/Home|Payroll/, '').trim())), ['الرئيسية', 'الرواتب'])
  check('account controls (logout menu) remain in the shared header', await page.locator('header').first().innerText().then(t => t.includes('owner.synthetic')))
  await page.waitForSelector('[data-testid="total-payable"], .text-primary-dark')
  await shot(page, '01-home-desktop')
  check('Home shows employee count', (await page.locator('main').innerText()).includes('40'))
  check('Home links to Payroll', await page.locator('main a[href="/owner/payroll"]').count() > 0)

  await page.goto(`${APP}/owner/payroll`)
  await page.waitForSelector('.payroll-grid .rgCell')
  await page.waitForTimeout(600)
  const headers = await page.$$eval('.payroll-grid .rgHeaderCell', els => els.map(e => ({ t: e.textContent.replace(/[▲▼]/g, '').trim(), x: e.getBoundingClientRect().x })).filter(h => h.t).sort((a, b) => b.x - a.x).map(h => h.t))
  equal('column order (right to left)', headers, ['رقم الموظف', 'الاسم الكامل', 'الصفة الوظيفية', 'الهيئة', 'مكان العمل', 'المستوى الأكاديمي', 'الراتب المقطوع $', 'الاقتطاع $', 'التعويض $', 'المستحق $'])
  const buttons = await page.locator('main header, main > div').first().innerText()
  for (const label of ['إضافة موظف', 'إدارة الهيئات', 'تصدير Excel', 'تصدير PDF']) check(`action above the grid: ${label}`, buttons.includes(label) || await page.getByRole('button', { name: label }).count() > 0)
  await shot(page, '02-payroll-desktop-wide')
  const blankCell = await text(page, id(1), 'fixed_salary')
  check('new financial inputs are blank (not zero)', blankCell === '' && (await serverRow(id(1))).fixed_salary === null)
  await wide.browser.close()
  check('no uncaught page errors (wide)', wide.errors.length === 0, wide.errors.join(' | '))
}

const desk = await openBrowser({ width: 1440, height: 900 })
const { page } = desk
await page.goto(`${APP}/owner/payroll`)
await page.waitForSelector('.payroll-grid .rgCell')
await page.waitForTimeout(500)

// ── editing ────────────────────────────────────────────────────────────
await select(page, id(1), 'fixed_salary')
await page.keyboard.type('1200.50', { delay: 40 })
await page.keyboard.press('Enter')
await savedStable(page)
equal('typed edit saved (server)', amounts(await serverRow(id(1))), ['1200.50', null, null])
await reveal(page, 'payable')
equal('payable recalculated and shown with $', await text(page, id(1), 'payable'), '$1,200.50')
equal('Enter moved the focus down', await focused(page), { prop: 'fixed_salary', id: id(2) })
check('saved state is shown', (await page.locator('[data-testid="payroll-totals"]').innerText()).includes('تم الحفظ'))

await select(page, id(2), 'deduction')
await page.keyboard.press('F2'); await page.waitForTimeout(250); await page.keyboard.type('99'); await page.waitForTimeout(150); await page.keyboard.press('Escape')
await page.waitForTimeout(400)
equal('F2 then Escape cancels the edit', [await text(page, id(2), 'deduction'), (await serverRow(id(2))).deduction], ['', null])
await page.keyboard.press('F2'); await page.waitForTimeout(250); await page.keyboard.type('25'); await page.keyboard.press('Enter'); await savedStable(page)
equal('F2 edit commits', (await serverRow(id(2))).deduction, '25.00')

await select(page, id(3), 'compensation')
await cellLoc(page, id(3), 'compensation').dblclick(); await page.waitForTimeout(300); await page.keyboard.type('7'); await page.keyboard.press('Enter'); await savedStable(page)
equal('double-click starts an edit', (await serverRow(id(3))).compensation, '7.00')

const patchesBefore = patches.length
await select(page, id(4), 'fixed_salary')
await page.keyboard.type('abc'); await page.keyboard.press('Enter'); await page.waitForTimeout(400)
check('invalid typed value is refused client-side (no request, message shown, editor stays on the value)', patches.length === patchesBefore && (await page.locator('main').innerText()).includes('قيمة غير مقبولة') && (await page.locator('revogr-edit input.pg-input-invalid').count()) === 1)
await page.keyboard.press('Escape'); await page.waitForTimeout(300)
await select(page, id(4), 'fixed_salary')
await page.keyboard.type('-5'); await page.keyboard.press('Enter'); await page.waitForTimeout(400)
check('negative typed value is refused (no request)', patches.length === patchesBefore && (await serverRow(id(4))).fixed_salary === null)
await page.keyboard.press('Escape'); await page.waitForTimeout(300)
await select(page, id(4), 'fixed_salary')
await page.keyboard.type('0'); await page.keyboard.press('Enter'); await savedStable(page)
equal('explicit zero stored as 0.00 and shown', [(await serverRow(id(4))).fixed_salary, await text(page, id(4), 'fixed_salary')], ['0.00', '$0.00'])
await shot(page, '03-after-edits')

// ── keyboard navigation ────────────────────────────────────────────────
await select(page, id(6), 'fixed_salary')
await key(page, 'ArrowLeft'); equal('ArrowLeft moves visually left', await focused(page), { prop: 'deduction', id: id(6) })
await key(page, 'ArrowRight'); equal('ArrowRight moves visually right', await focused(page), { prop: 'fixed_salary', id: id(6) })
await key(page, 'ArrowDown'); equal('ArrowDown', await focused(page), { prop: 'fixed_salary', id: id(7) })
await key(page, 'ArrowUp'); equal('ArrowUp', await focused(page), { prop: 'fixed_salary', id: id(6) })
await key(page, 'Tab'); equal('Tab follows the Arabic reading direction (to the left)', await focused(page), { prop: 'deduction', id: id(6) })
await key(page, 'Shift+Tab'); equal('Shift+Tab goes back (to the right)', await focused(page), { prop: 'fixed_salary', id: id(6) })
await key(page, 'Enter'); equal('Enter moves down', await focused(page), { prop: 'fixed_salary', id: id(7) })
await key(page, 'Shift+Enter'); equal('Shift+Enter moves up', await focused(page), { prop: 'fixed_salary', id: id(6) })
await select(page, id(6), 'payable')
await key(page, 'Tab'); equal('Tab from the last column wraps to the first column of the next row', await focused(page), { prop: 'employee_number', id: id(7) })
await select(page, id(6), 'deduction')
await page.keyboard.type('3'); await key(page, 'Tab'); await savedStable(page)
equal('Tab while editing commits and moves in reading order', [(await serverRow(id(6))).deduction, await focused(page)], ['3.00', { prop: 'compensation', id: id(6) }])
await page.keyboard.type('4'); await key(page, 'Shift+Enter'); await savedStable(page)
equal('Shift+Enter while editing commits and moves up', [(await serverRow(id(6))).compensation, await focused(page)], ['4.00', { prop: 'compensation', id: id(5) }])

// ── frozen columns, sticky header, horizontal scroll, column resizing ──
await page.evaluate(async () => { await document.querySelector('revo-grid').scrollToRow(0) })
await reveal(page, 'payable')
await page.waitForTimeout(300)
const gridBox = await page.locator('.payroll-grid').boundingBox()
const pinnedBox = await cellLoc(page, id(1), 'employee_number').boundingBox()
const pinnedNameBox = await cellLoc(page, id(1), 'full_name').boundingBox()
check('employee number and name stay visible while the sheet is scrolled horizontally to the last column', pinnedBox && pinnedNameBox && pinnedBox.x + pinnedBox.width <= gridBox.x + gridBox.width + 1 && pinnedNameBox.x >= gridBox.x - 1, JSON.stringify({ pinnedBox, pinnedNameBox, gridBox }))
check('the last column is reachable by horizontal scrolling', (await cellLoc(page, id(1), 'payable').count()) === 1)
await page.evaluate(async () => { const g = document.querySelector('revo-grid'); await g.scrollToRow(30) })
await page.waitForTimeout(250)
const headBox = await page.locator('.payroll-grid .rgHeaderCell').first().boundingBox()
check('header stays in place while the rows scroll vertically', headBox && headBox.y >= gridBox.y - 1 && headBox.y <= gridBox.y + 60, JSON.stringify(headBox))
await page.evaluate(async () => { await document.querySelector('revo-grid').scrollToRow(0) })
await reveal(page, 'deduction')
await page.waitForTimeout(250)
const deductionHead = page.locator('.payroll-grid .rgHeaderCell', { hasText: 'الاقتطاع' })
const before = (await deductionHead.boundingBox()).width
const handle = await deductionHead.locator('.resizable-r').boundingBox()
await page.mouse.move(handle.x + 2, handle.y + handle.height / 2); await page.mouse.down(); await page.mouse.move(handle.x + 52, handle.y + handle.height / 2, { steps: 8 }); await page.mouse.up()
await page.waitForTimeout(300)
const after = (await page.locator('.payroll-grid .rgHeaderCell', { hasText: 'الاقتطاع' }).boundingBox()).width
check('columns can be resized by dragging the header edge', Math.abs(after - before) >= 20, `${before} -> ${after}`)

// ── range selection, copy, paste, clear, undo/redo ─────────────────────
await select(page, id(1), 'fixed_salary')
await key(page, 'Shift+ArrowDown'); await key(page, 'Shift+ArrowDown'); await key(page, 'Shift+ArrowLeft')
const range = await page.evaluate(() => document.querySelector('revo-grid').getSelectedRange())
check('rectangular range selection (3 rows x 2 columns)', range && Math.abs(range.y1 - range.y) === 2 && Math.abs(range.x1 - range.x) === 1, JSON.stringify(range))
await page.keyboard.press('Control+c'); await page.waitForTimeout(250)
equal('copy puts tab/newline text on the clipboard (blank stays blank)', await readClipboard(page), '1200.50\t\n\t25.00\n\t')

patches.length = 0
await writeClipboard(page, '100\t5\t7\n200.5\t\t8\n300\t0\t')
await select(page, id(10), 'fixed_salary')
await page.keyboard.press('Control+v'); await savedStable(page)
equal('multi-cell paste is ONE request', patches.length, 1)
equal('that request carries the 3 employees by stable id and revision', patches[0].changes.map(c => c.employee_id), [id(10), id(11), id(12)])
equal('pasted block saved (row 10)', amounts(await serverRow(id(10))), ['100.00', '5.00', '7.00'])
equal('pasted block saved (row 11, blank over blank untouched)', amounts(await serverRow(id(11))), ['200.50', null, '8.00'])
equal('pasted block saved (row 12, explicit zero kept)', amounts(await serverRow(id(12))), ['300.00', '0.00', null])
equal('totals follow the pasted values immediately', await page.locator('[data-testid="total-fixed_salary"]').innerText(), `$${(1200.5 + 100 + 200.5 + 300).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')}`)

patches.length = 0
await writeClipboard(page, '1\tabc\n-5\t2')
await select(page, id(13), 'fixed_salary')
await page.keyboard.press('Control+v'); await page.waitForTimeout(500)
check('invalid paste sends nothing', patches.length === 0)
equal('invalid paste commits nothing (server)', [amounts(await serverRow(id(13))), amounts(await serverRow(id(14)))], [[null, null, null], [null, null, null]])
const alert = await page.locator('[role="alert"]', { hasText: 'لم يُلصق شيء' }).innerText()
check('invalid paste names the offending cells', alert.includes('0013') && alert.includes('0014') && alert.includes('قيمة غير صالحة'), alert)
check('offending cells are highlighted', await page.locator('.payroll-grid .rgCell.pg-invalid').count() >= 1)
await shot(page, '04-invalid-paste')

for (const [label, who, prop, clip] of [['metadata column', id(15), 'full_name', '9'], ['computed column', id(15), 'payable', '9'], ['block spilling into the computed column', id(15), 'compensation', '1\t2']]) {
  patches.length = 0
  await writeClipboard(page, clip)
  await select(page, who, prop)
  await page.keyboard.press('Control+v'); await page.waitForTimeout(400)
  check(`paste into ${label} is refused and nothing is sent`, patches.length === 0 && (await page.locator('[role="alert"]', { hasText: 'لم يُلصق شيء' }).count()) > 0)
}
const protectedRow = await serverRow(id(15))
check('protected cells unchanged after refused pastes', protectedRow.full_name === `${names[15 % 8]} 15` && amounts(protectedRow).every(v => v === null))
patches.length = 0
await writeClipboard(page, '5')
await select(page, id(16), 'fixed_salary')
await key(page, 'Shift+ArrowDown', 'Shift+ArrowDown', 'Shift+ArrowLeft')
await page.keyboard.press('Control+v'); await savedStable(page)
equal('a single copied value fills the selected range', [amounts(await serverRow(id(16))), amounts(await serverRow(id(18)))], [['5.00', '5.00', null], ['5.00', '5.00', null]])

// clear
patches.length = 0
await select(page, id(10), 'fixed_salary')
await key(page, 'Shift+ArrowDown', 'Shift+ArrowDown', 'Shift+ArrowLeft', 'Shift+ArrowLeft')
await page.keyboard.press('Delete'); await savedStable(page)
equal('Delete clears the selected range in ONE request', patches.length, 1)
equal('cleared cells are blank (null), not zero', [amounts(await serverRow(id(10))), amounts(await serverRow(id(12)))], [[null, null, null], [null, null, null]])
await select(page, id(11), 'deduction')
await page.keyboard.type('0'); await page.keyboard.press('Enter'); await savedStable(page)
await select(page, id(11), 'deduction'); await page.keyboard.press('Backspace'); await savedStable(page)
equal('Backspace clears one cell', (await serverRow(id(11))).deduction, null)

// undo / redo
await key(page, 'Control+z'); await savedStable(page)
equal('undo #1 restores the cleared cell (0)', (await serverRow(id(11))).deduction, '0.00')
await key(page, 'Control+z'); await savedStable(page); await key(page, 'Control+z'); await savedStable(page)
equal('undo of the clear restores the whole pasted range in one step', [amounts(await serverRow(id(10))), amounts(await serverRow(id(12)))], [['100.00', '5.00', '7.00'], ['300.00', '0.00', null]])
await key(page, 'Control+z'); await savedStable(page) // undoes the single-value fill of rows 16-18
equal('undo of the fill restores rows 16-18', [amounts(await serverRow(id(16))), amounts(await serverRow(id(18)))], [[null, null, null], [null, null, null]])
await key(page, 'Control+z'); await savedStable(page)
equal('undo of the paste removes it entirely', [amounts(await serverRow(id(10))), amounts(await serverRow(id(11))), amounts(await serverRow(id(12)))], [[null, null, null], [null, null, null], [null, null, null]])
await key(page, 'Control+y'); await savedStable(page)
equal('redo re-applies the paste', amounts(await serverRow(id(10))), ['100.00', '5.00', '7.00'])
await page.getByRole('button', { name: /تراجع/ }).click(); await savedStable(page)
check('toolbar undo works too', (await serverRow(id(10))).fixed_salary === null)
await page.getByRole('button', { name: /إعادة \(/ }).click(); await savedStable(page)
equal('toolbar redo works too', (await serverRow(id(10))).fixed_salary, '100.00')

// ── sorting, filtering: stable ids and totals ──────────────────────────
await page.locator('.payroll-grid .rgHeaderCell', { hasText: 'الراتب المقطوع' }).click()
await page.waitForFunction(() => document.querySelector('.pg-head[data-sort="fixed_salary"]')?.textContent.includes('▲'))
await savedStable(page); await page.waitForTimeout(500)
const expectedOrder = (await sheet('sort=fixed_salary&direction=asc')).data.map(r => r.id)
const domOrder = await page.evaluate(async () => (await document.querySelector('revo-grid').getSource()).map(r => r.id))
equal('ascending sort by salary matches the server order (blanks last)', domOrder.slice(0, 12), expectedOrder.slice(0, 12))
const top = domOrder[0]
await select(page, top, 'compensation')
await page.keyboard.type('11'); await page.keyboard.press('Enter'); await savedStable(page)
equal('editing after sorting updates the right employee (stable id, not row index)', [(await serverRow(top)).compensation, (await serverRow(domOrder[1])).compensation === '11.00'], ['11.00', false])
await page.locator('.payroll-grid .rgHeaderCell', { hasText: 'الراتب المقطوع' }).click()
await page.waitForFunction(() => document.querySelector('.pg-head[data-sort="fixed_salary"]')?.textContent.includes('▼'))
await page.waitForTimeout(600)
equal('second click sorts descending', (await page.evaluate(async () => (await document.querySelector('revo-grid').getSource()).map(r => r.id))).slice(0, 3), (await sheet('sort=fixed_salary&direction=desc')).data.slice(0, 3).map(r => r.id))

await page.locator('select').nth(0).selectOption(String(teaching.id))
await page.waitForTimeout(800)
const filteredServer = await sheet(`body_id=${teaching.id}&sort=fixed_salary&direction=desc`)
const fmt = value => { const n = Math.round(Number(value) * 100); const a = Math.abs(n); return `${n < 0 ? '-' : ''}$${String(Math.floor(a / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${String(a % 100).padStart(2, '0')}` }
for (const key of ['fixed_salary', 'deduction', 'compensation', 'payable']) equal(`filtered total ${key} equals the server totals for ALL matching rows`, await page.locator(`[data-testid="total-${key}"]`).innerText(), fmt(filteredServer.meta.totals[key]))
check('totals scope is labelled', (await page.locator('[data-testid="totals-scope"]').innerText()).includes('الصفوف المطابقة للمرشحات الحالية: 20'))
check('only matching rows are in the grid', (await page.evaluate(async () => (await document.querySelector('revo-grid').getSource()).length)) === 20)
await select(page, (filteredServer.data[0]).id, 'deduction')
await page.keyboard.type('13.13'); await page.keyboard.press('Enter'); await savedStable(page)
equal('edit under a filter hits the right employee', (await serverRow(filteredServer.data[0].id)).deduction, '13.13')
await page.locator('select').nth(1).selectOption('jarablus'); await page.waitForTimeout(700)
check('filters combine', (await page.evaluate(async () => (await document.querySelector('revo-grid').getSource()).every(r => r.workplace === 'jarablus' && r.body_id))) === true)
await page.getByRole('button', { name: 'مسح الفلاتر' }).first().click(); await page.waitForTimeout(700)
check('clearing filters restores all rows', (await page.evaluate(async () => (await document.querySelector('revo-grid').getSource()).length)) === 40)
await page.locator('input[type="text"]').first().fill('0007'); await page.waitForTimeout(900)
equal('search narrows the grid', await page.evaluate(async () => (await document.querySelector('revo-grid').getSource()).map(r => r.employee_number)), ['0007'])
await page.locator('input[type="text"]').first().fill(''); await page.waitForTimeout(900)

// ── failed save, retry, conflict ───────────────────────────────────────
await select(page, id(20), 'deduction')
blockPatches = true
await page.keyboard.type('21'); await page.keyboard.press('Enter')
await page.locator('[role="alert"]', { hasText: 'فشل حفظ آخر عملية' }).waitFor({ timeout: 6000 }).catch(() => {})
if (process.env.OWNER_E2E_DEBUG) console.log('DEBUG', await page.evaluate(() => ({ editors: document.querySelectorAll('revogr-edit input').length, active: document.activeElement?.tagName, alerts: [...document.querySelectorAll('[role=alert]')].map(a => a.innerText.slice(0, 60)) })))
check('failed save is announced', (await page.locator('[role="alert"]', { hasText: 'فشل حفظ آخر عملية' }).count()) === 1)
equal('entered value stays on screen (not lost, not shown as saved)', [await text(page, id(20), 'deduction'), await cellLoc(page, id(20), 'deduction').getAttribute('data-state')], ['$21.00', 'failed'])
equal('server unchanged after the failure', (await serverRow(id(20))).deduction, null)
check('"saved" indicator is not shown during failure', !(await page.locator('[data-testid="payroll-totals"]').innerText()).includes('تم الحفظ') || (await page.locator('[data-testid="payroll-totals"]').innerText()).includes('فشل الحفظ'))
await shot(page, '05-failed-save')
await page.getByRole('button', { name: 'تصدير Excel' }).click(); await page.waitForTimeout(500)
check('export is refused while an edit is unsaved', (await page.locator('main').innerText()).includes('لا يمكن التصدير قبل حلّ مشكلة الحفظ'))
blockPatches = false
await page.getByRole('button', { name: 'إعادة المحاولة' }).click(); await savedStable(page)
equal('retry saves the same value', (await serverRow(id(20))).deduction, '21.00')

await select(page, id(21), 'deduction') // loads revision of row 21
await api(owner, 'PATCH', '/v1/owner/payroll/amounts', { changes: [{ employee_id: id(21), expected_revision: (await serverRow(id(21))).entry_revision, deduction: '5' }] })
await page.keyboard.type('11'); await page.keyboard.press('Enter'); await page.waitForTimeout(900)
check('stale edit produces an explicit conflict', (await page.locator('[role="alert"]', { hasText: 'تعارض' }).count()) >= 1)
equal('pending value preserved on screen, other editor value kept on server', [await text(page, id(21), 'deduction'), (await serverRow(id(21))).deduction], ['$11.00', '5.00'])
await shot(page, '06-conflict')
await page.getByRole('button', { name: 'الاحتفاظ بقيمي وإعادة الحفظ' }).click(); await savedStable(page)
equal('"keep mine" saves on top of the latest revision', (await serverRow(id(21))).deduction, '11.00')

// ── exports through the real buttons ───────────────────────────────────
await page.goto(`${APP}/owner/payroll`); await page.waitForSelector('.payroll-grid .rgCell'); await page.waitForTimeout(500)
const gridTotals = {}
for (const key of ['fixed_salary', 'deduction', 'compensation', 'payable']) gridTotals[key] = await page.locator(`[data-testid="total-${key}"]`).innerText()
const [xlsxDownload] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'تصدير Excel' }).click()])
const xlsxPath = path.join(OUT, 'export.xlsx'); await xlsxDownload.saveAs(xlsxPath)
const [pdfDownload] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'تصدير PDF' }).click()])
const pdfPath = path.join(OUT, 'export.pdf'); await pdfDownload.saveAs(pdfPath)
check('download names carry the right extensions', xlsxDownload.suggestedFilename().endsWith('.xlsx') && pdfDownload.suggestedFilename().endsWith('.pdf'))
const csvDir = path.join(OUT, 'csv'); fs.mkdirSync(csvDir, { recursive: true })
execFileSync('soffice', ['--headless', '--convert-to', 'csv:Text - txt - csv (StarCalc):44,34,76,1', '--outdir', csvDir, xlsxPath], { stdio: 'ignore', timeout: 120000 })
const csv = fs.readFileSync(path.join(csvDir, 'export.csv'), 'utf8').trim().split('\n')
const totalLine = csv.at(-1)
check('Excel totals row (recalculated by LibreOffice) equals the grid totals', Object.values(gridTotals).every(value => totalLine.includes(value.includes(',') ? `"${value}"` : value)), `${totalLine} vs ${JSON.stringify(gridTotals)}`)
check('Excel has one data row per employee', csv.length === 5 + 40 + 1, String(csv.length))
check('Excel keeps employee numbers as text with leading zeros', csv.some(line => line.startsWith('"0001"')))
const pdfInfo = execFileSync('pdfinfo', [pdfPath]).toString()
check('PDF is A3 landscape', /Page size:\s+1190\.55 x 841\.89/.test(pdfInfo), pdfInfo)
execFileSync('pdftoppm', ['-r', '70', '-png', '-f', '1', '-l', '1', pdfPath, path.join(OUT, 'export-pdf')])
const pdfText = execFileSync('pdftotext', ['-layout', pdfPath, '-']).toString()
check('PDF shows the same totals', Object.values(gridTotals).every(value => pdfText.includes(value)), JSON.stringify(gridTotals))

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
  check(`${role} account cannot mutate or export`, [(await api(tokens[role], 'PATCH', '/v1/owner/payroll/amounts', { changes: [] })).status, (await api(tokens[role], 'GET', '/v1/owner/payroll/export/xlsx')).status].every(s => s === 403))
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
  await phone.getByRole('button', { name: 'إدارة الهيئات' }).click()
  await shot(phone, '12-bodies-dialog-mobile')
  check('narrow: no uncaught page errors', small.errors.length === 0, small.errors.join(' | '))
  await small.browser.close()
}

const failed = results.filter(r => !r.ok)
fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(results, null, 2))
console.log(`\n${results.length - failed.length}/${results.length} checks passed. Output: ${OUT}`)
assert.equal(failed.length, 0, `${failed.length} browser checks failed`)
