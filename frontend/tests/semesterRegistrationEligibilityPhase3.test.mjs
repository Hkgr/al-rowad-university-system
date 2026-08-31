import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const studentPageUrl = new URL('../src/features/student-dashboard/pages/StudentRegistration.jsx', import.meta.url)
const advisorPageUrl = new URL('../src/features/dean-dashboard/pages/DeanRegistrationRequestDetail.jsx', import.meta.url)
const examBoardRegistrationUrl = new URL('../src/features/exam-board/pages/CourseRegistrationPage.jsx', import.meta.url)

test('student eligibility presentation uses official backend credit and prerequisite fields', async () => {
  const source = await readFile(studentPageUrl, 'utf8')

  assert.match(source, /hours\?\.official_cgpa/)
  assert.match(source, /hours\?\.max_allowed_hours/)
  assert.match(source, /officialCgpa == null/)
  assert.match(source, /Number\(officialCgpa\)\.toFixed\(2\)/)
  assert.match(source, /hours\?\.below_recommended_minimum === true/)
  assert.match(source, /يمكنك متابعة إرسال الطلب/)
  assert.match(source, /course_already_passed/)
  assert.match(source, /missing_prerequisites/)
  assert.match(source, /credit_limit_exceeded/)
  assert.match(source, /course_outside_current_curriculum/)
  assert.match(source, /academic_requirement_configuration_invalid/)
  assert.match(source, /elective_requirement_completed/)
  assert.match(source, /elective_requirement_fully_committed/)
  assert.match(source, /elective_requirement_limit_exceeded/)
})

test('seat capacity is absent from student registration eligibility and actions', async () => {
  const source = await readFile(studentPageUrl, 'utf8')

  assert.doesNotMatch(source, /no_available_seats/)
  assert.doesNotMatch(source, /available_seats/)
  assert.doesNotMatch(source, /capacity/)
})

test('advisor detail renders backend eligibility and non-blocking load warning', async () => {
  const source = await readFile(advisorPageUrl, 'utf8')

  assert.match(source, /hours\.official_cgpa/)
  assert.match(source, /hours\.below_recommended_minimum === true/)
  assert.match(source, /item\.missing_prerequisites/)
  assert.match(source, /course_already_passed/)
  assert.doesNotMatch(source, /no_available_seats/)
  assert.doesNotMatch(source, /available_seats/)
})

test('legacy exam-board registration presentation does not reintroduce seat eligibility', async () => {
  const source = await readFile(examBoardRegistrationUrl, 'utf8')

  assert.doesNotMatch(source, /no_available_seats/)
  assert.doesNotMatch(source, /available_seats/)
  assert.match(source, /course_already_passed/)
})
