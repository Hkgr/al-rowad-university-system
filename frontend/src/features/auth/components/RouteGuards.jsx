import { Navigate, useLocation } from 'react-router-dom'
import useAuth from '../useAuth'
import {
  canAccessDashboard,
  hasAnyPermission,
  hasPermission,
} from '../authStorage'
import AuthLoadingScreen from './AuthLoadingScreen'

export function ProtectedRoute({
  children,
  dashboard,
  permission,
  anyPermissions,
}) {
  const { token, user, loading } = useAuth()
  const location = useLocation()

  if (loading) return <AuthLoadingScreen />

  if (!token || !user) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }

  if (dashboard && !canAccessDashboard(user, dashboard)) {
    return <Navigate to="/403" replace />
  }

  if (permission && !hasPermission(user, permission)) {
    return <Navigate to="/403" replace />
  }

  if (anyPermissions?.length && !hasAnyPermission(user, anyPermissions)) {
    return <Navigate to="/403" replace />
  }

  return children
}

export function PublicOnlyRoute({ children }) {
  const { token, user, loading } = useAuth()

  if (loading) return <AuthLoadingScreen />

  if (token && user) {
    return <Navigate to={user.default_dashboard || '/403'} replace />
  }

  return children
}

export function HomeRedirect() {
  const { token, user, loading } = useAuth()

  if (loading) return <AuthLoadingScreen />
  if (!token || !user) return <Navigate to="/login" replace />

  return <Navigate to={user.default_dashboard || '/403'} replace />
}
