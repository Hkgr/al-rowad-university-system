import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { canAccess } from '../src/features/auth/auth.js'
import { GROUP_GUARDS, GUIDE_ACCESS, GUIDE_PATHS, ROUTE_ACCESS } from '../src/features/user-guide/guideAccess.js'
import { GUIDES } from '../src/features/user-guide/content/index.js'
import {
  COMMON_TROUBLESHOOTING, HELP_CHANNELS, buildReportTemplate, flowsToNext, guideTitle, resolveLink,
  visibleSections, visibleTroubleshooting, visibleUnavailable,
} from '../src/features/user-guide/guideModel.js'

const root = new URL('../../', import.meta.url)
const read = path => readFile(new URL(path, root), 'utf8')
const src = path => read(`frontend/src/${path}`)

const id = (roles = [], permissions = [], extra = {}) => ({ user_id: 1, roles, permissions, access_scopes: [], ...extra })
const uni = [{ type: 'university', id: 1 }]
const college = [{ type: 'college', id: 3 }]

const tasksOf = (guideId, user) => visibleSections(GUIDES[guideId], user).flatMap(section => section.tasks)
const taskIds = (guideId, user) => tasksOf(guideId, user).map(task => task.id)
const stepTexts = (guideId, taskId, user) => tasksOf(guideId, user).find(task => task.id === taskId)?.steps.map(step => step.text) ?? []

const NAV_FILES = {
  student: 'features/student-dashboard/nav.js',
  professor: 'features/professor-dashboard/nav.js',
  studentAffairs: 'features/student-affairs/nav.js',
  examBoard: 'features/exam-board/nav.js',
  dean: 'features/dean-dashboard/nav.js',
  hr: 'features/hr-dashboard/nav.js',
  academicStructure: 'features/academic-structure/nav.js',
  vpScientific: 'features/vice-presidency/nav.js',
  vpAdministrative: 'features/vice-presidency/nav.js',
  technical: 'features/technical-portal/nav.js',
}

// The ProtectedRoute that encloses each guide route in App.jsx.
const EXPECTED_GUARD = {
  student: "<ProtectedRoute studentIdentity permissions={['registration.view', 'grades.view', 'attendance.view']}>",
  professor: "<ProtectedRoute employeeIdentity permissions={['grades.manage', 'attendance.manage', 'supplementary_exams.grades.view']}>",
  studentAffairs: '<ProtectedRoute {...GUIDE_ACCESS.studentAffairs}>',
  examBoard: '<ProtectedRoute {...GUIDE_ACCESS.examBoard}>',
  dean: "<ProtectedRoute roles={['dean']}>",
  hr: "<ProtectedRoute permissions={['hr.view']}>",
  academicStructure: "<ProtectedRoute permissions={['academic_structure.view']}>",
  vpScientific: '<ProtectedRoute {...ACCESS.scientificVicePresident}>',
  vpAdministrative: '<ProtectedRoute {...ACCESS.administrativeVicePresident}>',
  technical: '<ProtectedRoute {...ACCESS.technicalPortal}>',
}

