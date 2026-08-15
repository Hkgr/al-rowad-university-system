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

export function teacherInitials(name) {
  const parts = String(name ?? '')
    .replace('—', ' ')
    .trim()
    .split(/\s+/)
    .filter(Boolean)

  if (parts.length === 0) return 'م'
  if (parts.length === 1) return parts[0].slice(0, 1)
  return `${parts[0].slice(0, 1)}${parts[parts.length - 1].slice(0, 1)}`
}

export function sessionTypeLabel(type) {
  const value = String(type ?? '').toLowerCase().trim()
  if (value === 'theoretical' || value === 'lecture') return 'نظري'
  if (value === 'practical') return 'عملي'
  return displayValue(type)
}

export function offeringStatusLabel(status) {
  if (status === 'open') return 'مفتوح'
  if (status === 'closed') return 'مغلق'
  return displayValue(status)
}

export function assignmentStatusLabel(isActive) {
  return isActive ? 'نشط' : 'غير نشط'
}

export function formatDisplayDate(value) {
  if (value === null || value === undefined || value === '') return '—'
  const date = value instanceof Date ? value : new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  return date.toLocaleDateString('ar-SY')
}

export function formatClockRange(start, end) {
  const startText = String(start ?? '').trim()
  const endText = String(end ?? '').trim()
  if (startText && endText) return `${startText} – ${endText}`
  return startText || endText || '—'
}

export function componentState(hours, slot) {
  if ((Number(hours) || 0) <= 0) {
    return { kind: 'absent', label: 'غير موجود', title: 'المقرر لا يحتوي هذا الشق' }
  }
  if (slot) {
    return slot.is_active
      ? { kind: 'assigned', label: 'نعم', title: 'هذا المدرس مكلف بهذا الشق' }
      : { kind: 'inactive', label: 'غير نشط', title: 'تكليف غير نشط لهذا الشق' }
  }
  return { kind: 'unassigned', label: 'غير مكلف', title: 'المقرر يحتوي هذا الشق ولكن هذا المدرس ليس مكلفًا به' }
}

export function groupAssignmentsByOffering(rows) {
  const groups = []
  const indexByOffering = new Map()

  rows.forEach(row => {
    const offeringId = row?.course_offering?.course_offering_id
    if (offeringId == null) return

    if (!indexByOffering.has(offeringId)) {
      indexByOffering.set(offeringId, groups.length)
      groups.push({
        course_offering_id: offeringId,
        offering: row.course_offering,
        theoretical: null,
        practical: null,
        slots: [],
      })
    }

    const group = groups[indexByOffering.get(offeringId)]
    group.slots.push(row)
    if (row.instructor_role === 'theoretical') group.theoretical = row
    if (row.instructor_role === 'practical') group.practical = row
  })

  return groups
}

export function ownedRoleBadge(group) {
  const hasTheoretical = Boolean(group?.theoretical)
  const hasPractical = Boolean(group?.practical)
  if (hasTheoretical && hasPractical) return 'كلاهما'
  if (hasTheoretical) return 'نظري'
  if (hasPractical) return 'عملي'
  return '—'
}

function timelineInstant(value) {
  if (!value) return 0
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? 0 : date.getTime()
}

export function buildTeacherTimeline(assignments = [], sessions = []) {
  const events = []

  assignments.forEach(assignment => {
    const course = assignment.course_offering?.course
    const yearName = assignment.course_offering?.academic_year?.year_name
    const semesterName = assignment.course_offering?.semester?.semester_name
    const courseLabel = [course?.course_code, course?.course_name].filter(Boolean).join(' — ') || 'مقرر'
    const roleLabel = sessionTypeLabel(assignment.instructor_role)
    const termLabel = [yearName, semesterName].filter(Boolean).join(' / ')
    const description = [courseLabel, roleLabel, termLabel].filter(Boolean).join(' • ')

    events.push({
      type: 'assignment_created',
      date: assignment.created_at,
      title: 'تم إنشاء تكليف تدريسي',
      description,
      metadata: {
        role: assignment.instructor_role,
        course_offering_id: assignment.course_offering?.course_offering_id,
      },
    })

    const createdAt = timelineInstant(assignment.created_at)
    const updatedAt = timelineInstant(assignment.updated_at)
    if (updatedAt - createdAt >= 60_000) {
      events.push({
        type: 'assignment_updated',
        date: assignment.updated_at,
        title: 'آخر تحديث للتكليف',
        description,
        metadata: {
          role: assignment.instructor_role,
          course_offering_id: assignment.course_offering?.course_offering_id,
        },
      })
    }
  })

  sessions.forEach(session => {
    const courseLabel = [session.course?.course_code, session.course?.course_name].filter(Boolean).join(' — ') || 'مقرر'
    events.push({
      type: session.session_type === 'practical' ? 'session_practical' : 'session_theoretical',
      date: session.session_date
        ? `${session.session_date}${session.start_time ? `T${session.start_time}` : ''}`
        : session.created_at,
      title: session.session_type === 'practical' ? 'جلسة عملي' : (
        session.session_type === 'theoretical' || session.session_type === 'lecture'
          ? 'جلسة نظري'
          : `جلسة ${displayValue(session.session_type)}`
      ),
      description: [courseLabel, formatDisplayDate(session.session_date), formatClockRange(session.start_time, session.end_time)]
        .filter(value => value && value !== '—')
        .join(' • '),
      metadata: {
        session_type: session.session_type,
        attendance_session_id: session.attendance_session_id,
      },
    })
  })

  return events.sort((a, b) => timelineInstant(b.date) - timelineInstant(a.date))
}
