import assert from 'node:assert/strict'
import test from 'node:test'
import { blankProposal, damascusTime, deadlineEvidence, failedAttempt, freezeAttempt, hasRecoveryRead, listQuery, newDraft, receiveDraft, reviewDraft, incompleteError, validateAcknowledgement } from '../src/features/exam-board/lib/incompleteResults.js'
import { missingValue, resultDescription, resultSymbol, termWithdrawalText } from '../src/features/academic-record/lib/resultSymbols.js'
const view = { registration_id: 17, case: { incomplete_case_id: 4, revision: 2 }, components: [{ grade_component_id: 1, name: 'نظري', max_mark: 60, mark: null, editable: true }, { grade_component_id: 2, name: 'عملي', max_mark: 40, mark: 0, editable: false }] }

test('grant requires an explicit expiration choice, real requirements and a trusted/manual deadline', () => {
  assert.equal(blankProposal('grant').expiry_outcome, '')
  const proposal = { reason: ' سبب ', requirements: ' مطلوب ', expiry_outcome: 'F', deadline_at: '2026-10-21' }
  const attempt = freezeAttempt({ ...view, case: null }, 'grant', proposal, 'fixed-uuid')
  assert.deepEqual(JSON.parse(attempt.body), { request_id: 'fixed-uuid', expected_revision: 0, reason: 'سبب', requirements: 'مطلوب', expiry_outcome: 'F', deadline_at: '2026-10-21' })
  assert.throws(() => freezeAttempt(view, 'grant', { ...proposal, expiry_outcome: '' }))
  assert.throws(() => freezeAttempt(view, 'grant', { ...proposal, reason: ' ' }))
  assert.throws(() => freezeAttempt(view, 'grant', { ...proposal, deadline_at: '' }))
  assert.ok(!Object.hasOwn(JSON.parse(freezeAttempt({ ...view, deadline_suggestion: { available: true } }, 'grant', { ...proposal, deadline_at: '' }).body), 'deadline_at'))
})
test('settlement sends only missing required marks, preserves zero and accepts configured single parts', () => {
  const body = JSON.parse(freezeAttempt(view, 'resolve', { marks: { 1: '0', 2: '39' } }, 'uuid').body)
  assert.deepEqual(body.components, [{ grade_component_id: 1, mark: '0' }]); assert.equal(body.expected_revision, 2)
  assert.throws(() => freezeAttempt(view, 'resolve', { marks: { 1: '' } }))
  assert.throws(() => freezeAttempt(view, 'resolve', { marks: { 1: '60.01' } }))
  assert.throws(() => freezeAttempt(view, 'resolve', { marks: { 1: '1.123' } }))
  assert.ok(!Object.hasOwn(JSON.parse(freezeAttempt({ ...view, components: view.components.slice(1) }, 'resolve', { marks: {} }).body), 'components'))
})
test('deadline sends the selected day unchanged without an invented justification', () => {
  assert.equal(freezeAttempt(view, 'deadline', { deadline_at: '2026-11-02' }).method, 'PATCH')
  const body = JSON.parse(freezeAttempt(view, 'deadline', { deadline_at: '2026-11-02', reason: 'not sent' }).body)
  assert.equal(body.deadline_at, '2026-11-02'); assert.ok(!Object.hasOwn(body, 'reason'))
  assert.equal(damascusTime('2026-10-16T20:59:59Z'), new Intl.DateTimeFormat('ar-SY', { timeZone: 'Asia/Damascus', dateStyle: 'medium', timeStyle: 'short', hour12: false }).format(new Date('2026-10-16T20:59:59Z')))
})
test('a new server revision never rebases a proposal or an immutable original attempt implicitly', () => {
  let draft = newDraft(view, 'resolve'); draft.proposal.marks[1] = '50'; draft.attempt = freezeAttempt(view, 'resolve', draft.proposal, 'original')
  const original = draft.attempt.body, server = { ...view, case: { ...view.case, revision: 3 } }
  draft = receiveDraft(draft, server)
  assert.equal(draft.conflict, true); assert.equal(draft.baseline.case.revision, 2); assert.equal(draft.proposal.marks[1], '50'); assert.equal(draft.attempt.body, original)
  const reviewed = reviewDraft(draft, 'fresh-read'); assert.equal(reviewed.baseline.case.revision, 3); assert.equal(reviewed.attempt, null); assert.equal(reviewed.proposal.marks[1], '50')
  assert.equal(reviewDraft({ ...draft, server: { ...server, components: [] } }), null)
})
test('query allowlist retains list return context and discards unknown parameters', () => {
  assert.equal(listQuery('?state=active&student_id=1&academic_year_id=2&semester_id=3&page=4&raw=secret').toString(), 'state=active&student_id=1&academic_year_id=2&semester_id=3&page=4')
})
test('NULL differs from zero, F/Z remain authoritative, terminal history is not active I', () => {
  assert.equal(missingValue(null), '—'); assert.equal(missingValue(0), '0')
  for (const [state, code, letter] of [['incomplete_active', 'incomplete', 'I'], ['incomplete_expired', 'deprived', 'Z'], ['incomplete_expired', 'failed', 'F'], ['incomplete_resolved', 'passed', 'B']]) {
    const course = { result_lifecycle: { state, case_id: 1 }, result_status: { status_code: code }, letter_grade: letter, final_mark: code === 'deprived' ? 99 : null }
    assert.equal(resultSymbol(course), letter)
    if (state !== 'incomplete_active') assert.ok(!resultDescription(course).includes('نشطة'))
    assert.ok(!resultDescription(course).includes('سبب داخلي'))
  }
  assert.match(resultDescription({ result_status: { status_code: 'incomplete' } }), /غير موثق/)
  assert.equal(resultSymbol({ result_status: { status_code: 'withdrawn' } }), 'W')
  assert.match(termWithdrawalText({ withdrawal_status: 'full_withdrawal', counts_towards_maximum_study_duration: true }), /مدة الدراسة/)
  assert.equal(termWithdrawalText({ withdrawal_status: 'not_full_withdrawal' }), '')
})
test('errors separate authorization, validation, lifecycle conflicts and unknown writes', () => {
  assert.match(incompleteError({ status: 403 }), /صلاحية/)
  assert.match(incompleteError({ status: 422, details: { mark: ['حد العلامة'] } }), /حد العلامة/)
  assert.match(incompleteError({ status: 409, errorCode: 'incomplete_stale' }), /بمقترحك/)
})

