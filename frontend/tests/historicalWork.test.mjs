import assert from 'node:assert/strict'
import test from 'node:test'
import { historicalNextAction, historicalWorkQuery } from '../src/features/exam-board/lib/historicalWork.js'

test('imported work has its own filter allowlist, never a mutation or a formal I state', () => {
  assert.equal(historicalWorkQuery('view=imported&work=uuid&state=active&student_id=7&kind=ungraded_followup&page=2&academic_year_id=5').toString(), 'student_id=7&academic_year_id=5&kind=ungraded_followup&page=2')
  assert.match(historicalNextAction({ kind: 'ungraded_followup' }), /ليست منحة I/)
  assert.match(historicalNextAction({ kind: 'aggregate_incomplete_followup' }), /لا توزّع العلامة/)
  assert.match(historicalNextAction({ kind: 'final_absence_review' }), /لا تُفترض علامة صفر/)
  assert.match(historicalNextAction({ adopted: true }), /التعديل العادي مقفل/)
})
