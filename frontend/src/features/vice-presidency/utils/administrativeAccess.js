import { PERMISSIONS, ROLES, canAccess } from '../../auth/auth.js'

// Mirrors App\Support\AdministrativeGovernance on the server: an active account with an
// actual university scope AND either (the administrative VP role + the assigned
// permission) or super_admin. Organizational placement grants nothing. The server
// re-checks every request; these rules only decide what the UI shows.
const viaAdministrativeVp = permission => ({
  allRoles: [ROLES.vicePresidentAdministrative],
  assignedPermissions: [permission],
  actualUniversityScope: true,
})
const viaSuperAdmin = { allRoles: ['super_admin'], actualUniversityScope: true }

export const ADMINISTRATIVE_ACCESS = Object.freeze({
  facultyView: { anyAccess: [viaAdministrativeVp(PERMISSIONS.vpAdministrativeFacultyView), viaSuperAdmin] },
  facultyManage: { anyAccess: [viaAdministrativeVp(PERMISSIONS.vpAdministrativeFacultyManage), viaSuperAdmin] },
  deansView: { anyAccess: [viaAdministrativeVp(PERMISSIONS.vpAdministrativeDeansView), viaSuperAdmin] },
  deansManage: { anyAccess: [viaAdministrativeVp(PERMISSIONS.vpAdministrativeDeansManage), viaSuperAdmin] },
  dashboard: { anyAccess: [viaAdministrativeVp(PERMISSIONS.vicePresidencyAdministrativeAccess), viaSuperAdmin] },
})

export const ADMINISTRATIVE_PATHS = Object.freeze({
  home: '/vp/administrative',
  faculty: '/vp/administrative/faculty',
  deans: '/vp/administrative/deans',
  teachingAssignments: '/vp/administrative/teaching-assignments',
})

export function canUseAdministrative(key, identity) {
  const access = ADMINISTRATIVE_ACCESS[key]
  return Boolean(access) && canAccess(access, identity)
}
