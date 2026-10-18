import { SCOPES, TYPES, queryString } from './catalog.js'

export const emptyDistribution = () => ({ scope: '', college: null, department: null, course_type: 'mandatory', level: null, semester: null, draftSelections: {} })
export const distributionPath = value => {
  const scope = distributionScope(value)
  if (!scope) return null
  const { draft_version_ids = [], ...context } = scope
  const query = new URLSearchParams(queryString(context))
  draft_version_ids.forEach(id => query.append('draft_version_ids[]', id))
  return `/distribution-preview?${query}`
}

export function classificationLabel(pc) {
  const c = pc?.requirement_classification
  return `${SCOPES[c?.requirement_scope] || 'غير مصنف'} — ${TYPES[c?.requirement_type] || TYPES[pc?.course_type] || 'غير محدد'}`
}

/** Distinct visible identities, not counts of duplicate program memberships or teaching slots. */
export function courseAssociations(course) {
  const departments = new Map(), colleges = new Map(), instructors = new Map()
  const add = d => {
    if (!d?.department_id) return
    departments.set(String(d.department_id), d)
    if (d.college?.college_id) colleges.set(String(d.college.college_id), d.college)
  }
  for (const row of course.course_departments || []) add(row.department && { department_id: row.department_id, ...row.department })
  for (const pc of course.program_courses || []) add(pc.academic_program?.department)
  for (const row of course.instructors || []) if (row.faculty_member_id) {
    const key = String(row.faculty_member_id), previous = instructors.get(key)
    instructors.set(key, { ...row, links: [...(previous?.links || []), { primary: !!Number(row.is_primary), active: !!Number(row.is_active) }] })
  }
  return { colleges: [...colleges.values()], departments: [...departments.values()], instructors: [...instructors.values()],
    classifications: [...new Set((course.program_courses || []).map(classificationLabel))] }
}

export function distributionScope(value) {
  if (!['university', 'college', 'department'].includes(value.scope)) return null
  if (value.scope !== 'university' && !value.college?.id) return null
  if (value.scope === 'department' && !value.department?.id) return null
  return { scope: value.scope, course_type: value.course_type,
    ...(Object.values(value.draftSelections || {}).filter(Boolean).length ? { draft_version_ids: Object.values(value.draftSelections).filter(Boolean).map(Number) } : {}),
    ...(value.scope !== 'university' ? { college_id: Number(value.college.id) } : {}),
    ...(value.scope === 'department' ? { department_id: Number(value.department.id) } : {}) }
}
