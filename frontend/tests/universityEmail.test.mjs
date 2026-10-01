import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { ACCESS, canAccess } from '../src/features/auth/auth.js'
import { draftPayload, emailFailure, normalizeEnglishName, preparationLabels, previewAddress, requestSequence, requiresReview, studentSearchQuery, updateStudentSummary } from '../src/features/technical-portal/lib/universityEmail.js'
const source = path => readFileSync(new URL('../src/'+path, import.meta.url), 'utf8')
const technical = { roles: ['technical_team'], permissions: ['technical_portal.access', 'university_email.view', 'university_email.manage', 'university_email.check_connection'] }
test('blank search is omitted and nonempty Arabic/number searches keep applied pagination', () => {
  for (const q of ['', '   ']) assert.equal(new URLSearchParams(studentSearchQuery(q, 2)).has('q'), false)
  for (const q of ['أحمد', 'R24011002']) {
    const params = new URLSearchParams(studentSearchQuery(' '+q+' ', 3))
    assert.equal(params.get('q'), q); assert.equal(params.get('page'), '3')
  }
})
test('local draft and handover states stay separate and missing schema is not unprepared', () => {
  assert.match(preparationLabels(null).preparation, /غير متاح/)
  assert.match(preparationLabels({available:false}).preparation, /غير متاح/)
  assert.equal(preparationLabels({available:true}).preparation, 'لم تُجهّز')
  assert.deepEqual(preparationLabels({available:true, provisioning_status:'draft', handover_status:'not_delivered'}), {preparation:'مسودة محفوظة', handover:'غير مسلّم'})
  assert.deepEqual(preparationLabels({available:true, provisioning_status:'created', handover_status:'delivered'}), {preparation:'إنشاء مسجل محليًا', handover:'تم التسليم'})
  assert.equal(preparationLabels({available:true, provisioning_status:'unexpected'}).preparation, 'حالة غير معروفة')
})
test('confirmed save updates only its visible summary and leaves pagination and other rows intact', () => {
  const list = {data:[{student_id:1},{student_id:2}],meta:{current_page:2,total:30}}, summary = {available:true,provisioning_status:'draft',email_address:'a@example.invalid'}
  const updated = updateStudentSummary(list, {student_id:1,email_preparation:summary})
  assert.deepEqual(updated.data[0].email_preparation, summary)
  assert.equal(updated.data[1],list.data[1]); assert.equal(updated.meta,list.meta)
  assert.equal(list.data[0].email_preparation,undefined)
  assert.equal(updateStudentSummary(null,{student_id:1}),null)
})
test('dedicated view, manage and health permissions are assigned and do not imply account management', () => {
  for (const access of [ACCESS.universityEmail, ACCESS.universityEmailManage, ACCESS.universityEmailCheck]) assert.equal(canAccess(access, technical), true)
  assert.equal(canAccess(ACCESS.technicalAccountsManage, technical), false)
  for (const roles of [['dean'], ['student'], []]) assert.equal(canAccess(ACCESS.universityEmail, { ...technical, roles }), false)
  assert.equal(canAccess(ACCESS.universityEmail, {roles:['super_admin']}), true)
  const view = { ...technical, permissions: ['technical_portal.access', 'university_email.view'] }
  assert.equal(canAccess(ACCESS.universityEmail, view), true)
  assert.equal(canAccess(ACCESS.universityEmailManage, view), false)
  assert.equal(canAccess(ACCESS.universityEmailCheck, view), false)
})
test('name preview is ASCII only with server-owned context and exact quota-independent payload', () => {
  assert.equal(normalizeEnglishName('\u00a0 Ahmad \u00a0'), 'ahmad')
  assert.equal(previewAddress(' Ahmad ', 'R24011002', 'alrowaduni.edu.sy'), 'ahmad.r24011002@alrowaduni.edu.sy')
  for (const name of ['علي','é','a1','a-b','ali omar','']) assert.equal(previewAddress(name, 'R24011002', 'alrowaduni.edu.sy'), null)
  assert.equal(previewAddress('a'.repeat(55), 'R24011002', 'alrowaduni.edu.sy'), null)
  assert.equal(previewAddress('ali', 'R24011002', 'other.invalid'), null)
  assert.deepEqual(draftPayload('ali', null), { english_first_name: 'ali', revision: 0 })
  assert.deepEqual(draftPayload('ali', { revision: 2 }), { english_first_name: 'ali', revision: 2 })
})
test('conflicts and lost responses require explicit server review rather than retry', () => {
  assert.equal(requiresReview({ status: 409 }), true)
  assert.equal(requiresReview(new TypeError('network')), true)
  assert.equal(requiresReview({ status: 502 }), true)
  assert.equal(requiresReview({ status: 408 }), true)
  assert.equal(requiresReview({ status: 422 }), false)
  assert.match(emailFailure({ status: 409 }), /بقيت قيمك/)
  assert.match(emailFailure({}), /لن نعيد الإرسال تلقائيًا/)
  assert.match(emailFailure({ status: 403 }), /انتهى الوصول/)
})
test('immediate invalidation rejects old reads even when cancellation is ineffective', () => {
  const sequence = requestSequence(), first = sequence.next()
  sequence.invalidate()
  assert.equal(sequence.accepts(first), false)
  const second = sequence.next()
  assert.equal(sequence.accepts(second), true)
  assert.equal(sequence.accepts(first), false)
})
test('route/nav access parity and local-only draft boundary (static)', () => {
  const app = source('app/App.jsx'), nav = source('features/technical-portal/nav.js'), page = source('features/technical-portal/pages/UniversityEmailPage.jsx')
  assert.match(app, /technical\/university-email.*ACCESS.universityEmail/)
  assert.match(nav, /technical\/university-email.*ACCESS.universityEmail/)
  assert.match(page, /useBlocker/)
  assert.match(page, /beforeunload/)
  assert.match(page, /350/)
  assert.match(page, /UniversityEmailMailboxDialog/)
  const dialog = source('features/technical-portal/components/UniversityEmailMailboxDialog.jsx')
  assert.match(dialog, /setReviewRequired\(true\)/)
  assert.match(dialog, /لن يُرسل طلب إنشاء ثانٍ تلقائيًا/)
  assert.doesNotMatch(page + dialog, /MAILCOW_API_KEY|localStorage\.setItem|createMailbox|retry\(/)
})
