export const SUPPLEMENTARY_OVERVIEW_PATH = '/v1/exams/supplementary-overview'

export const OVERVIEW_STAGE_LABELS = Object.freeze({
  announcement: 'الإعلان',
  registration: 'التسجيل',
  roster_fixed: 'تثبيت القائمة',
  grading: 'إدخال العلامات',
  review: 'المراجعة والاعتماد',
  publication: 'النشر',
  materialization: 'الترحيل الرسمي',
})

export function overviewQuery({ periodId, offeringId, search, page = 1, perPage = 20 } = {}) {
  const params = new URLSearchParams({ page: String(page), per_page: String(perPage) })
  if (periodId) params.set('period_id', String(periodId))
  if (offeringId) params.set('offering_id', String(offeringId))
  if (search?.trim()) params.set('search', search.trim())
  return `${SUPPLEMENTARY_OVERVIEW_PATH}?${params}`
}

export function overviewEmptyMessage(payload) {
  if (!payload?.periods?.length) return 'لا توجد دورات امتحانات تكميلية متاحة ضمن نطاق البيانات.'
  if (!payload?.offerings?.length) return 'لا توجد عروض امتحانية مرتبطة بهذه الدورة ضمن نطاق البيانات.'
  const status = payload?.selected_period?.status
  if (status === 'announced') return 'لم يبدأ التسجيل بعد؛ ستظهر قائمة الطلاب عند فتح التسجيل.'
  if (status === 'registration_open') return 'التسجيل مفتوح، ولا توجد تسجيلات حالية مطابقة للفلاتر.'
  if (status === 'registration_closed') return 'ثُبتت القائمة دون طلاب مسجلين ضمن النطاق الحالي.'
  if (status === 'grading_open') return 'فُتح التصحيح ولا توجد تسجيلات مطابقة للفلاتر.'
  return 'لا توجد تسجيلات حالية مطابقة للفلاتر.'
}

export function canOpenSupplementaryGrades(payload, identity) {
  return payload?.capabilities?.can_access_grades === true
    && identity?.roles?.includes('exam_officer') === true
    && identity?.permissions?.includes('supplementary_exams.grades.review') === true
}

export function responseMatchesPeriod(payload, requestedPeriodId) {
  if (!requestedPeriodId) return true
  return String(payload?.selected_period?.supplementary_exam_period_id ?? '') === String(requestedPeriodId)
}
