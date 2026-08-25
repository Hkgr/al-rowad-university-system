import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const page = readFileSync(
  new URL('../src/features/professor-dashboard/pages/ProfessorSupplementaryExams.jsx', import.meta.url),
  'utf8',
)

function sourceBetween(startMarker, endMarker) {
  const start = page.indexOf(startMarker)
  const end = page.indexOf(endMarker, start)
  assert.notEqual(start, -1, `missing ${startMarker}`)
  assert.notEqual(end, -1, `missing ${endMarker}`)

  return page.slice(start, end)
}

test('supplementary occurrence indicator is backend-driven and informational', () => {
  const indicator = sourceBetween(
    'function SupplementaryExamOccurrenceIndicator(',
    'export default function ProfessorSupplementaryExams',
  )

  for (const copy of [
    'فترة الامتحانات التكميلية جارية',
    'خارج فترة الامتحانات التكميلية',
    'حالة فترة الامتحانات التكميلية غير متاحة',
  ]) assert.match(indicator, new RegExp(copy))

  for (const forbidden of ['new Date(', 'Date.now(', 'starts_at', 'ends_at', 'editable', 'canSave', 'canSubmit', 'disabled=']) {
    assert.equal(indicator.includes(forbidden), false, `${forbidden} must stay outside occurrence presentation`)
  }
})

test('grading capabilities remain governed by the existing period workflow', () => {
  assert.match(page, /const editable = Boolean\(serverCanEdit && periodStatus === 'grading_open'\)/)
  const save = sourceBetween('const save = async () =>', 'const submit = () =>')
  const submit = sourceBetween('const performSubmit = async (action) =>', 'return (')
  for (const mutation of [save, submit]) {
    assert.equal(mutation.includes('occurrence'), false)
    assert.equal(mutation.includes('is_occurring'), false)
  }
})

test('read refreshes separate occurrence state while mutations preserve it', () => {
  assert.match(page, /const \[occurrence, setOccurrence\] = useState\(null\)/)
  assert.match(page, /setOccurrence\(nextSheet\?\.supplementary_exam_occurrence \?\? null\)/)
  assert.match(page, /setOccurrence\(null\)/)

  const save = sourceBetween('const save = async () =>', 'const submit = () =>')
  const submit = sourceBetween('const performSubmit = async (action) =>', 'return (')
  assert.equal(save.includes('setOccurrence('), false)
  assert.equal(submit.includes('setOccurrence('), false)
  assert.match(page, /<SupplementaryExamOccurrenceIndicator occurrence=\{occurrence\} \/>/)
})
