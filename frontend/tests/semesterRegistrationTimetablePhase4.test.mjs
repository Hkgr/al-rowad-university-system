import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import {
  ISO_WEEKDAY_LABELS,
  timetableConflictLabel,
  timetableSlotLabel,
  timetableStatusLabel,
} from '../src/features/registration-requests/courseOfferingTimetable.js'

const deanPage = new URL('../src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx', import.meta.url)
const studentPage = new URL('../src/features/student-dashboard/pages/StudentRegistration.jsx', import.meta.url)
const advisorPage = new URL('../src/features/dean-dashboard/pages/DeanRegistrationRequestDetail.jsx', import.meta.url)
const editor = new URL('../src/features/dean-dashboard/components/DeanTimetableDialog.jsx', import.meta.url)

test('ISO weekdays and slots are presentation-only Arabic labels', () => {
  assert.equal(ISO_WEEKDAY_LABELS[1], 'الاثنين')
  assert.equal(ISO_WEEKDAY_LABELS[7], 'الأحد')
  assert.match(timetableSlotLabel({ component_type: 'theoretical', day_of_week: 2, start_time: '08:00:00', end_time: '09:30:00' }), /نظري.*الثلاثاء.*08:00.*09:30/)
})

test('undefined components and incomplete schedules are never presented as complete', () => {
  assert.equal(timetableStatusLabel({ schema_ready: true, components_defined: false, complete: false }), 'مكونات التدريس غير محددة')
  assert.equal(timetableStatusLabel({ schema_ready: true, components_defined: true, complete: false }), 'الجدول غير مكتمل بعد')
  assert.equal(timetableStatusLabel({ schema_ready: true, components_defined: true, complete: true }), 'الجدول مكتمل')
})

test('conflict presentation uses authoritative backend details', () => {
  const label = timetableConflictLabel({ conflicting_with: { course_code: 'CS102', day_of_week: 1, start_time: '10:00:00', end_time: '11:00:00' } })
  assert.match(label, /CS102/)
  assert.match(label, /الاثنين/)
})

test('Dean editor submits the complete slot collection and does not compute overlap', async () => {
  const source = await readFile(editor, 'utf8')
  assert.match(source, /method: 'PUT'/)
  assert.match(source, /JSON\.stringify\(\{ slots \}\)/)
  assert.match(source, /schedule\?\.required_components/)
  assert.doesNotMatch(source, /startA|endB|overlap/i)
})

test('Dean, student, and advisor surfaces consume backend official timetable data', async () => {
  const [dean, student, advisor] = await Promise.all([
    readFile(deanPage, 'utf8'),
    readFile(studentPage, 'utf8'),
    readFile(advisorPage, 'utf8'),
  ])
  assert.match(dean, /offering\.official_timetable/)
  assert.match(student, /official_timetable/)
  assert.match(student, /timetable_conflicts/)
  assert.match(student, /registration\.official_timetable/)
  assert.match(advisor, /official_timetable/)
  assert.match(advisor, /timetable_conflicts/)
  assert.match(student, /offering_schedule_incomplete/)
  assert.match(student, /timetable_reference_incomplete/)
  assert.match(advisor, /timetable_conflict/)
  assert.match(advisor, /timetable_reference_incomplete/)
})

test('calendar-schema uncertainty and incomplete comparison sources have explicit Arabic presentation', async () => {
  const [presentation, student, advisor] = await Promise.all([
    readFile(new URL('../src/features/registration-requests/courseOfferingTimetable.js', import.meta.url), 'utf8'),
    readFile(studentPage, 'utf8'),
    readFile(advisorPage, 'utf8'),
  ])
  assert.match(presentation, /registration_calendar_schema_not_ready/)
  assert.match(student, /timetable_reference_incomplete/)
  assert.match(advisor, /timetable_reference_incomplete/)
})
