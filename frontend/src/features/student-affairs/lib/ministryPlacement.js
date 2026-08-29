export function paginatedRows(response) {
  const payload = response?.data ?? response
  return Array.isArray(payload?.data) ? payload.data : []
}

export function paginationMeta(response) {
  const payload = response?.data ?? response
  return payload?.meta ?? {}
}

export function canImportPreview(preview) {
  return Boolean(preview)
    && Number(preview.invalid_rows) === 0
    && Number(preview.duplicate_rows) === 0
    && Array.isArray(preview.structural_errors)
    && preview.structural_errors.length === 0
}

export function previewStatusLabel(status) {
  if (status === 'valid') return 'صحيح'
  if (status === 'duplicate') return 'مكرر'
  return 'خاطئ'
}

const validationLabels = {
  required: 'الحقل مطلوب',
  invalid_email: 'البريد الإلكتروني غير صالح',
  invalid_date: 'التاريخ غير صالح',
  ambiguous_date: 'صيغة التاريخ ملتبسة',
  invalid_boolean: 'القيمة المنطقية غير معروفة',
  invalid_score: 'العلامة غير صالحة',
  invalid_certificate_grant_year: 'سنة منح الشهادة غير صالحة',
  invalid_row_number: 'رقم السطر غير صالح',
  duplicate_national_civil_id: 'الرقم الوطني مكرر في الملف',
  exceeds_max_total_score: 'المجموع يتجاوز المجموع الأعظمي',
  formula_not_allowed: 'الصيغ الحسابية غير مسموحة',
}

const workbookIssueLabels = {
  blank_title_row: 'صف العنوان الأول فارغ (تنبيه فقط)',
  additional_empty_sheet_ignored: 'تم تجاهل ورقة إضافية فارغة',
  missing_a_to_x_columns: 'بنية الأعمدة A:X غير مكتملة',
  unexpected_data_after_column_x: 'توجد بيانات غير متوقعة بعد العمود X',
  additional_data_sheet_not_supported: 'توجد بيانات في ورقة إضافية غير مدعومة',
  no_data_rows: 'لا توجد صفوف بيانات للاستيراد',
}

export function validationErrorLabel(code) {
  if (validationLabels[code]) return validationLabels[code]
  if (String(code).startsWith('max_length_')) return 'القيمة تتجاوز الطول المسموح'
  return 'خطأ تحقق غير معروف'
}

export function workbookIssueLabel(code) {
  if (workbookIssueLabels[code]) return workbookIssueLabels[code]
  if (String(code).startsWith('missing_header_')) return 'عنوان مطلوب مفقود من الصف الثاني'
  if (String(code).startsWith('invalid_header_anchor_')) return 'عنوان حرج في موضع غير صحيح'
  if (String(code).startsWith('formula_header_not_allowed_')) return 'صيغة حسابية غير مسموحة في صف العناوين'
  return 'مشكلة بنيوية غير معروفة في الملف'
}

export function rowErrorLabels(errors) {
  if (!errors || typeof errors !== 'object') return []
  return [...new Set(Object.values(errors).flat().map(validationErrorLabel))]
}

export function buildMinistryPlacementFormData(file, fields = {}) {
  const body = new FormData()
  body.append('file', file)
  Object.entries(fields).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') body.append(key, String(value))
  })
  return body
}

export function programMatchStateLabel(state) {
  if (state === 'unmatched') return 'غير مطابق'
  if (state === 'matched') return 'تمت المطابقة'
  if (state === 'stale_match') return 'يحتاج إلى مراجعة'
  if (state === 'locked') return 'مقفل'
  return 'حالة غير معروفة'
}

export function programSuggestionStatusLabel(status) {
  if (status === 'unique') return 'اقتراح وحيد'
  if (status === 'ambiguous') return 'اقتراحات متعددة — يلزم الاختيار'
  if (status === 'missing_preference') return 'لا توجد رغبة — يلزم مراجعة فردية'
  return 'لا يوجد اقتراح مطابق'
}

export function canBulkMatchProgramGroup(canManage, group) {
  return canManage === true
    && group?.bulk_matchable === true
    && Number(group?.bulk_eligible_unmatched_count ?? 0) > 0
}

export function programOptionLabel(program) {
  if (!program) return '—'
  return [program.program_code, program.program_name, program.department_name, program.college_name]
    .filter(Boolean)
    .join(' — ')
}

export function canMutateProgramMatch(canManage, record) {
  return canManage === true && ['unmatched', 'matched', 'stale_match'].includes(record?.program_match_state)
}

export function applicantConversionStateLabel(state) {
  if (state === 'convertible') return 'جاهز للتحويل'
  if (state === 'converted') return 'تم إنشاء المتقدم'
  if (state === 'not_ready') return 'غير جاهز'
  if (state === 'inconsistent') return 'يحتاج مراجعة'
  if (state === 'later_stage') return 'مرحلة لاحقة'
  return 'حالة غير معروفة'
}

