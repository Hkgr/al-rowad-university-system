const TOKEN_KEY = 'token'
const USER_KEY = 'user'

export function getStoredToken() {
  return localStorage.getItem(TOKEN_KEY)
}

export function getStoredUser() {
  try {
    return JSON.parse(localStorage.getItem(USER_KEY) || 'null')
  } catch {
    localStorage.removeItem(USER_KEY)
    return null
  }
}

export function storeAuthSession(token, user) {
  localStorage.setItem(TOKEN_KEY, token)
  localStorage.setItem(USER_KEY, JSON.stringify(user))
}

export function storeIdentity(user) {
  localStorage.setItem(USER_KEY, JSON.stringify(user))
}

export function clearAuthSession() {
  localStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem('auth_token')
  localStorage.removeItem(USER_KEY)
}

export function hasPermission(user, permission) {
  const permissions = user?.permissions ?? []

  if (permissions.includes(permission)) return true

  if (permission.endsWith('.view')) {
    return permissions.includes(permission.replace(/\.view$/, '.manage'))
  }

  return false
}

export function hasAnyPermission(user, permissions = []) {
  return permissions.some((permission) => hasPermission(user, permission))
}

export function canAccessDashboard(user, dashboardCode) {
  return (user?.dashboards ?? []).some(({ code }) => code === dashboardCode)
}
