import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { actualChanges, changePayload, courseChanges, courseIdentity, groupActivityLabel, legacyWorkspaceLink, preparation, requirementGroupChanges, upsertPreparedCourse, WORKSPACE_ACCESS } from '../src/features/scientific-programs/workspace.js'
import { CATALOG_ACCESS } from '../src/features/scientific-courses/catalog.js'
import { PROGRAM_ACCESS } from '../src/features/scientific-programs/programs.js'

const snapshot = { program: { academic_program_id: 1, program_name: 'Synthetic' }, plan: { version: { academic_plan_version_id: null } }, values: { total_credit_hours: 120, groups: [{ requirement_scope: 'university', requirement_type: 'mandatory', required_credit_hours: 0 }], courses: [] } }
test('preparation retains missing versus explicit zero and performs no persistence', () => {
  const d = preparation(snapshot)
  assert.equal(d.requirements.groups.length, 6); assert.equal(d.requirements.groups.find(g => g.requirement_scope === 'university' && g.requirement_type === 'mandatory').required_credit_hours, 0)
  assert.equal(d.requirements.groups.find(g => g.requirement_scope === 'college').required_credit_hours, null)
  assert.equal(d.source_version_id, null); assert.equal(snapshot.values.groups.length, 1)
})
test('many new materials are staged for one atomic final payload', () => {
  const before = preparation(snapshot)
  let d = before
  for (const key of ['a', 'b']) d = upsertPreparedCourse(d, { course_id: null, new_course_key: key, requirement_scope: 'department', course_type: 'mandatory', academic_level_id: 1, recommended_semester_id: 2, is_active: true })
  const payload = changePayload('synthetic-id', '17', [d], [{ key: 'a' }, { key: 'b' }, { key: 'unused' }])
  assert.equal(payload.targets[0].courses.length, 2); assert.equal(payload.new_courses.length, 2); assert.equal(before.courses.length, 0)
  assert.deepEqual(actualChanges(before, d), { added: 2, removed: 0, updated: 0, requirements: false })
})
test('selected-program targets never expand scope, shared identity is not copied', () => {
  const a = upsertPreparedCourse(preparation(snapshot), { course_id: 5, requirement_scope: 'university', course_type: 'mandatory', academic_level_id: 1, recommended_semester_id: 1, is_active: true })
  const b = { ...a, program: { academic_program_id: 8 }, courses: [{ ...a.courses[0], academic_level_id: 2, recommended_semester_id: 2 }] }
  const payload = changePayload('id', '1', [a, b], [])
  assert.deepEqual(payload.targets.map(t => t.academic_program_id), [1, 8]); assert.deepEqual(payload.targets.map(t => t.courses[0].course_id), [5, 5])
  assert.equal(payload.targets[0].courses[0].academic_level_id, 1); assert.equal(payload.targets[1].courses[0].academic_level_id, 2)
})
test('upsert and noop comparisons preserve one identity and existing drafts', () => {
  const d = preparation(snapshot), c = { course_id: 2, academic_level_id: 1 }
  const next = upsertPreparedCourse(upsertPreparedCourse(d, c), { ...c, academic_level_id: 3 })
  assert.equal(next.courses.length, 1); assert.equal(d.courses.length, 0); assert.equal(courseIdentity(next.courses[0]), 'id:2')
  assert.deepEqual(actualChanges(next, structuredClone(next)), { added: 0, removed: 0, updated: 0, requirements: false })
})
test('route identity preserves selected program/version and access is the existing union', () => {
  assert.equal(legacyWorkspaceLink(2, '?version=3'), '/vp/scientific/programs-courses?version=3&program=2')
  assert.deepEqual(WORKSPACE_ACCESS.anyAccess, [PROGRAM_ACCESS, CATALOG_ACCESS])
})
test('navigation/pending-write and uncertain-outcome wiring is guarded (static)', () => {
  const source = readFileSync(new URL('../src/features/scientific-programs/UnifiedProgramsPage.jsx', import.meta.url), 'utf8')
  assert.match(source, /useBlocker/); assert.match(source, /beforeunload/); assert.match(source, /plan-changes\/\$\{uncertain.request_id\}/)
  assert.match(source, /failure.status !== 422/); assert.match(source, /key=\{`\$\{identity\}/)
  assert.doesNotMatch(source, /إعادة.*تلقائي.*POST/)
})
test('confirmation differences exclude presentation hints and preserve real before/after values', () => {
  const original = { courses: [{ course_id: 1, requirement_scope: 'college', course_type: 'mandatory', is_active: true }], requirements: preparation(snapshot).requirements }
  const same = { ...original, courses: [{ ...original.courses[0], display_credit_hours: 3 }] }
  assert.deepEqual(courseChanges(original, same), []); assert.equal(actualChanges(original, same).updated, 0)
  const next = { ...same, courses: [{ ...same.courses[0], course_type: 'elective' }, { course_id: 2 }] }
  const changes = courseChanges(original, next)
  assert.equal(changes.length, 2); assert.equal(changes[0].before.course_type, 'mandatory'); assert.equal(changes[0].after.course_type, 'elective')
  const payload = changePayload('id', '1', [{ ...next, program: { academic_program_id: 1 }, source_version_id: 3 }], [])
  assert.equal('display_credit_hours' in payload.targets[0].courses[0], false)
})
test('inactive requirement activity is retained and explicit state-only edits are an actual confirmed delta', () => {
  const source = { ...snapshot, values: { ...snapshot.values, groups: [{ requirement_scope: 'university', requirement_type: 'mandatory', required_credit_hours: 0, is_active: false }] } }
  const original = preparation(source), staged = structuredClone(original)
  assert.equal(staged.requirements.groups[0].is_active, false)
  staged.requirements.groups[0].is_active = true
  assert.deepEqual(actualChanges(original, staged), { added: 0, removed: 0, updated: 0, requirements: true })
  const changes = requirementGroupChanges(original.requirements, staged.requirements)
  assert.equal(changes.length, 1); assert.equal(changes[0].before.required_credit_hours, changes[0].after.required_credit_hours)
  assert.equal(groupActivityLabel(changes[0].before.is_active), 'غير فعالة'); assert.equal(groupActivityLabel(changes[0].after.is_active), 'فعالة')
  assert.equal(groupActivityLabel(undefined), 'غير مسجلة')
  const payload = changePayload('id', '1', [staged], [])
  assert.equal(payload.targets[0].requirements.groups[0].is_active, true)
  assert.equal('group_exists' in payload.targets[0].requirements.groups[0], false)
  assert.equal(source.values.groups[0].is_active, false)
})
test('confirmation/history share activity diff semantics and do not hide equal-hour state changes (static)', () => {
  const read = name => readFileSync(new URL(`../src/features/scientific-programs/${name}`, import.meta.url), 'utf8')
  assert.match(read('UnifiedProgramsPage.jsx'), /requirementGroupChanges\(originals\[i\]\.requirements, d\.requirements\)/)
  assert.match(read('WorkspaceHistory.jsx'), /requirementGroupChanges\(e.before, e.after\)/)
  assert.match(read('WorkspaceRequirements.jsx'), /'is_active', e.target.value === '1'/)
  assert.match(read('WorkspaceRequirements.jsx'), /تفعيلها اختيار صريح/)
})
