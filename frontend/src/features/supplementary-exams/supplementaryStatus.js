export const PERIOD_STATUS_LABELS = Object.freeze({
  legacy: 'سجل سابق',
  announced: 'معلنة',
  registration_open: 'التسجيل مفتوح',
  registration_closed: 'التسجيل مغلق',
  grading_open: 'إدخال العلامات مفتوح',
  grading_submitted: 'أُرسلت العلامات',
  results_approved: 'النتائج معتمدة',
  results_published: 'النتائج منشورة',
  results_materialized: 'النتائج مُرحّلة إلى السجل الرسمي',
})

export const WORKFLOW_STATUS_LABELS = Object.freeze({
  waiting: 'بانتظار العلامات',
  draft: 'مسودة',
  submitted: 'مرسلة للمراجعة',
  returned: 'معادة للتصحيح',
  approved: 'معتمدة',
  published: 'منشورة',
})

export const MATERIALIZATION_STATUS_LABELS = Object.freeze({
  waiting: 'بانتظار الترحيل الرسمي',
  materialized: 'مُرحّلة إلى السجل الرسمي',
  already_materialized: 'مُرحّلة مسبقاً',
  conflict: 'تعارض يمنع الترحيل',
  no_candidates: 'لا توجد قائمة طلاب',
  not_ready: 'غير جاهزة للترحيل',
})

export const MATERIALIZATION_REASON_LABELS = Object.freeze({
  schema_not_ready: 'بنية بيانات الترحيل غير جاهزة.',
  empty_roster: 'لا توجد قائمة طلاب مثبتة لهذا العرض.',
  regular_attempt_already_materialized: 'سبق استخدام المحاولة النظامية في ترحيل تكميلي آخر.',
  provenance_mismatch: 'إثباتات الترحيل الحالية لا تطابق مصدر النتيجة المنشورة.',
  period_not_published: 'الدورة لم تصل بعد إلى حالة النتائج المنشورة.',
  result_not_published: 'نتائج هذا العرض لم تُنشر بعد.',
})

export const RESULT_STATUS_LABELS = Object.freeze({
  passed: 'ناجح',
  failed: 'راسب',
  incomplete: 'غير مكتمل',
  deprived: 'محروم',
  withdrawn: 'منسحب',
})

export const ELIGIBILITY_REASON_LABELS = Object.freeze({
  failed_theoretical: 'رسوب في الجزء النظري',
  voluntarily_deferred_theoretical: 'تأجيل اختياري للجزء النظري',
})

export const RECONCILIATION_STATUS_LABELS = Object.freeze({
  PASS: 'متناسق',
  WARNING: 'تحذيرات تحتاج إلى مراجعة',
  CONFLICT: 'تعارض يمنع المتابعة',
})

const OPERATIONAL_STATUS_LABELS = Object.freeze({
  registration_open: 'التسجيل مفتوح',
  registration_closed: 'التسجيل مغلق والقائمة مثبتة',
  awaiting_grade_entry: 'بانتظار إدخال العلامات',
  grades_submitted: 'تم إرسال العلامات للمراجعة',
  returned_for_correction: 'أُعيدت العلامات للتعديل',
  grades_approved: 'العلامات معتمدة بانتظار النشر',
  results_published: 'النتائج منشورة',
  awaiting_official_materialization: 'بانتظار الترحيل إلى السجل الرسمي',
  officially_materialized: 'تم الترحيل إلى السجل الرسمي',
  conflict_requires_review: 'يوجد تعارض يتطلب المراجعة',
  workflow_incomplete: 'سير العمل غير مكتمل ويحتاج إلى مراجعة',
  reconciled: 'متطابق مع مرحلة الدورة',
  no_candidates: 'لا توجد قائمة طلاب لهذا العرض',
  awaiting_workflow_progress: 'بانتظار تقدم إجراءات الدورة',
  registration: 'التسجيل جارٍ',
  waiting: 'بانتظار فتح إدخال العلامات',
  grading: 'إدخال العلامات جارٍ',
  review: 'قيد مراجعة لجنة الامتحانات',
  correction: 'معادة إلى المصحح',
  approval: 'معتمدة بانتظار النشر',
  publication: 'منشورة بانتظار الترحيل',
  completed: 'مكتملة ومُرحّلة رسمياً',
  conflict: 'متوقفة بسبب تعارض',
})

