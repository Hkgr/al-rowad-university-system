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
assert.equal(workbookIssueLabel('additional_empty_sheet_ignored'), 'تم تجاهل ورقة إضافية فارغة')
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
const importUi = page.slice(page.indexOf('async function importBatch'), page.indexOf('async function changeBatchPage'))
for (const forbidden of ['program-match', 'convert-to-applicant', 'Applicant', 'AdmissionApplication', 'Student']) assert.equal(importUi.includes(forbidden), false)

const auth = fs.readFileSync(path.join(root, 'src/features/auth/auth.js'), 'utf8')
assert.match(auth, /actualUniversityScope/)
assert.match(auth, /scope\?\.type === 'university'/)

const { canAccess, landingRoute } = await import('../src/features/auth/auth.js')
const ministryAuthority = { assignedPermissions: ['admissions.view'], actualUniversityScope: true }
const admissionsOnly = { permissions: ['admissions.view'], access_scopes: [{ type: 'university', id: 3 }], roles: [] }
const admissionsWithoutScope = { permissions: ['admissions.view'], access_scopes: [], roles: [] }
const studentsOnly = { permissions: ['students.view'], access_scopes: [{ type: 'university', id: 3 }], roles: [] }
assert.equal(canAccess(ministryAuthority, admissionsOnly), true, 'Admissions view plus actual university scope must allow Ministry access')
assert.equal(landingRoute(admissionsOnly), '/student-affairs/ministry-placements', 'Admissions-only operator needs a valid landing page')
assert.equal(canAccess(ministryAuthority, admissionsWithoutScope), false, 'Admissions view without actual university scope must fail closed')
assert.equal(canAccess(ministryAuthority, studentsOnly), false, 'Students view alone must not grant Ministry access')

const app = fs.readFileSync(path.join(root, 'src/app/App.jsx'), 'utf8')
const studentGroupStart = app.indexOf('{/* ── شؤون الطلاب dashboard ── */}')
const ministryGroupStart = app.indexOf('{/* ── Ministry Placement dashboard:')
const studentGroup = app.slice(studentGroupStart, ministryGroupStart)
const ministryGroup = app.slice(ministryGroupStart, app.indexOf('{/* ── بوابة الطالب dashboard ── */}'))
assert.match(studentGroup, /permissions=\{\['students\.view'\]\}/, 'Existing Student Affairs pages must retain their students.view parent')
assert.doesNotMatch(studentGroup, /path="\/student-affairs\/ministry-placements"/, 'Ministry route must not remain under the students.view parent')
assert.match(ministryGroup, /assignedPermissions=\{\[PERMISSIONS\.admissionsView\]\} actualUniversityScope/, 'Ministry sibling parent must match backend authority')
assert.doesNotMatch(ministryGroup, /students\.view|\/student-affairs\/students|\/student-affairs\/graduates/, 'Ministry sibling must not expose other Student Affairs pages')

console.log('Ministry Placement Phase 1 frontend tests passed.')
