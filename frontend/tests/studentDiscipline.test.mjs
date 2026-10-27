import test from 'node:test'
import assert from 'node:assert/strict'
import { canAccess } from '../src/features/auth/auth.js'
import { DISCIPLINE_ACCESS, disciplineDate, disciplineWrite, isUncertainDisciplineWrite } from '../src/features/student-affairs/lib/discipline.js'

globalThis.localStorage = { getItem: () => null }
test('discipline access requires actual office role, permission and scope; VP needs no staff role', () => {
  const base = { roles: ['registration_officer'], permissions: ['students.view'], access_scopes: [{ type: 'program', id: 1 }] }
  assert.equal(canAccess(DISCIPLINE_ACCESS, base), true)
  for (const user of [{ ...base, roles: ['student'] }, { ...base, permissions: [] }, { ...base, access_scopes: [] }]) assert.equal(canAccess(DISCIPLINE_ACCESS, user), false)
  assert.equal(canAccess(DISCIPLINE_ACCESS, { ...base, roles: ['vice_president_administrative'], access_scopes: [{ type: 'university', id: 1 }] }), true)
  assert.equal(canAccess(DISCIPLINE_ACCESS, { roles: ['super_admin'] }), true)
})
test('date-only display is independent of client timezone and missing dates are not invented', () => {
  assert.equal(disciplineDate('2026-10-01T00:00:00.000Z'), '01/10/2026')
  assert.equal(disciplineDate(null), 'غير مسجل')
})
test('mutation acknowledgements require the matching student/case/appeal, not just HTTP success', async () => {
  const original = globalThis.fetch
  try {
    for (const payload of [null, {}, { success: false }, { success: true, data: {} }, { success: true, data: { case_id: 1, student_id: 12 } }]) {
      globalThis.fetch = async () => ({ ok: true, json: async () => payload })
      await assert.rejects(disciplineWrite('disciplinary-cases', { student_id: 11 }, { studentId: 11 }), e => isUncertainDisciplineWrite(e))
    }
    globalThis.fetch = async () => ({ ok: true, json: async () => { throw new SyntaxError('bad JSON') } })
    await assert.rejects(disciplineWrite('disciplinary-cases', {}, { studentId: 11 }), e => e.uncertain)
    globalThis.fetch = async () => ({ ok: true, json: async () => ({ success: true, data: { appeal_id: 4, case_id: 1 } }) })
    assert.equal((await disciplineWrite('disciplinary-case-appeals', {}, { appeal: true, caseId: 1 })).appeal_id, 4)
    await assert.rejects(disciplineWrite('disciplinary-case-appeals/5/decide', {}, { appeal: true, caseId: 1, appealId: 5 }), e => e.uncertain)
  } finally { globalThis.fetch = original }
})
