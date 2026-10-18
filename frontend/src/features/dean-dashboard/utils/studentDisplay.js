const YEAR_ORDINALS_AR = ['الأولى', 'الثانية', 'الثالثة', 'الرابعة', 'الخامسة', 'السادسة', 'السابعة']

export const STUDENT_STATUS_BY_CODE = {
  active: { ar: 'يدرس حاليًا', color: '#22c55e', bg: 'rgba(34,197,94,0.1)' },
  frozen: { ar: 'مجمّد', color: '#3b82f6', bg: 'rgba(59,130,246,0.1)' },
  graduated: { ar: 'خريج', color: '#8b5cf6', bg: 'rgba(139,92,246,0.1)' },
  withdrawn: { ar: 'منسحب', color: '#f59e0b', bg: 'rgba(245,158,11,0.1)' },
  dismissed: { ar: 'مفصول', color: '#ef4444', bg: 'rgba(239,68,68,0.1)' },
  suspended: { ar: 'موقوف', color: '#f97316', bg: 'rgba(249,115,22,0.1)' },
  deceased: { ar: 'متوفى', color: '#64748b', bg: 'rgba(100,116,139,0.12)' },
}

// Known production/seed IDs. Prefer status_code whenever the API returns it.
export const STUDENT_STATUS_BY_ID = {
  1: STUDENT_STATUS_BY_CODE.active,
  2: STUDENT_STATUS_BY_CODE.frozen,
  3: STUDENT_STATUS_BY_CODE.graduated,
  4: STUDENT_STATUS_BY_CODE.withdrawn,
  5: STUDENT_STATUS_BY_CODE.dismissed,
  6: STUDENT_STATUS_BY_CODE.suspended,
  12: STUDENT_STATUS_BY_CODE.deceased,
}

export const STUDENT_STATUS_FILTER_OPTIONS = [
  { value: '1', code: 'active', label: STUDENT_STATUS_BY_CODE.active.ar },
  { value: '2', code: 'frozen', label: STUDENT_STATUS_BY_CODE.frozen.ar },
  { value: '3', code: 'graduated', label: STUDENT_STATUS_BY_CODE.graduated.ar },
  { value: '4', code: 'withdrawn', label: STUDENT_STATUS_BY_CODE.withdrawn.ar },
  { value: '5', code: 'dismissed', label: STUDENT_STATUS_BY_CODE.dismissed.ar },
  { value: '6', code: 'suspended', label: STUDENT_STATUS_BY_CODE.suspended.ar },
  { value: '12', code: 'deceased', label: STUDENT_STATUS_BY_CODE.deceased.ar },
]

export function arabicYearLabel(order) {
  const numericOrder = Number(order)
  if (!Number.isFinite(numericOrder) || numericOrder < 1) return null
  const word = YEAR_ORDINALS_AR[numericOrder - 1]
  return word ? `السنة ${word}` : `السنة ${numericOrder}`
}

export function genderLabel(gender) {
  if (gender === 'male') return 'ذكر'
  if (gender === 'female') return 'أنثى'
  if (!gender) return '—'
  return String(gender)
}

export function formatDate(value, options) {
  if (value === null || value === undefined || value === '') return '—'
  const date = value instanceof Date ? value : new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  return date.toLocaleDateString('ar-SY', options)
}

export function academicLevelLabel(level) {
  if (!level) return null
  return arabicYearLabel(level.level_order ?? level.order) ?? level.level_name ?? level.name ?? null
}

export function fullStudentName(student) {
  if (!student) return '—'
  if (student.full_name?.trim()) return student.full_name.trim()
  const composed = `${student.first_name ?? ''} ${student.last_name ?? ''}`.trim()
  return composed || '—'
}

export function normalizeSearchText(value) {
  return String(value ?? '')
    .toLowerCase()
    .normalize('NFKC')
    .replace(/[أإآٱ]/g, 'ا')
    .replace(/ة/g, 'ه')
    .replace(/ى/g, 'ي')
    .trim()
}

export function resolveStudentStatus(input = {}) {
  const nested = input.student_status && typeof input.student_status === 'object'
    ? input.student_status
    : null

  const statusCode = input.status_code ?? nested?.status_code ?? null
  const statusId = input.student_status_id ?? nested?.student_status_id ?? null
  const statusName = input.status_name ?? nested?.status_name ?? null

  if (statusCode && STUDENT_STATUS_BY_CODE[statusCode]) {
    return { ...STUDENT_STATUS_BY_CODE[statusCode], code: statusCode, id: statusId }
  }

  if (statusId != null && STUDENT_STATUS_BY_ID[statusId]) {
    return { ...STUDENT_STATUS_BY_ID[statusId], code: statusCode, id: statusId }
  }

  if (statusName) {
    return {
      ar: statusName,
      color: '#64748b',
      bg: 'rgba(100,116,139,0.1)',
      code: statusCode,
      id: statusId,
    }
  }

  return null
}

export function studentStatusLabel(input) {
  return resolveStudentStatus(input)?.ar ?? '—'
}
