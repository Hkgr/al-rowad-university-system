// Presentation only: no GPA, repeat selection, withdrawal, or deadline calculation.
export const missingValue = value => value === null || value === undefined || value === '' ? '—' : String(value)
export function resultSymbol(course) {
  const lifecycle = course.result_lifecycle
  const state = lifecycle?.state
  const code = course.result_status?.status_code ?? course.result_status?.code ?? course.status
  if (state === 'incomplete_active') return 'I'
  if (code === 'withdrawn') return 'W'
  if (code === 'deprived') return 'Z'
  if (code === 'incomplete') return 'I'
  return course.letter_grade || '—'
}
export function resultDescription(course) {
  const state = course.result_lifecycle?.state
  if (state === 'incomplete_active') return 'I نشطة موثقة — خارج المعدل والساعات المكتسبة'
  if (state === 'incomplete_resolved') return 'نتيجة مسوّاة — لها تاريخ I موثق'
  if (state === 'incomplete_expired') return 'نتيجة انتهاء المهلة — لها تاريخ I موثق'
  const code = course.result_status?.status_code ?? course.result_status?.code ?? course.status
  if (code === 'withdrawn') return 'انسحاب W — خارج المعدل'
  if (code === 'incomplete') return 'نقص علامات غير موثق كمنحة I'
  if (code === 'pending_approval') return 'بانتظار الاعتماد — ليست نتيجة رسمية'
  return course.result_status?.status_name ?? course.result_status?.name ?? (course.letter_grade ? 'نتيجة حسب حالة الاعتماد المعروضة' : 'لا توجد نتيجة')
}
export const termWithdrawalText = term => term.withdrawal_status === 'full_withdrawal' ? `فصل منسحب بالكامل${term.counts_towards_maximum_study_duration ? '؛ يُحتسب ضمن مدة الدراسة بحسب السجل' : ''}.` : ''
