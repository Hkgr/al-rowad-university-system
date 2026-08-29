import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import {
  canBulkEnrollMinistryStudents,
  canEnrollMinistryStudent,
  enrollmentInputComplete,
  studentEnrollmentStateLabel,
} from '../src/features/student-affairs/lib/ministryPlacement.js'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const page = fs.readFileSync(path.join(root, 'src/features/student-affairs/pages/MinistryPlacementsPage.jsx'), 'utf8')
const panel = fs.readFileSync(path.join(root, 'src/features/student-affairs/components/MinistryStudentEnrollmentPanel.jsx'), 'utf8')

assert.match(page, /setBatchView\('student_enrollment'\)/, 'MINISTRY-UI-P4-01: fourth tab must exist')
assert.match(page, /<MinistryStudentEnrollmentPanel/, 'MINISTRY-UI-P4-01: fourth tab must render its panel')

const ready = { placement_record_id: 1, enrollment_state: 'ready' }
assert.equal(canEnrollMinistryStudent(false, ready), false, 'MINISTRY-UI-P4-02: view-only must have no mutation capability')
assert.equal(canEnrollMinistryStudent(true, ready), true, 'MINISTRY-UI-P4-03: manager can prepare a READY row')
assert.match(panel, /ministry-placement-academic-levels/, 'MINISTRY-UI-P4-04: levels must use the Ministry-authorized source')
assert.match(panel, /role="dialog"/, 'MINISTRY-UI-P4-05: an explicit confirmation dialog is required')
assert.match(panel, /لن يتم إنشاء حساب مستخدم أو كلمة مرور، ولن يتم تسجيل الطالب في أي مقرر/, 'MINISTRY-UI-P4-06: individual confirmation must state scope boundaries')
assert.match(panel, /لن يتم إنشاء حسابات مستخدمين أو كلمات مرور أو تسجيل مقررات/, 'MINISTRY-UI-P4-06: bulk confirmation must state scope boundaries')
assert.match(panel, /record\.student\.student_number/, 'MINISTRY-UI-P4-07: enrolled Student must render read-only')
assert.equal(canEnrollMinistryStudent(true, { enrollment_state: 'rejected' }), false, 'MINISTRY-UI-P4-08: rejected applications cannot create Students')

const complete = { student_number: 'R26001', current_academic_level_id: 1, enrollment_date: '2026-09-01' }
assert.equal(enrollmentInputComplete(complete), true)
assert.equal(enrollmentInputComplete({ ...complete, student_number: '' }), false)
assert.equal(enrollmentInputComplete({ ...complete, current_academic_level_id: '' }), false)
assert.equal(enrollmentInputComplete({ ...complete, enrollment_date: '' }), false)
const summary = { eligible_count: 2, eligible_snapshot: 'a'.repeat(64), records: [ready, { placement_record_id: 2, enrollment_state: 'ready' }] }
assert.equal(canBulkEnrollMinistryStudents(true, summary, { 1: complete, 2: complete }), true, 'MINISTRY-UI-P4-09: complete values enable bulk')
assert.equal(canBulkEnrollMinistryStudents(true, summary, { 1: complete, 2: {} }), false, 'MINISTRY-UI-P4-09: every READY row must be complete')

for (const required of ['placement_record_id: record.placement_record_id', 'student_number:', 'current_academic_level_id:', 'enrollment_date:', 'expected_eligible_count:', 'expected_snapshot:', 'items: selection.items']) {
  assert.ok(panel.includes(required), `MINISTRY-UI-P4-10: missing exact bulk mapping ${required}`)
}
assert.match(panel, /err\.errorCode === 'ministry_placement_enrollment_batch_stale'/, 'MINISTRY-UI-P4-11: stale response must be recognized')
assert.match(panel, /setConfirmation\(null\)[\s\S]*await Promise\.all\(\[load\(\), onChanged\?\.\(\)\]\)/, 'MINISTRY-UI-P4-11: stale response must clear confirmation and refresh without retry')
assert.match(panel, /createLatestRequestGuard/, 'MINISTRY-UI-P4-12: response guard must be present')
assert.match(panel, /currentBatchId\.current !== selection\.batch_id/, 'MINISTRY-UI-P4-12: batch-bound mutations must reject stale completion')
const confirmationStart = panel.indexOf('async function confirmEnrollment()')
const confirmationEnd = panel.indexOf('if (loading && !summary)', confirmationStart)
const confirmationMethod = panel.slice(confirmationStart, confirmationEnd)
const responseBatchGuard = confirmationMethod.indexOf('if (currentBatchId.current !== selection.batch_id) return')
const successWrite = confirmationMethod.indexOf('setSuccess(successMessage)')
assert.ok(responseBatchGuard >= 0 && successWrite > responseBatchGuard, 'MINISTRY-UI-P4-12: stale Batch A success must be rejected before Batch B success state is written')

for (const forbiddenButton of ['إنشاء حساب', 'توليد كلمة مرور', 'تسجيل مقررات']) {
  assert.doesNotMatch(panel, new RegExp(`<button[^>]*>[^<]*${forbiddenButton}`), `MINISTRY-UI-P4-13..15: forbidden control ${forbiddenButton}`)
}
for (const [state, label] of [['ready', 'جاهز للاعتماد'], ['enrolled', 'تم إنشاء الطالب'], ['not_ready', 'غير جاهز'], ['rejected', 'مرفوض'], ['inconsistent', 'يحتاج مراجعة']]) {
  assert.equal(studentEnrollmentStateLabel(state), label)
}

console.log('Ministry Placement Phase 4 frontend tests passed.')
