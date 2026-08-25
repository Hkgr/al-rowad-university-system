import assert from 'node:assert/strict'
import {
  ADVISORY_NOTICE,
  BULK_PREPARE_FULL_SUCCESS_PREFIX,
  BULK_PREPARE_PARTIAL_KEEP_DRAFT,
  applyAdvisoryPlan,
  applyBulkPrepareOutcome,
  advisoryLevelLabel,
  advisoryPlanDiffers,
  canSubmitCurrentWorkflowRequest,
  catalogCoursesForAdvisoryLevel,
  clearUnsavedDraft,
  plannerRowsForLevel,
  rowsByAcademicLevel,
  savePreview,
  uniqueProgramCourseIds,
} from '../../../frontend/src/features/dean-dashboard/utils/deanOfferingPlanner.js'

function course(id, levelId, semesterId, code, name, offering = null) {
  return {
    program_course_id: id,
    academic_level_id: levelId,
    course: { course_code: code, course_name: name, credit_hours: 3 },
    advisory_plan: semesterId == null
      ? undefined
      : {
        recommended_semester_id: semesterId,
        recommended_semester_name: semesterId === 1 ? 'الفصل الأول' : 'الفصل الثاني',
      },
    offering,
  }
}

function businessAdministrationCurriculum() {
  return [
    {
      academic_level_id: 1,
      level_name: 'السنة الأولى',
      courses: [
        course(101, 1, 1, 'BA101', 'مبادئ الإدارة'),
        course(102, 1, 2, 'BA102', 'مبادئ المحاسبة'),
      ],
    },
    {
      academic_level_id: 2,
      level_name: 'السنة الثانية',
      courses: [
        course(201, 2, 1, 'BA201', 'مبادئ التسويق'),
        course(202, 2, 2, 'FMF204', 'إدارة مالية'),
      ],
    },
    {
      academic_level_id: 3,
      level_name: 'السنة الثالثة',
      courses: [
        course(301, 3, 1, 'BA301', 'إدارة الموارد البشرية'),
        course(302, 3, 2, 'BA302', 'إدارة العمليات'),
      ],
    },
    {
      academic_level_id: 4,
      level_name: 'السنة الرابعة',
      courses: [
        course(401, 4, 1, 'BA401', 'الإدارة الاستراتيجية'),
        course(402, 4, 2, 'BA402', 'مشروع التخرج'),
      ],
    },
  ]
}

const results = []

function test(name, fn) {
  try {
    fn()
    results.push({ name, ok: true })
  } catch (error) {
    results.push({ name, ok: false, error: error.message })
  }
}

test('UX-PLAN-01 advisory click with matching rows produces non-empty draft', () => {
  const levels = businessAdministrationCurriculum()
  const result = applyAdvisoryPlan([], levels, 1)
  assert.equal(result.kind, 'added')
  assert.ok(result.matched > 0)
  assert.ok(result.added > 0)
  assert.deepEqual(result.draftIds, [101, 201, 301, 401])
  assert.equal(result.notice, ADVISORY_NOTICE.added(4))
  assert.equal(result.notice.includes('تمت إضافة 0'), false)
})

test('UX-PLAN-02 advisory click with 0 true matches shows NO success message', () => {
  const levels = businessAdministrationCurriculum()
  const result = applyAdvisoryPlan([], levels, 99)
  assert.equal(result.kind, 'zero-match')
  assert.equal(result.matched, 0)
  assert.deepEqual(result.draftIds, [])
  assert.equal(result.notice, ADVISORY_NOTICE.zeroMatch)
  assert.equal(result.notice.startsWith('تمت إضافة'), false)
})

test('UX-PLAN-03 missing advisory metadata produces explicit warning, not silent empty state', () => {
  const levels = [{
    academic_level_id: 1,
    level_name: 'السنة الأولى',
    courses: [
      course(101, 1, null, 'BA101', 'مبادئ الإدارة'),
      course(102, 1, null, 'BA102', 'مبادئ المحاسبة'),
    ],
  }]
  const result = applyAdvisoryPlan([], levels, 1)
  assert.equal(result.kind, 'missing-metadata')
  assert.deepEqual(result.draftIds, [])
  assert.equal(result.notice, ADVISORY_NOTICE.missingMetadata)
  assert.notEqual(result.notice, ADVISORY_NOTICE.zeroMatch)
})

