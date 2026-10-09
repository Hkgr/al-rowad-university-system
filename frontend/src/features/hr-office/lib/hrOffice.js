import { canAccess } from '../../auth/auth.js'

export const HR = Object.freeze({ view: 'administrative_hr.view', recruit: 'administrative_hr.recruitment.manage', classify: 'administrative_hr.classification.manage', prepare: 'administrative_hr.relationships.prepare', review: 'administrative_hr.relationships.review', correct: 'administrative_hr.relationships.correct', cancel: 'administrative_hr.relationships.cancel', workerExport: 'administrative_hr.workers.export', payrollAccess: 'administrative_hr.payroll.access', payrollLink: 'administrative_hr.payroll.link' })
const roles = ['hr_officer', 'vice_president_administrative']
export const hrAccess = permission => ({ anyAccess: roles.map(role => ({ allRoles: [role], assignedPermissions: [permission], actualScopeTypes: ['university', 'college'] })) })
export const payrollAccess = permission => ({ anyAccess: [...roles, 'finance_officer'].map(role => ({ allRoles: [role], assignedPermissions: [HR.payrollAccess, permission], actualUniversityScope: true })) })
export const canHr = (key, identity) => canAccess(['review', 'correct', 'cancel'].includes(key) ? { allRoles: ['vice_president_administrative'], assignedPermissions: [HR[key]], actualUniversityScope: true } : hrAccess(HR[key]), identity)
export const SECTIONS = Object.freeze([['workers', 'العاملون'], ['needs', 'الاحتياجات'], ['candidates', 'المرشحون والمقابلات'], ['requests', 'طلبات الاعتماد'], ['relationships', 'العقود والتوظيف'], ['classification', 'استكمال التصنيف']])
export const labels = Object.freeze({ educational: 'هيئة تعليمية', administrative: 'هيئة إدارية', temporary_contract: 'تعاقد مؤقت — ثلاثة أشهر', continuous_contract: 'تعاقد مستمر', employment: 'توظيف', full: 'كلي', part: 'جزئي', draft: 'مسودة', submitted: 'بانتظار الاعتماد', returned: 'معاد للتعديل', approved: 'معتمد', rejected: 'مرفوض', candidate: 'مرشح', proposed: 'قبول مقترح', accepted: 'مقبول بعلاقة معتمدة', declined: 'اعتذار', open: 'مفتوح', closed: 'مغلق', scheduled: 'مجدولة', completed: 'مكتملة', cancelled: 'ملغاة', legacy_classification: 'توثيق البيانات القائمة — دون اعتماد تاريخي', approved_request: 'علاقة معتمدة', issue: 'إصدار علاقة', accept: 'قبول مرشح', renew: 'تجديد', convert: 'تحويل' })
export const label = value => labels[value] || value || 'غير محدد'
export const payrollFilePath = id => `/vp/administrative/payroll?payroll_employee_id=${encodeURIComponent(id)}`
export const workerFilePath = id => `/vp/administrative/hr/workers/${encodeURIComponent(id)}`
export const PAYMENT_MANAGE = 'owner_payroll.payments.manage'
export function hrDate(value) {
  if (!value) return 'غير محدد'
  const dateOnly = /^\d{4}-\d{2}-\d{2}$/.test(value)
  const date = new Date(dateOnly ? `${value}T12:00:00` : value.replace(' ', 'T'))
  if (Number.isNaN(date.getTime())) return 'غير محدد'
  return new Intl.DateTimeFormat('ar-SY', { year: 'numeric', month: 'short', day: 'numeric', ...(dateOnly ? {} : { hour: '2-digit', minute: '2-digit' }) }).format(date)
}
export const blankProposal = () => ({ body: '', relationship_type: '', work_mode: '', starts_on: '', ends_on: '', job_title: '', college_id: '', organizational_unit_id: '', position_id: '', notes: '', employee_number: '', employee_type_id: '', academic_rank: '', specialization: '', predecessor_id: '' })
export function proposalPayload(form) {
  const p = { ...form }
  for (const key of ['college_id', 'organizational_unit_id', 'position_id', 'employee_type_id', 'predecessor_id']) p[key] = p[key] ? Number(p[key]) : null
  for (const key of ['ends_on', 'notes', 'employee_number', 'academic_rank', 'specialization']) p[key] = p[key]?.trim() || null
  if (p.body === 'educational') p.organizational_unit_id = null
  else p.college_id = null
  return p
}
export function requestEditable(request) { return ['draft', 'returned'].includes(request?.status) && !request?.materialized_at }
export function changeIntent(current, next, dirty, pending) { return current === next ? 'noop' : pending ? 'blocked' : dirty ? 'confirm' : 'change' }
export function responseCurrent(sequence, current, identity, requested) { return sequence === current && identity === requested }
export function hrError(error) {
  if (error.status === 401) return 'انتهت الجلسة. سجل الدخول مجددًا.'
  if (error.status === 403) return 'لا تملك الصلاحية أو النطاق المطلوب. لم تتغير البيانات.'
  if (error.status === 409) return 'تغيرت البيانات أو لم يعد الإجراء متاحًا. احتفظنا بالمدخلات؛ راجع السجل الحالي قبل طلب جديد.'
  if (error.status === 422) return error.message || 'راجع المدخلات والتواريخ والحقول المطلوبة.'
  if (error.status === 503) return ['hr_pdf_not_ready', 'payroll_payments_not_ready', 'hr_relationship_actions_not_ready'].includes(error.errorCode) ? error.message : 'مخطط الموارد البشرية غير جاهز؛ الخدمات القديمة لم تتغير.'
  return 'تعذر التأكد من نتيجة الحفظ. لا تعاود الطلب تلقائيًا؛ افتح السجل الحالي وتحقق أولًا.'
}