const SAMPLE_USERS = {
  anonymousLike: id(),
  student: id(['student'], ['registration.view', 'grades.view', 'attendance.view', 'supplementary_exams.deferrals.self', 'supplementary_exams.registrations.self'], { student_id: 9 }),
  studentNoIdentity: id(['student'], ['registration.view', 'grades.view', 'attendance.view']),
  professor: id(['doctor_instructor'], ['grades.manage', 'attendance.manage', 'attendance.view', 'supplementary_exams.grades.view'], { employee_id: 4 }),
  professorNoEmployee: id(['doctor_instructor'], ['grades.manage', 'attendance.manage']),
  registrar: id(['registration_officer'], ['students.view', 'registration.view', 'supplementary_exams.registrations.view'], { access_scopes: college }),
  studentsManager: id([], ['students.view', 'students.manage']),
  admissionsOnly: id([], ['admissions.manage', 'admissions.view'], { access_scopes: uni }),
  admissionsNoScope: id([], ['admissions.manage', 'admissions.view'], { access_scopes: college }),
  examOfficer: id(['exam_officer'], ['exams.view', 'exams.manage', 'grades.manage', 'grades.view', 'students.view', 'supplementary_exams.grades.review', 'supplementary_exams.registrations.view', 'courses.view', 'academic_structure.view', 'system_settings.view']),
  manualOnly: id(['exam_officer'], ['exams.manage', 'grades.manage', 'students.view']),
  registrationStaff: id([], ['registration.view', 'registration.manage', 'students.view', 'academic_structure.view', 'courses.view', 'system_settings.view']),
  dean: id(['dean'], ['registration_requests.review', 'course_offerings.semester_governance.view', 'course_offerings.semester_governance.manage', 'teaching_staff.manage', 'teaching_assignments.manage', 'supplementary_exams.offerings.view'], { employee_id: 5, access_scopes: college }),
  deanPlain: id(['dean'], []),
  hrViewer: id([], ['hr.view']),
  hrManager: id([], ['hr.view', 'hr.manage']),
  structureViewer: id([], ['academic_structure.view']),
  vpScientific: id(['vice_president_scientific'], ['vice_presidency.scientific.access', 'teaching_assignments.review_scientific', 'course_offerings.exceptional_open.review_scientific', 'course_offerings.semester_governance.view', 'course_offerings.semester_governance.review_scientific', 'supplementary_exams.periods.view', 'supplementary_exams.periods.decide', 'academic_calendar.manage'], { access_scopes: uni }),
  vpAdministrative: id(['vice_president_administrative'], ['vice_presidency.administrative.access', 'teaching_assignments.review_administrative', 'course_offerings.exceptional_open.review_administrative'], { access_scopes: uni }),
  technical: id(['technical_team'], ['technical_portal.access', 'user_accounts.view', 'user_accounts.manage']),
  superAdmin: id(['super_admin'], []),
  deanAndVp: id(['dean', 'vice_president_scientific'], ['registration_requests.review', 'vice_presidency.scientific.access', 'teaching_assignments.review_scientific'], { access_scopes: uni }),
}

test('every portal sidebar has exactly one «دليل الاستخدام» item bound to the guide path and access', async () => {
  const seen = new Map()
  for (const [guideId, file] of Object.entries(NAV_FILES)) {
    const nav = await src(file)
    seen.set(file, (seen.get(file) ?? 0) + 1)
    const expected = `{ to: GUIDE_PATHS.${guideId}, Icon: FaQuestionCircle, ar: 'دليل الاستخدام', en: 'User guide', ...GUIDE_ACCESS.${guideId} }`
    assert.equal(nav.split(expected).length - 1, 1, `${guideId} guide item`)
  }
  for (const [file, navCount] of seen) {
    const nav = await src(file)
    assert.equal(nav.split("ar: 'دليل الاستخدام'").length - 1, navCount, `${file} has one guide item per sidebar`)
  }
  const layout = await src('components/layout/DashboardLayout.jsx')
  assert.match(layout, /items: section\.items\.filter\(item => canAccess\(item\)\)/)
})

test('every guide route exists and sits under its portal guard, not only behind a hidden menu item', async () => {
  const app = await src('app/App.jsx')
  for (const [guideId, path] of Object.entries(GUIDE_PATHS)) {
    const route = `path="${path}"`
    const at = app.indexOf(route)
    assert.ok(at > 0, `route ${path}`)
    assert.equal(app.split(route).length - 1, 1, `${path} declared once`)
    assert.ok(app.slice(at, at + 200).includes(`<UserGuidePage guideId="${guideId}" />`), `${path} renders its guide`)
    const guardAt = app.lastIndexOf('<ProtectedRoute', at)
    const groupStart = app.lastIndexOf('<Route', guardAt)
    const guard = app.slice(guardAt, app.indexOf('>', app.indexOf('ProtectedRoute', guardAt)) + 1)
    assert.equal(guard, EXPECTED_GUARD[guideId], `${path} guard`)
    assert.ok(!app.slice(groupStart, at).includes('</Route>\n'), `${path} is inside the guarded group`)
  }
})

