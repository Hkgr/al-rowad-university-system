const IDENTITY_KEY = 'user'

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
export function canAccess({ permissions = [], roles = [] } = {}, user = getIdentity()) {
  if (!user) return false
  return permissions.some(permission => hasPermission(permission, user))
    || roles.some(role => hasRole(role, user))
    || (permissions.length === 0 && roles.length === 0)
}
export function landingRoute(user) {
  if (hasRole('student', user)) return '/student'
  if (hasRole('exam_officer', user)) return '/exam-board'
  if (hasRole('doctor_instructor', user)) return '/professor'
  if (hasPermission('hr.view', user)) return '/hr'
  if (hasPermission('academic_structure.view', user)) return '/academic-structure'
  return '/student-affairs'
}
