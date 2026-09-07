export const studentGridPath = id => `/exam-board/manual-grade-entry/students/${encodeURIComponent(id)}`
export const periodsPath = id => `/v1/exams/manual-grade-entry/students/${encodeURIComponent(id)}/periods`
export const catalogPath = (id, filters = {}) => `/v1/exams/manual-grade-entry/students/${encodeURIComponent(id)}/catalog?${new URLSearchParams(filters)}`
export const preparationPath = (student, offering, action) => `/v1/exams/manual-grade-entry/students/${encodeURIComponent(student)}/offerings/${encodeURIComponent(offering)}/${action}`
export const catalogLabel = course => ({ college_catalog: 'كتالوج الكلية', program_requirement: 'متطلب البرنامج / مشترك', existing_registration: 'تسجيل قائم خارج كتالوج الكلية' }[course.catalog_source] ?? 'مصدر غير متاح') + (course.own_program ? ' — ضمن برنامج الطالب' : ' — خارج برنامج الطالب الحالي')

export const preparationError = error => ({
  manual_academic_context_invalid: 'تعذر إثبات السياق الفعلي أو انتماء المقرر إلى المنهج الحالي للطالب. لا يُنشأ تسجيل خارج هذا السياق.',
  course_not_in_program: 'المقرر ليس ضمن المنهج النشط الحالي لبرنامج الطالب. الاستثناء لا يتجاوز هذا الشرط ولا يفترض خطة تاريخية.',
  course_already_passed: 'سبق للطالب النجاح رسميًا بهذا المقرر؛ لا يرفع الاستثناء هذا الحاجز.',
  supplementary_grade_configuration_locked: 'الطرح مرتبط بقائمة أو ترحيل تكميلي؛ تهيئة سياقه مقفلة.',
  manual_components_undefined: 'ساعات المقرر لا تعرّف أجزاء التدريس. راجع المسؤول المخول بتكوين المقرر؛ لم تُنشأ مكونات.',
  manual_components_incompatible: 'توجد مكونات جزئية أو اختيارية أو غير متوافقة. يلزم تصحيح صريح عبر تهيئة المكونات الحالية؛ لن تستبدلها هذه العملية.',
  grading_policy_incompatible: 'السياسة الحالية لا تدعم حدود هذه الأجزاء. راجع المسؤول المخول بسياسة العلامات؛ لا يوجد تقسيم افتراضي.',
  official_result_locked: 'الطرح مقفل للإرسال أو الاعتماد الرسمي؛ لا يمكن تجهيز سياق جديد أو إعادة فتح النتيجة.',
  manual_registration_ineligible: 'المحاولة القائمة غير مؤهلة؛ لا يمكن إعادة تصنيفها أو إنشاء محاولة بديلة ضمنيًا.',
  manual_registration_ambiguous: 'توجد محاولات متعددة. اختر المحاولة الموجودة صراحةً.',
  supplementary_fixed_roster_target_locked: 'الطرح مرتبط بقائمة تكميلية ثابتة؛ لا يمكن تغيير سياقه.',
}[error.errorCode] ?? null)

// Never silently pick one of several sections or academic attempts.
export function selectedContext(course, offeringId, registrationId) {
  const offering = offeringId ? course.offerings.find(o => String(o.course_offering_id) === String(offeringId))
    : course.offerings.length === 1 ? course.offerings[0] : null
  const attempts = offering?.registrations ?? []
  const registration = registrationId ? attempts.find(r => String(r.registration_id) === String(registrationId))
    : attempts.length === 1 ? attempts[0] : null
  return { offering, registration, attempts }
}
