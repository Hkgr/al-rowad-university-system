import { PERMISSIONS, ROLES, canAccess } from '../auth/auth.js'

export const EXECUTIVE_REPORT_ACCESS = Object.freeze({
  scientific: Object.freeze({
    allRoles: [ROLES.vicePresidentScientific],
    assignedPermissions: [PERMISSIONS.vicePresidencyScientificAccess],
    actualUniversityScope: true,
  }),
  administrative: Object.freeze({
    allRoles: [ROLES.vicePresidentAdministrative],
    assignedPermissions: [PERMISSIONS.vicePresidencyAdministrativeAccess],
    actualUniversityScope: true,
  }),
})

const DENIED_REPORT_ACCESS = Object.freeze({ allRoles: ['__executive_report_office_invalid__'] })

export function reportAccessForOffice(office) {
  return EXECUTIVE_REPORT_ACCESS[office] ?? DENIED_REPORT_ACCESS
}

export function canAccessExecutiveReports(office, identity) {
  return canAccess(reportAccessForOffice(office), identity)
}

export function reportPathForOffice(office) {
  return office === 'scientific' || office === 'administrative' ? `/vp/${office}/reports` : '/forbidden'
}
