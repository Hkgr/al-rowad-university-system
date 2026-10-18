import test from 'node:test'
import assert from 'node:assert/strict'
import { advisoryPlacementValid } from '../src/features/scientific-programs/advisoryPlacement.js'
import { advisoryYears } from '../src/features/scientific-programs/entities.js'

test('only an elective can explicitly omit both advisory placement IDs', () => {
  for (const [course_type, academic_level_id, recommended_semester_id, expected] of [
    ['elective', null, null, true], ['mandatory', null, null, false], ['elective', 1, null, false], ['elective', null, 1, false],
    ['mandatory', 1, 2, true], ['elective', 1, 2, true], ['elective', 0, 0, false], ['elective', '', '', false],
  ]) assert.equal(advisoryPlacementValid({ course_type, academic_level_id, recommended_semester_id }), expected)
})
test('advisory display keeps an unplaced alternative, distinct from a placed semester and zero', () => {
  const rows = [{ course_id: 409, academic_level_id: null, recommended_semester_id: null }, { course_id: 1, academic_level_id: 1, recommended_semester_id: 2 }]
  const groups = advisoryYears(rows)
  assert.equal(groups.length, 2)
  assert.equal(groups[1].key, 'unset'); assert.equal(groups[1].terms[0].key, 'unset')
  assert.deepEqual(groups.flatMap(y => y.terms.flatMap(t => t.rows)).map(c => c.course_id), [1, 409])
  assert.equal(rows[0].academic_level_id, null); assert.equal(rows[0].recommended_semester_id, null)
})
