const IDENTITY_KEY = 'user'

export const ROLES = Object.freeze({
  dean: 'dean',
  examOfficer: 'exam_officer',
  registrationOfficer: 'registration_officer',
  vicePresidentScientific: 'vice_president_scientific',
  vicePresidentAdministrative: 'vice_president_administrative',
  vicePresidentLegacy: 'vice_president',
})

export const PERMISSIONS = Object.freeze({
  registrationView: 'registration.view',
  registrationManage: 'registration.manage',
  coursesView: 'courses.view',
  coursesManage: 'courses.manage',
  courseOfferingsManage: 'course_offerings.manage',
  academicStructureView: 'academic_structure.view',
  academicStructureManage: 'academic_structure.manage',
  studentsView: 'students.view',
  studentsManage: 'students.manage',
  hrView: 'hr.view',
  teachingStaffManage: 'teaching_staff.manage',
  teachingStaffView: 'teaching_staff.view',
  gradesView: 'grades.view',
  attendanceView: 'attendance.view',
  dashboardsView: 'dashboards.view',
  systemSettingsView: 'system_settings.view',
  vicePresidencyScientificAccess: 'vice_presidency.scientific.access',
  vicePresidencyAdministrativeAccess: 'vice_presidency.administrative.access',
  teachingAssignmentsView: 'teaching_assignments.view',
  teachingAssignmentsManage: 'teaching_assignments.manage',
  teachingAssignmentsReviewScientific: 'teaching_assignments.review_scientific',
  teachingAssignmentsReviewAdministrative: 'teaching_assignments.review_administrative',
  exceptionalOpenView: 'course_offerings.exceptional_open.view',
  exceptionalOpenRequest: 'course_offerings.exceptional_open.request',
  exceptionalOpenReviewScientific: 'course_offerings.exceptional_open.review_scientific',
  exceptionalOpenReviewAdministrative: 'course_offerings.exceptional_open.review_administrative',
  closureView: 'course_offerings.closure.view',
  closureRequest: 'course_offerings.closure.request',
  supplementaryExamsPeriodsView: 'supplementary_exams.periods.view',
  supplementaryExamsPeriodsDecide: 'supplementary_exams.periods.decide',
  supplementaryExamsOfferingsView: 'supplementary_exams.offerings.view',
  supplementaryExamsOfferingsManage: 'supplementary_exams.offerings.manage',
  supplementaryExamsRegistrationsView: 'supplementary_exams.registrations.view',
  supplementaryExamsGradesReview: 'supplementary_exams.grades.review',
  academicCalendarManage: 'academic_calendar.manage',
  semesterOfferingGovernanceView: 'course_offerings.semester_governance.view',
  semesterOfferingGovernanceManage: 'course_offerings.semester_governance.manage',
  semesterOfferingGovernanceReviewScientific: 'course_offerings.semester_governance.review_scientific',
  admissionsView: 'admissions.view',
  admissionsManage: 'admissions.manage',
})

