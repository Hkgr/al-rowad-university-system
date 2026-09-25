import assert from 'node:assert/strict'
import { readFile, readdir } from 'node:fs/promises'
import test from 'node:test'
import { ACCESS, canAccess, landingRoute } from '../src/features/auth/auth.js'
import { GUIDES } from '../src/features/user-guide/content/index.js'
import { visibleSections } from '../src/features/user-guide/guideModel.js'
import {
  appliedFilterLabels, buildQuery, errorMessage, formatNumber, formatPercent, hasActiveFilters, portalLink, programsOf, readFilters, viewState,
} from '../src/features/ministry-portal/lib/ministryState.js'

const source = path => readFile(new URL(`../src/${path}`, import.meta.url), 'utf8')
const ALL = ['ministry_portal.access', 'ministry_portal.dashboard.view', 'ministry_portal.deans.view', 'ministry_portal.students.view', 'ministry_portal.colleges.view', 'ministry_portal.courses.view', 'ministry_portal.faculty.view', 'ministry_portal.leadership.view']
const ministry = { roles: ['ministry_observer'], permissions: ALL, access_scopes: [] }
const PAGES = ['ministryDashboard', 'ministryDeans', 'ministryStudents', 'ministryColleges', 'ministryCourses', 'ministryFaculty', 'ministryLeadership']

test('the ministry account lands on its portal; every existing landing is unchanged', () => {
  assert.equal(landingRoute(ministry), '/ministry')
  assert.equal(landingRoute({ ...ministry, permissions: [] }), '/forbidden', 'role without the portal permission')
  assert.equal(landingRoute({ roles: [], permissions: ALL }), '/forbidden', 'permissions without the role')
  assert.equal(landingRoute({ roles: ['dean'], permissions: [] }), '/dean')
  assert.equal(landingRoute({ roles: ['vice_president_scientific'], permissions: [] }), '/vp/scientific')
  assert.equal(landingRoute({ roles: ['technical_team'], permissions: ['technical_portal.access'] }), '/technical')
  assert.equal(landingRoute({ roles: ['super_admin'], permissions: [] }), '/exam-board')
  assert.equal(landingRoute({ roles: [], permissions: ['hr.view'] }), '/hr')
})

test('portal access needs the ministry role AND each assigned permission; super_admin gets no bypass', () => {
  for (const page of PAGES) {
    assert.equal(canAccess(ACCESS[page], ministry), true, page)
    assert.equal(canAccess(ACCESS[page], { roles: ['super_admin'], permissions: [] }), false, `${page} super_admin`)
    assert.equal(canAccess(ACCESS[page], { roles: ['dean'], permissions: ALL }), false, `${page} other role`)
  }
  const studentsOnly = { roles: ['ministry_observer'], permissions: ['ministry_portal.access', 'ministry_portal.students.view'] }
  assert.deepEqual(PAGES.filter(page => canAccess(ACCESS[page], studentsOnly)), ['ministryStudents'])
  assert.deepEqual(visibleSections(GUIDES.ministry, studentsOnly).flatMap(s => s.tasks).map(t => t.id), ['students'])
  assert.equal(visibleSections(GUIDES.ministry, ministry).flatMap(s => s.tasks).length, 7)
})

test('sidebar has the seven sections, each behind its own access rule, plus the guide', async () => {
  const nav = await source('features/ministry-portal/nav.js')
  const items = [
    ["'/ministry'", "ar: 'الرئيسية'", 'ministryDashboard'], ["'/ministry/deans'", "ar: 'العمداء'", 'ministryDeans'],
    ["'/ministry/students'", "ar: 'الطلاب'", 'ministryStudents'], ["'/ministry/colleges'", "ar: 'الكليات'", 'ministryColleges'],
    ["'/ministry/courses'", "ar: 'المواد'", 'ministryCourses'], ["'/ministry/faculty'", "ar: 'المدرسون'", 'ministryFaculty'],
    ["'/ministry/leadership'", "ar: 'النواب'", 'ministryLeadership'],
  ]
  for (const [to, label, access] of items) assert.match(nav, new RegExp(`to: ${to}, Icon: \\w+, ${label}.*\\.\\.\\.ACCESS\\.${access} }`))
  assert.equal((nav.match(/\bto: /g) ?? []).length, 8)
})

