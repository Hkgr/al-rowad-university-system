// Pure presentation helpers for the administrative VP home, teachers and deans pages.

export const UNAVAILABLE_REASON_TEXT = Object.freeze({
  workflow_schema_missing: 'جداول دورة اعتماد التكليفات غير مهيأة في قاعدة البيانات.',
  review_permission_missing: 'حسابك لا يملك صلاحية المراجعة الإدارية للتكليفات، فلا تُعرض أعدادها.',
  request_failed: 'تعذّر تحميل المؤشرات من الخادم.',
})

export function unavailableText(reason) {
  return UNAVAILABLE_REASON_TEXT[reason] ?? 'البيانات غير متاحة حاليًا.'
}

export function formatCount(value) {
  if (value === null || value === undefined || !Number.isFinite(Number(value))) return 'غير متاح'
  return new Intl.NumberFormat('ar-SY').format(Number(value))
}

// Map server rows [{college_id, ...}] to chart rows keyed by `college`, labelled from options.
export function collegeLabels(colleges = []) {
  return Object.fromEntries(colleges.map(college => [String(college.id), college.label]))
}

export function collegeSeries(rows = [], valueKey = 'total') {
  return rows
    .map(row => ({ college: row.college_id === null ? '' : String(row.college_id), [valueKey]: row[valueKey] ?? null }))
    .sort((a, b) => (Number(b[valueKey]) || 0) - (Number(a[valueKey]) || 0))
}

// Queue link for a dashboard indicator. Year/semester/college carry over so the
// queue shows the same population the indicator counted.
export function queueLink(queue, filters = {}, collegeId = null) {
  const params = new URLSearchParams()
  if (queue && queue !== 'pending') params.set('queue', queue)
  const college = collegeId ?? filters.college_id
  if (college) params.set('college_id', String(college))
  if (filters.academic_year_id) params.set('academic_year_id', String(filters.academic_year_id))
  if (filters.academic_year_id && filters.semester_id) params.set('semester_id', String(filters.semester_id))
  const text = params.toString()
  return `/vp/administrative/teaching-assignments${text ? `?${text}` : ''}`
}

export function dashboardFilters(state = {}) {
  const filters = {}
  ;['academic_year_id', 'semester_id', 'college_id'].forEach(key => {
    const value = String(state[key] ?? '').trim()
    if (/^\d+$/.test(value)) filters[key] = value
  })
  if (filters.semester_id && !filters.academic_year_id) delete filters.semester_id
  return filters
}

// ── Errors ─────────────────────────────────────────────────────────────────

export const GOVERNANCE_ERROR_TEXT = Object.freeze({
  administrative_governance_forbidden: 'لا يملك حسابك هذه الصلاحية (أو لا يملك نطاق الجامعة)، أو سُحبت منك مؤخرًا.',
  affiliation_stale: 'تغيّر انتماء المدرس منذ فتح الصفحة. أُعيد تحميل البيانات؛ راجع الانتماء الحالي ثم أعد المحاولة.',
  already_affiliated: 'المدرس منتسب إلى هذه الكلية بالفعل.',
  dean_state_stale: 'تغيّر عميد الكلية منذ فتح الصفحة. أُعيد تحميل القائمة؛ راجع الحالة قبل المتابعة.',
  college_has_dean: 'للكلية عميد حالي. فعّل خيار الاستبدال صراحة إن كان هذا مقصودًا.',
  dean_of_other_college: 'هذا الحساب عميد لكلية أخرى؛ استخدم «نقل العميد».',
  protected_account: 'هذا الحساب محمي من هذا المسار (مدير نظام، أو نطاق جامعة، أو أدوار أخرى).',
  self_change_forbidden: 'لا يمكنك تعديل حسابك الشخصي من هذا المسار.',
  dean_role_restricted: 'دور العميد يحمل صلاحيات إدارية محجوزة؛ رُفضت العملية احتياطًا. راجع مدير النظام.',
  dean_duplicate_request: 'تعارضت العملية مع طلب متزامن أو بيانات مكررة. أعد التحميل وتحقق من الحالة.',
  employee_identity_mismatch: 'رقم الموظف والكنية لا يطابقان سجل الموظف.',
  employee_inactive: 'الموظف غير نشط.',
  faculty_profile_exists: 'لهذا الموظف ملف تدريسي بالفعل.',
  faculty_profile_inactive: 'الملف التدريسي غير مفعّل.',
  employee_has_account: 'لهذا الموظف حساب دخول قائم؛ اختر «ربط حساب قائم».',
  employee_has_other_account: 'الموظف مرتبط بحساب آخر.',
  account_linked_to_other_employee: 'الحساب مرتبط بموظف آخر.',
  account_inactive: 'الحساب المختار غير مفعّل.',
  college_invalid: 'الكلية غير موجودة أو غير مفعّلة أو غير مرتبطة بوحدة تنظيمية.',
})

