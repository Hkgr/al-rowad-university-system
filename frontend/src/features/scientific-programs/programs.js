import { apiRequest } from '../../services/apiClient.js'
import { canAccess, getIdentity } from '../auth/auth.js'

export const PROGRAM_ACCESS = Object.freeze({ allRoles: ['vice_president_scientific'], assignedPermissions: ['vice_presidency.scientific.access', 'academic_structure.view'], actualAcademicScope: true })
export const canViewPrograms = identity => canAccess(PROGRAM_ACCESS, identity)
export const PROGRAM_API = '/v1/vice-presidency/scientific/program-management'
export const SETUP_LABELS = { legacy: 'النظام السابق', preparing: 'تهيئة الخطط — القبول الجديد موقوف', ready: 'جاهز للطلاب الجدد' }
export const VERSION_LABELS = { draft: 'مسودة للتعديل', approved: 'خطة معتمدة', transitional: 'مرجع انتقالي — ليس اعتمادًا تاريخيًا' }
export const SCOPES = { university: 'متطلبات الجامعة', college: 'متطلبات الكلية', department: 'متطلبات القسم' }
export const TYPES = { mandatory: 'إجباري', elective: 'اختياري' }

export async function programRead(path, signal) {
  const identity = JSON.stringify(getIdentity())
  try {
    const r = await apiRequest(PROGRAM_API + path, { signal })
    if (!r?.success || !r.data || typeof r.data !== 'object') throw new Error('استجابة إدارة البرامج غير مكتملة.')
    return r.data
  } catch (e) {
    if ([401, 403].includes(e.status) && typeof window !== 'undefined' && identity === JSON.stringify(getIdentity())) window.dispatchEvent(new Event('scientific-program-denied'))
    throw e
  }
}
export async function programWrite(path, method, payload) {
  const r = await apiRequest(PROGRAM_API + path, { method, body: JSON.stringify(payload) })
  if (!r?.success || !r.data) throw new Error('لم يصل تأكيد العملية؛ راجع البيانات الرسمية قبل إعادة المحاولة.')
  return r.data
}
export function sixGroups(groups = []) {
  return Object.keys(SCOPES).flatMap(scope => Object.keys(TYPES).map(type => {
    const matching = groups.filter(g => g.requirement_scope === scope && g.requirement_type === type && g.is_active)
    const group = matching.length === 1 ? matching[0] : null
    return { requirement_scope: scope, requirement_type: type, required_credit_hours: group?.required_credit_hours ?? '', ambiguous: matching.length > 1 }
  }))
}
export const nullableHours = value => value === '' ? null : Number(value)
/** Arithmetic of the typed form only, not an academic approval or eligibility decision. */
export function budgetDraftSummary(draft) {
  const values = draft.groups.map(g => g.required_credit_hours)
  const complete = values.length === 6 && values.every(v => v !== '' && v !== null && Number.isInteger(Number(v)) && Number(v) >= 0)
  const sum = complete ? values.reduce((total, v) => total + Number(v), 0) : null
  const total = draft.total_credit_hours === '' || draft.total_credit_hours == null ? null : Number(draft.total_credit_hours)
  return { sum, total, difference: sum === null || total === null ? null : total - sum }
}
export function requirementsPayload(draft, revision) {
  return { revision, total_credit_hours: nullableHours(draft.total_credit_hours), groups: draft.groups.map(({ requirement_scope, requirement_type, required_credit_hours }) => ({ requirement_scope, requirement_type, required_credit_hours: nullableHours(required_credit_hours) })) }
}
