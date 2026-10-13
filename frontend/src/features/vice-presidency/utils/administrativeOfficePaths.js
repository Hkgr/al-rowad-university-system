import { useCallback } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'

// Navigation only; reused endpoint/service guards remain authoritative.
const PREFIXES = Object.freeze({ '/student-affairs': '/vp/administrative/student-affairs', '/exam-board': '/vp/administrative/exams', '/technical': '/vp/administrative/technical', '/hr': '/vp/administrative/personnel', '/admissions': '/vp/administrative/admissions' })
export function administrativeOfficePath(path, pathname) {
  if (path && typeof path === 'object' && path.pathname) return { ...path, pathname: administrativeOfficePath(path.pathname, pathname) }
  if (typeof path !== 'string' || !pathname.startsWith('/vp/administrative')) return path
  const prefix = Object.keys(PREFIXES).find(key => path === key || path.startsWith(`${key}/`) || path.startsWith(`${key}?`))
  return prefix ? PREFIXES[prefix] + path.slice(prefix.length) : path
}
export function useOfficeNavigate() {
  const navigate = useNavigate(); const { pathname } = useLocation()
  return useCallback((to, options) => navigate(administrativeOfficePath(to, pathname), options), [navigate, pathname])
}
export const ADMINISTRATIVE_OFFICE_ACCESS = Object.freeze({ allRoles: ['vice_president_administrative'], assignedPermissions: ['vice_presidency.administrative.access'], actualUniversityScope: true })
export function administrativeOfficeAccess(access) {
  if (access.anyAccess?.length) return { anyAccess: access.anyAccess.map(administrativeOfficeAccess) }
  return { ...access, allRoles: [...new Set([...(access.allRoles || []), ...ADMINISTRATIVE_OFFICE_ACCESS.allRoles])],
    assignedPermissions: [...new Set([...(access.assignedPermissions || []), ...ADMINISTRATIVE_OFFICE_ACCESS.assignedPermissions])], actualUniversityScope: true }
}
