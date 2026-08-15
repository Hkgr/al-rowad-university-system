const IDENTITY_KEY = 'user'

export const PERMISSIONS = Object.freeze({
  registrationView: 'registration.view',
  registrationManage: 'registration.manage',
  coursesView: 'courses.view',
  coursesManage: 'courses.manage',
  courseOfferingsManage: 'course_offerings.manage',
  academicStructureView: 'academic_structure.view',
  academicStructureManage: 'academic_structure.manage',
  studentsView: 'students.view',
  hrView: 'hr.view',
  teachingStaffManage: 'teaching_staff.manage',
  teachingStaffView: 'teaching_staff.view',
  gradesView: 'grades.view',
  attendanceView: 'attendance.view',
  dashboardsView: 'dashboards.view',
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
export function canAccess({ permissions = [], allPermissions = [], roles = [], studentIdentity = false, employeeIdentity = false } = {}, user = getIdentity()) {
  if (!user) return false
  if (studentIdentity && !user.student_id) return false
  if (employeeIdentity && !user.employee_id) return false
  const hasEveryRequiredPermission = allPermissions.every(permission => hasPermission(permission, user))
  const hasAnyAlternative = permissions.some(permission => hasPermission(permission, user))
    || roles.some(role => hasRole(role, user))
    || (permissions.length === 0 && roles.length === 0)

  return hasEveryRequiredPermission && hasAnyAlternative
}
export function landingRoute(user) {
  // Portal roles take precedence over permission-based staff landing pages.
  if (hasRole('dean', user)) return '/dean'
  if (canAll(['exams.view', 'exams.manage'], user)) return '/exam-board'
  if (canAccess(ACCESS.courseRegistration, user) && hasPermission('registration.manage', user)) return '/exam-board/course-registration'
  if (canAny(['attendance.manage', 'grades.manage'], user) && user?.employee_id) return '/professor'
  if (hasPermission('hr.view', user)) return '/hr'
  if (user?.student_id && canAny(['registration.view', 'grades.view', 'attendance.view'], user)) return '/student'
  if (hasPermission('academic_structure.view', user)) return '/academic-structure'
  if (hasPermission('students.view', user)) return '/student-affairs'
  return '/forbidden'
}
