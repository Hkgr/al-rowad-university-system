// Run after ExamTranscriptExportTest with EXAM_TRANSCRIPT_EVIDENCE_DIR set.
// Requires Playwright/Chromium and a Vite server serving tests/fixtures.
import assert from 'node:assert/strict'
import { readFile, mkdir, readdir, unlink, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { createRequire } from 'node:module'
import { execFileSync } from 'node:child_process'

const require = createRequire(import.meta.url)
const { chromium } = require('playwright')
const source = resolve(process.env.EXAM_TRANSCRIPT_EVIDENCE_DIR || '/workspace/.cloud-environment/exam-transcript-evidence')
const output = resolve(process.env.EXAM_TRANSCRIPT_PDF_DIR || '../docs/review-evidence/exam-transcript')
const base = process.env.EXAM_TRANSCRIPT_BROWSER_URL || 'http://127.0.0.1:5174'
await mkdir(output, { recursive: true })
for (const file of await readdir(output)) {
  if (/^(short|long|states-missing|historical|withdrawal-only|default-other-offices|fresh-request)(?:-\d+\.png|-layout\.json|\.pdf)$/.test(file)) await unlink(resolve(output, file))
}
const fixtures = Object.fromEntries(await Promise.all(['short', 'long', 'states-missing', 'historical', 'withdrawal-only'].map(async name => [
  name, JSON.parse(await readFile(resolve(source, name + '.json'), 'utf8')),
])))
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_EXECUTABLE || '/usr/bin/chromium', headless: true, args: ['--no-sandbox'] })
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, acceptDownloads: true })
const errors = []
page.on('pageerror', error => errors.push(error.message))
const requests = []
let freshName = null
await page.route('**/api/**', async route => {
  const request = route.request()
  requests.push({ method: request.method(), url: request.url() })
  const isRecord = /\/(academic-record|exam-transcript)(?:\?|$)/.test(request.url())
  const data = structuredClone(fixtures.short)
  if (/\/exam-transcript(?:\?|$)/.test(request.url()) && freshName) data.student.full_name = freshName
  await route.fulfill({
    status: 200,
    headers: {
      'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Headers': 'Authorization, Content-Type, Accept',
      'Access-Control-Allow-Methods': 'GET, OPTIONS',
    },
    body: JSON.stringify(isRecord ? { success: true, data } : { success: true, data: [], meta: { total: 0, current_page: 1, last_page: 1 } }),
  })
})
const results = []
try {
  await page.goto(base + '/tests/fixtures/exam-transcript.html')
  await page.waitForFunction(() => window.pdfFixtureReady === true)
  await page.evaluate(() => {
    window.capturedPdfPages = []
    new MutationObserver(() => {
      const pages = [...document.querySelectorAll('[data-pdf-page]')]
      if (!pages.length) return
      window.capturedPdfPages = pages.map(page => {
        const content = page.querySelector('[data-pdf-content]')
        return {
          text: page.innerText,
          courseIds: [...page.querySelectorAll('[data-exam-course-id]')].map(row => row.dataset.examCourseId),
          courseCells: [...page.querySelectorAll('[data-exam-course-id]')].map(row => ({ id: row.dataset.examCourseId, cells: [...row.cells].map(cell => cell.textContent) })),
          averages: [...page.querySelectorAll('.exam-averages')].map(row => [...row.querySelectorAll('bdi')].map(cell => cell.textContent)),
          height: page.clientHeight, scrollHeight: page.scrollHeight,
          contentHeight: content.clientHeight, contentScrollHeight: content.scrollHeight,
          overflowingCells: [...page.querySelectorAll('td,th')].filter(cell => cell.scrollWidth > cell.clientWidth + 2).map(cell => cell.textContent),
          logos: [...page.querySelectorAll('img')].map(image => image.naturalWidth),
        }
      })
    }).observe(document.body, { childList: true, subtree: true })
  })

  async function checkDownload(name, download, record, { exam = true } = {}) {
    const filename = resolve(output, name + '.pdf')
    await download.saveAs(filename)
    const captured = await page.evaluate(() => window.capturedPdfPages)
    assert.ok(captured.length > 0, name + ': no captured PDF pages')
    for (const [index, item] of captured.entries()) {
      assert.ok(item.scrollHeight <= item.height + 2, name + ': page overflow ' + index)
      assert.ok(item.contentScrollHeight <= item.contentHeight + 2, name + ': content overflow ' + index)
      assert.deepEqual(item.overflowingCells, [], name + ': horizontally clipped cells')
      assert.ok(item.logos.every(width => width > 0), name + ': logo missing')
      assert.ok(item.text.includes('صفحة ' + (index + 1) + ' من ' + captured.length))
    }
    if (exam) {
      const expected = record.exam_export.terms.flatMap(term => term.courses.map(course => String(course.registration_id))).sort()
      const actual = captured.flatMap(item => item.courseIds).sort()
      assert.deepEqual(actual, expected, name + ': a course was omitted or duplicated')
      const courseCells = new Map(captured.flatMap(item => item.courseCells).map(row => [row.id, row.cells]))
      for (const course of record.exam_export.terms.flatMap(term => term.courses)) {
        const cells = courseCells.get(String(course.registration_id))
        assert.equal(cells[0], course.course_code || '', name + ': historical code changed')
        assert.equal(cells[2], course.credit_hours == null ? '' : String(Number(course.credit_hours)), name + ': applicable hours changed')
        assert.equal(cells[3], course.final_mark == null ? '' : String(Number(course.final_mark)), name + ': official mark changed')
      }
      if (name === 'withdrawal-only') assert.deepEqual(captured.flatMap(item => item.averages), [['', '']], 'Missing GPA must remain blank')
      assert.ok(captured.every(item => item.text.includes('رئيس الجامعة') && item.text.includes('رئيس دائرة الامتحانات')))
      assert.ok(captured.every(item => item.text.includes('شؤون الطلاب رقم')), 'Keep the approved form text unchanged')
    } else {
      assert.ok(captured.some(item => item.text.includes('نسخة إلكترونية غير مصدقة')))
      assert.ok(captured.some(item => item.text.includes('رئيس الهيئة الامتحانية')))
      assert.ok(captured.every(item => !item.text.includes('أعطي/ت الطالب/ة')))
      const text = captured.map(item => item.text).join('\n')
      const codes = new Map()
      for (const course of record.transcript.terms.flatMap(term => term.courses)) codes.set(course.course_code, (codes.get(course.course_code) || 0) + 1)
      for (const [code, count] of codes) assert.equal(text.split(code).length - 1, count, 'Default template must retain every attempt')
    }
    const info = execFileSync('pdfinfo', [filename], { encoding: 'utf8' })
    assert.match(info, new RegExp('Pages:\\s+' + captured.length + '\\b'))
    assert.match(info, /Page size:\s+595[\d.]* x 841[\d.]* pts \(A4\)/)
    await writeFile(resolve(output, name + '-layout.json'), JSON.stringify(captured, null, 2) + '\n')
    execFileSync('pdftoppm', ['-r', '105', '-png', filename, resolve(output, name)], { timeout: 180000 })
    const courses = exam ? captured.reduce((sum, item) => sum + item.courseIds.length, 0) : record.transcript.terms.reduce((sum, term) => sum + term.courses.length, 0)
    results.push({ name, pages: captured.length, courses, bytes: (await readFile(filename)).length })
    console.log('PASS PDF', name, captured.length, 'pages')
    return captured
  }

  for (const [name, record] of Object.entries(fixtures)) {
    const download = page.waitForEvent('download', { timeout: 180000 })
    await page.evaluate(async record => {
      window.capturedPdfPages = []
      await window.pdfFixture.exportRecord(record)
    }, record)
    await checkDownload(name, await download, record)
  }

  const defaultDownload = page.waitForEvent('download', { timeout: 180000 })
  await page.evaluate(async record => {
    window.capturedPdfPages = []
    await window.pdfFixture.exportRecord(record, 'default')
  }, fixtures.short)
  await checkDownload('default-other-offices', await defaultDownload, fixtures.short, { exam: false })

  await page.evaluate(() => window.pdfFixture.show('reference'))
  await page.getByRole('heading', { name: fixtures.short.student.full_name }).waitFor()
  await page.screenshot({ path: resolve(output, 'reference-record-desktop.png'), fullPage: true })
  await page.evaluate(() => window.pdfFixture.show('exam'))
  await page.getByRole('heading', { name: fixtures.short.student.full_name }).waitFor()
  await page.getByRole('button', { name: 'استخراج كشف العلامات الإلكتروني', exact: true }).click()
  const dialog = page.getByRole('dialog')
  await dialog.waitFor()
  assert.equal(await dialog.locator('input[name=date]').inputValue(), '')
  assert.equal(await dialog.locator('input[name=number]').inputValue(), '')
  await page.screenshot({ path: resolve(output, 'export-dialog-desktop.png') })
  await page.keyboard.press('Escape')
  assert.equal(await dialog.count(), 0)
  assert.equal(requests.filter(request => request.url.endsWith('/exam-transcript')).length, 0)

  await page.setViewportSize({ width: 390, height: 844 })
  await page.getByRole('button', { name: 'استخراج كشف العلامات الإلكتروني', exact: true }).click()
  await page.getByRole('dialog').waitFor()
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1))
  await page.screenshot({ path: resolve(output, 'export-dialog-mobile.png') })
  await page.getByRole('button', { name: 'إلغاء', exact: true }).click()
  await page.screenshot({ path: resolve(output, 'record-mobile.png'), fullPage: true })
  await page.setViewportSize({ width: 1440, height: 1000 })

  await page.getByRole('button', { name: 'استخراج كشف العلامات الإلكتروني', exact: true }).click()
  await page.getByRole('dialog').locator('input[name=number]').fill('SYN-REQ-123')
  await page.getByRole('dialog').locator('input[name=date]').fill('2024-01-02')
  freshName = 'اسم الطالب المحدث عند التصدير — بيانات اصطناعية'
  const uiDownload = page.waitForEvent('download', { timeout: 180000 })
  await page.getByRole('button', { name: 'تصدير PDF', exact: true }).dblclick()
  const updated = structuredClone(fixtures.short)
  updated.student.full_name = freshName
  const captured = await checkDownload('fresh-request', await uiDownload, updated)
  assert.ok(captured[0].text.includes(freshName))
  assert.ok(captured[0].text.includes('SYN-REQ-123'))
  assert.ok(captured[0].text.includes('2024-01-02'))
  assert.equal(requests.filter(request => request.method === 'GET' && request.url.endsWith('/exam-transcript')).length, 1)
  assert.ok(requests.every(request => ['GET', 'OPTIONS'].includes(request.method)), 'Export must not issue any mutation requests')
  assert.equal(await page.getByRole('dialog').count(), 0)

  await page.evaluate(() => window.pdfFixture.show('approvals'))
  await page.waitForTimeout(400)
  await page.screenshot({ path: resolve(output, 'reference-approvals-desktop.png'), fullPage: true })
  assert.deepEqual(errors, [], 'Browser errors')
  await writeFile(resolve(output, 'browser-results.json'), JSON.stringify({ results, requests, errors }, null, 2) + '\n')
  console.log('PASS fresh authorized GET, duplicate-click guard, optional dates, default template, desktop/mobile dialogs')
} finally {
  await browser.close()
}
