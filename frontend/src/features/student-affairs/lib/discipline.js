import { apiRequest } from '../../../services/apiClient.js'

export const DISCIPLINE_ACCESS = { anyAccess: [
  { allRoles: ['registration_officer'], assignedPermissions: ['students.view'], actualAcademicScope: true },
  { allRoles: ['vice_president_administrative'], permissions: ['students.view'], actualUniversityScope: true },
] }
export const disciplineTitle = 'الإنذارات والعقوبات'
export const caseStates = { active: 'نافذة', appealed: 'قيد الطعن', overturned: 'ملغاة بقبول الطعن', served: 'منفّذة', expired: 'منتهية' }
export const appealStates = { submitted: 'مقدّم', accepted: 'مقبول', rejected: 'مرفوض' }
export const authorities = { instructor: 'المدرّس', dean_or_institute_director: 'العميد / مدير المعهد', university_president: 'رئيس الجامعة', disciplinary_council: 'مجلس التأديب' }
export function disciplineDate(value) {
  if (!value) return 'غير مسجل'
  const date = String(value).slice(0, 10)
  return /^\d{4}-\d{2}-\d{2}$/.test(date) ? date.split('-').reverse().join('/') : 'غير مسجل'
}
export async function disciplineRead(path, signal) {
  const json = await apiRequest('/v1/' + path, { signal })
  if (json?.success !== true || json.data == null) throw new Error('تعذرت قراءة السجل. أعد المحاولة.')
  return json.data
}
export async function disciplineWrite(path, body, expected) {
  const json = await apiRequest('/v1/' + path, { method: 'POST', body: JSON.stringify(body) })
  const data = json?.data
  const id = Number(data?.[expected.appeal ? 'appeal_id' : 'case_id'])
  if (json?.success !== true || !Number.isSafeInteger(id) || id < 1
    || (expected.studentId && Number(data?.student_id) !== Number(expected.studentId))
    || (expected.caseId && Number(data?.case_id) !== Number(expected.caseId))
    || (expected.appealId && id !== Number(expected.appealId))) {
    const error = new Error('لم يصل تأكيد حفظ صالح. راجع السجل الحالي قبل أي إجراء آخر.')
    error.uncertain = true
    throw error
  }
  return data
}
export function disciplineError(error) {
  if (error.status === 403) return 'ليس لديك صلاحية لهذا السجل أو أنه خارج نطاقك.'
  if (error.status === 401) return 'انتهت الجلسة. أعد تسجيل الدخول.'
  if (error.status === 404) return 'السجل غير متاح ضمن نطاقك الحالي.'
  if (error.status === 503) return 'سجل العقوبات غير متاح؛ يلزم التحقق من جاهزية الخدمة على الخادم.'
  if (/[\u0600-\u06ff]/.test(error.message || '')) return error.message
  if (error.status === 409) return 'تغيرت حالة السجل. راجع البيانات الحالية قبل متابعة العمل.'
  if (error.status === 422) return 'تعذر حفظ العملية. تحقق من الحقول ومن شروط العقوبة والمقرر؛ لا تُغيّر علامات أو تواريخ لتجاوز المنع.'
  return 'تعذر الاتصال بالخادم. أعد محاولة القراءة.'
}
export const isUncertainDisciplineWrite = error => error.uncertain || !error.status || error.status >= 500
