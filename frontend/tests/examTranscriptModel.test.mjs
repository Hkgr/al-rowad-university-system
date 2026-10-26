import assert from 'node:assert/strict'
import test from 'node:test'
import { arabicMarkWords, displayNumber, pairExamTerms } from '../src/features/exam-board/lib/examTranscriptModel.js'

test('Arabic marks preserve zero, decimal digits and missing values without inventing results', () => {
  assert.equal(arabicMarkWords(0), 'صفر')
  assert.equal(arabicMarkWords(98), 'ثمانية وتسعون')
  assert.equal(arabicMarkWords(100), 'مائة')
  assert.equal(arabicMarkWords('87.50'), 'سبعة وثمانون فاصلة خمسة')
  assert.equal(arabicMarkWords('50.05'), 'خمسون فاصلة صفر خمسة')
  assert.equal(arabicMarkWords('٩٨'), 'ثمانية وتسعون')
  for (const missing of [null, undefined, '', 'W', 'NaN', -1, 101]) assert.equal(arabicMarkWords(missing), '')
  assert.equal(displayNumber(0, 2), '0.00')
  assert.equal(displayNumber(null, 2), '')
})

test('semester one is on the right, semester two on the left, summer and all additional years survive', () => {
  const terms = []
  for (let year = 1; year <= 7; year += 1) {
    for (const semester of [1, 2, 3]) terms.push({
      academic_year: { academic_year_id: year },
      semester: { semester_order: semester },
      courses: [{ registration_id: year * 10 + semester }],
    })
  }
  const pairs = pairExamTerms(terms)
  assert.equal(pairs.length, 14)
  assert.equal(pairs[0][0], terms[0])
  assert.equal(pairs[0][1], terms[1])
  assert.equal(pairs[1][0], terms[2])
  assert.equal(pairs[1][1], null)
  assert.deepEqual(pairs.flat().filter(Boolean), terms)
  assert.equal(terms[0].historical_academic_level, undefined)
})

test('a lone second semester retains its left column and unknown years are not merged', () => {
  const second = { academic_year: { academic_year_id: 3 }, semester: { semester_order: 2 } }
  assert.deepEqual(pairExamTerms([second]), [[null, second]])
  const unknown = [{ semester: {} }, { semester: {} }]
  assert.deepEqual(pairExamTerms(unknown), [[unknown[0], null], [unknown[1], null]])
})