test('UX-PLAN-04 matched rows are distributed under their correct academic levels', () => {
  const levels = businessAdministrationCurriculum()
  const result = applyAdvisoryPlan([], levels, 1)
  const cards = rowsByAcademicLevel(levels, result.draftIds)
  assert.equal(cards.length, 4)
  assert.equal(cards[0].level_name, 'السنة الأولى')
  assert.deepEqual(cards[0].rows.map(row => row.program_course_id), [101])
  assert.deepEqual(cards[1].rows.map(row => row.program_course_id), [201])
  assert.deepEqual(cards[2].rows.map(row => row.program_course_id), [301])
  assert.deepEqual(cards[3].rows.map(row => row.program_course_id), [401])
  assert.equal(cards[0].curriculumCount, 2)
})

test('UX-PLAN-05 duplicate click does not duplicate rows', () => {
  const levels = businessAdministrationCurriculum()
  const first = applyAdvisoryPlan([], levels, 1)
  const second = applyAdvisoryPlan(first.draftIds, levels, 1)
  assert.deepEqual(second.draftIds, first.draftIds)
  assert.equal(second.draftIds.length, uniqueProgramCourseIds(second.draftIds).length)
  assert.equal(second.added, 0)
  assert.equal(second.alreadyPresent, 4)
})

test('OFFER-ADV-01 global manual search includes courses from every advisory level', () => {
  const levels = businessAdministrationCurriculum()
  const allLevels = catalogCoursesForAdvisoryLevel(levels)
  const finance = allLevels.find(row => row.course.course_code === 'FMF204')
  assert.ok(finance)
  assert.equal(allLevels.length, 8)
  assert.equal(finance.advisory_plan.recommended_semester_id, 2)
  const afterAdvisory = applyAdvisoryPlan([], levels, 1)
  assert.equal(afterAdvisory.draftIds.includes(202), false)
  const withManual = uniqueProgramCourseIds([...afterAdvisory.draftIds, finance.program_course_id])
  assert.ok(withManual.includes(202))
  const visible = plannerRowsForLevel(levels[1], withManual).map(row => row.program_course_id)
  assert.deepEqual(visible, [201, 202])
})

test('OFFER-ADV-01 advisory level filter is optional over the complete universe', () => {
  const levels = businessAdministrationCurriculum()
  const year4 = catalogCoursesForAdvisoryLevel(levels, 4)
  assert.deepEqual(year4.map(row => row.program_course_id), [401, 402])
  const allLevels = catalogCoursesForAdvisoryLevel(levels, '')
  assert.ok(allLevels.some(row => row.course.course_code === 'FMF204'))
  assert.equal(allLevels.length, 8)
})

test('OFFER-ADV-13 null advisory metadata stays searchable with explicit labels', () => {
  const levels = businessAdministrationCurriculum()
  levels.push({
    academic_level_id: null,
    level_name: 'بدون مستوى دراسي',
    courses: [{
      program_course_id: 999,
      academic_level_id: null,
      course: { course_code: 'FMF321', course_name: 'محاسبة متقدمة', credit_hours: 3 },
      advisory_plan: {
        academic_level_id: null,
        academic_level_name: null,
        recommended_semester_id: null,
        recommended_semester_name: null,
      },
      offering: null,
    }],
  })
  const candidates = catalogCoursesForAdvisoryLevel(levels)
  const row = candidates.find(item => item.course.course_code === 'FMF321')
  assert.ok(row)
  assert.equal(advisoryLevelLabel(row), 'المستوى الإرشادي غير محدد')
  assert.equal(advisoryPlanDiffers(row, 2, 4), false)
  assert.ok(uniqueProgramCourseIds([row.program_course_id]).includes(999))
})

test('UX-PLAN-07 clear removes unsaved only', () => {
  const levels = businessAdministrationCurriculum()
  levels[0].courses[0].offering = { course_offering_id: 9001, status: 'closed' }
  const result = applyAdvisoryPlan([], levels, 1)
  assert.ok(result.draftIds.includes(101))
  const cleared = clearUnsavedDraft(result.draftIds)
  assert.deepEqual(cleared, [])
  const visible = plannerRowsForLevel(levels[0], cleared).map(row => row.program_course_id)
  assert.deepEqual(visible, [101])
})

test('UX-PLAN-08 existing offerings remain visible after clear', () => {
  const levels = businessAdministrationCurriculum()
  levels[3].courses[0].offering = { course_offering_id: 9401, status: 'open' }
  const result = applyAdvisoryPlan([402], levels, 1)
  const cleared = clearUnsavedDraft(result.draftIds)
  const cards = rowsByAcademicLevel(levels, cleared)
  assert.deepEqual(cards[3].rows.map(row => row.program_course_id), [401])
  assert.equal(cards[3].rows[0].offering.status, 'open')
  assert.equal(cards[0].rows.length, 0)
})

