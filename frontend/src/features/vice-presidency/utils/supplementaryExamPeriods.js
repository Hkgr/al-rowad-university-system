import { hasRole, ROLES } from '../../auth/auth'

export const STATUS_LEGACY = 'legacy'
export const STATUS_ANNOUNCED = 'announced'

export function hasAssignedPermission(permission, user) {
  return user?.permissions?.includes(permission) ?? false
}

export function canDecideSupplementaryExamPeriod(user) {
  return hasRole(ROLES.vicePresidentScientific, user)
    && hasAssignedPermission('supplementary_exams.periods.decide', user)
}

export function canViewSupplementaryExamPeriod(user) {
  return hasRole(ROLES.vicePresidentScientific, user)
    && hasAssignedPermission('supplementary_exams.periods.view', user)
}

export function periodForIdentity(periods, academicYearId, semesterId) {
  const yearId = Number(academicYearId)
  const semId = Number(semesterId)
  if (!Array.isArray(periods) || !yearId || !semId) return null
  return periods.find(period => {
    const periodYear = Number(period?.academic_year?.academic_year_id ?? period?.academic_year?.id ?? period?.academic_year_id)
    const periodSemester = Number(period?.semester?.semester_id ?? period?.semester?.id ?? period?.semester_id)
    return periodYear === yearId && periodSemester === semId
  }) ?? null
}

export function canAnnouncePeriod(period) {
  return period == null
}

export function defaultSupplementaryPeriodName(semesterName, yearName) {
  return `الدورة التكميلية — ${semesterName || 'الفصل'} — ${yearName || 'السنة الأكاديمية'}`
}

export function statusLabelAr(status) {
  if (status === STATUS_ANNOUNCED) return 'معلنة'
  if (status === STATUS_LEGACY) return 'سجل سابق'
  if (!status) return 'غير مفعلة'
  return String(status)
}

export function formatPeriodDate(value) {
  if (!value) return '—'
  const text = String(value)
  return text.slice(0, 10)
}
