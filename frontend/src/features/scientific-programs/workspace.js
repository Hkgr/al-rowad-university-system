import { CATALOG_ACCESS } from '../scientific-courses/catalog.js'
import { PROGRAM_ACCESS, SCOPES, TYPES } from './programs.js'

export const WORKSPACE_ACCESS = { anyAccess: [PROGRAM_ACCESS, CATALOG_ACCESS] }
export const WORKSPACE_PATH = '/vp/scientific/programs-courses'
export function preparation(snapshot) {
  const values = snapshot.values
  return { program: snapshot.program, source_courses: snapshot.plan.courses, source_version_id: snapshot.plan.version.academic_plan_version_id,
    requirements: { total_credit_hours: values.total_credit_hours, groups: Object.keys(SCOPES).flatMap(scope => Object.keys(TYPES).map(type => {
      const rows = values.groups.filter(g => g.requirement_scope === scope && g.requirement_type === type)
      return { requirement_scope: scope, requirement_type: type, required_credit_hours: rows.length === 1 ? rows[0].required_credit_hours : null }
    })) }, courses: values.courses.map(c => ({ ...c })) }
}
export function changePayload(requestId, revision, drafts, newCourses) {
  const targets = drafts.map(d => ({ academic_program_id: Number(d.program.academic_program_id), source_version_id: d.source_version_id,
    requirements: d.requirements, courses: d.courses.map(({ course_id, new_course_key, requirement_scope, course_type, academic_level_id, recommended_semester_id, is_active }) => ({
      course_id: course_id ?? null, ...(new_course_key ? { new_course_key } : {}), requirement_scope, course_type,
      academic_level_id: academic_level_id == null ? null : Number(academic_level_id), recommended_semester_id: recommended_semester_id == null ? null : Number(recommended_semester_id), is_active: !!is_active,
    })) }))
  const used = new Set(targets.flatMap(t => t.courses.map(c => c.new_course_key)).filter(Boolean))
  return { request_id: requestId, revision, confirmed: true, targets, new_courses: newCourses.filter(c => used.has(c.key)) }
}
export function courseIdentity(c) { return c.course_id ? `id:${c.course_id}` : `new:${c.new_course_key}` }
export function upsertPreparedCourse(draft, course) {
  const identity = courseIdentity(course)
  return { ...draft, courses: [...draft.courses.filter(c => courseIdentity(c) !== identity), course] }
}
export function courseChanges(original, draft) {
  const old = new Map(original.courses.map(c => [courseIdentity(c), c])), next = new Map(draft.courses.map(c => [courseIdentity(c), c]))
  const fields = ['requirement_scope', 'course_type', 'academic_level_id', 'recommended_semester_id', 'is_active']
  return [...new Set([...old.keys(), ...next.keys()])].filter(k => !old.has(k) || !next.has(k) || fields.some(f => JSON.stringify(old.get(k)[f]) !== JSON.stringify(next.get(k)[f])))
    .map(identity => ({ identity, before: old.get(identity), after: next.get(identity) }))
}
export function actualChanges(original, draft) {
  const old = new Map(original.courses.map(c => [courseIdentity(c), c]))
  const next = new Map(draft.courses.map(c => [courseIdentity(c), c]))
  return { added: [...next.keys()].filter(k => !old.has(k)).length, removed: [...old.keys()].filter(k => !next.has(k)).length,
    updated: [...next].filter(([k, c]) => old.has(k) && ['requirement_scope', 'course_type', 'academic_level_id', 'recommended_semester_id', 'is_active'].some(field => JSON.stringify(c[field]) !== JSON.stringify(old.get(k)[field]))).length,
    requirements: JSON.stringify(original.requirements) !== JSON.stringify(draft.requirements) }
}
export function legacyWorkspaceLink(programId, search = '') {
  const q = new URLSearchParams(search)
  if (programId) q.set('program', String(programId))
  return `${WORKSPACE_PATH}${q.size ? `?${q}` : ''}`
}
