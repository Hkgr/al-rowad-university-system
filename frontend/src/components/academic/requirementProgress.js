export const REQUIREMENT_SCOPE_ORDER = ['university', 'college', 'department']

export const REQUIREMENT_SCOPE_LABELS = {
  university: 'متطلبات الجامعة',
  college: 'متطلبات الكلية',
  department: 'متطلبات القسم',
}

export const REQUIREMENT_TYPE_LABELS = {
  mandatory: 'إجباري',
  elective: 'اختياري',
}

export const REQUIREMENT_BLOCKER_LABELS = {
  no_academic_program: 'لا يوجد برنامج أكاديمي مرتبط بالسجل.',
  academic_requirements_incomplete: 'ما تزال بعض متطلبات الخطة غير مكتملة.',
  mandatory_requirements_incomplete: 'ما تزال هناك متطلبات إجبارية غير مكتملة.',
  elective_requirements_incomplete: 'ما تزال هناك ساعات اختيارية مطلوبة.',
}

export function asRequirementNumber(value) {
  const number = Number(value)
  return Number.isFinite(number) ? number : 0
}

export function visualRequirementPercent(part, whole) {
  if (!whole || whole <= 0) return null
  return Math.min(100, Math.max(0, Math.round((part / whole) * 100)))
}

export function mergeRequirementCountedHours(groups = [], eligibilityGroups = []) {
  const countedById = new Map(
    eligibilityGroups.map(group => [group.requirement_group_id, group.graduation_counted_hours]),
  )
  return groups.map(group => (
    countedById.has(group.requirement_group_id)
      ? { ...group, graduation_counted_hours: countedById.get(group.requirement_group_id) }
      : group
  ))
}

export function groupRequirementsByScope(groups = []) {
  const buckets = new Map()
  groups.forEach(group => {
    const scope = String(group.requirement_scope || 'other').toLowerCase()
    if (!buckets.has(scope)) buckets.set(scope, [])
    buckets.get(scope).push(group)
  })

  const known = REQUIREMENT_SCOPE_ORDER
    .filter(scope => buckets.has(scope))
    .map(scope => [scope, buckets.get(scope)])
  const extra = [...buckets.entries()].filter(([scope]) => !REQUIREMENT_SCOPE_ORDER.includes(scope))
  return [...known, ...extra]
}

export function requirementGroupPresentation(group = {}) {
  const type = String(group.requirement_type || '').toLowerCase()
  const required = asRequirementNumber(group.required_credit_hours)
  const earned = asRequirementNumber(group.earned_hours)
  const courseCount = asRequirementNumber(group.course_count)
  const passedCount = Array.isArray(group.passed_courses) ? group.passed_courses.length : 0
  const progress = type === 'mandatory' && courseCount > 0
    ? visualRequirementPercent(passedCount, courseCount)
    : visualRequirementPercent(earned, required)

  return {
    type,
    typeLabel: REQUIREMENT_TYPE_LABELS[type] || group.requirement_type || '—',
    required,
    earned,
    registered: asRequirementNumber(group.registered_in_progress_hours),
    pending: asRequirementNumber(group.pending_request_hours),
    remaining: asRequirementNumber(group.remaining_hours),
    pool: asRequirementNumber(group.pool_credit_hours),
    courseCount,
    passedCount,
    counted: group.graduation_counted_hours,
    progress,
    completed: group.completed === true,
  }
}

export function academicRequirementPresentation(progress = null, eligibility = null) {
  const groups = mergeRequirementCountedHours(progress?.groups ?? [], eligibility?.groups ?? [])
  const blockers = (eligibility?.blockers ?? []).filter(blocker => blocker && !blocker.requirement_group_id)
  const outside = progress?.outside_current_curriculum?.length
    ? progress.outside_current_curriculum
    : (eligibility?.outside_current_curriculum ?? [])
  const totalRequired = asRequirementNumber(eligibility?.total_required_hours ?? progress?.total_required_hours)
  const actualEarned = asRequirementNumber(
    eligibility?.actual_earned_curriculum_hours ?? progress?.earned_curriculum_hours,
  )
  const countedHours = asRequirementNumber(eligibility?.graduation_counted_hours)
  const remainingHours = asRequirementNumber(eligibility?.remaining_graduation_hours)

  return {
    groups,
    groupedScopes: groupRequirementsByScope(groups),
    outside,
    blockers,
    readableBlockers: [...new Set(blockers.map(blocker => REQUIREMENT_BLOCKER_LABELS[blocker.code]).filter(Boolean))],
    noProgram: !progress?.academic_program_id
      || blockers.some(blocker => blocker.code === 'no_academic_program')
      || eligibility?.blockers?.some(blocker => blocker.code === 'no_academic_program'),
    totalRequired,
    actualEarned,
    countedHours,
    remainingHours,
    overallProgress: visualRequirementPercent(countedHours, totalRequired),
    eligible: eligibility?.eligible === true,
  }
}
