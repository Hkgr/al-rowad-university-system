export const ISO_WEEKDAY_LABELS = Object.freeze({
  1: 'الاثنين',
  2: 'الثلاثاء',
  3: 'الأربعاء',
  4: 'الخميس',
  5: 'الجمعة',
  6: 'السبت',
  7: 'الأحد',
})

export const TIMETABLE_COMPONENT_LABELS = Object.freeze({
  theoretical: 'نظري',
  practical: 'عملي',
})

export function timetableSlotLabel(slot) {
  if (!slot) return ''
  const component = TIMETABLE_COMPONENT_LABELS[slot.component_type] || slot.component_type || '—'
  const day = ISO_WEEKDAY_LABELS[Number(slot.day_of_week)] || 'يوم غير معروف'
  const start = String(slot.start_time || '').slice(0, 5)
  const end = String(slot.end_time || '').slice(0, 5)
  const location = slot.location_label ? ` · ${slot.location_label}` : ''
  return `${component} · ${day} · ${start}–${end}${location}`
}

export function timetableStatusLabel(schedule) {
  if (schedule?.schema_ready !== true) return 'مخطط الجدول غير جاهز'
  if (schedule?.components_defined !== true) return 'مكونات التدريس غير محددة'
  if (schedule?.complete !== true) return 'الجدول غير مكتمل بعد'
  return 'الجدول مكتمل'
}

export function timetableLockedReason(reason) {
  return ({
    timetable_schema_not_ready: 'مخطط الجدول الرسمي غير جاهز',
    registration_calendar_schema_not_ready: 'مخطط تقويم التسجيل غير جاهز',
    course_registration_started: 'بدأت نافذة تسجيل الطلاب',
    student_registration_exists: 'يوجد تسجيل طلاب مرتبط بهذا الطرح',
    submitted_registration_request_exists: 'اعتمد الطلاب على الجدول ضمن طلب تسجيل مُرسل',
  })[reason] || null
}

export function timetableConflictLabel(conflict) {
  const other = conflict?.conflicting_with
  if (!other) return 'تعارض في الجدول'
  const day = ISO_WEEKDAY_LABELS[Number(other.day_of_week)] || 'يوم غير معروف'
  return `يتعارض مع ${other.course_code || other.course_name || 'مقرر آخر'} يوم ${day} من ${String(other.start_time || '').slice(0, 5)} إلى ${String(other.end_time || '').slice(0, 5)}`
}
