import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const studentPage = new URL('../src/features/student-dashboard/pages/StudentRegistration.jsx', import.meta.url)
const advisorQueue = new URL('../src/features/dean-dashboard/pages/DeanRegistrationRequests.jsx', import.meta.url)
const advisorDetail = new URL('../src/features/dean-dashboard/pages/DeanRegistrationModificationDetail.jsx', import.meta.url)
const appRoutes = new URL('../src/app/App.jsx', import.meta.url)

test('student modification UI consumes only backend workflow projections', async () => {
  const source = await readFile(studentPage, 'utf8')

  assert.match(source, /payload\?\.modification_workflow/)
  assert.match(source, /workflow\?\.schema_ready/)
  assert.match(source, /current\?\.editable === true/)
  assert.match(source, /hours\?\.below_recommended_minimum/)
  assert.match(source, /current\?\.failures/)
  assert.doesNotMatch(source, /Date\.now|new Date\(|startsAt\s*[<>=]|endsAt\s*[<>=]/)
})

test('approved official registrations expose no immediate drop control', async () => {
  const source = await readFile(studentPage, 'utf8')

  assert.doesNotMatch(source, /student\/registration\/\$\{[^}]+\}\/drop/)
  assert.match(source, /تعديل التسجيل المعتمد/)
  assert.match(source, /سيُحذف المقرر فقط بعد اعتماد المرشد الأكاديمي\./)
})

test('student delta controls remain read-only outside backend editable state', async () => {
  const source = await readFile(studentPage, 'utf8')

  assert.match(source, /const editable = current\?\.editable === true/)
  assert.match(source, /\{editable && item\.operation !== 'add'/)
  assert.match(source, /\{editable \? \(/)
  assert.match(source, /operation: item\.operation === 'remove' \? 'keep' : 'remove'/)
  assert.match(source, /registration\/modification\/submit/)
})

test('advisor queue and detail use the existing authority shell and backend decisions', async () => {
  const [queue, detail, routes] = await Promise.all([
    readFile(advisorQueue, 'utf8'),
    readFile(advisorDetail, 'utf8'),
    readFile(appRoutes, 'utf8'),
  ])

  assert.match(queue, /طلبات تعديل التسجيل/)
  assert.match(queue, /academic-advising\/\$\{resource\}/)
  assert.match(detail, /academic-advising\/registration-modifications/)
  assert.match(detail, /advisor_decision_open/)
  assert.match(detail, /\$\{id\}\/\$\{action\}/)
  assert.match(detail, /decide\('return'\)/)
  assert.match(detail, /decide\('approve'\)/)
  assert.match(routes, /dean\/registration-modifications\/:id/)
  assert.doesNotMatch(detail, /credit_hours\s*\*|overlap|prerequisite/i)
})