test('guide access equals the union of the route groups rendering the same sidebar', () => {
  const groupsFor = {
    student: [GROUP_GUARDS.student],
    professor: [GROUP_GUARDS.professor],
    studentAffairs: [GROUP_GUARDS.studentAffairs, GROUP_GUARDS.studentAffairsAdd, GROUP_GUARDS.ministryPlacements],
    examBoard: [GROUP_GUARDS.examBoard, GROUP_GUARDS.courseRegistration, GROUP_GUARDS.manualGradeEntry],
    dean: [GROUP_GUARDS.dean],
    hr: [GROUP_GUARDS.hr],
    academicStructure: [GROUP_GUARDS.academicStructure],
    vpScientific: [GROUP_GUARDS.vpScientific],
    vpAdministrative: [GROUP_GUARDS.vpAdministrative],
    technical: [GROUP_GUARDS.technical],
  }
  for (const [guideId, groups] of Object.entries(groupsFor)) {
    for (const [name, user] of Object.entries(SAMPLE_USERS)) {
      assert.equal(canAccess(GUIDE_ACCESS[guideId], user), groups.some(group => canAccess(group, user)), `${guideId} × ${name}`)
    }
  }
  // Concrete role, identity and scope cases.
  assert.equal(canAccess(GUIDE_ACCESS.student, SAMPLE_USERS.studentNoIdentity), false)
  assert.equal(canAccess(GUIDE_ACCESS.professor, SAMPLE_USERS.professorNoEmployee), false)
  assert.equal(canAccess(GUIDE_ACCESS.studentAffairs, SAMPLE_USERS.admissionsOnly), true)
  assert.equal(canAccess(GUIDE_ACCESS.studentAffairs, SAMPLE_USERS.admissionsNoScope), false)
  assert.equal(canAccess(GUIDE_ACCESS.examBoard, SAMPLE_USERS.manualOnly), true)
  assert.equal(canAccess(GUIDE_ACCESS.examBoard, SAMPLE_USERS.registrationStaff), true)
  assert.equal(canAccess(GUIDE_ACCESS.examBoard, SAMPLE_USERS.hrViewer), false)
  assert.equal(canAccess(GUIDE_ACCESS.dean, SAMPLE_USERS.vpScientific), false)
  assert.equal(canAccess(GUIDE_ACCESS.technical, id([], [], { organizational_unit: { code: '715' } })), false)
})

test('guide titles follow the viewer access on shared sidebars', () => {
  assert.equal(guideTitle(GUIDES.examBoard, SAMPLE_USERS.registrationStaff), 'القبول والتسجيل')
  assert.equal(guideTitle(GUIDES.examBoard, SAMPLE_USERS.examOfficer), 'هيئة الامتحانات')
  assert.equal(guideTitle(GUIDES.examBoard, SAMPLE_USERS.manualOnly), 'هيئة الامتحانات')
})

test('tasks, steps and flows are hidden without the real role, permission or scope', () => {
  // Professor: deprivation apply needs exams.manage (the API rule), and the 403 note is shown instead.
  const plain = stepTexts('professor', 'deprivation', SAMPLE_USERS.professor)
  assert.ok(!plain.some(text => text.includes('اضغط «تطبيق الحرمان»')))
  assert.ok(visibleTroubleshooting(GUIDES.professor, SAMPLE_USERS.professor).some(item => item.id === 'deprivation-403'))
  const board = { ...SAMPLE_USERS.professor, permissions: [...SAMPLE_USERS.professor.permissions, 'exams.manage'] }
  assert.ok(stepTexts('professor', 'deprivation', board).some(text => text.includes('اضغط «تطبيق الحرمان»')))

  // Add student: full ACCESS.studentAffairsAddStudent, with only the branch the viewer may use.
  const manual = stepTexts('studentAffairs', 'students-add', SAMPLE_USERS.studentsManager)
  assert.ok(manual.some(text => text.includes('الإدخال اليدوي')))
  assert.ok(!manual.some(text => text.includes('رفع طلاب المفاضلة')))
  const admissions = stepTexts('studentAffairs', 'students-add', SAMPLE_USERS.admissionsOnly)
  assert.ok(admissions.some(text => text.includes('رفع طلاب المفاضلة')))
  assert.ok(!admissions.some(text => text.includes('الإدخال اليدوي')))
  assert.ok(!taskIds('studentAffairs', SAMPLE_USERS.admissionsNoScope).includes('students-add'))
  assert.ok(!taskIds('studentAffairs', SAMPLE_USERS.registrar).includes('students-add'))
  assert.ok(!taskIds('studentAffairs', SAMPLE_USERS.admissionsOnly).includes('students-list'))

  // HR: faculty is view-only; writes need hr.manage.
  assert.deepEqual(taskIds('hr', SAMPLE_USERS.hrViewer), ['employees-view', 'positions', 'faculty'])
  assert.ok(taskIds('hr', SAMPLE_USERS.hrManager).includes('employees-manage'))
  assert.ok(!JSON.stringify(GUIDES.hr).includes('faculty-members'))
  assert.equal(stepTexts('hr', 'faculty', SAMPLE_USERS.hrManager).length, 1)
  assert.ok(!taskIds('academicStructure', SAMPLE_USERS.structureViewer).includes('structure-manage'))

  // Assigned-permission tasks do not appear for super_admin (backend ignores the bypass there).
  assert.ok(!taskIds('vpScientific', SAMPLE_USERS.superAdmin).includes('teaching-assignments'))
  assert.ok(!taskIds('vpScientific', SAMPLE_USERS.superAdmin).includes('semester-offerings'))
  assert.ok(!taskIds('student', SAMPLE_USERS.superAdmin).includes('supplementary'))

  // Manual grade entry does not expose the rest of the exam board.
  assert.deepEqual(taskIds('examBoard', SAMPLE_USERS.manualOnly), ['manual-grade-entry'])
  assert.deepEqual(taskIds('examBoard', SAMPLE_USERS.registrationStaff), ['approved-requests'])
  assert.ok(visibleUnavailable(GUIDES.examBoard, SAMPLE_USERS.registrationStaff).some(item => item.id === 'staff-registration'))
  assert.ok(!visibleUnavailable(GUIDES.examBoard, SAMPLE_USERS.registrationStaff).some(item => item.id === 'results'))

  // Multi-role: each guide shows only its own tasks, and scope-bound VP tasks need the scope.
  assert.ok(taskIds('dean', SAMPLE_USERS.deanAndVp).includes('registration-review'))
  assert.ok(!taskIds('vpScientific', SAMPLE_USERS.deanAndVp).includes('registration-review'), 'dean tasks stay in the dean guide')
  assert.ok(!taskIds('dean', SAMPLE_USERS.deanAndVp).includes('semester-offerings'), 'dean governance needs its own assigned permission')
  assert.ok(taskIds('vpScientific', SAMPLE_USERS.deanAndVp).includes('teaching-assignments'))
  assert.ok(!taskIds('vpScientific', SAMPLE_USERS.deanAndVp).includes('semester-offerings'))
  assert.deepEqual(taskIds('dean', SAMPLE_USERS.deanPlain), ['college-views'])
  assert.ok(!taskIds('vpScientific', { ...SAMPLE_USERS.vpScientific, access_scopes: college }).includes('semester-offerings'))
})

