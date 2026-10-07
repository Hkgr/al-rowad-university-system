import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { canAccess } from '../src/features/auth/auth.js'
import { advisoryYears, entityFilterSearch, entityLink, entityListFilters, membershipEntityLink, safeEntityReturn } from '../src/features/scientific-programs/entities.js'
import { PROGRAM_ACCESS, canViewPrograms } from '../src/features/scientific-programs/programs.js'
import { CATALOG_ACCESS, canViewCatalog } from '../src/features/scientific-courses/catalog.js'
import { ROUTE_ACCESS } from '../src/features/user-guide/guideAccess.js'
import { changePayload, preparation, upsertPreparedCourse } from '../src/features/scientific-programs/workspace.js'
const read = p => readFileSync(new URL('../src/' + p, import.meta.url), 'utf8')

test('direct local entity routes preserve parent return and historical plan identity', () => {
  const from = '/vp/scientific/programs-courses/programs?q=معلوماتية&college_id=3&page=4'
  const link = entityLink('programs', 8, { returnTo: from, version: 42 })
  assert.equal(link.split('?')[0], '/vp/scientific/programs-courses/programs/8')
  const q = new URLSearchParams(link.split('?')[1]); assert.equal(q.get('return'), from); assert.equal(q.get('version'), '42')
  assert.equal(membershipEntityLink({ academic_program_id: 8, academic_plan_version_id: 42 }, from), link)
  assert.equal(new URLSearchParams(membershipEntityLink({ academic_program_id: 8, academic_plan_version_id: null }, from).split('?')[1]).has('version'), false)
})
test('return targets never escape the local entity portal', () => {
  for (const value of ['https://example.com', '//example.com', '/academic-structure/programs', '/vp/scientific/programs-courses/../technical', '/vp/scientific/programs-courses\\bad', '/vp/scientific/programs-courses\n']) assert.equal(safeEntityReturn(value), '/vp/scientific/programs-courses')
  assert.equal(safeEntityReturn('/vp/scientific/programs-courses/courses?page=3'), '/vp/scientific/programs-courses/courses?page=3')
})
test('URL filter state survives direct reload and preserves selected labels and pagination', () => {
  const input = { q: 'مادة عربية', college: { id: 3, label: 'كلية اصطناعية' }, department: { id: 4, label: 'قسم اصطناعي' }, program: { id: 9, label: 'برنامج اصطناعي' }, status: 'inactive', is_active: '0', requirement_scope: 'college', course_type: 'elective', sort: 'course_code', direction: 'desc', page: 4 }
  assert.deepEqual(entityListFilters(entityFilterSearch(input)), input)
  assert.equal(entityListFilters('?college_id=not-an-id&page=-4').college, null)
  assert.equal(entityListFilters('?page=-4').page, 1)
})
test('semesters are nested within actual years, including undefined advisory fields', () => {
  const rows = [{ course_id: 1, academic_level_id: 9, recommended_semester_id: 2 }, { course_id: 2, academic_level_id: 9, recommended_semester_id: 1 }, { course_id: 3, academic_level_id: 8, recommended_semester_id: 1 }, { course_id: 4, academic_level_id: null, recommended_semester_id: null }]
  const source = rows.map(r => ({ ...r, academic_level: r.academic_level_id ? { level_name: r.academic_level_id === 9 ? 'الأولى' : 'الثانية', level_order: r.academic_level_id === 9 ? 1 : 2 } : null, recommended_semester: r.recommended_semester_id ? { semester_name: r.recommended_semester_id === 1 ? 'الأول' : 'الثاني', semester_order: r.recommended_semester_id } : null }))
  const before = JSON.stringify(rows), years = advisoryYears(rows, source)
  assert.equal(years.length, 3); assert.ok(years[0].label.includes('الأولى')); assert.equal(years[0].terms.length, 2)
  assert.deepEqual(years[0].terms.map(t => t.rows[0].course_id), [2, 1]); assert.ok(years[2].label.includes('غير محددة')); assert.ok(years[2].terms[0].label.includes('غير محدد'))
  assert.equal(JSON.stringify(rows), before)
})
test('new origin shared across explicit programs retains independent distributions in one payload', () => {
  const snapshot = { program: { academic_program_id: 1 }, plan: { version: { academic_plan_version_id: null }, courses: [] }, values: { total_credit_hours: null, groups: [{ requirement_scope: 'college', requirement_type: 'elective', required_credit_hours: 0, is_active: false }], courses: [] } }
  const one = upsertPreparedCourse(preparation(snapshot), { new_course_key: 'shared', academic_level_id: 1, recommended_semester_id: 1, requirement_scope: 'college', course_type: 'elective', is_active: true })
  const two = { ...one, program: { academic_program_id: 3 }, courses: [{ ...one.courses[0], academic_level_id: 2, recommended_semester_id: 2 }] }
  const payload = changePayload('same-request', 'monotonic', [one, two], [{ key: 'shared', course_code: 'SYN', course_name: 'اصطناعية' }])
  assert.equal(payload.new_courses.length, 1); assert.deepEqual(payload.targets.map(t => t.academic_program_id), [1, 3]); assert.deepEqual(payload.targets.map(t => t.courses[0].academic_level_id), [1, 2])
  assert.equal(payload.targets[0].requirements.groups.find(g => g.requirement_scope === 'college' && g.requirement_type === 'elective').is_active, false)
  assert.equal(payload.targets[0].requirements.total_credit_hours, null)
})
test('new route, guide and directory read policies agree without expanding roles (static wiring and pure access)', () => {
  const scientific = { roles: ['vice_president_scientific'], permissions: [...new Set([...PROGRAM_ACCESS.assignedPermissions, ...CATALOG_ACCESS.assignedPermissions])], access_scopes: [{ type: 'college', id: 1 }] }
  assert.equal(canViewPrograms(scientific), true); assert.equal(canViewCatalog(scientific), true)
  for (const role of ['dean', 'student', 'vice_president_administrative', 'ministry_observer']) { assert.equal(canViewPrograms({ ...scientific, roles: [role] }), false); assert.equal(canViewCatalog({ ...scientific, roles: [role] }), false) }
  assert.equal(canViewPrograms({ roles: ['super_admin'] }), true); assert.equal(canViewCatalog({ roles: ['super_admin'] }), true)
  const routes = read('app/App.jsx')
  for (const kind of ['colleges', 'departments', 'programs', 'courses']) {
    const access = kind === 'courses' ? 'CATALOG_ACCESS' : 'PROGRAM_ACCESS'
    assert.ok(routes.includes(`path="/vp/scientific/programs-courses/${kind}/:entityId" element={protect(<AcademicEntitiesPage kind="${kind}" />, ${access})}`))
    assert.deepEqual(ROUTE_ACCESS[`/vp/scientific/programs-courses/${kind}`][1], kind === 'courses' ? CATALOG_ACCESS : PROGRAM_ACCESS)
  }
  // Only redirect aliases accept the union; actual program pages stay protected.
  assert.ok(routes.includes('protect(<LegacyAcademicEntityRoute kind="programs" />, WORKSPACE_ACCESS)'))
  const catalogOnly = { ...scientific, permissions: CATALOG_ACCESS.assignedPermissions }
  assert.equal(canViewPrograms(catalogOnly), false); assert.equal(canViewCatalog(catalogOnly), true)
  assert.equal(canAccess(ROUTE_ACCESS['/vp/scientific/programs'][1], catalogOnly), true)
})
test('active screens have no academic-structure egress or advanced fallback; all entity names use local links (static)', () => {
  const files = ['AcademicEntitiesPage.jsx', 'UnifiedProgramsPage.jsx', 'CourseEntityPage.jsx', 'ProgramEntityActions.jsx']
  for (const file of files) assert.doesNotMatch(read(`features/scientific-programs/${file}`), /to=[^\n]*\/academic-structure|advanced=1|return <ScientificProgramsPage/)
  assert.match(read('features/scientific-programs/AcademicEntitiesPage.jsx'), /children\.data\?\.meta.total > 1/)
  assert.match(read('features/scientific-programs/WorkspaceCurriculum.jsx'), /entityLink\('courses', c.course_id/)
  assert.match(read('features/scientific-programs/AcademicEntityNavigation.jsx'), /details.*إجراءات/)
})
test('primary add, pending-write guard, immutable replay and per-material draft cancellation are wired (static, not browser proof)', () => {
  const page = read('features/scientific-programs/UnifiedProgramsPage.jsx'), guard = read('features/scientific-programs/useEntityEditorGuard.js')
  assert.match(page, /primary[^\n]*onClick=\{addMaterial\}[^\n]*إضافة مادة/)
  assert.match(page, /changePayload\(crypto.randomUUID\(\), revision, drafts, newCourses\)/)
  assert.match(page, /retry \? uncertain/); assert.match(page, /plan-changes\/\$\{uncertain.request_id\}/)
  assert.match(page, /courseEditorDirty/); assert.match(page, /تبقى بقية مواد الإعداد/)
  assert.match(guard, /controls\.current\.busy/); assert.match(guard, /useBlocker/); assert.match(guard, /beforeunload/)
  assert.match(read('features/scientific-programs/AcademicEntitiesPage.jsx'), /key=\{`\$\{identity\}:\$\{kind\}:\$\{entityId/)
  assert.match(read('features/scientific-programs/AcademicEntitiesPage.jsx'), /scientific-catalog-denied/)
})
