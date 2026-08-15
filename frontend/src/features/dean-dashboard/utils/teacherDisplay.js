const ACADEMIC_RANK_LABELS = {
  professor: 'أستاذ',
  associate_professor: 'أستاذ مشارك',
  assistant_professor: 'أستاذ مساعد',
  lecturer: 'محاضر',
  instructor: 'مدرس',
  'associate professor': 'أستاذ مشارك',
  'assistant professor': 'أستاذ مساعد',
}

export const ASSIGNMENT_TYPE_OPTIONS = [
  { value: 'theoretical', label: 'لديه نظري' },
  { value: 'practical', label: 'لديه عملي' },
  { value: 'both', label: 'نظري وعملي' },
  { value: 'unassigned', label: 'بدون تكليف حالي' },
]

export function normalizeSearchText(value) {
  return String(value ?? '')
    .toLowerCase()
    .normalize('NFKC')
    .replace(/[أإآٱ]/g, 'ا')
    .replace(/ة/g, 'ه')
    .replace(/ى/g, 'ي')
    .trim()
}

export function displayValue(value) {
  const text = String(value ?? '').trim()
  return text || '—'
}

export function fullTeacherName(teacher) {
  const employee = teacher?.employee
  if (!employee) return '—'
  const composed = `${employee.first_name ?? ''} ${employee.last_name ?? ''}`.trim()
  return composed || '—'
}

export function academicRankLabel(rank) {
  const raw = String(rank ?? '').trim()
  if (!raw) return '—'
  const key = raw.toLowerCase().replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim()
  const snakeKey = raw.toLowerCase().replace(/[\s-]+/g, '_')
  return ACADEMIC_RANK_LABELS[key]
    ?? ACADEMIC_RANK_LABELS[snakeKey]
    ?? raw
}

export function matchesAssignmentType(teacher, assignmentType) {
  const theoretical = Number(teacher?.theoretical_assignment_count) || 0
  const practical = Number(teacher?.practical_assignment_count) || 0
  const active = Number(teacher?.active_assignment_count) || 0

  if (assignmentType === 'theoretical') return theoretical > 0
  if (assignmentType === 'practical') return practical > 0
  if (assignmentType === 'both') return theoretical > 0 && practical > 0
  if (assignmentType === 'unassigned') return active === 0
  return true
}
