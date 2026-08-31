import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import {
  advisorActionsVisible,
  registrationPhaseLabel,
  studentRegistrationNotice,
} from '../src/features/registration-requests/registrationDeadlinePresentation.js'

test('registration phase presentation covers before-start, student, advisor, closed and configuration states', () => {
  assert.equal(registrationPhaseLabel({ phase: 'not_started' }), 'لم يبدأ التسجيل بعد')
  assert.equal(registrationPhaseLabel({ phase: 'student_open' }), 'التسجيل متاح للطلاب')
  assert.match(registrationPhaseLabel({ phase: 'advisor_review' }), /مراجعة المرشد/)
  assert.match(registrationPhaseLabel({ phase: 'closed' }), /انتهت/)
  assert.match(registrationPhaseLabel({ phase: 'configuration_error' }), /غير متاح/)
})

test('returned requests after the student cutoff and expired requests are explicitly read-only states', () => {
  assert.match(studentRegistrationNotice({ student_registration_open: false }, 'returned'), /لا يمكن تعديله أو إعادة إرساله/)
  assert.equal(studentRegistrationNotice({ phase: 'closed' }, 'expired'), 'انتهت المهلة دون اعتماد')
})

test('advisor actions use backend deadline capability and submitted state only', () => {
  assert.equal(advisorActionsVisible({ status: 'submitted', registration_calendar: { advisor_decision_open: true } }), true)
  assert.equal(advisorActionsVisible({ status: 'submitted', registration_calendar: { advisor_decision_open: false } }), false)
  assert.equal(advisorActionsVisible({ status: 'expired', registration_calendar: { advisor_decision_open: true } }), false)
})

test('Scientific VP form exposes ordered course-registration deadlines without a second page', async () => {
  const source = await readFile(new URL('../src/features/academic-calendar/AcademicCalendarPage.jsx', import.meta.url), 'utf8')
  assert.match(source, /student_registration_ends_at/)
  assert.match(source, /advisor_approval_ends_at/)
  assert.match(source, /بداية تسجيل الطلاب/)
  assert.match(source, /نهاية تسجيل الطلاب/)
  assert.match(source, /نهاية اعتماد المرشد الأكاديمي/)
  assert.match(source, /min=\{form\.starts_at\}/)
  assert.match(source, /min=\{form\.student_registration_ends_at\}/)
  assert.match(source, /ends_at: fromUniversityInput\(isCourseRegistration \? form\.advisor_approval_ends_at : form\.ends_at\)/)
})

test('student mutation capabilities close together at the backend-provided student deadline', async () => {
  const source = await readFile(new URL('../src/features/student-dashboard/pages/StudentRegistration.jsx', import.meta.url), 'utf8')
  assert.match(source, /const registrationOpen = payload\?\.registration_open === true/)
  assert.match(source, /const requestItemRemovalOpen = payload\?\.request_item_removal_open === true/)
  assert.match(source, /const canEdit = registrationOpen/)
  assert.match(source, /const canRemoveItem = requestItemRemovalOpen/)
  assert.match(source, /expired: \{ ar: 'انتهت المهلة دون اعتماد'/)
  assert.match(source, /studentRegistrationNotice\(registrationCalendar, request\?\.status\)/)
})

test('existing advisor queue and detail expose expiration, deadlines and backend action flags', async () => {
  const queue = await readFile(new URL('../src/features/dean-dashboard/pages/DeanRegistrationRequests.jsx', import.meta.url), 'utf8')
  const detail = await readFile(new URL('../src/features/dean-dashboard/pages/DeanRegistrationRequestDetail.jsx', import.meta.url), 'utf8')
  assert.match(queue, /key: 'expired'/)
  assert.match(queue, /advisor_approval_ends_at/)
  assert.match(detail, /advisorActionsVisible\(request\)/)
  assert.match(detail, /student_registration_ends_at/)
  assert.match(detail, /advisor_approval_ends_at/)
  assert.doesNotMatch(detail, /new Date\([^)]*advisor_approval_ends_at/)
})
