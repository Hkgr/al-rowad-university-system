export const REQUEST_STATUS_LABELS = {
  submitted: 'بانتظار الموافقة',
  returned: 'معاد للتعديل',
  approved: 'معتمد',
  superseded: 'مستبدل',
}

export const REVIEW_STATUS_LABELS = {
  pending: 'بانتظار الموافقة',
  approved: 'موافق',
  returned: 'معاد للتعديل',
}

export const EVENT_LABELS = {
  submitted: 'إرسال الطلب',
  resubmitted: 'إعادة الإرسال',
  scientific_approved: 'موافقة النائب العلمي',
  scientific_returned: 'إعادة علمية للعميد',
  administrative_approved: 'موافقة النائب الإداري',
  administrative_returned: 'إعادة إدارية للعميد',
  superseded: 'استبدال الطلب',
  effective_assignment_created: 'تفعيل التكليف',
  effective_assignment_changed: 'تغيير التكليف النافذ',
}

export const ROLE_LABELS = {
  theoretical: 'نظري',
  practical: 'عملي',
}

export function requestStatusLabel(status) {
  return REQUEST_STATUS_LABELS[status] || status || '—'
}

export function reviewStatusLabel(status) {
  return REVIEW_STATUS_LABELS[status] || status || '—'
}

export function eventLabel(type) {
  return EVENT_LABELS[type] || type || '—'
}

export function roleLabel(role) {
  return ROLE_LABELS[role] || role || '—'
}

export function facultyName(faculty) {
  if (!faculty) return '—'
  return faculty.full_name || '—'
}

export function formatDateTime(value) {
  if (!value) return '—'
  return String(value).replace('T', ' ').slice(0, 16)
}

export function offeringTitle(offering) {
  const course = offering?.course
  const codeName = [course?.course_code, course?.course_name].filter(Boolean).join(' — ')
  return codeName || '—'
}
