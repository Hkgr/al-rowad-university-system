import { useState, useEffect, useCallback } from 'react'
import useAuth from '../../auth/useAuth'
import { getMyFacultyMember, getMyOpenOfferings } from '../lib/professorApi'

export default function useMyOfferings() {
  const { user } = useAuth()
  const facultyMemberId = user?.faculty_member_id
  const [facultyMember, setFacultyMember] = useState(null)
  const [offerings,     setOfferings]     = useState([])
  const [loading,       setLoading]       = useState(true)
  const [error,         setError]         = useState('')

  const fetchOfferings = useCallback(async () => {
    const nextFacultyMember = await getMyFacultyMember(facultyMemberId)
    const nextOfferings = nextFacultyMember
      ? await getMyOpenOfferings()
      : []

    return { nextFacultyMember, nextOfferings }
  }, [facultyMemberId])

  const reload = useCallback(async () => {
    setLoading(true); setError('')
    try {
      const { nextFacultyMember, nextOfferings } = await fetchOfferings()
      setFacultyMember(nextFacultyMember)
      setOfferings(nextOfferings)
    } catch {
      setError('تعذّر الاتصال بالخادم')
    } finally {
      setLoading(false)
    }
  }, [fetchOfferings])

  useEffect(() => {
    let active = true

    fetchOfferings()
      .then(({ nextFacultyMember, nextOfferings }) => {
        if (!active) return
        setFacultyMember(nextFacultyMember)
        setOfferings(nextOfferings)
        setError('')
      })
      .catch(() => {
        if (active) setError('تعذّر الاتصال بالخادم')
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [fetchOfferings])

  return { facultyMember, offerings, loading, error, reload }
}
