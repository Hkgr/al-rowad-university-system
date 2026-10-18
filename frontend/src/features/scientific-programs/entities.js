import { programRead, programWrite } from './programs.js'

export const ENTITY_ROOT = '/vp/scientific/programs-courses'
export const ENTITY_LABELS = { colleges: 'الكليات والمعاهد', departments: 'الأقسام', programs: 'البرامج', courses: 'دليل المواد' }
export const entityRead = (path, signal) => programRead(`/entities${path}`, signal)
export const entityWrite = (path, method, payload) => programWrite(`/entities${path}`, method, payload)
export function safeEntityReturn(value, fallback = ENTITY_ROOT) {
  if (typeof value !== 'string' || value.length > 2000 || [...value].some(c => c.charCodeAt(0) < 32 || c === '\\')) return fallback
  return /^\/vp\/scientific\/programs-courses(?:\/(?:colleges|departments|programs|courses)(?:\/[1-9]\d*)?)?(?:\?[^#]*)?$/.test(value) ? value : fallback
}
export function entityLink(kind, id = null, options = {}) {
  const path = `${ENTITY_ROOT}/${kind}${id == null ? '' : `/${Number(id)}`}`
  const query = new URLSearchParams()
  if (options.returnTo) query.set('return', safeEntityReturn(options.returnTo))
  for (const key of ['version', 'tab', 'addCourse']) if (options[key] != null) query.set(key, String(options[key]))
  return path + (query.size ? `?${query}` : '')
}
export function entityListFilters(search) {
  const q = new URLSearchParams(search)
  const selected = (key, label) => /^[1-9]\d*$/.test(q.get(key) || '') ? { id: Number(q.get(key)), label: q.get(`${key}_label`) || label } : null
  return { q: q.get('q') || '', college: selected('college_id', 'الكلية المختارة'), department: selected('department_id', 'القسم المختار'), program: selected('academic_program_id', 'البرنامج المختار'),
    status: q.get('status') || '', sort: q.get('sort') || 'program_code', direction: q.get('direction') === 'desc' ? 'desc' : 'asc',
    is_active: q.get('is_active') || '', requirement_scope: q.get('requirement_scope') || '', course_type: q.get('course_type') || '',
    page: Math.max(1, Number(q.get('page')) || 1) }
}
export function entityFilterSearch(filters) {
  const q = new URLSearchParams()
  for (const key of ['q', 'status', 'sort', 'direction', 'is_active', 'requirement_scope', 'course_type']) if (filters[key]) q.set(key, filters[key])
  for (const [key, field] of [['college', 'college_id'], ['department', 'department_id'], ['program', 'academic_program_id']]) if (filters[key]) {
    q.set(field, String(filters[key].id)); q.set(`${field}_label`, filters[key].label)
  }
  if (filters.page > 1) q.set('page', String(filters.page))
  return q.toString()
}
/** Plan identity stays explicit, including historical/read-only course links. */
export function membershipEntityLink(pc, returnTo) {
  return entityLink('programs', pc.academic_program_id, { version: pc.academic_plan_version_id, returnTo })
}

/** Display grouping only; no budgets, prerequisites or academic decisions. */
export function advisoryYears(rows, source = []) {
  const years = new Map()
  for (const row of rows) {
    const yearKey = String(row.academic_level_id ?? 'unset')
    if (!years.has(yearKey)) {
      const level = source.find(c => Number(c.academic_level_id) === Number(row.academic_level_id))?.academic_level
      years.set(yearKey, { key: yearKey, label: level?.level_name ? `السنة الدراسية — ${level.level_name}` : row.academic_level_id ? `السنة الدراسية ${row.academic_level_id}` : 'السنة الدراسية غير محددة', order: level?.level_order ?? row.academic_level_id ?? Number.MAX_SAFE_INTEGER, terms: new Map() })
    }
    const terms = years.get(yearKey).terms, termKey = String(row.recommended_semester_id ?? 'unset')
    if (!terms.has(termKey)) {
      const term = source.find(c => Number(c.recommended_semester_id) === Number(row.recommended_semester_id))?.recommended_semester
      terms.set(termKey, { key: termKey, label: term?.semester_name ? `الفصل الإرشادي — ${term.semester_name}` : row.recommended_semester_id ? `الفصل الإرشادي ${row.recommended_semester_id}` : 'الفصل الإرشادي غير محدد', order: term?.semester_order ?? row.recommended_semester_id ?? Number.MAX_SAFE_INTEGER, rows: [] })
    }
    terms.get(termKey).rows.push(row)
  }
  return [...years.values()].sort((a, b) => a.order - b.order).map(year => ({ ...year, terms: [...year.terms.values()].sort((a, b) => a.order - b.order) }))
}
