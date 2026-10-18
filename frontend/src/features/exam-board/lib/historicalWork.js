import { apiRequest } from '../../../services/apiClient.js'

export const historicalWorkKinds = {
  ungraded_followup: 'عمل غير مُقيّم — ليس منحة I',
  aggregate_incomplete_followup: 'مجموع مرصود يحتاج تفصيلًا',
  correction_review: 'تصحيح نتيجة — مقترح يحتاج مراجعة واعتمادًا',
  final_absence_review: 'غياب عن النهائي — بانتظار مراجعة مخوّلة',
}
export function historicalWorkQuery(search) {
  const source = new URLSearchParams(search), result = new URLSearchParams()
  for (const key of ['student_id', 'academic_year_id', 'semester_id', 'kind', 'page']) if (source.get(key)) result.set(key, source.get(key))
  return result
}
export async function historicalWorkRead(path, signal) {
  const envelope = await apiRequest('/v1/exams/historical-work' + path, { signal })
  const data = envelope?.data
  const valid = path.startsWith('?')
    ? Array.isArray(data?.items) && Number.isInteger(data?.meta?.total) && data.items.every(row => row.work_item_id && row.student_id && row.course_id)
    : data?.work_item_id === path.slice(1) && data.student && data.course && data.period && data.assessment_groups && data.capabilities
  if (envelope?.success !== true || !valid) throw new Error('استجابة العمل المستورد غير مكتملة؛ لم تُعرض بيانات بديلة.')
  return data
}
export function historicalNextAction(data) {
  if (data.adopted) return 'تمت مراجعة هذا العمل وتثبيت نتيجته بإجراء مدقق. التعديل العادي مقفل.'
  if (data.kind === 'final_absence_review') return 'يلزم فحص الغياب والنتيجة الحالية عبر مراجعة التصحيح المخوّلة. لا تُفترض علامة صفر للامتحان.'
  if (data.kind === 'ungraded_followup') return 'تُستكمل العلامات الفعلية عبر الإدخال اليدوي المخوّل. هذه ليست منحة I، ولا تُنشأ لها مهلة أو نتيجة تلقائيًا.'
  const guidance = data.guidance
  const gaps = []
  if (guidance?.missing_activity_marks) gaps.push('توجد علامات أنشطة فعلية غائبة؛ يلزم توثيقها من مصدرها. لا توزّع العلامة أو تحسب الباقي تلقائيًا.')
  if (guidance?.unproven_examination_evidence) gaps.push('دليل الامتحان النهائي غير مثبت؛ يلزم توثيق علامته ومحاولته من مصدر مستقل. لا تُستنتج علامته من المجموع أو الفرق.')
  if (gaps.length) return gaps.join(' ')
  if (guidance?.correction_proposal === 'historical_source_aggregates') return 'مجموعا أعمال الفصل والامتحان النهائي المرصودان في المصدر محفوظان في مقترح تصحيح تاريخي، دون اختلاق علامات أنشطة. يلزم إجراء مراجعة مخوّلة جديدة ثم اعتماد صريح منفصل. الاستعراض لا يغيّر النتيجة الحالية، وحفظ المقترح لا يعني اعتماده.'
  if (guidance?.correction_proposal === 'component_assessments') return 'مقترح تصحيح نتيجة محفوظ للمراجعة؛ يلزم إجراء مراجعة مخوّلة جديدة ثم اعتماد صريح منفصل. الاستعراض لا يغيّر النتيجة الحالية، وحفظ المقترح لا يعني اعتماده.'
  return 'يلزم فحص سياق التسجيل والتقييم والأدلة المحفوظة عبر المراجعة المخوّلة؛ الاستعراض لا يثبت اكتمالها ولا يغيّر النتيجة الحالية. لا توزّع العلامة أو تحسب الباقي تلقائيًا.'
}
