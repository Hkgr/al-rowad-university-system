import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = path => readFile(new URL(path, import.meta.url), 'utf8')

test('student replacement UI uses backend deadline and workflow capabilities only', async () => {
  const source = await read('../src/features/student-dashboard/pages/StudentRegistration.jsx')
  assert.match(source, /payload\?\.replacement_workflow/)
  assert.match(source, /deadline\?\.student_registration_open === true/)
  assert.match(source, /student\/registration\/replacement\/items/)
  assert.match(source, /method: 'PATCH'/)
  assert.match(source, /method: 'DELETE'/)
  assert.match(source, /يبقى المقرر المصدر ملغى تاريخياً/)
  assert.doesNotMatch(source, /new Date\(|Date\.now|available_seats|capacity/)
})

test('advisor replacement review is additive to the existing queue and has no browser academic logic', async () => {
  const [queue, detail, routes] = await Promise.all([read('../src/features/dean-dashboard/pages/DeanRegistrationRequests.jsx'), read('../src/features/dean-dashboard/pages/DeanRegistrationReplacementDetail.jsx'), read('../src/app/App.jsx')])
  assert.match(queue, /registration-replacements/)
  assert.match(detail, /academic-advising\/registration-replacements/)
  assert.match(detail, /\$\{id\}\/\$\{action\}/)
  assert.match(routes, /dean\/registration-replacements\/:id/)
  assert.doesNotMatch(detail, /overlap|prerequisite|credit_hours\s*[+*]/i)
})

test('Dean and Scientific VP surfaces preserve recommendation and decision separation', async () => {
  const [dean, scientific, nav] = await Promise.all([read('../src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx'), read('../src/features/vice-presidency/pages/MinimumEnrollmentQueue.jsx'), read('../src/features/vice-presidency/nav.js')])
  assert.match(dean, /recommendMinimum\(review, 'continue'\)/)
  assert.match(dean, /recommendMinimum\(review, 'cancel'\)/)
  assert.match(scientific, /decide\(row, 'continue'\)/)
  assert.match(scientific, /decide\(row, 'cancel'\)/)
  assert.match(scientific, /النائب الإداري/)
  assert.match(nav, /minimum-enrollment/)
})