export const ACCESS = Object.freeze({
  courseRegistration: { allPermissions: [PERMISSIONS.registrationView, PERMISSIONS.studentsView, PERMISSIONS.academicStructureView, PERMISSIONS.coursesView, PERMISSIONS.systemSettingsView] },
  courseManagement: { allPermissions: [PERMISSIONS.coursesView, PERMISSIONS.academicStructureView, PERMISSIONS.systemSettingsView] },
  studentAffairs: { allPermissions: [PERMISSIONS.studentsView] },
  studentAffairsAddStudent: { anyAccess: [
    { allPermissions: [PERMISSIONS.studentsView, PERMISSIONS.studentsManage] },
    { assignedPermissions: [PERMISSIONS.admissionsManage], actualUniversityScope: true },
  ] },
  studentAffairsArchivedStudents: { allPermissions: [PERMISSIONS.studentsView, PERMISSIONS.studentsManage] },
  studentAffairsApprovedRegistrationRequests: { allPermissions: [PERMISSIONS.studentsView, PERMISSIONS.registrationView] },
  scientificVicePresident: { permissions: [PERMISSIONS.vicePresidencyScientificAccess] },
  administrativeVicePresident: { permissions: [PERMISSIONS.vicePresidencyAdministrativeAccess] },
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
export function hasAssignedPermission(permission, user = getIdentity()) {
  return user?.permissions?.includes(permission) ?? false
}
export function hasActualUniversityScope(user = getIdentity()) {
  return user?.access_scopes?.some(scope => scope?.type === 'university') ?? false
}
export function can(permission, user = getIdentity()) { return hasPermission(permission, user) }
export function canAny(permissions, user = getIdentity()) { return permissions.some(permission => can(permission, user)) }
export function canAll(permissions, user = getIdentity()) { return permissions.every(permission => can(permission, user)) }
export function canAccess({ permissions = [], allPermissions = [], roles = [], allRoles = [], assignedPermissions = [], actualUniversityScope = false, studentIdentity = false, employeeIdentity = false, anyAccess = [] } = {}, user = getIdentity()) {
  if (!user) return false
  if (anyAccess.length > 0) return anyAccess.some(access => canAccess(access, user))
  if (studentIdentity && !user.student_id) return false
  if (employeeIdentity && !user.employee_id) return false
  if (actualUniversityScope && !hasActualUniversityScope(user)) return false
  const hasEveryRequiredPermission = allPermissions.every(permission => hasPermission(permission, user))
  const hasEveryRequiredRole = allRoles.every(role => hasRole(role, user))
  const hasEveryAssignedPermission = assignedPermissions.every(permission => hasAssignedPermission(permission, user))
  const hasAnyAlternative = permissions.some(permission => hasPermission(permission, user))
    || roles.some(role => hasRole(role, user))
    || (permissions.length === 0 && roles.length === 0)

  return hasEveryRequiredPermission && hasEveryRequiredRole && hasEveryAssignedPermission && hasAnyAlternative
}
export function landingRoute(user) {
  // Portal roles take precedence over permission-based staff landing pages.
  if (hasRole(ROLES.dean, user)) return '/dean'
  if (hasRole(ROLES.vicePresidentScientific, user)) return '/vp/scientific'
  if (hasRole(ROLES.vicePresidentAdministrative, user)) return '/vp/administrative'
  if (hasRole(ROLES.examOfficer, user)) return '/exam-board'
  if (hasRole(ROLES.registrationOfficer, user)) return '/student-affairs'
  if (canAll(['exams.view', 'exams.manage'], user)) return '/exam-board'
  if (canAccess(ACCESS.courseRegistration, user) && hasPermission('registration.manage', user)) return '/exam-board/course-registration'
  if (canAny(['attendance.manage', 'grades.manage'], user) && user?.employee_id) return '/professor'
  if (hasPermission('supplementary_exams.grades.view', user) && user?.employee_id) return '/professor/supplementary-exams'
  if (hasPermission('hr.view', user)) return '/hr'
  if (user?.student_id && canAny(['registration.view', 'grades.view', 'attendance.view'], user)) return '/student'
  if (hasPermission('academic_structure.view', user)) return '/academic-structure'
  if (canAccess({ assignedPermissions: [PERMISSIONS.admissionsManage], actualUniversityScope: true }, user)) return '/student-affairs/students/add'
  if (hasPermission('students.view', user)) return '/student-affairs'
  if (canAccess({ assignedPermissions: [PERMISSIONS.admissionsView], actualUniversityScope: true }, user)) return '/student-affairs/ministry-placements'
  // Permission fallback for VP-only identities. Exclude super_admin so existing
  // staff landings (exam board, HR, …) are not redirected to the VP shell.
  if (!hasRole('super_admin', user) && hasPermission(PERMISSIONS.vicePresidencyScientificAccess, user)) return '/vp/scientific'
  if (!hasRole('super_admin', user) && hasPermission(PERMISSIONS.vicePresidencyAdministrativeAccess, user)) return '/vp/administrative'
  return '/forbidden'
}
