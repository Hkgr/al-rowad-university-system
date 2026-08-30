import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import {
  academicRequirementPresentation,
  groupRequirementsByScope,
  requirementGroupPresentation,
} from '../src/components/academic/requirementProgress.js'
import { transcriptGenerationMetadata } from '../src/features/exam-board/lib/academicRecordPresentation.js'

const app = readFileSync(new URL('../src/app/App.jsx', import.meta.url), 'utf8')
const searchPage = readFileSync(new URL('../src/features/exam-board/pages/GradeSheetPage.jsx', import.meta.url), 'utf8')
const recordPage = readFileSync(new URL('../src/features/exam-board/pages/ExamStudentAcademicRecordPage.jsx', import.meta.url), 'utf8')
const studentRequirements = readFileSync(new URL('../src/features/student-dashboard/pages/StudentRequirements.jsx', import.meta.url), 'utf8')
const sharedProgress = readFileSync(new URL('../src/components/academic/AcademicRequirementProgress.jsx', import.meta.url), 'utf8')
const transcriptPdf = readFileSync(new URL('../src/features/exam-board/lib/transcriptPdf.js', import.meta.url), 'utf8')
const recordPresentation = readFileSync(new URL('../src/features/exam-board/lib/academicRecordPresentation.js', import.meta.url), 'utf8')

test('known requirement scopes retain order and additional backend scopes remain visible', () => {
  const grouped = groupRequirementsByScope([
    { requirement_group_id: 4, requirement_scope: 'custom_scope' },
    { requirement_group_id: 3, requirement_scope: 'department' },
    { requirement_group_id: 1, requirement_scope: 'university' },
    { requirement_group_id: 2, requirement_scope: 'college' },
  ])

  assert.deepEqual(grouped.map(([scope]) => scope), ['university', 'college', 'department', 'custom_scope'])
  assert.equal(grouped.at(-1)[1][0].requirement_group_id, 4)
})

test('mandatory progress uses course counts while elective progress uses required and earned hours', () => {
  const mandatory = requirementGroupPresentation({
    requirement_type: 'mandatory',
    required_credit_hours: 12,
    earned_hours: 6,
    course_count: 3,
    passed_courses: [{}, {}],
  })
  const elective = requirementGroupPresentation({
    requirement_type: 'elective',
    required_credit_hours: 9,
    earned_hours: 3,
    course_count: 9,
    passed_courses: [{}, {}, {}, {}, {}],
  })

  assert.equal(mandatory.progress, 67)
  assert.equal(mandatory.passedCount, 2)
  assert.equal(elective.progress, 33)
})

test('official graduation counted hours augment progress without replacing canonical progress values', () => {
  const view = academicRequirementPresentation({
    academic_program_id: 9,
    total_required_hours: 120,
    earned_curriculum_hours: 82,
    groups: [{
      requirement_group_id: 7,
      requirement_scope: 'college',
      requirement_type: 'elective',
      required_credit_hours: 6,
      earned_hours: 9,
      remaining_hours: 0,
      completed: true,
    }],
  }, {
    eligible: false,
    total_required_hours: 120,
    actual_earned_curriculum_hours: 82,
    graduation_counted_hours: 79,
    remaining_graduation_hours: 41,
    groups: [{ requirement_group_id: 7, graduation_counted_hours: 6 }],
    blockers: [{ code: 'academic_requirements_incomplete' }],
  })

  assert.equal(view.groups[0].earned_hours, 9)
  assert.equal(view.groups[0].graduation_counted_hours, 6)
  assert.equal(view.countedHours, 79)
  assert.equal(view.remainingHours, 41)
})

test('search navigation and protected independent record route use student ID only', () => {
  assert.match(searchPage, /navigate\(`\/exam-board\/grade-sheet\/\$\{student\.student_id\}`\)/)
  assert.match(app, /path="\/exam-board\/grade-sheet\/:studentId"/)
  assert.match(app, /protect\(<ExamStudentAcademicRecordPage \s*\/>, \{ permissions: \['grades\.view'\] \}\)/)
  assert.equal(searchPage.includes('location.state'), false)
  assert.equal(recordPage.includes('StudentPicker'), false)
})

test('student and Exam Board pages use one presentation component without changing self endpoints', () => {
  assert.match(studentRequirements, /AcademicRequirementProgress/)
  assert.match(recordPage, /AcademicRequirementProgress/)
  assert.match(studentRequirements, /apiRequest\('\/v1\/student\/requirements'\)/)
  assert.match(studentRequirements, /apiRequest\('\/v1\/student\/graduation-eligibility'\)/)
  assert.match(sharedProgress, /REQUIREMENT_SCOPE_LABELS\[scope\] \|\| scope/)
  assert.match(sharedProgress, /isMandatory && view\.courseCount > 0/)
  assert.match(sharedProgress, /isElective/)
  assert.match(sharedProgress, /selfView \? 'استوفيت متطلبات الخطة الأكاديمية/)
  assert.match(sharedProgress, /: 'استوفى الطالب متطلبات الخطة الأكاديمية/)
  assert.match(studentRequirements, /eligibility=\{eligibility\} selfView/)
  assert.match(recordPage, /eligibility=\{record\.requirements\.graduation_eligibility\} \/>/)
})

test('generation identity and time are backend supplied with safe PDF fallbacks', () => {
  const employee = transcriptGenerationMetadata({
    generated_at: '2026-08-30T09:10:11+00:00',
    timezone: 'Asia/Damascus',
    generated_by: {
      display_name: 'سارة خالد',
      username: 'exam.sara',
      organizational_unit: { code: 'EXAM', name: 'قسم الامتحانات' },
    },
  })
  const usernameFallback = transcriptGenerationMetadata({
    generated_at: 'not-a-date',
    generated_by: { username: 'exam.fallback' },
  })

  assert.notEqual(employee.generatedAt, '—')
  assert.equal(employee.generatedBy, 'سارة خالد')
  assert.equal(employee.organizationalUnit, 'قسم الامتحانات')
  assert.deepEqual(usernameFallback, { generatedAt: '—', generatedBy: 'exam.fallback', organizationalUnit: '' })
  assert.match(recordPage, /const fresh = await apiRequest\(endpoint\)/)
  assert.match(transcriptPdf, /transcriptGenerationMetadata\(academicRecord\?\.generation\)/)
  assert.match(recordPresentation, /generation\.generated_at/)
  assert.match(recordPresentation, /generatedBy\.display_name \|\| generatedBy\.username \|\| '—'/)
  assert.match(recordPresentation, /organizational_unit\?\.name/)
  assert.equal(/function generationTimestamp\(now = new Date\(\)\)/.test(transcriptPdf + recordPresentation), false)
  assert.match(transcriptPdf, /تاريخ ووقت الإنشاء/)
  assert.match(transcriptPdf, /تم الإنشاء بواسطة/)
})

test('PDF includes requirement tables and neutral empty/unavailable states in the existing safe paginator', () => {
  assert.match(transcriptPdf, /requirementTableHeader/)
  assert.match(transcriptPdf, /requirementScopes\.forEach/)
  assert.match(transcriptPdf, /تقدم الخطة الدراسية غير متاح/)
  assert.match(transcriptPdf, /لا توجد بيانات دراسية/)
  assert.match(transcriptPdf, /paginateMeasuredSections/)
  assert.match(transcriptPdf, /data\.renderRows\(rows\)/)
  assert.match(transcriptPdf, /نسخة إلكترونية غير مصدقة/)
})
