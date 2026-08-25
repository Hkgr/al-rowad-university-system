import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
  eligibilityBlockerLabel,
  materializationStatusLabel,
  periodStatusLabel,
  reconciliationStatusLabel,
  registrationStatusLabel,
  resultStatusLabel,
  workflowStatusLabel,
} from '../src/features/supplementary-exams/supplementaryStatus.js'
import { canAccess } from '../src/features/auth/auth.js'

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')

test('central status presentation covers supplementary domains with neutral unknowns', () => {
  for (const [formatter, known] of [
    [periodStatusLabel, 'results_materialized'],
    [registrationStatusLabel, 'registered'],
    [workflowStatusLabel, 'published'],
    [resultStatusLabel, 'passed'],
    [materializationStatusLabel, 'materialized'],
    [reconciliationStatusLabel, 'CONFLICT'],
    [eligibilityBlockerLabel, 'practical_failed'],
  ]) {
    assert.notEqual(formatter(known), formatter('future_unknown_state'))
    assert.match(formatter('future_unknown_state'), /غير معروف|غير متاح|غير محدد/)
  }
})

test('professor payload and page remain strictly theoretical-only', () => {
  const page = read('src/features/professor-dashboard/pages/ProfessorSupplementaryExams.jsx')
  assert.ok(page.includes('theoretical_mark'))
  assert.ok(page.includes('preserved_practical_mark'))
  assert.equal(/<input[^>]+practical/i.test(page), false)
  for (const forbidden of ['practical_mark:', 'practical_total:', 'practical_components:', 'components:']) {
    assert.equal(page.includes(forbidden), false, forbidden)
  }
})

test('critical supplementary pages use native RTL dialogs and no browser prompts', () => {
  const paths = [
    'src/features/student-dashboard/pages/StudentSupplementaryExams.jsx',
    'src/features/student-affairs/pages/SupplementaryExamRegistrations.jsx',
    'src/features/professor-dashboard/pages/ProfessorSupplementaryExams.jsx',
    'src/features/exam-board/pages/SupplementaryGradesPage.jsx',
  ]
  for (const path of paths) {
    const source = read(path)
    assert.ok(source.includes('SupplementaryConfirmDialog'), path)
    assert.equal(source.includes('window.prompt'), false, path)
    assert.equal(source.includes('window.confirm'), false, path)
  }
  const shared = read('src/features/supplementary-exams/SupplementaryUi.jsx')
  assert.ok(shared.includes('dir="rtl"'))
  assert.ok(shared.includes('reasonRequired'))
})

test('student result semantics never call an unmaterialized preview official', () => {
  const page = read('src/features/student-dashboard/pages/StudentSupplementaryExams.jsx')
  assert.ok(page.includes('published_supplementary_result'))
  assert.ok(page.includes('official_result'))
  assert.ok(page.includes('لم يُحدّث السجل الأكاديمي الرسمي بعد'))
  assert.ok(page.includes('تم تحديث نتيجتك الأكاديمية الرسمية'))
})

test('exam board uses canonical fields and backend capabilities without alias soup', () => {
  const page = read('src/features/exam-board/pages/SupplementaryGradesPage.jsx')
  for (const required of ['row?.offering', 'row?.roster', 'row.action_flags?.can_publish', 'materialization.can_materialize']) {
    assert.ok(page.includes(required), required)
  }
  for (const forbidden of ['row?.supplementary_exam_offering', 'row?.candidates', 'row?.registrations', 'row.actions?.']) {
    assert.equal(page.includes(forbidden), false, forbidden)
  }
})

test('supplementary route and navigation gates require backend-matching authority', () => {
  const app = read('src/app/App.jsx')
  const auth = read('src/features/auth/auth.js')
  for (const role of ['student', 'registration_officer', 'doctor_instructor', 'exam_officer', 'dean', 'vice_president_scientific']) {
    assert.ok(app.includes(`allRoles: ['${role}']`), role)
  }
  assert.ok(auth.includes('assignedPermissions'))
  assert.ok(auth.includes('hasAssignedPermission'))
  const navContracts = [
    ['src/features/student-dashboard/nav.js', "allRoles: ['student']", 'supplementary_exams.registrations.self'],
    ['src/features/student-affairs/nav.js', "allRoles: ['registration_officer']", 'supplementary_exams.registrations.view'],
    ['src/features/professor-dashboard/nav.js', "allRoles: ['doctor_instructor']", 'supplementary_exams.grades.view'],
    ['src/features/dean-dashboard/nav.js', "allRoles: ['dean']", 'supplementary_exams.offerings.view'],
    ['src/features/exam-board/nav.js', "allRoles: ['exam_officer']", 'supplementary_exams.grades.review'],
    ['src/features/vice-presidency/nav.js', 'ROLES.vicePresidentScientific', 'PERMISSIONS.supplementaryExamsPeriodsView'],
  ]
  for (const [path, role, permission] of navContracts) {
    const source = read(path)
    assert.ok(source.includes(role), `${path}: ${role}`)
    assert.ok(source.includes(permission), `${path}: ${permission}`)
  }
  const vpHome = read('src/features/vice-presidency/pages/VicePresidentShell.jsx')
  assert.ok(vpHome.includes('hasRole(ROLES.vicePresidentScientific, identity)'))
  assert.ok(vpHome.includes('hasAssignedPermission(PERMISSIONS.supplementaryExamsPeriodsView, identity)'))

  const gate = { allRoles: ['exam_officer'], assignedPermissions: ['supplementary_exams.grades.review'] }
  assert.equal(canAccess(gate, { roles: ['super_admin'], permissions: [] }), false)
  assert.equal(canAccess(gate, { roles: ['exam_officer'], permissions: [] }), false)
  assert.equal(canAccess(gate, { roles: ['exam_officer'], permissions: ['supplementary_exams.grades.review'] }), true)
})
