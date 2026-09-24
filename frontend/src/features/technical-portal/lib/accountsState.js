// Pure helpers for the Technical Office accounts page (no React, no network).

export const STATUS_AR = Object.freeze({ active: 'مفعّل', disabled: 'معطّل', locked: 'مقفل', pending: 'قيد التفعيل' })

export const STATUS_BADGE = Object.freeze({
  active: 'bg-green-100 text-green-700',
  disabled: 'bg-red-100 text-red-600',
  locked: 'bg-amber-100 text-amber-700',
  pending: 'bg-slate-100 text-slate-600',
})

export const RESTRICTION_AR = Object.freeze({
  role_not_assignable: 'دور محجوز لمدير النظام',
  role_carries_restricted_permission: 'يحمل صلاحية إدارة حسابات أو صلاحيات',
  role_inactive: 'دور غير مفعّل',
  self_change_forbidden: 'لا يمكنك تعديل أدوار أو حالة حسابك الشخصي.',
  protected_account: 'هذا الحساب يحمل دورًا محجوزًا لمدير النظام؛ تعديله متاح لـ super_admin فقط.',
  manage_permission_missing: 'لديك صلاحية العرض فقط.',
})

const ERROR_CODE_AR = Object.freeze({
  self_change_forbidden: 'لا يمكنك تعديل أدوار أو حالة حسابك الشخصي.',
  protected_account: 'هذا الحساب محمي؛ تعديله متاح لمدير النظام فقط.',
  role_not_assignable: 'هذا الدور ليس ضمن الأدوار المسموح للفريق التقني بإسنادها أو سحبها.',
  role_carries_restricted_permission: 'هذا الدور يحمل صلاحية إدارة حسابات أو صلاحيات، وإسناده محصور بمدير النظام.',
  last_super_admin: 'لا يمكن تعطيل آخر مدير نظام نشط أو سحب دوره.',
  role_already_assigned: 'هذا الدور مُسند للحساب مسبقًا.',
  role_not_assigned: 'هذا الدور غير مُسند للحساب حاليًا.',
  status_unchanged: 'الحساب على هذه الحالة مسبقًا.',
  role_inactive: 'لا يمكن إسناد دور غير مفعّل.',
})

/** Map an apiRequest error to { status, message, fieldErrors } in Arabic. */
export function accountErrorMessage(error) {
  const status = error?.status ?? 0
  const code = error?.errorCode
  if (!status) return { status, message: 'تعذّر الاتصال بالخادم', fieldErrors: {} }
  if (status === 401) return { status, message: 'انتهت الجلسة، يرجى تسجيل الدخول مجددًا.', fieldErrors: {} }
  if (status === 422) {
    const details = error?.details && typeof error.details === 'object' && !Array.isArray(error.details) ? error.details : {}
    const fieldErrors = Object.fromEntries(Object.entries(details).map(([field, messages]) => [field, Array.isArray(messages) ? messages[0] : String(messages)]))
    return { status, message: 'تحقق من الحقول المدخلة.', fieldErrors }
  }
  if (status === 403) return { status, message: ERROR_CODE_AR[code] ?? 'ليست لديك صلاحية لتنفيذ هذا الإجراء.', fieldErrors: {} }
  if (status === 409) return { status, message: ERROR_CODE_AR[code] ?? 'تعارض مع الحالة الحالية للحساب؛ حدّث البيانات وأعد المحاولة.', fieldErrors: {} }
  if (status === 404) return { status, message: 'الحساب أو الدور غير موجود.', fieldErrors: {} }
  return { status, message: 'حدث خطأ غير متوقع في الخادم.', fieldErrors: {} }
}

/** One of: loading | forbidden | error | emptyFiltered | empty | ready */
export function accountsViewState({ loading, error, rows, hasFilters }) {
  if (loading) return 'loading'
  if (error?.status === 403) return 'forbidden'
  if (error) return 'error'
  if (!rows || rows.length === 0) return hasFilters ? 'emptyFiltered' : 'empty'
  return 'ready'
}

/** Group role-derived permissions by the role that grants them. */
export function groupPermissionsByRole(effectivePermissions = []) {
  const groups = new Map()
  for (const permission of effectivePermissions) {
    for (const role of permission.granted_by_roles ?? []) {
      if (!groups.has(role.role_code)) groups.set(role.role_code, { role_code: role.role_code, role_name: role.role_name, permissions: [] })
      groups.get(role.role_code).permissions.push({ permission_code: permission.permission_code, permission_name: permission.permission_name })
    }
  }
  return [...groups.values()]
}

/** Roles the server marks assignable and the account does not already hold. */
export function assignableRolesFor(detail, roles = []) {
  const held = new Set((detail?.roles ?? []).map(role => role.role_id))
  return roles.filter(role => role.assignable && !held.has(role.role_id))
}

export function buildAccountsQuery({ search = '', status = '', roleId = '', page = 1, perPage = 15 } = {}) {
  const params = new URLSearchParams({ page: String(page), per_page: String(perPage) })
  if (search.trim()) params.set('search', search.trim())
  if (status) params.set('status', status)
  if (roleId) params.set('role_id', String(roleId))
  return params.toString()
}
