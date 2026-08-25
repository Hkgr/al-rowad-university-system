export const ADVISORY_NOTICE = {
  added(count) {
    return `تمت إضافة ${count} مادة من الخطة الإرشادية للفصل المحدد.`
  },
  addedWithExisting(added, alreadyPresent) {
    return `تمت إضافة ${added} مادة، و${alreadyPresent} مادة كانت موجودة مسبقًا في التجهيز.`
  },
  zeroMatch: 'لم يتم العثور على مواد مرتبطة بالفصل المحدد في الخطة الإرشادية.',
  missingMetadata: 'تعذّر قراءة الفصل الإرشادي من بيانات الخطة. أعد تحميل الصفحة أو تحقق من بيانات الخطة.',
}

export const CLEAR_DRAFT_WARNING = 'سيتم حذف المواد غير المحفوظة من التجهيز الحالي فقط.\nلن يتم حذف أي طرح محفوظ أو بيانات أكاديمية.'

export function uniqueProgramCourseIds(ids) {
  const seen = new Set()
  const result = []
  ;(ids ?? []).forEach(raw => {
    const id = Number(raw)
    if (!Number.isFinite(id) || id < 1 || seen.has(id)) return
    seen.add(id)
    result.push(id)
  })
  return result
}

export function flattenCatalogCourses(levels) {
  return (levels ?? []).flatMap(level => (level.courses ?? []).map(row => ({
    ...row,
    academic_level_id: row.academic_level_id ?? level.academic_level_id,
    academic_level_name: row.academic_level_name ?? level.level_name,
  })))
}

export function catalogCoursesForAdvisoryLevel(levels, academicLevelId = null) {
  const rows = flattenCatalogCourses(levels)
  if (academicLevelId == null || academicLevelId === '') return rows
  return rows.filter(row => Number(row.academic_level_id) === Number(academicLevelId))
}

export function advisoryLevelLabel(row) {
  const id = row?.advisory_plan?.academic_level_id ?? row?.academic_level_id
  if (id == null || id === '') return 'المستوى الإرشادي غير محدد'
  const name = row?.advisory_plan?.academic_level_name ?? row?.academic_level_name
  return name ? `المستوى الإرشادي: ${name}` : 'المستوى الإرشادي غير محدد'
}

export function advisoryPlanDiffers(row, selectedSemesterId, contextAcademicLevelId = null) {
  const rowLevelId = row?.advisory_plan?.academic_level_id ?? row?.academic_level_id
  const recommendedId = recommendedSemesterId(row)
  const levelDiffers = contextAcademicLevelId != null
    && contextAcademicLevelId !== ''
    && rowLevelId != null
    && Number(rowLevelId) !== Number(contextAcademicLevelId)
  const semesterDiffers = selectedSemesterId != null
    && selectedSemesterId !== ''
    && recommendedId != null
    && recommendedId !== Number(selectedSemesterId)
  return levelDiffers || semesterDiffers
}

export function recommendedSemesterId(row) {
  const value = row?.advisory_plan?.recommended_semester_id
  if (value == null || value === '') return null
  const id = Number(value)
  return Number.isFinite(id) ? id : null
}

export function hasAdvisorySemesterMetadata(rows) {
  return (rows ?? []).some(row => recommendedSemesterId(row) != null)
}

export function recommendedSemesterMatches(row, selectedSemesterId) {
  const recommendedId = recommendedSemesterId(row)
  if (recommendedId == null) return false
  if (selectedSemesterId == null || selectedSemesterId === '') return false
  return recommendedId === Number(selectedSemesterId)
}

export function advisorySemesterLabel(row, selectedSemesterId) {
  const recommendedId = recommendedSemesterId(row)
  if (recommendedId == null) return 'الفصل الإرشادي غير محدد'
  if (selectedSemesterId != null && selectedSemesterId !== '' && recommendedId === Number(selectedSemesterId)) {
    return 'موصى بها لهذا الفصل'
  }
  const name = row?.advisory_plan?.recommended_semester_name
  return name ? `إرشاديًا: ${name}` : 'إرشاديًا: فصل آخر'
}

export function advisoryPlanDraftIds(levels, selectedSemesterId) {
  return uniqueProgramCourseIds(
    flattenCatalogCourses(levels)
      .filter(row => recommendedSemesterMatches(row, selectedSemesterId))
      .map(row => row.program_course_id),
  )
}

export function preparationIds(levels, draftIds) {
  const existing = flattenCatalogCourses(levels)
    .filter(row => Boolean(row.offering))
    .map(row => row.program_course_id)
  return uniqueProgramCourseIds([...(existing ?? []), ...(draftIds ?? [])])
}

export function applyAdvisoryPlan(currentIds, levels, selectedSemesterId) {
  const rows = flattenCatalogCourses(levels)
  if (rows.length > 0 && !hasAdvisorySemesterMetadata(rows)) {
    return {
      draftIds: uniqueProgramCourseIds(currentIds),
      kind: 'missing-metadata',
      added: 0,
      alreadyPresent: 0,
      matched: 0,
      notice: ADVISORY_NOTICE.missingMetadata,
    }
  }

  const matchedIds = advisoryPlanDraftIds(levels, selectedSemesterId)
  const matchedCount = matchedIds.length
  if (matchedCount === 0) {
    return {
      draftIds: uniqueProgramCourseIds(currentIds),
      kind: 'zero-match',
      added: 0,
      alreadyPresent: 0,
      matched: 0,
      notice: ADVISORY_NOTICE.zeroMatch,
    }
  }

  const visible = new Set(preparationIds(levels, currentIds))
  const alreadyPresent = matchedIds.filter(id => visible.has(id)).length
  const added = matchedCount - alreadyPresent
  const draftIds = uniqueProgramCourseIds([...(currentIds ?? []), ...matchedIds])
  const notice = alreadyPresent > 0
    ? ADVISORY_NOTICE.addedWithExisting(added, alreadyPresent)
    : ADVISORY_NOTICE.added(added)

  return {
    draftIds,
    kind: 'added',
    added,
    alreadyPresent,
    matched: matchedCount,
    notice,
  }
}