test('UX-PLAN-09 save uses selected ids including existing and unsaved', () => {
  const levels = businessAdministrationCurriculum()
  levels[0].courses[0].offering = { course_offering_id: 9001, status: 'closed' }
  const result = applyAdvisoryPlan([202], levels, 1)
  const preview = savePreview(levels, result.draftIds)
  assert.ok(preview.programCourseIds.includes(101))
  assert.ok(preview.programCourseIds.includes(202))
  assert.equal(preview.existing, 1)
  assert.ok(preview.creating >= 4)
})

test('UX-PLAN-10/11 preview does not mark existing offerings as creating', () => {
  const levels = businessAdministrationCurriculum()
  levels[1].courses[0].offering = { course_offering_id: 9201, status: 'open' }
  const result = applyAdvisoryPlan([], levels, 1)
  const preview = savePreview(levels, result.draftIds)
  const existingRow = preview.programCourseIds.includes(201)
  assert.equal(existingRow, true)
  assert.equal(preview.existing, 1)
  assert.equal(levels[1].courses[0].offering.status, 'open')
})

test('UX-SAVE-FAIL-01 bulk response with created + existing + failed keeps failed ids in draft', () => {
  const outcome = applyBulkPrepareOutcome({
    created_count: 2,
    existing_count: 1,
    failed_count: 1,
    items: [
      { program_course_id: 101, result: 'created' },
      { program_course_id: 201, result: 'existing' },
      { program_course_id: 301, result: 'failed', error_code: 'conflict' },
    ],
  })
  assert.deepEqual(outcome.draftIds, [301])
  assert.equal(outcome.tone, 'warning')
  assert.equal(outcome.prepareErrors[301], 'تعذّر تجهيز المادة بسبب تعارض في البيانات.')
})

test('UX-SAVE-FAIL-02 failed_count > 0 does NOT show the full-success notice', () => {
  const outcome = applyBulkPrepareOutcome({
    created_count: 1,
    existing_count: 0,
    failed_count: 2,
    items: [
      { program_course_id: 101, result: 'created' },
      { program_course_id: 102, result: 'failed', error_code: 'not_found' },
      { program_course_id: 103, result: 'failed', error_code: 'SQLSTATE[23000]' },
    ],
  })
  assert.equal(outcome.notice.includes(BULK_PREPARE_FULL_SUCCESS_PREFIX), false)
  assert.equal(outcome.notice.includes(BULK_PREPARE_PARTIAL_KEEP_DRAFT), true)
  assert.equal(outcome.prepareErrors[103].includes('SQLSTATE'), false)
})

test('UX-SAVE-FAIL-03 successful items persist after reload while failed unsaved remain visible', () => {
  const levels = businessAdministrationCurriculum()
  const outcome = applyBulkPrepareOutcome({
    created_count: 1,
    existing_count: 0,
    failed_count: 1,
    items: [
      { program_course_id: 101, result: 'created' },
      { program_course_id: 201, result: 'failed', error_code: 'prepare_failed' },
    ],
  })
  levels[0].courses[0].offering = { course_offering_id: 9101, status: 'closed' }
  const cards = rowsByAcademicLevel(levels, outcome.draftIds)
  assert.deepEqual(cards[0].rows.map(row => row.program_course_id), [101])
  assert.equal(cards[0].rows[0].offering.status, 'closed')
  assert.deepEqual(cards[1].rows.map(row => row.program_course_id), [201])
  assert.equal(cards[1].rows[0].offering, null)
})

test('UX-SAVE-FAIL-04 full success clears draft', () => {
  const outcome = applyBulkPrepareOutcome({
    created_count: 3,
    existing_count: 1,
    failed_count: 0,
    items: [
      { program_course_id: 101, result: 'created' },
      { program_course_id: 201, result: 'existing' },
    ],
  })
  assert.deepEqual(outcome.draftIds, [])
  assert.equal(outcome.tone, 'success')
  assert.equal(outcome.notice.includes(BULK_PREPARE_FULL_SUCCESS_PREFIX), true)
})

test('closure current request blocks duplicate submit', () => {
  assert.equal(canSubmitCurrentWorkflowRequest(null), true)
  assert.equal(canSubmitCurrentWorkflowRequest({ status: 'returned' }), true)
  assert.equal(canSubmitCurrentWorkflowRequest({ status: 'superseded' }), true)
  assert.equal(canSubmitCurrentWorkflowRequest({ status: 'submitted' }), false)
  assert.equal(canSubmitCurrentWorkflowRequest({ status: 'approved' }), false)
})

if (results.some(result => !result.ok)) {
  console.error(JSON.stringify(results, null, 2))
  process.exit(1)
}

console.log(JSON.stringify({ ok: true, count: results.length, results }))