const ISSUE_LABELS = Object.freeze({
  missing_offering: 'عرض امتحاني مفقود',
  missing_registration: 'تسجيل طالب مفقود',
  missing_grade: 'علامة تكميلية مفقودة',
  missing_submission: 'دفعة العلامات مفقودة',
  duplicate_registration: 'تسجيل طالب مكرر',
  duplicate_grade: 'علامة طالب مكررة',
  roster_mismatch: 'قائمة الطلاب لا تطابق الدفعة',
  count_mismatch: 'الأعداد غير متطابقة',
  version_mismatch: 'إصدار الدفعة غير متطابق',
  official_record_mismatch: 'السجل الرسمي لا يطابق النتيجة المنشورة',
  materialization_conflict: 'تعارض في الترحيل إلى السجل الرسمي',
})

const FIXED_ROSTER_STATUSES = new Set([
  'registration_closed',
  'grading_open',
  'grading_submitted',
  'results_approved',
  'results_published',
  'results_materialized',
])

function localizedLabel(labels, status, missingLabel, unknownLabel) {
  if (status === null || status === undefined || status === '') return missingLabel
  return labels[status] ?? unknownLabel
}

export function periodStatusLabel(status) {
  return localizedLabel(PERIOD_STATUS_LABELS, status, 'غير مفعلة', 'حالة دورة غير معروفة')
}

export function workflowStatusLabel(status) {
  return localizedLabel(WORKFLOW_STATUS_LABELS, status, 'لم تبدأ', 'حالة مراجعة غير معروفة')
}

export function materializationStatusLabel(status) {
  return localizedLabel(MATERIALIZATION_STATUS_LABELS, status, 'غير جاهزة للترحيل', 'حالة ترحيل غير معروفة')
}

export function materializationReasonLabel(reason) {
  return localizedLabel(MATERIALIZATION_REASON_LABELS, reason, 'لا يوجد سبب مانع.', 'سبب عدم الجاهزية غير معروف؛ راجع تقرير المطابقة.')
}

export function resultStatusLabel(status) {
  return localizedLabel(RESULT_STATUS_LABELS, status, 'لم تُحسب بعد', 'نتيجة غير معروفة')
}

export function eligibilityReasonLabel(reason) {
  return localizedLabel(ELIGIBILITY_REASON_LABELS, reason, 'غير محدد', 'سبب أهلية غير معروف')
}

export function reconciliationStatusLabel(status) {
  const normalized = typeof status === 'string' ? status.toUpperCase() : status
  return localizedLabel(RECONCILIATION_STATUS_LABELS, normalized, 'لم تُفحص بعد', 'حالة مطابقة غير معروفة')
}

export function reconciliationIssueLabel(issue) {
  const code = typeof issue === 'string' ? issue : issue?.code ?? issue?.type
  return localizedLabel(ISSUE_LABELS, code, 'ملاحظة غير محددة', 'ملاحظة مطابقة غير معروفة')
}

export function isFixedRosterStatus(status) {
  return FIXED_ROSTER_STATUSES.has(status)
}

export function derivedOperationalStatus(row = {}) {
  const explicit = row.materialization?.operational_status ?? row.operational_status
  if (OPERATIONAL_STATUS_LABELS[explicit]) return explicit
  const materialization = row.materialization?.state ?? row.materialization_status
  const workflow = row.workflow_status ?? row.submission?.status
  const period = row.period_status ?? row.offering?.period?.status

  if (materialization === 'conflict') return 'conflict'
  if (materialization === 'materialized' || period === 'results_materialized') return 'completed'
  if (workflow === 'published' || period === 'results_published') return 'publication'
  if (workflow === 'approved' || period === 'results_approved') return 'approval'
  if (workflow === 'returned') return 'correction'
  if (workflow === 'submitted' || period === 'grading_submitted') return 'review'
  if (period === 'grading_open' || workflow === 'draft') return 'grading'
  if (period === 'registration_open') return 'registration'
  return 'waiting'
}

export function operationalStatusLabel(row) {
  return OPERATIONAL_STATUS_LABELS[derivedOperationalStatus(row)]
}