export function plannerRowsForLevel(level, draftIds) {
  const draftSet = new Set((draftIds ?? []).map(Number))
  return (level?.courses ?? []).filter(row => (
    Boolean(row.offering) || draftSet.has(Number(row.program_course_id))
  ))
}

export function coursesForAcademicLevel(levels, academicLevelId) {
  const level = (levels ?? []).find(item => Number(item.academic_level_id) === Number(academicLevelId))
  return level?.courses ?? []
}

export function matchesCourseSearch(row, query) {
  const needle = String(query ?? '').trim().toLowerCase()
  if (!needle) return true
  const code = String(row?.course?.course_code ?? '').toLowerCase()
  const name = String(row?.course?.course_name ?? '').toLowerCase()
  return code.includes(needle) || name.includes(needle)
}

export function savePreview(levels, draftIds) {
  const byId = new Map(flattenCatalogCourses(levels).map(row => [Number(row.program_course_id), row]))
  const selected = preparationIds(levels, draftIds)
    .map(id => byId.get(id))
    .filter(Boolean)
  return {
    total: selected.length,
    existing: selected.filter(row => Boolean(row.offering)).length,
    creating: selected.filter(row => !row.offering).length,
    programCourseIds: selected.map(row => Number(row.program_course_id)),
  }
}

export function clearUnsavedDraft(draftIds) {
  return []
}

export function rowsByAcademicLevel(levels, draftIds) {
  return (levels ?? []).map(level => ({
    academic_level_id: level.academic_level_id,
    level_name: level.level_name,
    curriculumCount: (level.courses ?? []).length,
    rows: plannerRowsForLevel(level, draftIds),
  }))
}

export const BULK_PREPARE_FULL_SUCCESS_PREFIX = 'تم حفظ تجهيز الفصل بنجاح.'

export const BULK_PREPARE_PARTIAL_KEEP_DRAFT = 'بقيت المواد غير المحفوظة في التجهيز لتتمكن من المحاولة مرة أخرى.'

export const SAFE_PREPARE_ERROR_LABELS = {
  prepare_failed: 'تعذّر تجهيز هذه المادة.',
  invalid_program_course: 'المادة غير صالحة في خطة البرنامج.',
  not_found: 'تعذّر العثور على المادة.',
  conflict: 'تعذّر تجهيز المادة بسبب تعارض في البيانات.',
}

export const CLOSURE_REQUEST_WARNING = 'سيتم إرسال طلب إغلاق التسجيل للمراجعة.\nيبقى التسجيل مفتوحًا حتى اكتمال الموافقات المطلوبة.'

export const CLOSURE_REQUEST_SUBMITTED = 'تم إرسال طلب إغلاق التسجيل للمراجعة.'

export function prepareErrorLabel(errorCode) {
  const code = String(errorCode ?? '').trim()
  if (!code || /sqlstate|errorinfo|uq_course_offering/i.test(code)) {
    return SAFE_PREPARE_ERROR_LABELS.prepare_failed
  }
  return SAFE_PREPARE_ERROR_LABELS[code] || SAFE_PREPARE_ERROR_LABELS.prepare_failed
}

export function applyBulkPrepareOutcome(result) {
  const items = Array.isArray(result?.items) ? result.items : []
  const failedItems = items.filter(item => item.result === 'failed')
  const failedIds = uniqueProgramCourseIds(failedItems.map(item => Number(item.program_course_id)))
  const created = Number(result?.created_count) || 0
  const existing = Number(result?.existing_count) || 0
  const failed = Number(result?.failed_count) || failedItems.length
  const prepareErrors = {}
  failedItems.forEach(item => {
    const id = Number(item.program_course_id)
    if (id) prepareErrors[id] = prepareErrorLabel(item.error_code)
  })

  if (failed === 0) {
    return {
      draftIds: [],
      tone: 'success',
      notice: `${BULK_PREPARE_FULL_SUCCESS_PREFIX} ${created} طروحات أُنشئت، ${existing} كانت محفوظة مسبقًا.`,
      prepareErrors: {},
    }
  }

  return {
    draftIds: failedIds,
    tone: 'warning',
    notice: `تم إنشاء ${created} طروحات، و${existing} كانت محفوظة مسبقًا، وتعذّر تجهيز ${failed} مواد.\n${BULK_PREPARE_PARTIAL_KEEP_DRAFT}`,
    prepareErrors,
  }
}

export function pagedResourceRows(response) {
  const payload = response?.data
  if (Array.isArray(payload?.data)) return payload.data
  if (Array.isArray(payload)) return payload
  return []
}

export function currentWorkflowRequest(rows) {
  return (rows ?? []).find(row => row && row.status !== 'superseded') ?? null
}

export function canSubmitCurrentWorkflowRequest(request) {
  return !request || request.status === 'returned' || request.status === 'superseded'
}

export function openOfferingIds(levels) {
  return uniqueProgramCourseIds(
    flattenCatalogCourses(levels)
      .filter(row => row.offering?.status === 'open')
      .map(row => row.offering?.course_offering_id),
  )
}
