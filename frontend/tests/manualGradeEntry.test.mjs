import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { ACCESS, canAccess } from '../src/features/auth/auth.js'
import { changedComponents, savePayload, markValue, markText, manualPath, searchPath, manualError, requestSequence, stateLabel, MANUAL_GRADE_NOTICE } from '../src/features/exam-board/lib/manualGradeEntry.js'

const row = { revision: 'a'.repeat(64), components: [
  { grade_component_id: 1, component_type: 'theoretical', mark: null, max_mark: 35 },
  { grade_component_id: 2, component_type: 'theoretical', mark: 0, max_mark: 25 },
  { grade_component_id: 3, component_type: 'practical', mark: 10, max_mark: 40 },
] }
const actor = { roles: ['exam_officer'], permissions: ['exams.manage', 'grades.manage', 'students.view'] }
const read = path => readFileSync(new URL(path, import.meta.url), 'utf8')
test('manual access is actual officer plus assigned permissions and existing student read', () => {
  assert.equal(canAccess(ACCESS.manualGradeEntry, actor), true)
  for (const roles of [[], ['super_admin'], ['doctor_instructor']]) assert.equal(canAccess(ACCESS.manualGradeEntry, { ...actor, roles }), false)
  for (const permission of actor.permissions) assert.equal(canAccess(ACCESS.manualGradeEntry, { ...actor, permissions: actor.permissions.filter(p => p !== permission) }), false)
  assert.equal(canAccess(ACCESS.manualGradeEntry, { roles: ['super_admin', 'exam_officer'], permissions: [] }), false)
})
test('zero, null, precision and numeric validation do not invent a split', () => {
  assert.equal(markValue('0'), 0); assert.equal(markValue(''), null); assert.equal(markText(0), '0'); assert.equal(markText(null), '—')
  for (const bad of ['NaN', '-1', 'Infinity', '1.005', '1e3', 'abc']) assert.throws(() => markValue(bad))
  assert.deepEqual(changedComponents(row.components, { 1: '0', 2: '0' }).map(c => c.grade_component_id), [1])
})
test('payload only changes explicit components and requires acknowledgement', () => {
  assert.throws(() => savePayload(row, { 1: '0' }, false))
  assert.deepEqual(savePayload(row, { 1: '0' }, true).components, [{ grade_component_id: 1, mark: 0 }])
  assert.throws(() => savePayload(row, { 1: '36' }, true))
  assert.throws(() => savePayload(row, { 2: '0' }, true))
})
test('correction and clearing a prior zero need confirmation and reason', () => {
  assert.throws(() => savePayload(row, { 2: '' }, true))
  assert.throws(() => savePayload(row, { 2: '5' }, true, true, ' '))
  const payload = savePayload(row, { 2: '' }, true, true, 'تصحيح')
  assert.equal(payload.correction_confirmed, true)
  assert.deepEqual(payload.components, [{ grade_component_id: 2, mark: null }])
})
test('search pagination and mutation paths remain student/attempt specific', () => {
  const url = new URL(searchPath(' اسم ', 2), 'https://test.invalid')
  assert.equal(url.searchParams.get('page'), '2'); assert.equal(url.searchParams.get('q'), 'اسم')
  assert.equal(manualPath(9, 11), '/v1/exams/manual-grade-entry/students/9/registrations/11')
})
test('latest sequence wins including after cancellation', () => {
  const requests = requestSequence(); const first = requests.next(); const second = requests.next()
  assert.equal(requests.valid(first), false); assert.equal(requests.valid(second), true)
  requests.invalidate(); assert.equal(requests.valid(second), false)
})
test('workflow errors and unknown states have controlled Arabic presentation', () => {
  for (const status of [403, 409, 422, 500]) assert.ok(manualError({ status }).length)
  assert.match(manualError({}), /تأكيد نتيجة العملية/)
  assert.equal(stateLabel('invented'), 'حالة غير متاحة')
})
test('route/nav/card share access without inheriting the exam parent gate', () => {
  const app = read('../src/app/App.jsx'); const nav = read('../src/features/exam-board/nav.js'); const home = read('../src/features/exam-board/pages/ExamBoardHome.jsx')
  assert.match(app, /ProtectedRoute \{\.\.\.ACCESS\.manualGradeEntry\}/)
  for (const source of [app, nav, home]) { assert.ok(source.includes('/exam-board/manual-grade-entry')); assert.ok(source.includes('ACCESS.manualGradeEntry')) }
})
test('source contract: canonical components, explicit confirmations, uncertain write recovery and no legacy formula', () => {
  const page = [
  '../src/features/exam-board/pages/ManualGradeEntryPage.jsx',
  '../src/features/exam-board/pages/StudentManualGradePage.jsx',
  '../src/features/exam-board/components/RegistrationGridRow.jsx',
].map(read).join('\n')
  for (const text of ['apiRequest', 'row.components', 'correction', 'submission-readiness', 'ready.revision', 'AbortController', 'identityStamp()', 'sequence.current.valid', 'beforeunload', 'dirty.current.size', 'await reload()', 'setUncertain(true)', 'جميع طلاب الطرح']) assert.ok(page.includes(text), text)
  for (const forbidden of ['window.confirm(', 'window.prompt(', 'calcLetter', 'https://rust.', 'localStorage.getItem(\'token\')', '/grades`']) assert.ok(!page.includes(forbidden), forbidden)
  assert.match(page, /onCancel=\{\(\) => setDialog\(null\)\}/)
  assert.match(page, /useState\(false\)/)
  assert.match(MANUAL_GRADE_NOTICE, /حفظ العلامات لا يعني نشرها/)
})
