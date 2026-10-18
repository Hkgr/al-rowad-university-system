export const STUDENT_PICKER_PER_PAGE = 25
export const STUDENT_PICKER_DEBOUNCE_MS = 350

export function studentPickerSearchPath(query = '') {
  const params = new URLSearchParams({
    page: '1',
    per_page: String(STUDENT_PICKER_PER_PAGE),
  })
  const normalized = String(query).trim()
  if (normalized) params.set('q', normalized)

  return `/v1/students?${params.toString()}`
}

export function isLatestStudentPickerRequest(sequence, currentSequence) {
  return sequence === currentSequence
}