test('every guide link targets an existing route the viewer can open', async () => {
  const app = await src('app/App.jsx')
  for (const path of Object.keys(ROUTE_ACCESS)) assert.ok(app.includes(`path="${path}"`), `ROUTE_ACCESS ${path} exists in App.jsx`)

  const links = []
  const walk = value => {
    if (Array.isArray(value)) value.forEach(walk)
    else if (value && typeof value === 'object') {
      if (value.link?.to) links.push(value.link.to)
      Object.values(value).forEach(walk)
    }
  }
  walk(GUIDES)
  for (const to of links) assert.ok(ROUTE_ACCESS[to], `link ${to} is registered in ROUTE_ACCESS`)

  for (const [guideId, guide] of Object.entries(GUIDES)) {
    for (const [name, user] of Object.entries(SAMPLE_USERS)) {
      if (!canAccess(GUIDE_ACCESS[guideId], user)) continue
      for (const section of visibleSections(guide, user)) {
        for (const task of section.tasks) {
          for (const link of [task.link, ...task.steps.map(step => step.link)].filter(Boolean)) {
            assert.ok(ROUTE_ACCESS[link.to].every(guard => canAccess(guard, user)), `${guideId}/${task.id} → ${link.to} for ${name}`)
          }
          const raw = guide.sections.flatMap(s => s.tasks).find(t => t.id === task.id)
          if (raw.link) assert.ok(task.link, `${guideId}/${task.id} main page is reachable for ${name}`)
        }
      }
    }
  }
  assert.equal(resolveLink({ to: '/professor/grades' }, { ...SAMPLE_USERS.professor, permissions: ['attendance.manage'] }), null)
  assert.equal(resolveLink({ to: '/not-a-route' }, SAMPLE_USERS.superAdmin), null)
})