export const STALE_ERROR_CODES = Object.freeze(['affiliation_stale', 'dean_state_stale', 'dean_duplicate_request', 'college_has_dean', 'already_affiliated'])

// { message, fieldErrors, reload } — reload=true when the shown state is out of date.
export function governanceError(error) {
  const fieldErrors = {}
  const details = error?.details && typeof error.details === 'object' ? error.details : {}
  Object.entries(details).forEach(([key, value]) => {
    fieldErrors[key] = Array.isArray(value) ? value[0] : String(value)
  })
  const message = GOVERNANCE_ERROR_TEXT[error?.errorCode]
    ?? (error?.status === 422 ? 'تحقق من الحقول المظللة.' : (error?.message || 'تعذّر تنفيذ العملية.'))
  const reload = error?.status === 409 || error?.status === 403 || STALE_ERROR_CODES.includes(error?.errorCode)
  return { message, fieldErrors, reload }
}

// ── Teachers ───────────────────────────────────────────────────────────────

export function affiliationText(colleges = []) {
  if (!Array.isArray(colleges) || colleges.length === 0) return 'بلا انتماء لكلية'
  return colleges.map(college => college.college_name).join('، ')
}

export function affiliationSourceLabel(source) {
  return source === 'home_unit' ? 'الوحدة الأساسية' : 'إسناد وحدة'
}

export function facultyQuery(state = {}, perPage = 15) {
  const query = { per_page: perPage, page: state.page || 1 }
  if (String(state.search ?? '').trim()) query.search = String(state.search).trim()
  if (state.college_id === 'none') query.college_id = 0
  else if (/^\d+$/.test(String(state.college_id ?? ''))) query.college_id = state.college_id
  if (state.is_active === '1' || state.is_active === '0') query.is_active = state.is_active
  if (state.employee_status) query.employee_status = state.employee_status
  return query
}

export function newTeacherPayload(form) {
  const payload = {
    mode: form.mode === 'link' ? 'link' : 'new',
    employee_number: String(form.employee_number ?? '').trim(),
    last_name: String(form.last_name ?? '').trim(),
  }
  if (payload.mode === 'link') {
    payload.employee_id = Number(form.employee_id)
  } else {
    payload.first_name = String(form.first_name ?? '').trim()
    ;['father_name', 'phone_number', 'email', 'hire_date'].forEach(key => {
      const value = String(form[key] ?? '').trim()
      if (value) payload[key] = value
    })
  }
  ;['academic_rank', 'specialization', 'office_location'].forEach(key => {
    const value = String(form[key] ?? '').trim()
    if (value) payload[key] = value
  })
  if (/^\d+$/.test(String(form.college_id ?? ''))) {
    payload.college_id = Number(form.college_id)
    if (form.start_date) payload.start_date = form.start_date
  }
  return payload
}

// ── Deans ──────────────────────────────────────────────────────────────────

export function deanWarningText(warning) {
  if (warning === 'multiple_deans') return 'للكلية أكثر من عميد نشط — راجع الحالة.'
  if (warning === 'no_dean') return 'لا يوجد عميد نشط.'
  return ''
}

export function currentDeanId(college) {
  const deans = Array.isArray(college?.deans) ? college.deans : []
  return deans.length > 0 ? deans[0].user_id : null
}

// Builds the appoint payload. Password fields are only sent for a new account.
export function appointPayload(form, college) {
  const employee = form.employeeMode === 'existing'
    ? { mode: 'existing', employee_id: Number(form.employee_id), employee_number: String(form.employee_number ?? '').trim(), last_name: String(form.last_name ?? '').trim() }
    : { mode: 'new', employee_number: String(form.employee_number ?? '').trim(), first_name: String(form.first_name ?? '').trim(), last_name: String(form.last_name ?? '').trim() }
  if (form.employeeMode !== 'existing') {
    ;['father_name', 'phone_number', 'email'].forEach(key => {
      const value = String(form[key] ?? '').trim()
      if (value) employee[key] = value
    })
  }
  const account = form.accountMode === 'existing'
    ? { mode: 'existing', user_id: Number(form.user_id) }
    : { mode: 'new', username: String(form.username ?? '').trim(), email: String(form.account_email ?? '').trim(), password: form.password ?? '', password_confirmation: form.password_confirmation ?? '' }
  const payload = {
    college_id: Number(college.college_id),
    expected_current_dean_user_id: currentDeanId(college),
    employee,
    account,
  }
  if (form.replace_current) payload.replace_current = true
  if (form.start_date) payload.start_date = form.start_date
  return payload
}
