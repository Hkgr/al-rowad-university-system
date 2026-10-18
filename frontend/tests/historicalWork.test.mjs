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

test('complete recorded aggregate and ordinary correction proposals require fresh review then separate adoption', () => {
  const aggregate = historicalNextAction({ kind: 'correction_review', guidance: { correction_proposal: 'historical_source_aggregates' } })
  assert.match(aggregate, /مجموعا أعمال الفصل والامتحان النهائي/)
  assert.match(aggregate, /مراجعة مخوّلة جديدة ثم اعتماد صريح منفصل/)
  assert.match(aggregate, /الاستعراض لا يغيّر النتيجة الحالية/)
  assert.doesNotMatch(aggregate, /غير مكتمل|يلزم توثيق|جاهز|تم اعتماد/)
  const ordinary = historicalNextAction({ kind: 'correction_review', guidance: { correction_proposal: 'component_assessments' } })
  assert.match(ordinary, /مقترح تصحيح نتيجة/)
  assert.match(ordinary, /مراجعة مخوّلة جديدة ثم اعتماد صريح منفصل/)
  assert.doesNotMatch(ordinary, /دليل الامتحان غير مكتمل/)
  // A technical work kind alone cannot certify the evidence or readiness.
  assert.doesNotMatch(historicalNextAction({ kind: 'correction_review' }), /مجموعا أعمال الفصل|جاهز|تم اعتماد/)
})

test('actual missing activities and unproven examination evidence have distinct truthful instructions', () => {
  const activity = historicalNextAction({ kind: 'aggregate_incomplete_followup', guidance: { missing_activity_marks: true } })
  assert.match(activity, /علامات أنشطة فعلية غائبة/)
  assert.match(activity, /لا توزّع العلامة/)
  const examination = historicalNextAction({ kind: 'aggregate_incomplete_followup', guidance: { unproven_examination_evidence: true } })
  assert.match(examination, /دليل الامتحان النهائي غير مثبت/)
  assert.doesNotMatch(examination, /تفاصيل الأنشطة/)
  const both = historicalNextAction({ guidance: { missing_activity_marks: true, unproven_examination_evidence: true } })
  assert.match(both, /علامات أنشطة فعلية غائبة/); assert.match(both, /دليل الامتحان النهائي غير مثبت/)
  for (const data of [{ adopted: true }, { kind: 'final_absence_review' }, { kind: 'ungraded_followup' }]) {
    assert.equal(historicalNextAction({ ...data, guidance: { correction_proposal: 'historical_source_aggregates', missing_activity_marks: true } }), historicalNextAction(data))
  }
})
