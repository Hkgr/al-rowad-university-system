import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const pdfEngine = readFileSync(new URL('../src/utils/pdfExport.js', import.meta.url), 'utf8')
const transcriptPdf = readFileSync(new URL('../src/features/exam-board/lib/transcriptPdf.js', import.meta.url), 'utf8')
const gradeSheet = readFileSync(new URL('../src/features/exam-board/pages/GradeSheetPage.jsx', import.meta.url), 'utf8')

function ordered(source, markers) {
  let cursor = -1
  for (const marker of markers) {
    const next = source.indexOf(marker, cursor + 1)
    assert.notEqual(next, -1, `missing pipeline marker: ${marker}`)
    assert.ok(next > cursor, `${marker} is out of order`)
    cursor = next
  }
}

test('assets and fonts precede layout measurement and pagination is explicitly two-pass', () => {
  ordered(pdfEngine, [
    'loadedImages = await preloadImages(requiredImages)',
    'await document.fonts.ready',
    'const measuring = createMeasurementWorkspace(geometry)',
    'const pageBodies = await preparePages',
    'const totalPages = pageBodies.length',
    'const pages = pageBodies.map',
    'assertPageFits(page)',
    'const canvas = await html2canvas',
  ])
  assert.match(pdfEngine, /Pass 1: paginate the complete semantic document/)
  assert.match(pdfEngine, /Pass 2: build every final page/)
})

test('measurement remains layout-active and page geometry controls portrait and landscape output', () => {
  assert.equal(pdfEngine.includes('display: none'), false)
  assert.equal(pdfEngine.includes("display: 'none'"), false)
  assert.match(pdfEngine, /left: '-10000px'/)
  assert.match(pdfEngine, /width: `\$\{geometry\.widthMm\}mm`/)
  assert.match(pdfEngine, /height: `\$\{geometry\.heightMm\}mm`/)
  assert.match(pdfEngine, /boxSizing: 'border-box'/)
  assert.match(pdfEngine, /overflow: 'hidden'/)
  assert.match(pdfEngine, /portrait: Object\.freeze\(\{ orientation: 'portrait', widthMm: 210, heightMm: 297/)
  assert.match(pdfEngine, /landscape: Object\.freeze\(\{ orientation: 'landscape', widthMm: 297, heightMm: 210/)
  assert.match(pdfEngine, /pageConfig: PDF_PAGE_CONFIGS\.landscape/)
  assert.match(transcriptPdf, /pageConfig: PDF_PAGE_CONFIGS\.portrait/)
})

test('every final page and its content region are checked before capture and save', () => {
  assert.match(pdfEngine, /assertPageFits\(page\)/)
  assert.match(pdfEngine, /assertPageFits\(page\.querySelector\('\[data-pdf-content\]'\)\)/)
  assert.ok(pdfEngine.indexOf('assertPageFits(page)') < pdfEngine.indexOf('const pdf = new jsPDF'))
  assert.ok(pdfEngine.indexOf('assertPageFits(page)') < pdfEngine.indexOf('const canvas = await html2canvas'))
  assert.ok(pdfEngine.indexOf('const canvas = await html2canvas') < pdfEngine.indexOf('pdf.save(filename)'))
})

test('generic export preserves its public signature and removes giant-canvas slicing', () => {
  assert.match(pdfEngine, /export async function exportRowsToPdf\(\{ title, subtitle, columns, rows, filename \}\)/)
  for (const forbidden of ['heightLeft', 'position -= pageHeight', 'imgHeight = (canvas.height', 'pdf.addImage(imgData']) {
    assert.equal(pdfEngine.includes(forbidden), false, `${forbidden} must not return`)
  }
  assert.match(pdfEngine, /paginateMeasuredSections/)
  assert.match(pdfEngine, /— متابعة/)
  assert.match(pdfEngine, /صفحة \$\{pageNumber\} من \$\{totalPages\}/)
})

test('transcript uses only official payload data and the authoritative classification formatter', () => {
  assert.match(transcriptPdf, /classificationPlainText\(course\?\.requirement_classification\)/)
  assert.match(transcriptPdf, /transcript\?\.student/)
  assert.match(transcriptPdf, /transcript\?\.summary/)
  assert.match(transcriptPdf, /transcript\?\.terms/)
  assert.match(transcriptPdf, /summary\.cgpa/)
  assert.match(transcriptPdf, /summary\.total_attempted_credit_hours/)
  assert.match(transcriptPdf, /Asia\/Damascus/)
  assert.match(transcriptPdf, /requiredImages: \['\/logo\.png'\]/)
  assert.match(transcriptPdf, /direction: 'rtl'|dir="rtl"/)
  assert.equal(transcriptPdf.includes('reduce((sum'), false)
})

test('unofficial disclaimer, blank signatures, footer numbering, and safe filename are exact', () => {
  const exactDisclaimer = 'تنبيه: هذا الكشف مُولّد إلكترونيًا من نظام جامعة الرواد، ولا يُعد وثيقة رسمية أو مصدقة، ولا يكتسب الحجية إلا بعد استكمال التواقيع والأختام الرسمية المعتمدة.'
  assert.ok(transcriptPdf.includes(exactDisclaimer))
  for (const label of ['رئيس الهيئة الامتحانية', 'رئيس قسم الامتحانات', 'الختم الرسمي', 'نسخة إلكترونية غير مصدقة']) {
    assert.ok(transcriptPdf.includes(label))
  }
  assert.match(transcriptPdf, /صفحة \$\{pageNumber\} من \$\{totalPages\}/)
  assert.match(transcriptPdf, /grade-transcript-\$\{sanitized \|\| 'student'\}\.pdf/)
  assert.match(transcriptPdf, /replace\(\/\[\^A-Za-z0-9\._-\]\+\/g, '-'\)/)
  for (const forbidden of ['QR', 'verification code', 'document_number', 'documentNumber']) {
    assert.equal(transcriptPdf.includes(forbidden), false)
  }
})

test('grade sheet uses apiRequest and keeps PDF failure separate from transcript state', () => {
  assert.match(gradeSheet, /import \{ apiRequest \} from '\.\.\/\.\.\/\.\.\/services\/apiClient'/)
  assert.match(gradeSheet, /apiRequest\(`\/v1\/students\/\$\{student\.student_id\}\/transcript`\)/)
  assert.match(gradeSheet, /apiRequest\(`\/v1\/students\/\$\{student\.student_id\}\/cgpa`\)/)
  assert.equal(gradeSheet.includes('rust.alrowaduni.edu.sy'), false)
  assert.equal(gradeSheet.includes('localStorage'), false)
  assert.equal(gradeSheet.includes('fetch('), false)
  assert.match(gradeSheet, /const \[pdfError, setPdfError\]/)
  assert.match(gradeSheet, /if \(!transcript \|\| pdfExporting\.current\) return/)
  assert.match(gradeSheet, /جاري إنشاء الملف\.\.\./)
  assert.match(gradeSheet, /استخراج كشف PDF/)
  const exportHandler = gradeSheet.slice(gradeSheet.indexOf('async function handlePdfExport'), gradeSheet.indexOf('const terms'))
  assert.equal(exportHandler.includes('setTranscript('), false)
})
