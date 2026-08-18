import { academicRankLabel, displayValue, offeringStatusLabel } from './teacherDisplay'

export const TEACHER_ASSIGNMENT_FILTER_OPTIONS = [
  { value: 'fully_assigned', label: 'تكليف مكتمل' },
  { value: 'partially_assigned', label: 'تكليف جزئي' },
  { value: 'unassigned', label: 'بدون تكليف' },
]

export const OFFERING_STATUS_FILTER_FALLBACK = [
  { value: 'open', label: 'مفتوح' },
  { value: 'closed', label: 'مغلق' },
  { value: 'cancelled', label: 'ملغى' },
]

export function formatAverageMark(value) {
  if (value === null || value === undefined || value === '') return '—'
  const number = Number(value)
  if (!Number.isFinite(number)) return '—'
  return number.toLocaleString('ar-SY', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  })
}

export function teacherSlotLabel(slot) {
  if (!slot?.available) return 'غير موجود'
  if (!slot.faculty_member_id && !slot.full_name) return 'بدون مدرس'
  return displayValue(slot.full_name)
}

export function teacherSlotRank(slot) {
  if (!slot?.available || !slot.academic_rank) return ''
  const label = academicRankLabel(slot.academic_rank)
  return label === '—' ? '' : label
}

export function offeringCodeName(offering) {
  const course = offering?.course
  return [course?.course_code, course?.course_name].filter(Boolean).join(' — ') || 'مادة مطروحة'
}

export function statusBadgeClass(status) {
  if (status === 'open') return 'bg-green-500/10 text-green-700'
  if (status === 'closed') return 'bg-slate-500/10 text-slate-600'
  if (status === 'cancelled') return 'bg-red-500/10 text-red-600'
  return 'bg-primary/8 text-primary-dark'
}

export function offeringStatusText(status) {
  return offeringStatusLabel(status)
}

export const INSTRUCTOR_ROLE_LABELS = {
  theoretical: 'النظري',
  practical: 'العملي',
}

export function instructorCoverageSummary(coverage) {
  if (!coverage) return '—'
  if (coverage.components_defined === false) {
    return 'مكونات التدريس غير محددة'
  }
  if (coverage.complete) return 'مكتمل'

  const missing = Array.isArray(coverage.missing_roles) ? coverage.missing_roles : []
  const hasTheory = missing.includes('theoretical')
  const hasPractical = missing.includes('practical')
  if (hasTheory && hasPractical) return 'ناقص مدرس النظري والعملي'
  if (hasTheory) return 'ناقص مدرس النظري'
  if (hasPractical) return 'ناقص مدرس العملي'
  return 'غير مكتمل'
}

export function instructorCoverageComplete(coverage) {
  return Boolean(coverage?.complete)
}

export function instructorRoleTeacherName(coverage, role) {
  const name = coverage?.roles?.[role]?.name
  return name && String(name).trim() !== '' ? name : null
}

export function registrationStatusLabel(status) {
  const code = String(status?.status_code ?? '').toLowerCase()
  if (code === 'registered') return status?.status_name || 'مسجّل'
  if (code === 'dropped') return status?.status_name || 'منسحب'
  if (code === 'withdrawn') return status?.status_name || 'منسحب إداري'
  if (code === 'completed') return status?.status_name || 'مكتمل'
  return status?.status_name || displayValue(status?.status_code)
}

export function resultStatusLabel(status) {
  if (!status) return '—'
  return status.status_name || displayValue(status.status_code)
}
