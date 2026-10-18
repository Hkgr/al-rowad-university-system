import assert from 'node:assert/strict'
import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { extname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import test from 'node:test'

const srcRoot = fileURLToPath(new URL('../src/', import.meta.url))
const action = readFileSync(new URL('../src/features/academic-record/components/TranscriptPdfExportAction.jsx', import.meta.url), 'utf8')
const pdf = readFileSync(new URL('../src/features/academic-record/lib/transcriptPdf.js', import.meta.url), 'utf8')
const examBoard = readFileSync(new URL('../src/features/exam-board/pages/ExamStudentAcademicRecordPage.jsx', import.meta.url), 'utf8')
const studentAffairs = readFileSync(new URL('../src/features/student-affairs/pages/StudentProfilePage.jsx', import.meta.url), 'utf8')
const student = readFileSync(new URL('../src/features/student-dashboard/pages/StudentTranscript.jsx', import.meta.url), 'utf8')
const dean = readFileSync(new URL('../src/features/dean-dashboard/pages/DeanStudentProfile.jsx', import.meta.url), 'utf8')

function sourceFiles(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
    const path = join(directory, entry.name)
    if (entry.isDirectory()) return sourceFiles(path)
    return ['.js', '.jsx'].includes(extname(entry.name)) ? [path] : []
  })
}

test('all four individual transcript surfaces use the shared export action', () => {
  for (const surface of [examBoard, studentAffairs, student, dean]) {
    assert.match(surface, /academic-record\/components\/TranscriptPdfExportAction/)
    assert.match(surface, /<TranscriptPdfExportAction/)
  }

  assert.match(examBoard, /endpoint=\{endpoint\}/)
  assert.match(studentAffairs, /endpoint=\{`\/v1\/students\/\$\{id\}\/academic-record`\}/)
  assert.match(dean, /endpoint=\{`\/v1\/students\/\$\{id\}\/academic-record`\}/)
  assert.match(student, /endpoint="\/v1\/student\/academic-record"/)
  assert.equal(student.includes('student_id='), false)
})

test('shared action always refetches the full official record and prevents duplicate exports', () => {
  assert.match(action, /if \(exporting\.current\) return/)
  assert.match(action, /const response = await apiRequest\(endpoint\)/)
  assert.match(action, /exportTranscriptPdf\(\{ academicRecord: response\.data \}\)/)
  assert.match(action, /جاري إنشاء الملف\.\.\./)
  assert.match(action, /استخراج كشف العلامات الإلكتروني/)
  assert.match(action, /تعذّر إنشاء كشف العلامات الإلكتروني\. يرجى المحاولة مجدداً\./)
})

test('only the shared page-safe transcript generator remains', () => {
  const generators = sourceFiles(srcRoot).filter(path => /export\s+(?:async\s+)?function\s+exportTranscriptPdf/.test(readFileSync(path, 'utf8')))
  assert.equal(generators.length, 1)
  assert.ok(generators[0].replaceAll('\\', '/').endsWith('/features/academic-record/lib/transcriptPdf.js'))
  assert.equal(existsSync(new URL('../src/features/exam-board/lib/transcriptPdf.js', import.meta.url)), false)
  assert.equal(existsSync(new URL('../src/features/exam-board/lib/academicRecordPresentation.js', import.meta.url)), false)

  for (const forbidden of ['html2canvas', 'new jsPDF', 'pdfContentRef', 'heightLeft', 'position -= pageHeight']) {
    assert.equal(studentAffairs.includes(forbidden), false, `${forbidden} must not remain in StudentProfilePage`)
  }
  assert.equal(student.includes('window.print'), false)
})

test('official aggregate fields and unofficial-document safeguards remain canonical', () => {
  for (const field of ['academicRecord?.student', 'academicRecord?.transcript', 'academicRecord?.requirements', 'academicRecord?.generation']) {
    assert.ok(pdf.includes(field))
  }
  assert.match(pdf, /تنبيه: هذا الكشف مُولّد إلكترونيًا من نظام جامعة الرواد، ولا يُعد وثيقة رسمية أو مصدقة، ولا يكتسب الحجية إلا بعد استكمال التواقيع والأختام الرسمية المعتمدة\./)
  for (const label of ['رئيس الهيئة الامتحانية', 'رئيس قسم الامتحانات', 'الختم الرسمي', 'صفحة ${pageNumber} من ${totalPages}']) {
    assert.ok(pdf.includes(label))
  }
  for (const forbidden of ['QR', 'verification_code', 'document_number']) {
    assert.equal(pdf.includes(forbidden), false)
  }
})

test('list-report PDF exports remain separate and unchanged in scope', () => {
  for (const relative of [
    'features/student-affairs/pages/StudentsPage.jsx',
    'features/student-affairs/pages/GraduatesPage.jsx',
    'features/student-affairs/pages/ArchivedStudentsPage.jsx',
    'features/dean-dashboard/pages/DeanStudents.jsx',
  ]) {
    assert.match(readFileSync(new URL(`../src/${relative}`, import.meta.url), 'utf8'), /exportRowsToPdf/)
  }
})
