export const REGISTRATION_PHASES = Object.freeze({
  NOT_STARTED: 'not_started',
  STUDENT_OPEN: 'student_open',
  ADVISOR_REVIEW: 'advisor_review',
  CLOSED: 'closed',
  CONFIGURATION_ERROR: 'configuration_error',
})

export const REGISTRATION_PHASE_LABELS = Object.freeze({
  [REGISTRATION_PHASES.NOT_STARTED]: 'لم يبدأ التسجيل بعد',
  [REGISTRATION_PHASES.STUDENT_OPEN]: 'التسجيل متاح للطلاب',
  [REGISTRATION_PHASES.ADVISOR_REVIEW]: 'انتهت مهلة الطلاب — مراجعة المرشد مستمرة',
  [REGISTRATION_PHASES.CLOSED]: 'انتهت مهلة التسجيل والاعتماد',
  [REGISTRATION_PHASES.CONFIGURATION_ERROR]: 'إعداد فترة التسجيل غير متاح',
})

export function registrationPhaseLabel(calendar) {
  return REGISTRATION_PHASE_LABELS[calendar?.phase] ?? 'حالة فترة التسجيل غير متاحة'
}

export function studentRegistrationNotice(calendar, requestStatus) {
  if (requestStatus === 'expired') return 'انتهت المهلة دون اعتماد'
  if (requestStatus === 'returned' && calendar?.student_registration_open !== true) {
    return 'أعيد الطلب بعد انتهاء مهلة الطالب — لا يمكن تعديله أو إعادة إرساله'
  }
  if (requestStatus === 'submitted') return 'الطلب بانتظار المرشد الأكاديمي'
  if (calendar?.phase === REGISTRATION_PHASES.STUDENT_OPEN) {
    return calendar.student_registration_ends_at
      ? `التسجيل متاح حتى ${formatUniversityDateTime(calendar.student_registration_ends_at)}`
      : REGISTRATION_PHASE_LABELS.student_open
  }

  return registrationPhaseLabel(calendar)
}

export function advisorActionsVisible(request) {
  return request?.status === 'submitted'
    && request?.registration_calendar?.advisor_decision_open === true
}

export function formatUniversityDateTime(value) {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  return new Intl.DateTimeFormat('ar-SY', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Damascus',
  }).format(date)
}
