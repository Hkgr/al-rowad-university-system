export const studentGridPath = id => `/exam-board/manual-grade-entry/students/${encodeURIComponent(id)}`
export const periodsPath = id => `/v1/exams/manual-grade-entry/students/${encodeURIComponent(id)}/periods`
export const catalogPath = (id, filters = {}) => `/v1/exams/manual-grade-entry/students/${encodeURIComponent(id)}/catalog?${new URLSearchParams(filters)}`
export const preparationPath = (student, offering, action) => `/v1/exams/manual-grade-entry/students/${encodeURIComponent(student)}/offerings/${encodeURIComponent(offering)}/${action}`
export const catalogLabel = course => ({ college_catalog: 'كتالوج الكلية', program_requirement: 'متطلب البرنامج / مشترك', existing_registration: 'تسجيل قائم خارج كتالوج الكلية' }[course.catalog_source] ?? 'مصدر غير متاح') + (course.own_program ? ' — ضمن برنامج الطالب' : ' — خارج برنامج الطالب الحالي')

export const preparationError = error => ({
  manual_academic_context_invalid: 'هوية المقرر أو برنامج الطالب أو فترة العلامات غير متوافقة.',
  manual_recording_relationship_missing: 'لا يوجد ارتباط برنامج محفوظ أو محاولة سابقة تربط المقرر ببرنامج الطالب. يلزم دليل أكاديمي محفوظ؛ لا تُخترع خطة تاريخية.',
  supplementary_grade_configuration_locked: 'الطرح مرتبط بقائمة أو ترحيل تكميلي؛ تهيئة سياقه مقفلة.',
  manual_components_undefined: 'ساعات المقرر لا تعرّف أجزاء التدريس. راجع المسؤول المخول بتكوين المقرر؛ لم تُنشأ مكونات.',
  manual_components_incompatible: 'توجد مكونات جزئية أو اختيارية أو غير متوافقة. يلزم تصحيح صريح عبر تهيئة المكونات الحالية؛ لن تستبدلها هذه العملية.',
  grading_policy_incompatible: 'السياسة الحالية لا تدعم حدود هذه الأجزاء. راجع المسؤول المخول بسياسة العلامات؛ لا يوجد تقسيم افتراضي.',
  official_result_locked: 'الطرح مقفل للإرسال أو الاعتماد الرسمي؛ لا يمكن تجهيز سياق جديد أو إعادة فتح النتيجة.',
  manual_registration_ineligible: 'المحاولة القائمة غير مؤهلة؛ لا يمكن إعادة تصنيفها أو إنشاء محاولة بديلة ضمنيًا.',
  manual_registration_ambiguous: 'توجد محاولات متعددة. اختر المحاولة الموجودة صراحةً.',
  supplementary_fixed_roster_target_locked: 'الطرح مرتبط بقائمة تكميلية ثابتة؛ لا يمكن تغيير سياقه.',
}[error.errorCode] ?? null)

// Semantic query identity, deliberately independent of refresh count and row revisions.
export function catalogRequestKey({ studentId, identity, historyMode, term, search, page }) {
  return JSON.stringify([String(studentId), identity, historyMode ? 'history' : 'recording',
    String(term.academic_year_id ?? ''), String(term.semester_id ?? ''), search, Number(page)])
}

// Never silently pick one of several sections or academic attempts.
export function selectedContext(course, offeringId, registrationId) {
  // The API already excludes other-program contexts without a student's own attempt.
  const withAttempts = course.offerings.filter(o => o.registrations?.length)
  const choices = withAttempts.length ? withAttempts : course.offerings
  const offering = offeringId ? choices.find(o => String(o.course_offering_id) === String(offeringId))
    : choices.length === 1 ? choices[0] : null
  const attempts = offering?.registrations ?? []
  const registration = registrationId ? attempts.find(r => String(r.registration_id) === String(registrationId))
    : attempts.length === 1 ? attempts[0] : null
  return { offering, registration, attempts, choices }
}
