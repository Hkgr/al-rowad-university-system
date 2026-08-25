import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const {
  canMutateProgramMatch,
  programMatchStateLabel,
  programOptionLabel,
  programSuggestionStatusLabel,
} = await import('../src/features/student-affairs/lib/ministryPlacement.js')

assert.equal(programMatchStateLabel('unmatched'), 'غير مطابق')
assert.equal(programMatchStateLabel('matched'), 'تمت المطابقة')
assert.equal(programMatchStateLabel('stale_match'), 'يحتاج إلى مراجعة')
assert.equal(programMatchStateLabel('locked'), 'مقفل')
assert.equal(programSuggestionStatusLabel('ambiguous'), 'اقتراحات متعددة — يلزم الاختيار')
assert.equal(programOptionLabel({ program_code: 'BUS', program_name: 'إدارة الأعمال', college_name: 'كلية الإدارة' }), 'BUS — إدارة الأعمال — كلية الإدارة')
assert.equal(canMutateProgramMatch(false, { program_match_state: 'unmatched' }), false, 'View-only operators must not receive mutation controls')
assert.equal(canMutateProgramMatch(true, { program_match_state: 'locked' }), false, 'Locked records must stay read only')
assert.equal(canMutateProgramMatch(true, { program_match_state: 'stale_match' }), true, 'Mutable stale matches must support individual correction')

const page = fs.readFileSync(path.join(root, 'src/features/student-affairs/pages/MinistryPlacementsPage.jsx'), 'utf8')
const panel = fs.readFileSync(path.join(root, 'src/features/student-affairs/components/MinistryProgramMatchingPanel.jsx'), 'utf8')
const picker = fs.readFileSync(path.join(root, 'src/features/student-affairs/components/MinistryProgramPickerDialog.jsx'), 'utf8')

assert.match(page, /مطابقة البرامج/, 'Authorized Ministry page must expose its matching section')
assert.match(page, /canMutateProgramMatch\(canManage, row\)/, 'Record controls must require assigned manage authority')
assert.match(page, /تعديل المطابقة/)
assert.match(page, /إزالة المطابقة/)
assert.match(page, /تم الانتقال إلى مرحلة لاحقة — المطابقة للقراءة فقط/)
assert.match(page, /البرنامج المطابق غير نشط أو أن حالة السجل تحتاج مراجعة/)
assert.match(page, /سيتم استبدال المطابقة الحالية/, 'Individual rematch must require explicit override confirmation')

assert.match(picker, /\/v1\/ministry-placement-programs/, 'Program search must use the Ministry-authorized endpoint')
assert.match(picker, /لا يتم اعتماد أي اقتراح تلقائياً/, 'Suggestions must remain read only until selected')
assert.match(picker, /تأكيد المطابقة/, 'Matching needs an explicit confirmation action')
assert.match(picker, /page: String\(page\), per_page: '15'/, 'Program search must remain paginated')

assert.match(panel, /bulk_eligible_unmatched_count/, 'Bulk confirmation must use canonical unmatched count')
assert.match(panel, /expected_eligible_count: selectedGroup\.bulk_eligible_unmatched_count/)
assert.match(panel, /لن تتغير المطابقات الفردية أو السجلات المقفلة أو التي تحتاج مراجعة/)
assert.match(panel, /ministry_placement_group_stale/)
assert.match(panel, /await Promise\.all\(\[load\(\), onChanged\?\.\(\)\]\)/, 'Stale conflicts must force authoritative refresh')
assert.doesNotMatch(panel, /academic_program_id:\s*group\.suggestions\[0\]/, 'A suggestion must never be auto-applied')

for (const forbidden of ['تحويل لمتقدم', 'إنشاء طالب', 'إنشاء حساب', 'Applicant', 'AdmissionApplication']) {
  assert.equal(`${page}\n${panel}\n${picker}`.includes(forbidden), false, `Later-phase control leaked: ${forbidden}`)
}

console.log('Ministry Placement Phase 2 frontend tests passed.')