test('acknowledgement validates the original transaction identity, state and revision, not a subsequent read', () => {
  for (const action of ['grant', 'deadline', 'resolve']) {
    const baseline = action === 'grant' ? { ...view, case: null } : view
    const proposal = action === 'grant' ? { reason: 'سبب', requirements: 'متطلب', expiry_outcome: 'F', deadline_at: '2026-10-21' } : action === 'deadline' ? { deadline_at: '2026-11-01' } : { marks: { 1: '50' } }
    const attempt = freezeAttempt(baseline, action, proposal, 'original')
    const data = { changed: true, case: { incomplete_case_id: 4, student_course_registration_id: 17, revision: action === 'grant' ? 1 : 3, state: action === 'resolve' ? 'resolved' : 'active' } }
    assert.equal(validateAcknowledgement({ success: true, data }, attempt), data)
    assert.equal(validateAcknowledgement(JSON.parse(JSON.stringify({ success: true, data })), attempt).case.revision, data.case.revision)
    for (const change of [{ changed: false }, { case: null }, { case: { ...data.case, student_course_registration_id: 18 } }, { case: { ...data.case, revision: 99 } }]) assert.throws(() => validateAcknowledgement({ success: true, data: { ...data, ...change } }, attempt), { errorCode: 'incomplete_acknowledgement_invalid' })
    if (action !== 'grant') assert.throws(() => validateAcknowledgement({ success: true, data: { ...data, case: { ...data.case, incomplete_case_id: 5 } } }, attempt))
  }
})

test('unknown and rejected attempts retain identity and baseline until an explicit eligible fresh-read review', () => {
  let draft = newDraft(view, 'resolve'); draft.proposal.marks[1] = '50'; draft.attempt = freezeAttempt(view, 'resolve', draft.proposal, 'original')
  draft = failedAttempt(draft, new TypeError('lost'), 'read-0')
  assert.equal(draft.uncertain, true); assert.equal(hasRecoveryRead(draft, 'read-0'), false); assert.equal(hasRecoveryRead(draft, 'read-1'), true)
  assert.equal(reviewDraft(draft, 'read-1'), null)
  const repeated = failedAttempt(draft, new Error('invalid acknowledgement'), 'read-1')
  assert.equal(hasRecoveryRead(repeated, 'read-1'), false)
  for (const status of [500, 503]) {
    const unavailable = failedAttempt(draft, { status }, 'read-1')
    assert.equal(unavailable.uncertain, true); assert.equal(unavailable.rejected, false); assert.equal(unavailable.attempt, draft.attempt)
  }
  for (const status of [409, 422]) {
    const rejected = failedAttempt(repeated, { status }, 'read-1')
    assert.equal(rejected.uncertain, false); assert.equal(rejected.rejected, true); assert.equal(rejected.attempt, draft.attempt)
    assert.equal(rejected.baseline, view); assert.equal(rejected.proposal.marks[1], '50'); assert.equal(reviewDraft(rejected, 'read-1'), null)
    const reviewed = reviewDraft(receiveDraft(rejected, { ...view, case: { ...view.case, revision: 3 } }), 'read-2')
    assert.equal(reviewed.attempt, null); assert.equal(reviewed.baseline.case.revision, 3); assert.equal(reviewed.proposal.marks[1], '50')
  }
})

test('deadline evidence preserves recorded source and references and interprets snapshot DB timestamps as UTC', () => {
  assert.deepEqual(deadlineEvidence({ deadline_source: 'manual', calendar_snapshot: { academic_calendar_event_id: 999 } }), ['موعد يدوي مسجل'])
  assert.deepEqual(deadlineEvidence({}), ['مصدر المهلة غير مسجل'])
  assert.match(deadlineEvidence({ deadline_source: 'calendar' }).join(' '), /غير مسجل/)
  const evidence = deadlineEvidence({ deadline_source: 'calendar', calendar_snapshot: { academic_calendar_event_id: 9, academic_calendar_event_version_id: 12, version_number: 2, academic_year_id: 1, semester_id: 2, starts_at: '2026-10-01 05:00:00' } }).join(' ')
  for (const text of ['التقويم المنشور', 'مرجع الحدث: 9', 'مرجع النسخة المسجلة: 12', 'رقم النسخة: 2', 'السنة 1 / الفصل 2']) assert.ok(evidence.includes(text))
  assert.equal(damascusTime('2026-10-01 05:00:00'), damascusTime('2026-10-01T05:00:00Z'))
})
