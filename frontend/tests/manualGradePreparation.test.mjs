import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { contextPath, preparationMarks, rebasePreparationValues } from '../src/features/exam-board/lib/manualGradePreparation.js'
const before = { components: [{ key: 'new:theoretical', name: 'Theory', component_type: 'theoretical', max_mark: 60, mark: null }] }
test('preparation values preserve zero, null, decimal precision and official maximum', () => {
  assert.deepEqual(preparationMarks(before, { 'new:theoretical': '0' }), [{ key: 'new:theoretical', mark: 0 }])
  assert.equal(preparationMarks(before, { 'new:theoretical': '' })[0].mark, null)
  for (const v of ['-1', 'Infinity', '60.01', '1.123']) assert.throws(() => preparationMarks(before, { 'new:theoretical': v }))
  assert.equal(contextPath(1, 2, 'save'), '/v1/exams/manual-grade-entry/students/1/courses/2/context-save')
})
test('explicit recovery maps newly persisted component IDs without silently dropping values', () => {
  const after = { components: [{ ...before.components[0], key: '43', mark: 20 }] }
  const draft = { 'new:theoretical': '21' }
  assert.deepEqual(rebasePreparationValues(before, after, draft), { 43: '21' })
  assert.deepEqual(draft, { 'new:theoretical': '21' })
  assert.equal(rebasePreparationValues(before, { components: [] }, draft), null)
  assert.equal(rebasePreparationValues(before, { components: [...after.components, { ...after.components[0], key: '44' }] }, draft), null)
})
test('integrated row keeps local input and confirmation separate from canonical submission', () => {
  const read = path => readFileSync(new URL(path, import.meta.url), 'utf8')
  const row = read('../src/features/exam-board/components/PreparationGradeRow.jsx')
  for (const value of ['contextPath', 'preview', 'preparationMarks', 'confirmed: true', 'acknowledged: true', 'setServer(null)', 'setRetry', 'rebasePreparationValues', 'onDirty(key, true)', 'onSaved(response.data)']) assert.ok(row.includes(value), value)
  assert.doesNotMatch(row, /\/submit|\/approve|window\.confirm|window\.prompt|setTimeout.*confirm/)
  assert.match(row, /useState\(false\)/)
  const page = read('../src/features/exam-board/pages/StudentManualGradePage.jsx')
  assert.match(page, /json.data.academic_years/)
  assert.match(page, /term=\{selectedTerm\}/)
  assert.match(page, /سنة العلامات/)
  assert.match(page, /فصل العلامات/)
  assert.match(page, /historyMode \? historyTerm : term/)
  assert.doesNotMatch(page, /السنة الأكاديمية الفعلية|السياق الفعلي|الفصل الفعلي/)
})
