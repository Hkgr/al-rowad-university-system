export const EMAIL_API = '/v1/technical/university-email'
export function normalizeEnglishName(value) { return value.trim().toLowerCase() }
export function previewAddress(name, studentNumber, domain) {
  const normalized = normalizeEnglishName(name)
  const number = String(studentNumber || '').trim().toLowerCase()
  if (!/^[a-z]+$/.test(normalized) || !/^[a-z0-9]+$/.test(number) || domain !== 'alrowaduni.edu.sy') return null
  const local = `${normalized}.${number}`
  const email = `${local}@${domain}`
  return local.length <= 64 && email.length <= 254 ? email : null
}
export function draftPayload(name, baseline) { return { english_first_name: name, revision: baseline?.revision ?? 0 } }
export function emailFailure(error) {
  if (error?.status === 401 || error?.status === 403) return 'انتهى الوصول المصرح؛ لا يمكن عرض البيانات أو حفظها.'
  if (error?.status === 409) return 'تعارض الحفظ. بقيت قيمك المقترحة؛ راجع النسخة المحفوظة ثم اختر صراحةً كيفية المتابعة.'
  if (error?.status === 422) return Object.values(error.details || {}).flat().join(' ') || 'تحقق من الاسم الإنكليزي وطول العنوان.'
  if (error?.status >= 500 || error?.status === 408) return 'تعذر تأكيد نتيجة الحفظ من الخادم. راجع النسخة المحفوظة قبل أي محاولة جديدة؛ بقيت قيمك المقترحة.'
  if (!error?.status) return 'لم يصل تأكيد الحفظ. قد تكون العملية نجحت؛ راجع النسخة المحفوظة قبل المحاولة التالية. لن نعيد الإرسال تلقائيًا.'
  return error?.message || 'تعذر تنفيذ الطلب؛ أعد المحاولة.'
}
export function requiresReview(error) { return !error?.status || error.status === 409 || error.status === 408 || error.status >= 500 }
export function requestSequence() {
  let current = 0
  return { next: () => ++current, invalidate: () => ++current, accepts: value => value === current }
}
