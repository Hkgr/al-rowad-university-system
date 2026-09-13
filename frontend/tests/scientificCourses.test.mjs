import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { CATALOG_ACCESS, canViewCatalog, groupedCourses, editableCourse, coursePayload, queryString, requestCounter, catalogError } from '../src/features/scientific-courses/catalog.js'
import { canAccess } from '../src/features/auth/auth.js'

const identity = { roles: ['vice_president_scientific'], permissions: [...CATALOG_ACCESS.assignedPermissions], access_scopes: [{ type: 'college', id: 1 }] }
test('actual Scientific VP, assigned permissions and actual academic scope; no super-admin shortcut', () => {
  assert.equal(canViewCatalog(identity), true)
  for (const user of [null, { ...identity, roles: ['super_admin'] }, { ...identity, roles: ['vice_president_administrative'] }, { ...identity, permissions: ['vice_presidency.scientific.access'] }, { ...identity, access_scopes: [] }]) assert.equal(canViewCatalog(user), false)
  assert.equal(canAccess({ permissions: ['existing'] }, { roles: ['super_admin'] }), true, 'Unrelated legacy policy unchanged')
})
test('both grouping views preserve exactly the same six membership identities and unlinked origins', () => {
  let id = 0
  const courses = ['university', 'college', 'department'].flatMap(scope => ['mandatory', 'elective'].map(type => ({ course_id: ++id, program_courses: [{ program_course_id: id, requirement_classification: { requirement_scope: scope, requirement_type: type } }] })))
  courses.push({ course_id: 99, program_courses: [] })
  const keys = order => groupedCourses(courses, order).flatMap(g => g.rows.map(r => r.key)).sort()
  assert.deepEqual(keys('scope'), keys('type'))
  assert.equal(new Set(keys('scope')).size, 7)
  assert.equal(groupedCourses(courses).at(-1).scope, 'unclassified')
})
test('create is independent of program, changed-only save preserves locked relations and original revision', () => {
  const baseline = { revision: '9223372036854775700', data: { course_id: 1, course_code: 'C1', course_name: 'قديم', credit_hours: 3, is_active: true } }
  const draft = editableCourse(baseline)
  draft.course_name = 'تصحيح'
  assert.deepEqual(coursePayload(draft, baseline), { revision: baseline.revision, course_name: 'تصحيح' })
  const create = coursePayload(editableCourse(null), { revision: '1' })
  assert.equal('academic_program_id' in create, false)
  assert.equal('required_credit_hours' in create, false)
})
test('nullable delivery hours, zero, active false, and explicit prerequisite removals survive payload building', () => {
  const baseline = { revision: '2', data: { course_id: 1, theoretical_hours: 2, practical_hours: 1, is_active: true, course_prerequisites: [{ prerequisite_course_id: 5, minimum_result_status_id: 1 }] } }
  const draft = editableCourse(baseline)
  draft.theoretical_hours = ''; draft.practical_hours = 0; draft.is_active = false; draft.prerequisites = []
  const p = coursePayload(draft, baseline)
  assert.equal(p.theoretical_hours, null); assert.equal(p.practical_hours, 0); assert.equal(p.is_active, false); assert.deepEqual(p.prerequisites, [])
})
test('all-colleges omission, stale lookup responses, and controlled conflict presentation', () => {
  assert.equal(queryString({ college_id: '', page: 2, is_active: 0 }), 'page=2&is_active=0')
  const gate = requestCounter(), before = gate.next()
  gate.invalidate(); assert.equal(gate.current(before), false)
  const after = gate.next(); assert.equal(gate.current(after), true)
  assert.match(catalogError({ status: 409 }), /راجع النسخة الحالية/)
})
test('read hooks bind data to request key, invalidate debounce responses and do not retry writes', async () => {
  const source = await readFile(new URL('../src/features/scientific-courses/useCatalogRead.js', import.meta.url), 'utf8')
  assert.match(source, /state\?\.key === key/)
  assert.match(source, /counter\.invalidate\(\)/)
  assert.match(source, /controller\.abort\(\)/)
  assert.match(source, /clearTimeout\(timer\)/)
})

