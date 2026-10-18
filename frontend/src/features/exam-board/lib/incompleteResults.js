import { apiRequest } from '../../../services/apiClient.js'

export const incompleteBase = '/v1/exams/incomplete-results'
export const incompletePage = '/exam-board/incomplete-results'
export const caseStates = { active: 'نشطة', resolved: 'مسوّاة', expired: 'منتهية' }
export const expiryOutcomes = { F: 'F — رسوب', Z: 'Z — حرمان' }
export const registrationResultStates = { no_result: 'لا توجد نتيجة محفوظة', pending_approval: 'نتيجة بانتظار الاعتماد', ordinary_missing_components: 'نقص مكونات عادي — ليس منحة I موثقة', official_result: 'نتيجة رسمية', incomplete_active: 'I نشطة موثقة', incomplete_resolved: 'نتيجة مسوّاة لها تاريخ I', incomplete_expired: 'نتيجة انتهاء مهلة I' }
export const operationLabels = { grant: 'منح I', deadline_changed: 'تعديل المهلة', resolved: 'تسوية', expired: 'انتهاء المهلة' }
export const incompleteLabels = {
  incomplete_supplementary_conflict: 'يوجد تأجيل أو سياق تكميلي متعارض؛ لا يُعاد تغييره تلقائيًا لمعالجة I.',
  incomplete_components_not_configured: 'المكونات المطلوبة غير معرّفة؛ يلزم تجهيزها عبر المسار المخوّل.',
  incomplete_component_configuration_invalid: 'تعريف المكونات غير صالح للتسوية. راجع تجهيز الطرح.',
  incomplete_components_missing: 'أكمل العلامات المطلوبة الغائبة فقط قبل التسوية.',
  incomplete_completed_component_locked: 'لا يجوز تغيير علامة مكتملة عبر تسوية I، بما فيها الصفر.',
  incomplete_context_stale: 'تغيّر سياق التسجيل؛ احتُفظ بالمقترح ويلزم مراجعة جديدة.',
  incomplete_request_conflict: 'معرّف هذه المحاولة مرتبط بمحتوى أو منفذ مختلف؛ لن تُعاد الكتابة.',
  incomplete_registration_not_eligible: 'حالة التسجيل غير مؤهلة لهذا الإجراء.',
  incomplete_registration_transition_locked: 'هذا التسجيل مرتبط بدورة I موثقة؛ لا يدعم هذا المسار انسحابه أو إسقاطه أو إلغاءه.',
  incomplete_outcome_locked: 'لهذا التسجيل دورة I موثقة؛ التعديل العادي مقفل. استخدم مساحة النتائج غير المكتملة.',
  incomplete_stale: 'تغيّرت الحالة. احتفظنا بمقترحك؛ راجع الحالة الحالية قبل إعداد عملية جديدة.',
  incomplete_deadline_elapsed: 'انتهت المهلة. يمكن تمديد الحالة النشطة صراحة قبل التسوية.',
  incomplete_already_settled: 'هذه الحالة نهائية ولا يمكن إعادة فتحها.',
  incomplete_grant_not_allowed: 'لا يسمح وضع التسجيل أو نتيجته بمنح I.',
  incomplete_outcome_conflict: 'النتيجة الحالية لا تطابق التسوية الموثقة؛ يلزم مراجعة مخوّلة، ولن تُصلح تلقائيًا.',
  course_result_symbols_schema_not_ready: 'مخطط النتائج غير المكتملة غير جاهز. راجع مسؤول النظام.',
  completed_component_locked: 'علامة مكتملة محفوظة؛ للقراءة فقط.',
  grade_components_not_configured: 'تعريف المكونات المطلوبة غير مكتمل أو غير متوافق؛ لا يمكن التسوية هنا.',
}
export function incompleteError(error) {
  if (error.status === 403 || error.status === 401) return 'انتهت صلاحية الوصول لهذه البيانات.'
  if (error.status === 422) return 'تحقق من الحقول المطلوبة والموعد وحدود العلامات. ' + Object.values(error.details || {}).flat().join(' ')
  return incompleteLabels[error.errorCode] || (error.status === 409 ? 'تعارض في الحالة؛ راجع البيانات الحالية مع الاحتفاظ بمقترحك.' : error.message || 'تعذّر الاتصال بالخادم.')
}
export function damascusTime(value) {
  if (!value) return 'غير محدد'
  // Recorded calendar snapshots contain DB timestamps in UTC without an offset.
  const utcValue = typeof value === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(value) ? value.replace(' ', 'T') + 'Z' : value
  const date = new Date(utcValue)
  return Number.isNaN(date.getTime()) ? 'غير محدد' : new Intl.DateTimeFormat('ar-SY', { timeZone: 'Asia/Damascus', dateStyle: 'medium', timeStyle: 'short', hour12: false }).format(date)
}
export function listQuery(search) {
  const source = new URLSearchParams(search), query = new URLSearchParams()
  for (const key of ['state', 'student_id', 'academic_year_id', 'semester_id', 'page']) if (source.get(key)) query.set(key, source.get(key))
  return query
}
export async function incompleteRead(path, signal) {
  const json = await apiRequest(incompleteBase + path, { signal })
  if (json?.success !== true || !json.data || typeof json.data !== 'object') throw new Error('استجابة غير متوافقة؛ لم تُعرض بيانات بديلة.')
  const data = json.data
  const validIdentity = row => row?.student && row?.course && row?.academic_year && row?.semester
  const valid = path.startsWith('?')
    ? Array.isArray(data.data) && Number.isInteger(data.total) && data.data.every(validIdentity)
    : validIdentity(data) && Array.isArray(data.components) && data.allowed_operations && data.resolution
  if (!valid) throw new Error('بيانات القراءة غير مكتملة؛ أعد المحاولة أو راجع المسؤول.')
  return json.data
}
export const blankProposal = action => action === 'grant' ? { reason: '', requirements: '', expiry_outcome: '', deadline_at: '' } : action === 'deadline' ? { deadline_at: '' } : { marks: {} }
export function freezeAttempt(view, action, proposal, uuid = crypto.randomUUID()) {
  const body = { request_id: uuid, expected_revision: view.case?.revision ?? 0 }
  let path
  if (action === 'grant') {
    if (!proposal.reason.trim() || !proposal.requirements.trim() || !['F', 'Z'].includes(proposal.expiry_outcome)) throw new Error('أدخل السبب والمتطلبات واختر مخرج الانتهاء F أو Z صراحة.')
    Object.assign(body, { reason: proposal.reason.trim(), requirements: proposal.requirements.trim(), expiry_outcome: proposal.expiry_outcome })
    if (proposal.deadline_at) body.deadline_at = proposal.deadline_at
    else if (!view.deadline_suggestion?.available) throw new Error('اختر موعدًا يدويًا لغياب اقتراح تقويم موثوق.')
    path = `/registrations/${view.registration_id}`
  } else if (action === 'deadline') {
    if (!proposal.deadline_at) throw new Error('اختر الموعد الجديد.')
    body.deadline_at = proposal.deadline_at
    path = `/${view.case.incomplete_case_id}/deadline`
  } else {
    const components = view.components.filter(component => component.editable).map(component => {
      const value = proposal.marks[component.grade_component_id]
      if (value == null || !/^\d+(\.\d{1,2})?$/.test(String(value)) || Number(value) > Number(component.max_mark)) throw new Error(`أدخل علامة ${component.name} بين صفر و${component.max_mark} بمنزلتين عشريتين كحد أقصى.`)
      return { grade_component_id: component.grade_component_id, mark: String(value) }
    })
    if (components.length) body.components = components
    path = `/${view.case.incomplete_case_id}/resolve`
  }
  return Object.freeze({ path, method: action === 'deadline' ? 'PATCH' : 'POST', body: JSON.stringify(body), action, registrationId: view.registration_id, caseId: view.case?.incomplete_case_id ?? null })
}
const positiveId = value => (typeof value === 'number' || (typeof value === 'string' && /^\d+$/.test(value))) && Number.isSafeInteger(Number(value)) && Number(value) > 0 ? Number(value) : null
export function validateAcknowledgement(response, attempt) {
  const data = response?.data, caseData = data?.case, expected = JSON.parse(attempt.body)
  const expectedState = attempt.action === 'resolve' ? 'resolved' : 'active'
  // Validate the original operation snapshot, NOT the latest GET revision: canonical
  // idempotent replay returns the saved snapshot even after later lifecycle events.
  const valid = response?.success === true && data?.changed === true && caseData && !Array.isArray(caseData)
    && positiveId(caseData.incomplete_case_id) !== null && positiveId(attempt.registrationId) !== null
    && positiveId(caseData.student_course_registration_id) === positiveId(attempt.registrationId)
    && (attempt.action === 'grant' || (positiveId(attempt.caseId) !== null && positiveId(caseData.incomplete_case_id) === positiveId(attempt.caseId)))
    && Number.isSafeInteger(caseData.revision) && caseData.revision === expected.expected_revision + 1 && caseData.state === expectedState
  if (!valid) {
    const error = new Error('لم يصل إقرار حفظ صالح يطابق هذه المحاولة. لا يمكن الجزم بنتيجتها؛ احتُفظ بالطلب الأصلي للتحقق.')
    error.errorCode = 'incomplete_acknowledgement_invalid'
    throw error
  }
  return data
}
export async function sendAttempt(attempt) {
  return validateAcknowledgement(await apiRequest(incompleteBase + attempt.path, { method: attempt.method, body: attempt.body }), attempt)
}
export function newDraft(view, action) { return { baseline: view, server: view, action, proposal: blankProposal(action), conflict: false, attempt: null, uncertain: false, rejected: false, recoveryReadKey: null } }
export function receiveDraft(draft, server) { return { ...draft, server, conflict: draft.conflict || draft.baseline.case?.revision !== server.case?.revision } }
export const hasRecoveryRead = (draft, readKey) => !!readKey && readKey !== draft.recoveryReadKey
export function failedAttempt(draft, error, readKey) {
  // Canonical 409/422 rejects this invocation without mutation. It is not proof
  // that an earlier lost invocation never committed. Keep its identity and proposal
  // until an explicit decision against a freshly read, still-eligible context.
  const rejected = [409, 422].includes(error.status)
  return { ...draft, uncertain: !rejected, rejected, conflict: draft.conflict || rejected, recoveryReadKey: readKey }
}
export function reviewDraft(draft, readKey) {
  if (draft.uncertain || (draft.attempt && !hasRecoveryRead(draft, readKey))) return null
  const operation = { grant: 'grant', deadline: 'deadline_update', resolve: 'resolve' }[draft.action]
  if (draft.server.allowed_operations && (!draft.server.allowed_operations[operation] || (draft.action === 'resolve' && !draft.server.resolution?.available))) return null
  // Explicit review keeps proposed values, but never silently moves component IDs to a new definition.
  if (draft.action === 'resolve' && Object.keys(draft.proposal.marks).some(id => !draft.server.components.some(c => String(c.grade_component_id) === id && c.editable))) return null
  return { ...draft, baseline: draft.server, conflict: false, uncertain: false, rejected: false, attempt: null, recoveryReadKey: null }
}

export function deadlineEvidence(caseData) {
  if (caseData.deadline_source === 'manual') return ['موعد يدوي مسجل']
  if (caseData.deadline_source !== 'calendar') return ['مصدر المهلة غير مسجل']
  const snapshot = caseData.calendar_snapshot
  const details = ['التقويم المنشور — فترة الدراسة للفصل التالي']
  if (!snapshot || typeof snapshot !== 'object') return [...details, 'مرجع التقويم التفصيلي غير مسجل']
  if (snapshot.academic_calendar_event_id != null) details.push(`مرجع الحدث: ${snapshot.academic_calendar_event_id}`)
  if (snapshot.academic_calendar_event_version_id != null) details.push(`مرجع النسخة المسجلة: ${snapshot.academic_calendar_event_version_id}`)
  if (snapshot.version_number != null) details.push(`رقم النسخة: ${snapshot.version_number}`)
  if (snapshot.academic_year_id != null && snapshot.semester_id != null) details.push(`فترة التقويم المسجلة: السنة ${snapshot.academic_year_id} / الفصل ${snapshot.semester_id}`)
  if (snapshot.starts_at) details.push(`بداية الدراسة المسجلة — دمشق: ${damascusTime(snapshot.starts_at)}`)
  return details
}
