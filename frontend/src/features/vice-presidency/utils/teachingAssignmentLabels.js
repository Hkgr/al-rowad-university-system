export const REQUEST_STATUS_LABELS = {
  submitted: 'بانتظار الموافقة',
  returned: 'معاد للتعديل',
  approved: 'معتمد',
  superseded: 'مستبدل',
}

export const REVIEW_STATUS_LABELS = {
  pending: 'بانتظار الموافقة',
  approved: 'موافق',
  returned: 'معاد للتعديل',
}

export const EVENT_LABELS = {
  submitted: 'إرسال الطلب',
  resubmitted: 'إعادة الإرسال',
  scientific_approved: 'موافقة النائب العلمي',
  scientific_returned: 'إعادة علمية للعميد',
  administrative_approved: 'موافقة النائب الإداري',
  administrative_returned: 'إعادة إدارية للعميد',
  superseded: 'استبدال الطلب',
  effective_assignment_created: 'تفعيل التكليف',
  effective_assignment_changed: 'تغيير التكليف النافذ',
  effective_assignment_removed: 'إنهاء التكليف النافذ',
  removal_withdrawn: 'سحب طلب الإنهاء',
  removal_stale: 'إلغاء طلب إنهاء لم يعد مطابقًا',
}

export const ACTION_LABELS = {
  assign: 'تكليف أو تغيير مدرس',
  remove: 'إنهاء تكليف نافذ',
}

// What the reviewer's decision would do, from viewer_context.approval_effect (server).
export const APPROVAL_EFFECT_TEXT = {
  effective_now: 'وافق المكتب الآخر مسبقًا؛ موافقتك ستجعل التكليف نافذًا فورًا عبر دورة الاعتماد.',
  awaits_other_office: 'بعد موافقتك يبقى الطلب بانتظار موافقة المكتب الآخر، ويبقى المدرس المعتمد الحالي نافذًا حتى ذلك.',
  already_effective: 'الطلب معتمد ونافذ؛ لا يوجد قرار مطلوب.',
  not_applicable: 'لا يوجد قرار متاح لك على هذا الطلب حاليًا.',
}

export const BLOCKED_REASON_TEXT = {
  not_reviewer: 'حسابك يطّلع على الطلب فقط؛ القرار لصاحب دور النائب وصلاحية المراجعة المسندة.',
  superseded: 'استُبدل هذا الطلب بطلب أحدث؛ القرار يكون على الطلب الحالي.',
  already_effective: 'الطلب معتمد ونافذ.',
  review_locked: 'سجّل مكتبك قراره على هذه النسخة؛ ينتظر الطلب إعادة إرسال من العميد أو قرار المكتب الآخر.',
  same_reviewer: 'وافقتَ على هذا الطلب باسم المكتب الآخر؛ لا تُقبل الموافقتان من الحساب نفسه. يمكنك الإعادة فقط.',
}

// Server error_code → explanation shown with a reload action (403/409 from decide()).
export const DECISION_ERROR_TEXT = {
  teaching_assignment_version_mismatch: 'أعاد العميد إرسال الطلب بنسخة أحدث منذ فتحه. أُعيد تحميل الطلب؛ راجِع النسخة الحالية ثم قرّر.',
  teaching_assignment_superseded: 'استُبدل الطلب بطلب أحدث أثناء المراجعة. أُعيد تحميل الصفحة.',
  teaching_assignment_not_current: 'لم يعد هذا الطلب هو الطلب الحالي للشعبة.',
  teaching_assignment_already_effective: 'اعتُمد الطلب وأصبح نافذًا قبل قرارك.',
  teaching_assignment_review_locked: 'سُجّل قرار مكتبك على هذه النسخة مسبقًا (ربما من نافذة أخرى).',
  teaching_assignment_same_reviewer_forbidden: 'لا يمكن للحساب نفسه الموافقة باسم المكتبين.',
  administrative_review_forbidden: 'حسابك لا يملك دور النائب الإداري مع صلاحية المراجعة الإدارية المسندة.',
  scientific_review_forbidden: 'حسابك لا يملك دور النائب العلمي مع صلاحية المراجعة العلمية المسندة.',
  offering_outside_user_scope: 'الشعبة خارج نطاق حسابك.',
  teaching_assignment_removal_stale: 'تغيّر التكليف النافذ منذ طلب الإنهاء، فأُلغي الطلب تلقائيًا.',
  teaching_assignment_removal_requires_closed_offering: 'لا يُنفذ إنهاء التكليف إلا على شعبة مغلقة؛ أُلغي الطلب تلقائيًا.',
  return_reason_required: 'اكتب سبب الإعادة.',
}

export function decisionErrorText(error) {
  if (!error) return ''
  return DECISION_ERROR_TEXT[error.errorCode] || error.message || 'تعذّر تنفيذ القرار.'
}

// Conflicts and permission changes make the shown state stale: reload from the server.
export function shouldReloadAfterError(error) {
  return error?.status === 409 || error?.status === 403 || error?.status === 404
}

export function actionLabel(type) {
  return ACTION_LABELS[type] || ACTION_LABELS.assign
}

export const QUEUE_FILTER_KEYS = ['queue', 'search', 'college_id', 'academic_year_id', 'semester_id', 'instructor_role', 'action_type', 'page']

// Queue URL/query state: only known keys, no empty values. `queue` defaults to pending.
export function queueParamsFromSearch(searchParams) {
  const read = key => (typeof searchParams?.get === 'function' ? searchParams.get(key) : searchParams?.[key]) ?? ''
  const state = Object.fromEntries(QUEUE_FILTER_KEYS.map(key => [key, String(read(key) || '')]))
  if (!['pending', 'returned', 'approved', 'all'].includes(state.queue)) state.queue = 'pending'
  if (!/^\d+$/.test(state.page) || Number(state.page) < 1) state.page = '1'
  ;['college_id', 'academic_year_id', 'semester_id'].forEach(key => { if (!/^\d+$/.test(state[key])) state[key] = '' })
  if (!['theoretical', 'practical'].includes(state.instructor_role)) state.instructor_role = ''
  if (!['assign', 'remove'].includes(state.action_type)) state.action_type = ''
  if (state.semester_id && !state.academic_year_id) state.semester_id = ''
  return state
}

export function queueApiQuery(authority, state, perPage = 20) {
  const query = new URLSearchParams({ authority, queue: state.queue || 'pending', per_page: String(perPage), page: state.page || '1' })
  ;['search', 'college_id', 'academic_year_id', 'semester_id', 'instructor_role', 'action_type'].forEach(key => {
    const value = String(state[key] ?? '').trim()
    if (value) query.set(key, value)
  })
  return query.toString()
}

export const ROLE_LABELS = {
  theoretical: 'نظري',
  practical: 'عملي',
}

export function requestStatusLabel(status) {
  return REQUEST_STATUS_LABELS[status] || status || '—'
}

export function reviewStatusLabel(status) {
  return REVIEW_STATUS_LABELS[status] || status || '—'
}

export function eventLabel(type) {
  return EVENT_LABELS[type] || type || '—'
}

export function roleLabel(role) {
  return ROLE_LABELS[role] || role || '—'
}

export function facultyName(faculty) {
  if (!faculty) return '—'
  return faculty.full_name || '—'
}

export function formatDateTime(value) {
  if (!value) return '—'
  return String(value).replace('T', ' ').slice(0, 16)
}

export function offeringTitle(offering) {
  const course = offering?.course
  const codeName = [course?.course_code, course?.course_name].filter(Boolean).join(' — ')
  return codeName || '—'
}
