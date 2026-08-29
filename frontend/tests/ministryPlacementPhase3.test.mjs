import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const {
  applicantConversionBlockerLabel,
  applicantConversionStateLabel,
  canBulkConvertMinistryApplicants,
  canConvertMinistryRecord,
} = await import('../src/features/student-affairs/lib/ministryPlacement.js')

assert.equal(applicantConversionStateLabel('convertible'), 'جاهز للتحويل')
assert.equal(applicantConversionStateLabel('converted'), 'تم إنشاء المتقدم')
assert.equal(applicantConversionStateLabel('later_stage'), 'مرحلة لاحقة')
assert.equal(applicantConversionStateLabel('unknown'), 'حالة غير معروفة')
assert.equal(applicantConversionBlockerLabel('decision_status_unsupported'), 'حالة قرار طلب القبول غير مدعومة')

assert.equal(canConvertMinistryRecord(false, { conversion_state: 'convertible' }), false, 'View-only operators must not see conversion controls')
assert.equal(canConvertMinistryRecord(true, { conversion_state: 'convertible' }), true, 'Manage operators may convert only backend-classified convertible rows')
assert.equal(canConvertMinistryRecord(true, { conversion_state: 'inconsistent' }), false)
assert.equal(canBulkConvertMinistryApplicants(true, { eligible_count: 2, eligible_snapshot: 'a'.repeat(64) }), true)
assert.equal(canBulkConvertMinistryApplicants(false, { eligible_count: 2, eligible_snapshot: 'a'.repeat(64) }), false)
assert.equal(canBulkConvertMinistryApplicants(true, { eligible_count: 0, eligible_snapshot: 'a'.repeat(64) }), false)
assert.equal(canBulkConvertMinistryApplicants(true, { eligible_count: 2, eligible_snapshot: 'not-a-hash' }), false)

const page = fs.readFileSync(path.join(root, 'src/features/student-affairs/pages/MinistryPlacementsPage.jsx'), 'utf8')
const panel = fs.readFileSync(path.join(root, 'src/features/student-affairs/components/MinistryApplicantConversionPanel.jsx'), 'utf8')

assert.match(page, /setBatchView\('applicant_conversion'\)/, 'The existing portal must expose its third Applicant-conversion tab')
assert.match(page, /تحويل إلى متقدم/)
assert.match(page, /<MinistryApplicantConversionPanel[^>]*canManage=\{canManage\}/, 'View/write capability must flow to the dedicated panel')

assert.match(panel, /canConvertMinistryRecord\(canManage, record\)/, 'Individual controls must depend on backend state plus manage authority')
assert.match(panel, /canBulkConvertMinistryApplicants\(canManage, summary\)/, 'Bulk controls must depend on backend eligibility plus manage authority')
assert.match(panel, /role="dialog"[\s\S]*onConfirm=\{convertRecord\}/, 'Individual conversion must use an explicit confirmation dialog')
assert.match(panel, /onConfirm=\{convertAll\}/, 'Bulk conversion must use an explicit confirmation dialog')
assert.match(panel, /لن يتم إنشاء طالب أو حساب مستخدم/, 'Individual confirmation must state the Phase 3 boundary')
assert.match(panel, /لن يتم إنشاء طلاب أو حسابات مستخدمين/, 'Bulk confirmation must state the Phase 3 boundary')
assert.match(panel, /expected_eligible_count: selection\.eligible_count/)
assert.match(panel, /expected_snapshot: selection\.eligible_snapshot/)
assert.match(panel, /ministry_placement_conversion_batch_stale[\s\S]*Promise\.all\(\[load\(\), onChanged\?\.\(\)\]\)/, 'A stale snapshot must refresh without retrying the mutation')
assert.match(panel, /setBulkSelection\(null\)/, 'A stale or completed operation must require a new confirmation')
assert.match(panel, /createLatestRequestGuard/, 'Changing batches must retain stale-response protection')
assert.match(panel, /record\.applicant\.applicant_number/)
assert.match(panel, /record\.admission_application\?\.decision_status/)
assert.match(panel, /دفعة \$\{item\.batch_id\}\/سجل \$\{item\.placement_record_id\}/, 'Identity conflicts may expose only safe operational references')
assert.doesNotMatch(panel, /identity_conflicts[^\n]*national_civil_id/, 'Conflict UI must not expose another record national identity')

assert.doesNotMatch(panel, /<button[^>]*>\s*(قبول الطلب|رفض الطلب|إنشاء طالب|إنشاء حساب مستخدم|إنشاء كلمة مرور)/, 'Phase 3 must not expose a later-stage action control')

console.log('Ministry Placement Phase 3 frontend tests passed.')
