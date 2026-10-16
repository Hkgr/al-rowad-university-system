// Executes the real PDF HTML/pagination builder with a deterministic measurement adapter.
// This verifies semantic content/escaping only, NOT geometry, canvas, font rendering or a PDF download.
import assert from 'node:assert/strict'
import { after, test } from 'node:test'
import { createServer } from 'vite'
const server = await createServer({ mode: 'test', server: { middlewareMode: true, hmr: false }, appType: 'custom', plugins: [{
  name: 'semantic-pdf-engine', enforce: 'pre',
  resolveId(source) { if (source.endsWith('/utils/pdfExport')) return '\0semantic-pdf-engine' },
  load(id) {
    if (id !== '\0semantic-pdf-engine') return
    return `export const PDF_PAGE_CONFIGS = { portrait: {} }; export const escapeHtml = value => String(value).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;'); export const exportPagedHtmlToPdf = options => ({ pages: options.preparePages({ measure: html => html.length / 80 + 10, contentHeightPx: 100000 }), options });`
  },
}] })
after(async () => server.close())
const { exportTranscriptPdf } = await server.ssrLoadModule('/src/features/academic-record/lib/transcriptPdf.js')
test('real shared builder preserves symbols, zero, missing marks, withdrawal and semantic hour labels without internal evidence', async () => {
  const courses = [
    { registration_id: 1, course_name: 'نشطة', course_code: 'SYN1', final_mark: null, letter_grade: 'I', result_status: { status_code: 'incomplete' }, result_lifecycle: { state: 'incomplete_active', case_id: 1 } },
    { registration_id: 2, course_name: 'انتهاء F', course_code: 'SYN2', final_mark: null, letter_grade: 'F', result_status: { status_code: 'failed' }, result_lifecycle: { state: 'incomplete_expired', case_id: 2 } },
    { registration_id: 3, course_name: 'انتهاء Z', course_code: 'SYN3', final_mark: 99, letter_grade: 'Z', result_status: { status_code: 'deprived' }, result_lifecycle: { state: 'incomplete_expired', case_id: 3 } },
    { registration_id: 4, course_name: 'صفر حقيقي', course_code: 'SYN4', final_mark: 0, letter_grade: 'F', result_status: { status_code: 'failed' } },
    { registration_id: 5, course_name: '<انسحاب>', course_code: 'SYN5', final_mark: null, result_status: { status_code: 'withdrawn' } },
  ].map(course => ({ ...course, reason: 'INTERNAL_REASON', requirements: 'INTERNAL_REQUIREMENTS', actor_user_id: 'INTERNAL_ACTOR', credit_hours: 3 }))
  const result = await exportTranscriptPdf({ academicRecord: { student: { full_name: 'طالب اصطناعي', student_number: 'SYN' }, transcript: { summary: { cgpa: 0, gpa_denominator_credit_hours: 9 }, terms: [{ academic_year: { year_name: '2026' }, semester: { semester_name: 'الأول' }, withdrawal_status: 'full_withdrawal', counts_towards_maximum_study_duration: true, courses }] } } })
  const html = result.pages.join('')
  for (const text of ['I نشطة موثقة', 'نتيجة انتهاء المهلة', 'انسحاب W', 'فصل منسحب بالكامل', 'ساعات مقام المعدل', 'ساعات السجل التاريخي', 'الختم الرسمي', 'لا يُعد وثيقة رسمية']) assert.ok(html.includes(text), text)
  assert.ok(html.includes('&lt;انسحاب&gt;')); assert.ok(!html.includes('<انسحاب>'))
  assert.match(html, />0<\/td>/); assert.match(html, />—<\/td>/)
  assert.ok(!/INTERNAL_REASON|INTERNAL_REQUIREMENTS|INTERNAL_ACTOR/.test(html))
})
