import { apiRequest } from '../../services/apiClient.js'
import { canAccess, getIdentity } from '../auth/auth.js'

export const CATALOG_ACCESS = Object.freeze({ allRoles: ['vice_president_scientific'],
  assignedPermissions: ['vice_presidency.scientific.access', 'vice_presidency.scientific.courses.view'], actualAcademicScope: true })
export const CATALOG_MANAGE = Object.freeze({ ...CATALOG_ACCESS, assignedPermissions: [...CATALOG_ACCESS.assignedPermissions, 'vice_presidency.scientific.courses.manage'] })
export const canViewCatalog = identity => canAccess(CATALOG_ACCESS, identity)
export const API = '/v1/vice-presidency/scientific/course-management'
export const SCOPES = { university: 'متطلبات الجامعة', college: 'متطلبات الكلية', department: 'متطلبات القسم', unclassified: 'غير مصنف / غير مرتبط ببرنامج' }
export const TYPES = { mandatory: 'إجباري', elective: 'اختياري', unclassified: 'غير مصنف' }
export function queryString(input) {
  return new URLSearchParams(Object.entries(input).filter(([, v]) => v !== '' && v !== null && v !== undefined).map(([k, v]) => [k, String(v)])).toString()
}
export async function catalogRead(path, signal) {
  const identity = JSON.stringify(getIdentity())
  try {
    const response = await apiRequest(API + path, { signal })
    if (!response?.success || !response.data || typeof response.data !== 'object') throw new Error('استجابة دليل المواد غير مكتملة.')
    return response.data
  } catch (error) {
    if ([401, 403].includes(error.status) && typeof window !== 'undefined' && identity === JSON.stringify(getIdentity())) window.dispatchEvent(new Event('scientific-catalog-denied'))
    throw error
  }
}
export async function catalogWrite(path, method, payload) {
  const response = await apiRequest(API + path, { method, body: JSON.stringify(payload) })
  if (!response?.success || !response.data) throw new Error('لم يصل تأكيد الحفظ؛ راجع النسخة الرسمية قبل المحاولة.')
  return response.data
}
// A display regrouping only: stable membership identity, never academic calculation.
export function groupedCourses(courses = [], order = 'scope') {
  const groups = new Map()
  for (const course of courses) {
    for (const pc of course.program_courses?.length ? course.program_courses : [null]) {
      const c = pc?.requirement_classification
      const scope = SCOPES[c?.requirement_scope] ? c.requirement_scope : 'unclassified'
      const type = TYPES[c?.requirement_type] ? c.requirement_type : 'unclassified'
      const key = `${scope}:${type}`
      if (!groups.has(key)) groups.set(key, { key, scope, type, rows: [] })
      groups.get(key).rows.push({ key: `${course.course_id}:${pc?.program_course_id ?? 'origin'}`, course, membership: pc })
    }
  }
  return [...groups.values()].sort((a, b) => {
    const keys = order === 'type' ? ['type', 'scope'] : ['scope', 'type']
    for (const k of keys) {
      const list = Object.keys(k === 'scope' ? SCOPES : TYPES), d = list.indexOf(a[k]) - list.indexOf(b[k])
      if (d) return d
    }
    return 0
  })
}
export function catalogError(error) {
  if (error?.status === 403) return 'لا تملك الصلاحية أو النطاق اللازم لهذا الإجراء.'
  if (error?.status === 409) return 'عدّل مستخدم آخر هذه البيانات أو تغيرت صلاحية تعديلها. راجع النسخة الحالية قبل الحفظ.'
  if (error?.status === 422) return 'راجع الحقول المشار إليها. لم يُعتمد هذا التعديل.'
  if (error?.status === 503) return 'الخدمة غير جاهزة حاليًا؛ راجع مسؤول النظام.'
  return error?.message || 'تعذر الاتصال بالخادم.'
}
export function editableCourse(snapshot) {
  const c = snapshot?.data || {}
  return { course_code: c.course_code || '', course_name: c.course_name || '', description: c.description || '',
    credit_hours: c.credit_hours ?? 1, theoretical_hours: c.theoretical_hours ?? '', practical_hours: c.practical_hours ?? '', is_active: c.is_active ?? true,
    departments: (c.course_departments || []).map(d => ({ department_id: d.department_id, is_primary: !!d.is_primary, label: d.department?.department_name || 'القسم الحالي' })),
    prerequisites: (c.course_prerequisites || []).map(p => ({ prerequisite_course_id: p.prerequisite_course_id, minimum_result_status_id: p.minimum_result_status_id ?? '', label: p.prerequisite_course?.course_name || 'المتطلب الحالي' })) }
}
export function coursePayload(draft, baseline) {
  const body = { revision: baseline.revision }
  const original = editableCourse(baseline)
  for (const field of ['course_code', 'course_name', 'description', 'credit_hours', 'theoretical_hours', 'practical_hours', 'is_active']) {
    if (!baseline.data?.course_id || String(draft[field]) !== String(original[field])) body[field] = ['credit_hours', 'theoretical_hours', 'practical_hours'].includes(field) ? (draft[field] === '' ? null : Number(draft[field])) : draft[field]
  }
  for (const field of ['departments', 'prerequisites']) if (JSON.stringify(draft[field]) !== JSON.stringify(original[field]) || !baseline.data?.course_id) {
    body[field] = draft[field].map(row => Object.fromEntries(Object.entries(row).filter(([k]) => !['label', 'statusLabel'].includes(k)).map(([k, v]) => [k, k === 'minimum_result_status_id' && v === '' ? null : v])))
  }
  return body
}
export function requestCounter() {
  let value = 0
  return { next: () => ++value, current: token => token === value, invalidate: () => { value++ } }
}