export function applicantConversionBlockerLabel(code) {
  const labels = {
    program_not_matched: 'لم تتم مطابقة البرنامج',
    program_match_stale: 'مطابقة البرنامج تحتاج مراجعة',
    identity_missing: 'هوية الوزارة غير محددة',
    identity_conflict: 'هوية الوزارة مكررة في سجل آخر',
    applicant_data_invalid: 'بيانات المتقدم الأساسية غير مكتملة',
    applicant_number_conflict: 'رقم المتقدم الحتمي مستخدم مسبقاً',
    conversion_link_missing: 'حالة التحويل لا تحتوي رابط متقدم',
    linked_applicant_missing: 'رابط المتقدم يشير إلى سجل غير موجود',
    expected_application_missing: 'طلب القبول المتوقع غير موجود',
    expected_application_ambiguous: 'يوجد أكثر من طلب قبول مطابق',
    application_context_mismatch: 'برنامج أو سنة طلب القبول غير متطابقين',
    conversion_status_inconsistent: 'حالة التحويل غير متسقة',
    decision_status_unsupported: 'حالة قرار طلب القبول غير مدعومة',
    decision_provenance_inconsistent: 'بيانات قرار طلب القبول غير مكتملة',
    academic_year_missing: 'السنة الأكاديمية للدفعة غير متاحة',
  }
  return labels[code] ?? 'حالة التحويل غير متسقة'
}

export function canConvertMinistryRecord(canManage, record) {
  return canManage === true && record?.conversion_state === 'convertible'
}

export function canBulkConvertMinistryApplicants(canManage, summary) {
  return canManage === true
    && Number(summary?.eligible_count ?? 0) > 0
    && /^[a-f0-9]{64}$/.test(String(summary?.eligible_snapshot ?? ''))
}

export function studentEnrollmentStateLabel(state) {
  if (state === 'ready') return 'جاهز للاعتماد'
  if (state === 'enrolled') return 'تم إنشاء الطالب'
  if (state === 'not_ready') return 'غير جاهز'
  if (state === 'rejected') return 'مرفوض'
  if (state === 'inconsistent') return 'يحتاج مراجعة'
  return 'حالة غير معروفة'
}

export function studentEnrollmentBlockerLabel(code) {
  const labels = {
    applicant_not_created: 'لم يكتمل تحويل السجل إلى متقدم',
    linked_applicant_missing: 'سجل المتقدم المرتبط غير موجود',
    expected_application_missing: 'طلب القبول المطابق غير موجود',
    expected_application_ambiguous: 'يوجد أكثر من طلب قبول مطابق',
    application_student_ambiguous: 'يوجد أكثر من طالب مرتبط بطلب القبول',
    student_reference_missing: 'مرجع المستوى أو حالة الطالب غير موجود',
    student_deleted: 'سجل الطالب المرتبط محذوف ويحتاج مراجعة',
    program_hierarchy_inactive: 'البرنامج أو القسم أو الكلية لم تعد نشطة',
    identity_missing: 'هوية الوزارة غير محددة',
    identity_conflict: 'هوية الوزارة متعارضة مع سجل آخر',
    decision_status_unsupported: 'حالة قرار طلب القبول غير مدعومة',
    decision_provenance_inconsistent: 'بيانات قرار طلب القبول غير مكتملة',
    student_with_nonaccepted_application: 'يوجد طالب مرتبط بطلب غير مقبول',
    accepted_without_student: 'طلب مقبول دون سجل طالب',
    student_program_mismatch: 'برنامج الطالب لا يطابق طلب القبول',
    documents_pending: 'الوثائق ما زالت قيد الاستكمال',
    processing_status_inconsistent: 'حالة سجل المفاضلة غير متسقة',
  }
  return labels[code] ?? 'تحتاج الحالة إلى مراجعة تشغيلية'
}

export function canEnrollMinistryStudent(canManage, record) {
  return canManage === true && record?.enrollment_state === 'ready'
}

export function enrollmentInputComplete(input) {
  return Boolean(String(input?.student_number ?? '').trim())
    && Number(input?.current_academic_level_id) > 0
    && /^\d{4}-\d{2}-\d{2}$/.test(String(input?.enrollment_date ?? ''))
}

export function canBulkEnrollMinistryStudents(canManage, summary, inputs) {
  const ready = (summary?.records ?? []).filter(record => record.enrollment_state === 'ready')
  return canManage === true
    && ready.length > 0
    && ready.length === Number(summary?.eligible_count ?? 0)
    && /^[a-f0-9]{64}$/.test(String(summary?.eligible_snapshot ?? ''))
    && ready.every(record => enrollmentInputComplete(inputs?.[record.placement_record_id]))
}

export function reconciliationGateLabel(gate) {
  return gate === 'READY' ? 'جاهز للإنتاج' : gate === 'BLOCKED' ? 'الإنتاج محظور' : 'غير متاح'
}

export function reconciliationSeverityLabel(severity) {
  if (severity === 'clean') return 'سليم'
  if (severity === 'warning') return 'تحذير'
  if (severity === 'blocked') return 'محظور'
  return 'غير معروف'
}

export function reconciliationStateLabel(state) {
  const labels = {
    imported: 'مستورد',
    matched: 'مطابق للبرنامج',
    applicant_pending: 'متقدم — قرار معلّق',
    documents_pending: 'وثائق قيد الاستكمال',
    enrolled: 'طالب منشأ',
    rejected: 'مرفوض',
    inconsistent: 'غير متسق',
  }
  return labels[state] ?? 'غير معروف'
}

export function reconciliationIssueLabel(issue) {
  if (issue?.code === 'identity_conflict_multiple_terminal_records') {
    return 'تعارض هوية بين عدة سلاسل نهائية — يلزم تحقيق يدوي قبل الإنتاج'
  }
  if (issue?.code === 'identity_conflict_terminal_record') {
    return 'سجل نهائي متسق له سجل غير نهائي مماثل — تحذير تاريخي'
  }
  return issue?.message || 'مشكلة مصالحة غير معروفة'
}
