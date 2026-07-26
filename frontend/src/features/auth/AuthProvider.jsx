import { useCallback, useEffect, useMemo, useState } from 'react'
import { apiRequest } from '../../services/apiClient'
import AuthContext from './authContext'
import {
  clearAuthSession,
  getStoredToken,
  getStoredUser,
  storeAuthSession,
  storeIdentity,
} from './authStorage'

export default function AuthProvider({ children }) {
  const [token, setToken] = useState(getStoredToken)
  const [user, setUser] = useState(getStoredUser)
  const [loading, setLoading] = useState(Boolean(getStoredToken()))

  const clearSession = useCallback(() => {
    clearAuthSession()
    setToken(null)
    setUser(null)
    setLoading(false)
  }, [])

  const refreshIdentity = useCallback(async () => {
    if (!getStoredToken()) {
      clearSession()
      return null
    }

    const response = await apiRequest('/user')
    storeIdentity(response.data)
    setUser(response.data)
    setToken(getStoredToken())

    return response.data
  }, [clearSession])

  useEffect(() => {
    let active = true

    if (!token) return undefined

    apiRequest('/user')
      .then((response) => {
        if (!active) return
        storeIdentity(response.data)
        setUser(response.data)
      })
      .catch((error) => {
        if (active && (error.status === 401 || error.status === 403)) {
          clearSession()
        }
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [clearSession, token])

  useEffect(() => {
    const handleUnauthorized = () => clearSession()
    window.addEventListener('auth:unauthorized', handleUnauthorized)

    return () => window.removeEventListener('auth:unauthorized', handleUnauthorized)
  }, [clearSession])

  const login = useCallback(async (credentials) => {
    const response = await apiRequest('/login', {
      method: 'POST',
      body: JSON.stringify(credentials),
    })

    const session = response.data
    storeAuthSession(session.token, session.user)
    setToken(session.token)
    setUser(session.user)

    return session.user
  }, [])

  const logout = useCallback(async () => {
    try {
      if (getStoredToken()) {
        await apiRequest('/logout', { method: 'POST' })
      }
    } catch {
      // Local session cleanup must still succeed when the API is unavailable.
    } finally {
      clearSession()
    }
  }, [clearSession])

  const value = useMemo(() => ({
    token,
    user,
    loading,
    login,
    logout,
    refreshIdentity,
    clearSession,
  }), [clearSession, loading, login, logout, refreshIdentity, token, user])

  return (
    <AuthContext.Provider value={value}>
      {children}
    </AuthContext.Provider>
  )
}
