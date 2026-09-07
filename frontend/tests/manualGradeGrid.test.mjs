import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { catalogPath, periodsPath, studentGridPath, selectedContext, preparationPath, preparationError } from '../src/features/exam-board/lib/manualGradeGrid.js'
const read = path => readFileSync(new URL(path, import.meta.url), 'utf8')
test('grid routes identify a student; actual term belongs to the catalog query, not curriculum advice', () => {
  assert.equal(studentGridPath(7), '/exam-board/manual-grade-entry/students/7')
  assert.equal(periodsPath(7), '/v1/exams/manual-grade-entry/students/7/periods')
  const url = new URL(catalogPath(7, { academic_year_id: 2, semester_id: 3, page: 2 }), 'https://fixture.invalid')
  assert.equal(url.searchParams.get('academic_year_id'), '2')
  assert.equal(url.searchParams.get('semester_id'), '3')
  assert.equal(preparationPath(7, 9, 'registration'), '/v1/exams/manual-grade-entry/students/7/offerings/9/registration')
})
test('period lookup and selectors are independent of catalog success; search intent invalidates before debounce', () => {
  const page = read('../src/features/exam-board/pages/StudentManualGradePage.jsx')
  const search = read('../src/features/exam-board/pages/ManualGradeEntryPage.jsx')
  assert.match(page, /apiRequest\(periodsPath\(studentId\)/)
  assert.match(page, /const \[terms, setTerms\] = useState\(\[\]\)/)
  assert.doesNotMatch(page, /\bdata\.terms\.(?:map|filter)/)
  assert.match(page, /setTerms\(\[\]\)/)
  assert.match(page, /setPeriodRetry/)
  assert.match(search, /lifecycle.current.change\(value\)/)
  assert.match(search, /setStudents\(null\); setLookupError\(''\); setLoading\(false\)/)
  assert.match(search, /searchPath\(applied.query, applied.page\)/)
  assert.match(search, /lifecycle.current.valid\(applied\)/)
})
test('zero contexts remains visible and multiple offerings/attempts never select first', () => {
  const course = { offerings: [] }
  assert.equal(selectedContext(course).offering, null)
  course.offerings = [{ course_offering_id: 1, registrations: [{ registration_id: 3 }, { registration_id: 4 }] }, { course_offering_id: 2, registrations: [] }]
  assert.equal(selectedContext(course).offering.course_offering_id, 1)
  assert.equal(selectedContext(course, '1').registration, null)
  assert.equal(selectedContext(course, '1', '4').registration.registration_id, 4)
  assert.equal(selectedContext(course, '2', '4').registration, undefined)
  assert.equal(selectedContext({ offerings: [course.offerings[0]] }).registration, null)
})
test('actionable configuration errors distinguish undefined, incompatible and official locks', () => {
  for (const errorCode of ['manual_components_undefined', 'manual_components_incompatible', 'grading_policy_incompatible', 'official_result_locked']) assert.ok(preparationError({ errorCode }))
  assert.equal(preparationError({ errorCode: 'unknown' }), null)
})

test('existing owned attempts take priority; genuinely competing owned attempts remain explicit', () => {
  const owned = { course_offering_id: 4, registrations: [{ registration_id: 8 }] }
  const course = { offerings: [{ course_offering_id: 3, registrations: [] }, owned] }
  assert.equal(selectedContext(course).registration.registration_id, 8)
  course.offerings.push({ course_offering_id: 5, registrations: [{ registration_id: 9 }] })
  assert.equal(selectedContext(course).offering, null)
  assert.equal(selectedContext(course, '5', '9').registration.registration_id, 9)
  assert.ok(preparationError({ errorCode: 'manual_recording_relationship_missing' }))
})
test('source integration: dedicated authorized route, catalog table and explicit preparation', () => {
  const app = read('../src/app/App.jsx')
  const search = read('../src/features/exam-board/pages/ManualGradeEntryPage.jsx')
  const page = read('../src/features/exam-board/pages/StudentManualGradePage.jsx')
  const course = read('../src/features/exam-board/components/CatalogGradeRow.jsx')
  const editor = read('../src/features/exam-board/components/RegistrationGridRow.jsx')
  assert.match(app, /manual-grade-entry\/students\/:studentId/)
  assert.match(search, /to=\{studentGridPath\(s.student_id\)\}/)
  for (const token of ['useParams', 'catalogPath(studentId', '<table', '<thead', '<tbody', 'scope="col"', 'overflow-x-auto', 'data.courses.map', 'setDraftEpoch', 'useBlocker']) assert.ok(page.includes(token), token)
  for (const token of ['selectedContext', 'PreparationGradeRow', 'RegistrationGridRow', 'draftEpoch']) assert.ok(course.includes(token), token)
  const preparation = read('../src/features/exam-board/components/PreparationGradeRow.jsx')
  for (const token of ['confirmed: true', 'reason', "method: 'POST'", 'pending.current', 'setBlocked(true)', 'props.reload()', 'onSaved(response.data)']) assert.ok(preparation.includes(token), token)
  assert.doesNotMatch(course, /component-preview|تجهيز مكونات العلامات|يلزم تجهيز الطرح عبر العميد/)
  assert.match(editor, /return <tr/)
  assert.match(editor, /<input className=\{field\} dir="ltr"/)
  assert.doesNotMatch(editor, /<article|onKeyDown|window.print|registerStudent/)
  assert.ok(editor.includes('savePayload(baseline, edits'))
  assert.ok(editor.includes('جميع طلاب الطرح'))
})