test('every portal route is guarded by the portal group and its own page access', async () => {
  const app = await source('app/App.jsx')
  assert.match(app, /<ProtectedRoute \{\.\.\.ACCESS\.ministryPortal\}>\s*<DashboardLayout nav=\{ministryNav\} appTitle="وزارة التربية والتعليم" \/>/)
  const routes = [
    ['/ministry', 'MinistryHome', 'ministryDashboard'], ['/ministry/deans', 'MinistryDeans', 'ministryDeans'], ['/ministry/deans/:person', 'MinistryDeanDetail', 'ministryDeans'],
    ['/ministry/students', 'MinistryStudents', 'ministryStudents'], ['/ministry/students/:id', 'MinistryStudentDetail', 'ministryStudents'],
    ['/ministry/colleges', 'MinistryColleges', 'ministryColleges'], ['/ministry/colleges/:id', 'MinistryCollegeDetail', 'ministryColleges'],
    ['/ministry/courses', 'MinistryCourses', 'ministryCourses'], ['/ministry/courses/:id', 'MinistryCourseDetail', 'ministryCourses'],
    ['/ministry/faculty', 'MinistryFaculty', 'ministryFaculty'], ['/ministry/faculty/:id', 'MinistryFacultyDetail', 'ministryFaculty'],
    ['/ministry/leadership', 'MinistryLeadership', 'ministryLeadership'], ['/ministry/leadership/units/:id', 'MinistryUnitDetail', 'ministryLeadership'],
  ]
  for (const [path, page, access] of routes) {
    assert.ok(app.includes(`<Route path="${path}" element={protect(<${page} />, ACCESS.${access})} />`), path)
  }
})

test('the portal code has no write call and offers no create/edit/delete/approve action', async () => {
  const api = await source('features/ministry-portal/lib/ministryApi.js')
  assert.doesNotMatch(api, /method:|POST|PUT|PATCH|DELETE/)
  const dir = new URL('../src/features/ministry-portal/', import.meta.url)
  const files = []
  for (const sub of ['pages', 'components', 'lib']) for (const name of await readdir(new URL(`${sub}/`, dir))) files.push(`features/ministry-portal/${sub}/${name}`)
  for (const file of files) {
    const text = await source(file)
    if (!file.endsWith('ministryApi.js')) assert.doesNotMatch(text, /apiRequest|fetch\(/, `${file} calls the API only through ministryApi`)
    assert.doesNotMatch(text, /from '\.\.\/\.\.\/(technical-portal|vice-presidency|dean-dashboard|student-affairs|exam-board|hr-dashboard)\//, `${file} reuses no other portal API`)
    assert.doesNotMatch(text, /(>|')(حذف|تعديل|إضافة|إنشاء|اعتماد|نشر|إعادة تعيين|حفظ)(<|')/, `${file} has no action button`)
    assert.doesNotMatch(text, /password|email|phone_number|date_of_birth|username/, `${file} renders no contact or credential field`)
  }
})

test('filters and links are built from an allowlist', () => {
  assert.equal(buildQuery('students', { status: 'active', college_id: '2', search: ' سامر ', evil: 'x' }, { pageNumber: 3, perPage: 20 }), 'search=%D8%B3%D8%A7%D9%85%D8%B1&college_id=2&status=active&page=3&per_page=20')
  assert.equal(buildQuery('dashboard', { academic_year_id: '8', semester_id: '', college_id: '1', status: 'active' }), 'academic_year_id=8&college_id=1')
  assert.deepEqual(Object.keys(readFilters('faculty', new URLSearchParams('college_id=1&status=active&active=1'))), ['search', 'college_id', 'active'])
  assert.equal(hasActiveFilters({ search: '', college_id: '' }), false)
  assert.equal(portalLink('/ministry/students?college_id=1&status=active'), '/ministry/students?college_id=1&status=active')
  assert.equal(portalLink('https://evil.example/ministry'), null)
  assert.equal(portalLink('/technical/accounts'), null)
  assert.equal(portalLink('/ministry/students?x=<script>'), null)
  assert.deepEqual(programsOf([{ id: 1, college_id: 1 }, { id: 2, college_id: 2 }], '2').map(p => p.id), [2])
  const options = { academic_years: [{ id: 8, name: '2026-2027' }], semesters: [{ id: 1, name: 'الفصل الأول' }], colleges: [{ id: 2, name: 'كلية' }], programs: [], departments: [], student_statuses: [{ code: 'active', name: 'نشط' }], levels: [] }
  assert.deepEqual(appliedFilterLabels({ registered_year_id: '8', registered_semester_id: '1', college_id: '2', status: 'active' }, options), ['الكلية: كلية', 'الحالة: نشط', 'مسجل في: 2026-2027 — الفصل الأول'])
})

test('states and numbers never turn "unavailable" into zero', () => {
  assert.equal(formatNumber(null), 'غير متاح')
  assert.equal(formatNumber(undefined), 'غير متاح')
  assert.equal(formatPercent(null), 'غير متاح')
  assert.notEqual(formatNumber(0), 'غير متاح')
  assert.equal(viewState({ loading: true }), 'loading')
  assert.equal(viewState({ loading: false, error: { status: 403 } }), 'forbidden')
  assert.equal(viewState({ loading: false, error: { status: 404 } }), 'notFound')
  assert.equal(viewState({ loading: false, error: { status: 500 } }), 'error')
  assert.equal(viewState({ loading: false, rows: [], filtered: true }), 'emptyFiltered')
  assert.equal(viewState({ loading: false, rows: [] }), 'empty')
  assert.match(errorMessage({ status: 403 }), /صلاحية/)
  assert.equal(errorMessage({ status: 422, details: { program_id: ['البرنامج المختار لا يتبع الكلية المختارة.'] } }), 'البرنامج المختار لا يتبع الكلية المختارة.')
})
