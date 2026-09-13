import test from 'node:test'
import assert from 'node:assert/strict'
import { courseAssociations, distributionScope } from '../src/features/scientific-courses/associations.js'
import { editableCourse, coursePayload } from '../src/features/scientific-courses/catalog.js'

test('scope selection only sends the intended hierarchy, not a client-selected program subset', () => {
  const value = { scope: 'university', college: { id: 1 }, department: { id: 2 }, course_type: 'elective' }
  assert.deepEqual(distributionScope(value), { scope: 'university', course_type: 'elective' })
  assert.deepEqual(distributionScope({ ...value, scope: 'college' }), { scope: 'college', course_type: 'elective', college_id: 1 })
  assert.deepEqual(distributionScope({ ...value, scope: 'department' }), { scope: 'department', course_type: 'elective', college_id: 1, department_id: 2 })
  assert.equal(distributionScope({ ...value, scope: 'department', college: null }), null)
  assert.equal(distributionScope({ ...value, scope: '' }), null)
})
test('counts deduplicate colleges/departments/instructors while preserving classification differences', () => {
  const department = { department_id: 2, college_id: 1, department_name: 'قسم', college: { college_id: 1, college_name: 'كلية' } }
  const data = courseAssociations({ course_departments: [{ department_id: 2, department }],
    program_courses: ['mandatory', 'elective'].map(requirement_type => ({ academic_program: { department }, requirement_classification: { requirement_scope: 'college', requirement_type } })),
    instructors: [{ faculty_member_id: 7, is_primary: 1, is_active: 1 }, { faculty_member_id: 7, is_primary: 0, is_active: 0 }] })
  assert.equal(data.colleges.length, 1); assert.equal(data.departments.length, 1); assert.equal(data.instructors.length, 1)
  assert.deepEqual(data.classifications, ['متطلبات الكلية — إجباري', 'متطلبات الكلية — اختياري'])
  assert.deepEqual(data.instructors[0].links, [{ primary: true, active: true }, { primary: false, active: false }])
  assert.deepEqual(courseAssociations({}).classifications, [])
})
test('saved prerequisites retain name and code without persisting presentation labels', () => {
  const baseline = { revision: '9', data: { course_id: 1, course_prerequisites: [{ prerequisite_course_id: 2, prerequisite_course: { course_name: 'رياضيات', course_code: 'MTH102' } }] } }
  const draft = editableCourse(baseline)
  assert.equal(draft.prerequisites[0].label, 'رياضيات (MTH102)')
  assert.deepEqual(coursePayload(draft, baseline), { revision: '9' })
})
