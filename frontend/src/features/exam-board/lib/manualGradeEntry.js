export const MANUAL_GRADE_BASE = '/v1/exams/manual-grade-entry'
export const MANUAL_GRADE_NOTICE = 'إدخال العلامات يدويًا يتم على المسؤولية الشخصية الكاملة للمستخدم، وتُسجَّل جميع عمليات الإدخال والتعديل باسمه. حفظ العلامات لا يعني نشرها؛ إذ تخضع لدورة الإرسال والاعتماد المعتمدة في النظام.'
export const MANUAL_GRADE_ACKNOWLEDGEMENT = 'أقرّ بصحة العلامات المدخلة وأتحمل مسؤوليتها.'
export const partLabel = part => ({ practical: 'العملي', theoretical: 'النظري' }[part] ?? 'غير معروف')
export const stateLabel = state => ({ draft: 'مسودة', returned: 'معاد للتصحيح', submitted: 'مرسل للاعتماد', approved: 'معتمد' }[state] ?? 'حالة غير متاحة')
export const registrationLabel = status => ({ registered: 'مسجل', completed: 'مكتمل', dropped: 'مسقط', withdrawn: 'منسحب', cancelled: 'ملغى' }[status] ?? 'حالة غير متاحة')
export const blockedLabel = code => ({
  grade_part_locked: 'الجزء مقفل وفق دورة الاعتماد.',
  official_result_locked: 'النتيجة رسمية؛ التصحيح بعد الاعتماد غير مدعوم هنا.',
  grade_entry_not_allowed: 'حالة التسجيل لا تسمح بإدخال العلامات.',
  deprived_student_grade_locked: 'الطالب محروم؛ لا يسمح بتعديل علاماته هنا.',
  supplementary_theoretical_deferred: 'يوجد تأجيل معتمد للامتحان النظري.',
  supplementary_materialized_result_locked: 'رُحّلت نتيجة تكميلية لهذه المحاولة؛ الإدخال النظامي مقفل.',
  supplementary_fixed_roster_target_locked: 'المحاولة ضمن قائمة تكميلية ثابتة؛ الإدخال النظامي مقفل.',
  supplementary_materialization_schema_not_ready: 'التحقق من قفل التكميلي غير متاح؛ راجع مسؤول النظام.',
  grade_part_incomplete: 'علامات الجزء لم تكتمل لجميع الطلاب المؤهلين.',
  grade_part_not_required: 'لا توجد مكونات مطلوبة لهذا الجزء.',
}[code] ?? 'الإجراء غير متاح وفق الحالة الرسمية الحالية.')
export const markText = mark => mark === null || mark === undefined ? '—' : String(mark)
export function markValue(value) {
  if (value === '' || value === null) return null
  if (!/^\d+(?:\.\d{1,2})?$/.test(String(value)) || !Number.isFinite(Number(value))) throw new Error('أدخل علامة غير سالبة بدقة منزلتين عشريتين كحد أقصى.')
  return Number(value)
}
export function changedComponents(components, edits) {
  return components.filter(c => Object.hasOwn(edits, c.grade_component_id))
    .map(c => ({ ...c, proposed: markValue(edits[c.grade_component_id]) }))
    .filter(c => c.mark !== c.proposed)
}
export function savePayload(row, edits, acknowledged, correctionConfirmed = false, reason = '') {
  const changed = changedComponents(row.components, edits)
  if (!acknowledged) throw new Error('يجب الإقرار بصحة العلامات.')
  if (!changed.length) throw new Error('لا توجد علامات معدلة للحفظ.')
  if (changed.some(c => c.proposed !== null && c.proposed > c.max_mark)) throw new Error('تجاوزت العلامة الحد الأعلى للمكوّن.')
  if (changed.some(c => c.mark !== null) && (!correctionConfirmed || !reason.trim())) throw new Error('تصحيح علامة موجودة يتطلب تأكيدًا وسببًا.')
  return { revision: row.revision, acknowledged: true, correction_confirmed: correctionConfirmed,
    correction_reason: reason.trim() || null,
    components: changed.map(c => ({ grade_component_id: c.grade_component_id, mark: c.proposed })) }
}
export function manualPath(studentId, registrationId) {
  return `${MANUAL_GRADE_BASE}/students/${Number(studentId)}/registrations${registrationId ? `/${Number(registrationId)}` : ''}`
}
export function searchPath(q, page = 1) {
  return `${MANUAL_GRADE_BASE}/students?${new URLSearchParams({ q: q.trim(), page, per_page: 15 })}`
}
export function manualError(error) {
  if (error.status === 403 || error.status === 401) return 'انتهى التفويض أو لم يعد الوصول مسموحًا. أعد تسجيل الدخول أو راجع المسؤول.'
  if (error.status === 409) return 'تغيرت العلامات أو أصبحت مقفلة. أعد تحميل الحالة الحالية قبل المتابعة.'
  if (error.status === 422) return Object.values(error.details ?? {}).flat().join(' ') || 'تحقق من العلامات والمكونات والإقرار وسبب التصحيح.'
  return 'تعذر تأكيد نتيجة العملية. أعد تحميل الحالة من الخادم قبل المحاولة مجددًا.'
}
export function requestSequence() {
  let current = 0
  return { next: () => ++current, valid: sequence => sequence === current, invalidate: () => ++current }
}