const ERROR_MESSAGES = Object.freeze({
  supplementary_grading_not_open: 'إدخال العلامات التكميلية مغلق حالياً.',
  supplementary_grading_open_invalid: 'يجب إغلاق التسجيل وتثبيت القائمة قبل فتح إدخال العلامات.',
  supplementary_grade_roster_empty: 'لا يمكن المتابعة لأن قائمة الطلاب المثبتة فارغة.',
  supplementary_grade_roster_mismatch: 'قائمة الطلاب أو مصدرها الأكاديمي غير متطابق. راجع تقرير المطابقة.',
  supplementary_grade_eligibility_drift: 'تغيّرت أهلية أحد طلاب القائمة الثابتة أو محاولته الرسمية. راجع تقرير المطابقة.',
  supplementary_grade_cross_period_target_conflict: 'المحاولة الأكاديمية الرسمية مثبتة في دورة تكميلية أخرى. راجع التعارض قبل فتح التصحيح.',
  supplementary_grade_batch_incomplete: 'لا يمكن الإرسال قبل إدخال علامات جميع الطلاب.',
  supplementary_grade_locked: 'العلامات مقفلة في الحالة الحالية.',
  supplementary_grade_stale_submission: 'هذه نسخة قديمة من دفعة العلامات. حدّث الصفحة ثم أعد المحاولة.',
  supplementary_grade_version_mismatch: 'نسخة الدفعة لا تطابق النتائج الحالية. حدّث الصفحة.',
  supplementary_grade_period_invalid: 'حالة الدورة لا تسمح بهذا الإجراء.',
  supplementary_grader_assignment_locked: 'لا يمكن تغيير المصحح بعد إرسال الدفعة.',
  supplementary_grader_out_of_scope: 'المصحح المحدد خارج نطاق البيانات المسموح.',
  supplementary_period_terminal: 'الدورة مُرحّلة نهائياً ولا تقبل تعديلات تشغيلية.',
  supplementary_exam_target_already_materialized: 'سبق ترحيل هذه المحاولة الرسمية من امتحان تكميلي.',
  supplementary_fixed_roster_target_locked: 'المحاولة مرتبطة بقائمة امتحان تكميلي ثابتة ولا تقبل تعديلاً عادياً.',
  supplementary_exam_registration_duplicate_target_conflict: 'المحاولة الأكاديمية نفسها مكررة في قائمة هذه الدورة.',
  supplementary_exam_registration_cross_period_target_conflict: 'المحاولة الأكاديمية نفسها مثبتة في دورة تكميلية أخرى. ألغِ التسجيل المتعارض قبل تثبيت القائمة.',
  supplementary_exam_registration_roster_empty: 'لا يمكن تثبيت قائمة تسجيل فارغة. سجّل طالباً مؤهلاً واحداً على الأقل أولاً.',
  supplementary_grading_premature_artifacts: 'توجد علامات أو دفعات قبل فتح مرحلة التصحيح. راجع تقرير المطابقة.',
  supplementary_grade_configuration_locked: 'إعداد أكاديمي مستخدم في سير عمل ثابت ولا يمكن تغيير معناه الآن.',
  supplementary_grading_policy_locked: 'سياسة التقييم مستخدمة في سير عمل ثابت أو نتيجة رسمية ولا يمكن تغيير معناها.',
  supplementary_official_status_locked: 'هذه الحالة تحفظ معنى سير عمل ثابت أو نتيجة رسمية ولا يمكن تغيير معناها.',
  supplementary_materialization_schema_not_ready: 'بنية إثباتات الترحيل الرسمي غير جاهزة؛ أوقف الإجراء وراجع مسؤول النظام.',
  supplementary_materialization_period_not_published: 'يجب نشر النتائج قبل ترحيلها إلى السجل الرسمي.',
  supplementary_materialization_repeat_attempt_unsupported: 'سبق ترحيل هذه المحاولة من امتحان تكميلي آخر ولا تدعم السياسة محاولة تكميلية ثانية.',
  supplementary_materialization_source_drift: 'تغيّر مصدر النشر بعد اعتماده. أوقف الإجراء وراجع تقرير المطابقة.',
  supplementary_materialization_target_drift: 'تغيّرت النتيجة الرسمية بعد النشر. أوقف الإجراء وراجع تقرير المطابقة.',
  supplementary_materialization_idempotency_conflict: 'إثبات الترحيل الحالي لا يطابق الطلب. راجع تقرير المطابقة.',
  supplementary_materialization_target_conflict: 'السجل الرسمي لا يطابق لقطة الترحيل المحفوظة.',
  supplementary_materialization_terminal_conflict: 'الدورة النهائية لا تملك تغطية ترحيل متطابقة.',
  supplementary_materialization_terminal_event_conflict: 'حدث الإغلاق النهائي مفقود أو غير متطابق.',
  supplementary_materialization_period_source_conflict: 'أحد عروض الدورة لا يملك مصدراً أكاديمياً صحيحاً.',
  supplementary_reconciliation_out_of_scope: 'الدورة خارج نطاق البيانات المسموح.',
  supplementary_reconciliation_forbidden: 'لا تملك الدور والصلاحية الفعليين لعرض تقرير المطابقة.',
})

export function supplementaryErrorMessage(error, fallback = 'تعذر تنفيذ الطلب. حاول مجدداً.') {
  const mapped = ERROR_MESSAGES[error?.errorCode ?? error?.code]
  if (mapped) return mapped
  if (/[\u0600-\u06ff]/.test(String(error?.message ?? ''))) return error.message
  return fallback
}
