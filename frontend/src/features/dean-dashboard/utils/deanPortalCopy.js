import { getIdentity } from '../../auth/auth'

export const DEAN_PORTAL_TITLE_FALLBACK = 'بوابة عميد الكلية'
export const DEAN_HOME_WELCOME_FALLBACK = 'مرحباً بكم في بوابة عميد الكلية'

function resolvedDeanCollegeName(identity) {
  const college = identity?.college
  if (!college || typeof college !== 'object') {
    return null
  }

  const collegeId = Number(college.college_id)
  if (!Number.isInteger(collegeId) || collegeId <= 0) {
    return null
  }

  const name = typeof college.college_name === 'string' ? college.college_name.trim() : ''
  return name || null
}

export function deanPortalTitle(identity = getIdentity()) {
  const name = resolvedDeanCollegeName(identity)
  return name ? `بوابة عميد ${name}` : DEAN_PORTAL_TITLE_FALLBACK
}

export function deanHomeWelcome(identity = getIdentity()) {
  const name = resolvedDeanCollegeName(identity)
  return name ? `مرحباً بكم في بوابة عميد ${name}` : DEAN_HOME_WELCOME_FALLBACK
}
