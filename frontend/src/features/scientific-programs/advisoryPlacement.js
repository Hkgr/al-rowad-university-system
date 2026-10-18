/** Advisory placement is not offering availability or registration eligibility. */
export function advisoryPlacementValid({ course_type, academic_level_id: level, recommended_semester_id: semester }) {
  if (level == null || semester == null) return course_type === 'elective' && level == null && semester == null
  return [level, semester].every(id => id !== '' && Number.isSafeInteger(Number(id)) && Number(id) > 0)
}
