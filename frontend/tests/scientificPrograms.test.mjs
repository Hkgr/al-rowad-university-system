import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { PROGRAM_ACCESS, canViewPrograms, sixGroups, requirementsPayload, budgetDraftSummary } from '../src/features/scientific-programs/programs.js'
import { distributionPath, distributionScope, emptyDistribution } from '../src/features/scientific-courses/associations.js'

const read = path => readFile(new URL('../src/' + path, import.meta.url), 'utf8')
test('program authority requires actual Scientific role, assigned permission and actual academic scope', () => {
  const valid = { roles: ['vice_president_scientific'], permissions: PROGRAM_ACCESS.assignedPermissions, access_scopes: [{ type: 'college', id: 1 }] }
  assert.equal(canViewPrograms(valid), true)
  for (const user of [null, { ...valid, roles: ['super_admin'] }, { ...valid, roles: ['vice_president_administrative'] }, { ...valid, permissions: [] }, { ...valid, access_scopes: [] }]) assert.equal(canViewPrograms(user), false)
})
test('six categories distinguish missing budgets from explicit zero and never invent groups during reads', () => {
  const groups = sixGroups([{ requirement_scope: 'university', requirement_type: 'mandatory', required_credit_hours: 0, is_active: true }])
  assert.equal(groups.length, 6); assert.equal(groups[0].required_credit_hours, 0); assert.equal(groups[1].required_credit_hours, '')
  const payload = requirementsPayload({ total_credit_hours: '', groups }, '12345678901234567890')
  assert.equal(payload.total_credit_hours, null); assert.equal(payload.groups[0].required_credit_hours, 0); assert.equal(payload.groups[1].required_credit_hours, null)
  assert.equal(payload.revision, '12345678901234567890')
  assert.equal('requirement_group_id' in payload.groups[0], false)
  assert.equal(sixGroups([{ ...groups[0], is_active: true }, { ...groups[0], is_active: true }])[0].ambiguous, true)
  assert.equal(budgetDraftSummary({ groups, total_credit_hours: 3 }).difference, null)
  assert.deepEqual(budgetDraftSummary({ groups: groups.map(g => ({ ...g, required_credit_hours: 1 })), total_credit_hours: 9 }), { sum: 6, total: 9, difference: 3 })
})
test('whole-scope distribution names explicit drafts without changing the selected program universe', () => {
  const value = { ...emptyDistribution(), scope: 'university', draftSelections: { 1: '5', 2: '9', 3: '' } }
  assert.deepEqual(distributionScope(value), { scope: 'university', course_type: 'mandatory', draft_version_ids: [5, 9] })
  const query = new URLSearchParams(distributionPath(value).split('?')[1])
  assert.deepEqual(query.getAll('draft_version_ids[]'), ['5', '9']); assert.equal(query.has('program_ids'), false)
})
test('static route/nav parity, independent defaults and transfer, shared design and uncertainty protection', async () => {
  const page = await read('features/scientific-programs/ScientificProgramsPage.jsx')
  assert.match(await read('app/App.jsx'), /ScientificProgramsPage \/>, PROGRAM_ACCESS/)
  assert.match(await read('features/vice-presidency/nav.js'), /to: '\/vp\/scientific\/programs'.*PROGRAM_ACCESS/)
  assert.match(page, /useBlocker/); assert.match(page, /beforeunload/); assert.match(page, /controls\.current\.busy/)
  assert.match(page, /loadAction\.current\+\+/); assert.match(page, /retained/)
  assert.match(page, /caps\.assign && plan\.data\.version\.status === 'approved'/)
  for (const title of ['إنشاء نسخة للتعديل', 'اعتماد الخطة', 'تعيين للطلاب الجدد', 'نقل الطلاب — إجراء مستقل']) assert.ok(page.includes(title))
  assert.match(page, /components\/table\/DataTable/); assert.match(page, /scientific-courses\/CatalogControls/)
  const decision = await read('features/scientific-programs/ProgramDecision.jsx')
  assert.match(decision, /preview\.revision/); assert.match(decision, /student_ids: students\.map/)
  assert.match(decision, /sequence\.current === token/); assert.match(decision, /outside_courses/)
  const distribution = await read('features/scientific-courses/CourseDistributionFields.jsx')
  assert.match(distribution, /draftSelections: \{\}/); assert.match(distribution, /requires_explicit_plan/)
})
