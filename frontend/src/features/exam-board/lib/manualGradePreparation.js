import { markValue } from './manualGradeEntry.js'
export const contextPath = (student, course, action) => `/v1/exams/manual-grade-entry/students/${encodeURIComponent(student)}/courses/${encodeURIComponent(course)}/context-${action}`
export function preparationMarks(preview, values) {
  return Object.entries(values).map(([key, value]) => {
    const component = preview.components.find(c => c.key === key)
    const mark = markValue(value)
    if (!component || (mark !== null && mark > component.max_mark)) throw new Error('تجاوزت العلامة الحد الرسمي أو تغير المكوّن.')
    return { key, mark }
  })
}
export function rebasePreparationValues(before, after, values) {
  const mapped = {}
  for (const [key, value] of Object.entries(values)) {
    const source = before.components.find(c => c.key === key)
    const matches = after.components.filter(c => c.key === key || (c.component_type === source?.component_type && c.name === source?.name && c.max_mark === source?.max_mark))
    if (matches.length !== 1 || Object.hasOwn(mapped, matches[0].key)) return null
    mapped[matches[0].key] = value
  }
  return mapped
}
