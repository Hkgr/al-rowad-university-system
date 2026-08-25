import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const {
  canBulkMatchProgramGroup,
  canMutateProgramMatch,
  programMatchStateLabel,
  programOptionLabel,
  programSuggestionStatusLabel,
} = await import('../src/features/student-affairs/lib/ministryPlacement.js')
const {
  bindSelectionToBatch,
  createLatestRequestGuard,
  selectionForBatch,
} = await import('../src/features/student-affairs/lib/latestRequestGuard.js')

const recordsGuard = createLatestRequestGuard()
const batchA = recordsGuard.begin({ batchId: 10, page: 1, search: '' })
const batchB = recordsGuard.begin({ batchId: 20, page: 1, search: '' })
assert.equal(recordsGuard.isCurrent(batchA, { batchId: 20, page: 1, search: '' }), false, 'A late Batch A response must not overwrite Batch B')
assert.equal(recordsGuard.isCurrent(batchB, { batchId: 20, page: 1, search: '' }), true)
let committedBatch = null
if (recordsGuard.isCurrent(batchB, { batchId: 20, page: 1, search: '' })) committedBatch = 'B'
if (recordsGuard.isCurrent(batchA, { batchId: 20, page: 1, search: '' })) committedBatch = 'A'
assert.equal(committedBatch, 'B', 'Out-of-order completion must leave the current Batch B response committed')
recordsGuard.invalidate()
assert.equal(recordsGuard.isCurrent(batchB, { batchId: 20, page: 1, search: '' }), false, 'Changing batch must invalidate a pending records response')

const summaryGuard = createLatestRequestGuard()
const summaryA = summaryGuard.begin({ batchId: 10 })
summaryGuard.invalidate()
assert.equal(summaryGuard.isCurrent(summaryA, { batchId: 10 }), false, 'Changing batch must invalidate a pending summary response')

const group = { preference_key: 'old-preference', bulk_eligible_unmatched_count: 3 }
const batchBoundGroup = bindSelectionToBatch(10, group)
assert.equal(selectionForBatch(batchBoundGroup, 10), group)
assert.equal(selectionForBatch(batchBoundGroup, 20), null, 'An old preference key must never be combined with a new batch ID')

assert.equal(programMatchStateLabel('unmatched'), 'غير مطابق')
assert.equal(programMatchStateLabel('matched'), 'تمت المطابقة')
assert.equal(programMatchStateLabel('stale_match'), 'يحتاج إلى مراجعة')
assert.equal(programMatchStateLabel('locked'), 'مقفل')
assert.equal(programSuggestionStatusLabel('ambiguous'), 'اقتراحات متعددة — يلزم الاختيار')
assert.equal(programSuggestionStatusLabel('missing_preference'), 'لا توجد رغبة — يلزم مراجعة فردية')
assert.equal(programOptionLabel({ program_code: 'BUS', program_name: 'إدارة الأعمال', college_name: 'كلية الإدارة' }), 'BUS — إدارة الأعمال — كلية الإدارة')
assert.equal(canMutateProgramMatch(false, { program_match_state: 'unmatched' }), false, 'View-only operators must not receive mutation controls')
assert.equal(canMutateProgramMatch(true, { program_match_state: 'locked' }), false, 'Locked records must stay read only')
assert.equal(canMutateProgramMatch(true, { program_match_state: 'stale_match' }), true, 'Mutable stale matches must support individual correction')
assert.equal(canBulkMatchProgramGroup(true, { bulk_matchable: false, bulk_eligible_unmatched_count: 3 }), false, 'Missing preferences must remain individual-review only')
assert.equal(canBulkMatchProgramGroup(true, { bulk_matchable: true, bulk_eligible_unmatched_count: 2 }), true, 'Real shared preferences must remain bulk matchable')
assert.equal(canBulkMatchProgramGroup(false, { bulk_matchable: true, bulk_eligible_unmatched_count: 2 }), false, 'View-only operators must not receive bulk controls')

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
assert.match(panel, /canBulkMatchProgramGroup\(canManage, group\)/, 'Bulk action visibility must use the tested capability')
assert.match(panel, /group\.bulk_matchable === false \? 'لا توجد رغبة — يلزم مراجعة فردية'/)
assert.match(panel, /expected_eligible_count: selected\.bulk_eligible_unmatched_count/)
assert.match(panel, /setSelectedGroup\(null\)/, 'Changing batch must clear the selected group')
assert.match(panel, /selectionForBatch\(selectedGroup, currentBatchId\.current\)/, 'Group mutations must verify batch identity')
assert.match(panel, /\/ministry-placements\/\$\{selectedBatchId\}\/program-matching\/apply-group/, 'Group mutation must use the batch captured with the selection')
assert.match(panel, /لن تتغير المطابقات الفردية أو السجلات المقفلة أو التي تحتاج مراجعة/)
assert.match(panel, /ministry_placement_group_stale/)
assert.match(panel, /await Promise\.all\(\[load\(\), onChanged\?\.\(\)\]\)/, 'Stale conflicts must force authoritative refresh')
assert.doesNotMatch(panel, /academic_program_id:\s*group\.suggestions\[0\]/, 'A suggestion must never be auto-applied')
assert.match(page, /recordsRequestGuard\.current\.invalidate\(\)[\s\S]*setRecords\(\[\]\)[\s\S]*setRecordMeta\(\{\}\)[\s\S]*setRecordMatch\(null\)[\s\S]*setRecordUnmatch\(null\)/, 'Changing batch must clear record state and dialogs')

for (const forbidden of ['تحويل لمتقدم', 'إنشاء طالب', 'إنشاء حساب', 'Applicant', 'AdmissionApplication']) {
  assert.equal(`${page}\n${panel}\n${picker}`.includes(forbidden), false, `Later-phase control leaked: ${forbidden}`)
}

console.log('Ministry Placement Phase 2 frontend tests passed.')
