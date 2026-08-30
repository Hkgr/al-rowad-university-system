import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import {
  isLatestStudentPickerRequest,
  STUDENT_PICKER_DEBOUNCE_MS,
  STUDENT_PICKER_PER_PAGE,
  studentPickerSearchPath,
} from '../src/features/exam-board/lib/studentPickerSearch.js'

const picker = readFileSync(new URL('../src/features/exam-board/components/StudentPicker.jsx', import.meta.url), 'utf8')
const gateway = readFileSync(new URL('../src/features/exam-board/pages/GradeSheetPage.jsx', import.meta.url), 'utf8')

test('student search uses the existing bounded server-side q endpoint', () => {
  const initial = new URL(`https://example.test${studentPickerSearchPath('')}`)
  const searched = new URL(`https://example.test${studentPickerSearchPath('  20261234  ')}`)

  assert.equal(STUDENT_PICKER_PER_PAGE, 25)
  assert.equal(initial.pathname, '/v1/students')
  assert.equal(initial.searchParams.get('page'), '1')
  assert.equal(initial.searchParams.get('per_page'), '25')
  assert.equal(initial.searchParams.has('q'), false)
  assert.equal(searched.searchParams.get('q'), '20261234')
  assert.equal(searched.searchParams.get('per_page'), '25')
})

test('server-side search is debounced and stale responses cannot replace the latest result', () => {
  assert.equal(STUDENT_PICKER_DEBOUNCE_MS, 350)
  assert.equal(isLatestStudentPickerRequest(8, 8), true)
  assert.equal(isLatestStudentPickerRequest(7, 8), false)
  assert.match(picker, /setTimeout\([\s\S]*setDebouncedQuery\(query\.trim\(\)\)[\s\S]*STUDENT_PICKER_DEBOUNCE_MS/)
  assert.match(picker, /apiRequest\(studentPickerSearchPath\(debouncedQuery\)\)/)
  assert.match(picker, /requestSequenceRef\.current = sequence/)
  assert.match(picker, /isLatestStudentPickerRequest\(sequence, requestSequenceRef\.current\)/)
  assert.match(picker, /setReloadKey\(value => value \+ 1\)/)
})

test('picker does not download or browser-filter a first-100 student snapshot', () => {
  assert.equal(picker.includes('per_page=100'), false)
  assert.equal(picker.includes('students.filter'), false)
  assert.equal(picker.includes('.filter('), false)
  assert.equal(picker.includes('rust.alrowaduni.edu.sy'), false)
  assert.equal(picker.includes('fetch('), false)
  assert.match(gateway, /navigate\(`\/exam-board\/grade-sheet\/\$\{student\.student_id\}`\)/)
})