test('diagrams are well-formed and cite the real states and labels in the code', async () => {
  const cache = new Map()
  const file = async path => {
    if (!cache.has(path)) cache.set(path, await read(path))
    return cache.get(path)
  }
  for (const guide of Object.values(GUIDES)) {
    const tasks = guide.sections.flatMap(section => section.tasks)
    for (const task of tasks) {
      assert.ok(task.sources?.length > 0, `${guide.id}/${task.id} cites sources`)
      for (const { file: path, mustContain } of task.sources) {
        const text = await file(path)
        for (const needle of mustContain) assert.ok(text.includes(needle), `${guide.id}/${task.id}: "${needle}" in ${path}`)
      }
      for (const flow of task.flows ?? []) {
        const ids = new Set(flow.nodes.map(node => node.id))
        assert.equal(ids.size, flow.nodes.length, `${flow.title} unique ids`)
        assert.equal(flow.nodes[0].kind, 'start', `${flow.title} starts with a start node`)
        assert.ok(flow.nodes.some(node => node.kind === 'end'), `${flow.title} has an end`)
        for (const node of flow.nodes) {
          assert.ok(node.label && node.explain, `${flow.title}/${node.id} labelled`)
          for (const branch of node.branches ?? []) assert.ok(ids.has(branch.to), `${flow.title}/${node.id} → ${branch.to}`)
          if (node.kind === 'review') assert.ok(node.branches?.length >= 1 || flowsToNext(node), `${flow.title}/${node.id} review has outcomes`)
        }
      }
    }
    for (const item of guide.unavailable ?? []) {
      assert.ok(!item.link, `${guide.id}/${item.id} unavailable items carry no operating link`)
      if (item.source) for (const needle of item.source.mustContain) assert.ok((await file(item.source.file)).includes(needle), `${item.id}: ${needle}`)
    }
  }
  // Return paths are only drawn where the backend defines a return state.
  assert.ok(!JSON.stringify(GUIDES.studentAffairs.sections.find(s => s.id === 'ministry')).includes("'return'"))
  assert.ok(!GUIDES.studentAffairs.sections.find(s => s.id === 'ministry').tasks[0].flows[0].nodes.some(node => node.kind === 'return'))
})

test('troubleshooting and help sections are complete, safe and invent no contact details', () => {
  for (const code of ['login', '403', '422', '409', 'missing', 'save', 'unexpected']) assert.ok(COMMON_TROUBLESHOOTING.some(item => item.id === code), code)
  assert.ok(COMMON_TROUBLESHOOTING.every(item => ['user', 'admin'].includes(item.who)))
  assert.match(COMMON_TROUBLESHOOTING.find(item => item.id === '409').steps.join(' '), /قبل التحقق من نتيجتها/)
  assert.deepEqual(HELP_CHANNELS.map(channel => channel.id), ['account', 'technical', 'academic'])

  const everything = JSON.stringify({ GUIDES, COMMON_TROUBLESHOOTING, HELP_CHANNELS })
  assert.doesNotMatch(everything, /[\w.+-]+@[\w-]+\.[\w.]+/, 'no e-mail addresses')
  assert.doesNotMatch(everything, /(?:\+?\d[\d\s-]{7,}\d)/, 'no phone numbers')
  assert.doesNotMatch(everything, /https?:\/\//, 'no external URLs')

  const template = buildReportTemplate({ portalTitle: 'بوابة الطالب', pagePath: '/student/guide', time: new Date('2026-09-24T10:00:00Z') })
  for (const field of ['البوابة: بوابة الطالب', 'الصفحة: /student/guide', 'وقت المشكلة:', 'الخطوات التي نفذتها:', 'نص الخطأ أو رقمه']) assert.ok(template.includes(field), field)
  assert.doesNotMatch(template, /كلمة المرور|password|token|توكن|درجة|علامة/i)
})

test('guide UI uses the shared components and sends nothing to external services', async () => {
  const page = await src('features/user-guide/UserGuidePage.jsx')
  const support = await src('features/user-guide/components/GuideSupport.jsx')
  const flow = await src('features/user-guide/components/FlowDiagram.jsx')
  assert.match(page, /visibleSections\(guide, user\)/)
  assert.match(page, /غير متاح حاليًا/)
  assert.match(support, /عند حدوث مشكلة/)
  assert.match(support, /التواصل وطلب المساعدة/)
  assert.match(support, /navigator\.clipboard\.writeText\(template\)/)
  for (const file of [page, support, flow]) assert.doesNotMatch(file, /fetch\(|apiRequest|XMLHttpRequest|sendBeacon/)
  assert.match(flow, /dir="rtl"/)
  assert.match(flow, /NODE_KINDS\[node\.kind\]/)
  const pkg = JSON.parse(await read('frontend/package.json'))
  assert.deepEqual(Object.keys(pkg.dependencies).sort(), ['@tailwindcss/vite', 'framer-motion', 'html2canvas-pro', 'jspdf', 'react', 'react-dom', 'react-icons', 'react-router-dom', 'tailwindcss', 'xlsx'])
})
