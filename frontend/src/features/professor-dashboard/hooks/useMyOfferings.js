import { useState, useEffect, useCallback } from 'react'
import { getMyCourseOfferings } from '../lib/professorApi'

export default function useMyOfferings() {
  const [facultyMember, setFacultyMember] = useState(null)
  const [offerings,     setOfferings]     = useState([])
  const [loading,       setLoading]       = useState(true)
  const [error,         setError]         = useState('')

  const load = useCallback(async () => {
    setLoading(true); setError('')
    try {
      const data = await getMyCourseOfferings()
      setFacultyMember(data?.faculty_member ?? null)
      setOfferings(Array.isArray(data?.offerings) ? data.offerings : [])
    } catch {
      setError('تعذّر الاتصال بالخادم')
      setFacultyMember(null)
      setOfferings([])
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  return { facultyMember, offerings, loading, error, reload: load }
}
