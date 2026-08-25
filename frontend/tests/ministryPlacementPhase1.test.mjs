import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const {
  canImportPreview,
  previewStatusLabel,
  rowErrorLabels,
  workbookIssueLabel,
} = await import('../src/features/student-affairs/lib/ministryPlacement.js')

assert.equal(canImportPreview({ invalid_rows: 0, duplicate_rows: 0, structural_errors: [] }), true)
assert.equal(canImportPreview({ invalid_rows: 1, duplicate_rows: 0, structural_errors: [] }), false)
assert.equal(canImportPreview({ invalid_rows: 0, duplicate_rows: 1, structural_errors: [] }), false)
assert.equal(previewStatusLabel('duplicate'), 'مكرر')
// MINISTRY-UI-P1-08: row validation reasons are translated and rendered.
assert.deepEqual(
  rowErrorLabels({ email: ['invalid_email'], date_of_birth: ['ambiguous_date'], id: ['max_length_50'] }),
  ['البريد الإلكتروني غير صالح', 'صيغة التاريخ ملتبسة', 'القيمة تتجاوز الطول المسموح'],
)
assert.equal(workbookIssueLabel('unexpected_data_after_column_x'), 'توجد بيانات غير متوقعة بعد العمود X')
// MINISTRY-UI-P1-09: structural machine codes have Arabic presentation text.
assert.equal(workbookIssueLabel('invalid_header_anchor_11'), 'عنوان حرج في موضع غير صحيح')

globalThis.localStorage = { getItem: () => 'test-token' }
const calls = []
globalThis.fetch = async (url, options) => {
  calls.push({ url, options })
  return { ok: true, json: async () => ({ success: true, data: {} }) }
}
const { apiRequest } = await import('../src/services/apiClient.js')
await apiRequest('/v1/json-test', { method: 'POST', body: JSON.stringify({ ok: true }) })
assert.equal(calls[0].options.headers['Content-Type'], 'application/json', 'JSON requests must retain their content type')

const formData = new FormData()
formData.append('file', new Blob(['fixture']), 'fixture.xlsx')
await apiRequest('/v1/form-test', { method: 'POST', body: formData })
assert.equal(Object.hasOwn(calls[1].options.headers, 'Content-Type'), false, 'FormData must let the browser add its multipart boundary')

const page = fs.readFileSync(path.join(root, 'src/features/student-affairs/pages/MinistryPlacementsPage.jsx'), 'utf8')
assert.ok(page.indexOf("'/v1/ministry-placements/preview'") < page.indexOf("'/v1/ministry-placements/import'"), 'Preview must precede import in the workflow')
assert.match(page, /disabled=\{!importReady/)
assert.match(page, /'الأخطاء'/)
assert.match(page, /rowErrorLabels\(row\.errors\)/)
assert.match(page, /workbookIssueLabel\(item\)/)
for (const forbidden of ['ربط برنامج', 'تحويل لمتقدم', 'إنشاء طالب']) assert.equal(page.includes(forbidden), false)

const auth = fs.readFileSync(path.join(root, 'src/features/auth/auth.js'), 'utf8')
assert.match(auth, /actualUniversityScope/)
assert.match(auth, /scope\?\.type === 'university'/)

console.log('Ministry Placement Phase 1 frontend tests passed.')
