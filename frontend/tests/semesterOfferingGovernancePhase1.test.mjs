import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import {
  governancePreparationIds,
  governanceProposalEditable,
  governanceSavePreview,
  governanceStatusLabel,
  isRegularMandatoryCourse,
  requiresSemesterOfferingMinimum,
} from '../src/features/dean-dashboard/utils/deanOfferingPlanner.js'
import { coverageLabel } from '../src/features/vice-presidency/utils/semesterOfferingLabels.js'

const levels = [{ courses: [
  { program_course_id: 1, course_type: 'mandatory', advisory_plan: { recommended_semester_id: 2 } },
  { program_course_id: 2, course_type: 'elective', advisory_plan: { recommended_semester_id: 1 } },
] }]

test('regular mandatory rows are automatic regardless of advisory semester', () => {
  assert.equal(isRegularMandatoryCourse(levels[0].courses[0], 'first'), true)
  assert.deepEqual(governancePreparationIds(levels, [2], 'first'), [2, 1])
  assert.deepEqual(governancePreparationIds(levels, [], 'summer'), [])
})

test('persisted offerings remain visible but are not silently selected for summer or elective governance', () => {
  const persisted = [{ courses: [{ program_course_id: 7, course_type: 'elective', offering: { course_offering_id: 70 } }] }]
  assert.deepEqual(governanceSavePreview(persisted, []), { total: 0, existing: 0, creating: 0, programCourseIds: [] })
  assert.deepEqual(governanceSavePreview(persisted, [7]), { total: 1, existing: 1, creating: 0, programCourseIds: [7] })
})

test('electives and every summer selection require a positive minimum', () => {
  assert.equal(requiresSemesterOfferingMinimum(levels[0].courses[1], 'first'), true)
  assert.equal(requiresSemesterOfferingMinimum(levels[0].courses[0], 'first'), false)
  assert.equal(requiresSemesterOfferingMinimum(levels[0].courses[0], 'summer'), true)
})

test('submitted and approved proposals are immutable while returned remains editable', () => {
  assert.equal(governanceProposalEditable({ status: 'draft', materialized_at: null }), true)
  assert.equal(governanceProposalEditable({ status: 'returned', materialized_at: null }), true)
  assert.equal(governanceProposalEditable({ status: 'submitted', materialized_at: null }), false)
  assert.equal(governanceProposalEditable({ status: 'approved', materialized_at: '2026-08-30T00:00:00Z' }), false)
  assert.equal(governanceStatusLabel('returned'), 'معاد للتعديل')
  assert.equal(governanceStatusLabel('approved', '2026-08-31T00:00:00Z', 'closed'), 'اعتماد مستهلك — الطرح مغلق')
})

test('canonical coverage payload presents theoretical practical and combined gaps without local assignment inference', () => {
  assert.equal(coverageLabel({ complete: true, missing_roles: [] }), 'التكليف الفعّال مكتمل')
  assert.equal(coverageLabel({ complete: false, missing_roles: ['theoretical'] }), 'التكليف النظري غير مكتمل')
  assert.equal(coverageLabel({ complete: false, missing_roles: ['practical'] }), 'التكليف العملي غير مكتمل')
  assert.equal(coverageLabel({ complete: false, missing_roles: ['theoretical', 'practical'] }), 'التكليف النظري والعملي غير مكتمل')
})

test('Dean and Scientific UI preserve separated authority and canonical coverage presentation', () => {
  const dean = readFileSync(new URL('../src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx', import.meta.url), 'utf8')
  const scientific = readFileSync(new URL('../src/features/vice-presidency/pages/SemesterOfferingDetail.jsx', import.meta.url), 'utf8')
  const routes = readFileSync(new URL('../src/app/App.jsx', import.meta.url), 'utf8')

  assert.match(dean, /إرسال للاعتماد العلمي/)
  assert.match(dean, /instructorCoverageComplete\(coverage\)/)
  assert.match(dean, /!governance && locallySelected/)
  assert.match(dean, /offering\.status === 'closed' && proposalEditable/)
  assert.doesNotMatch(dean, /state\.key === 'closed' && proposalEditable/)
  assert.doesNotMatch(dean, /تأكيد فتح المادة/)
  assert.match(scientific, /إعادة للتعديل/)
  assert.match(scientific, /coverageLabel\(offering\?\.instructor_coverage\)/)
  assert.match(scientific, /semesterOfferingGovernanceReviewScientific/)
  assert.match(routes, /actualUniversityScope: true/)
})
