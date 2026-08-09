const IDENTITY_KEY = 'user'

export const PERMISSIONS = Object.freeze({
  registrationView: 'registration.view',
  registrationManage: 'registration.manage',
  coursesView: 'courses.view',
  coursesManage: 'courses.manage',
  academicStructureView: 'academic_structure.view',
  academicStructureManage: 'academic_structure.manage',
  studentsView: 'students.view',
  hrView: 'hr.view',
  systemSettingsView: 'system_settings.view',
})

export const ACCESS = Object.freeze({
  courseRegistration: { allPermissions: [PERMISSIONS.registrationView, PERMISSIONS.studentsView, PERMISSIONS.academicStructureView, PERMISSIONS.coursesView, PERMISSIONS.systemSettingsView] },
  courseManagement: { allPermissions: [PERMISSIONS.coursesView, PERMISSIONS.academicStructureView, PERMISSIONS.systemSettingsView] },
})

export function getIdentity() {
  try { return JSON.parse(localStorage.getItem(IDENTITY_KEY) || 'null') } catch { return null }
}
export function storeIdentity(user, token) {
  if (token) localStorage.setItem('token', token)
  localStorage.setItem(IDENTITY_KEY, JSON.stringify(user))
}
export function clearIdentity() {
  localStorage.removeItem('token')
  localStorage.removeItem(IDENTITY_KEY)
}
export function hasRole(role, user = getIdentity()) { return user?.roles?.includes(role) ?? false }
export function hasPermission(permission, user = getIdentity()) {
  return hasRole('super_admin', user) || (user?.permissions?.includes(permission) ?? false)
}
export function can(permission, user = getIdentity()) { return hasPermission(permission, user) }
export function canAny(permissions, user = getIdentity()) { return permissions.some(permission => can(permission, user)) }
export function canAll(permissions, user = getIdentity()) { return permissions.every(permission => can(permission, user)) }
export function canAccess({ permissions = [], allPermissions = [], roles = [] } = {}, user = getIdentity()) {
  if (!user) return false
  const hasEveryRequiredPermission = allPermissions.every(permission => hasPermission(permission, user))
  const hasAnyAlternative = permissions.some(permission => hasPermission(permission, user))
    || roles.some(role => hasRole(role, user))
    || (permissions.length === 0 && roles.length === 0)

  return hasEveryRequiredPermission && hasAnyAlternative
}
export function landingRoute(user) {
  if (hasRole('exam_officer', user)) return '/exam-board'
  if (hasRole('registration_officer', user)) return '/exam-board/course-registration'
  if (hasRole('doctor_instructor', user)) return '/professor'
  if (hasPermission('hr.view', user)) return '/hr'
  if (hasPermission('academic_structure.view', user)) return '/academic-structure'
  if (hasRole('student', user)) return '/student'
  return '/student-affairs'
}