test('route, navigation and home entry share the same actual Scientific authorization', async () => {
  const read = p => readFile(new URL(p, import.meta.url), 'utf8')
  assert.match(await read('../src/app/App.jsx'), /path="\/vp\/scientific\/courses" element=\{protect\(<ScientificCoursesPage \/>, CATALOG_ACCESS\)\}/)
  assert.match(await read('../src/features/vice-presidency/nav.js'), /to: '\/vp\/scientific\/courses'.*\.\.\.CATALOG_ACCESS/)
  assert.match(await read('../src/features/vice-presidency/pages/VicePresidentShell.jsx'), /office === 'scientific' && canViewCatalog\(identity\)/)
})

test('forms retain baseline on conflict, inspect before explicit discard and separate budgets', async () => {
  const read = name => readFile(new URL(`../src/features/scientific-courses/${name}`, import.meta.url), 'utf8')
  const mutation = await read('useCatalogMutation.js'), page = await read('ScientificCoursesPage.jsx')
  assert.match(mutation, /flight\.current \|\| state\.blocked/)
  assert.match(mutation, /e\.status !== 422/)
  assert.match(mutation, /const current = await catalogRead\(currentPath\)/)
  assert.match(page, /useBlocker/); assert.match(page, /beforeunload/)
  assert.match(page, /controls\.current\.busy/)
  assert.match(await read('CatalogConflict.jsx'), /تجاهل تعديلاتي وفتح البيانات الحالية/)
  assert.match(await read('CourseEditor.jsx'), /caps\.academic_lock_reason/)
  assert.doesNotMatch(await read('CourseEditor.jsx'), /total_credit_hours|saveGroups|\/requirement-groups/)
  assert.match(await read('ProgramEditors.jsx'), /\/requirement-groups/)
})

test('one existing DataTable, course identity rows, separate details and compact requirements', async () => {
  const read = name => readFile(new URL(`../src/features/scientific-courses/${name}`, import.meta.url), 'utf8')
  const page = await read('ScientificCoursesPage.jsx'), editors = await read('ProgramEditors.jsx')
  assert.equal((page.match(/<DataTable\s/g) || []).length, 1)
  assert.match(page, /rows=\{read\.data\?\.data \|\| \[\]\} rowKey=\{c => c\.course_id\}/)
  assert.doesNotMatch(page, /groupedCourses/)
  assert.match(page, /<FilterBar/)
  assert.match(page, /filters\.program \? \[/)
  assert.match(editors, /<table/)
  assert.doesNotMatch(editors, /group_code|group_name|requirement_group_id: Number/)
  assert.match(editors, /matches\.length !== 1/)
  assert.match(editors, /إعداد متطلبات التخرج/)
  assert.match(editors, /required_credit_hours: ''/)
  assert.doesNotMatch(editors, /configuration\.message/)
  assert.match(editors, /راجع التصنيفات والساعات المطلوبة والمواد المرتبطة/)
  assert.match(editors, /إزالة من البرنامج/)
  assert.match(await read('CourseDetails.jsx'), /حذف المادة/)
})

test('program creation reports partial success and requires a separate explicit link action', async () => {
  const page = await readFile(new URL('../src/features/scientific-courses/ScientificCoursesPage.jsx', import.meta.url), 'utf8')
  assert.match(page, /kind: 'created'/)
  assert.match(page, /لم تُضف للبرنامج بعد/)
  assert.match(page, /متابعة إضافة المادة للبرنامج/)
  assert.match(page, /إنهاء دون إضافة للبرنامج/)
  assert.doesNotMatch(page, /catalogWrite|mutation\.write/)
  assert.match(await readFile(new URL('../AGENTS.md', import.meta.url), 'utf8'), /Do not invent an independent visual style/)
})
