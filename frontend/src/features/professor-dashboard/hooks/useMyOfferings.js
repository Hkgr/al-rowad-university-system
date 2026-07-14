import { useState, useEffect, useCallback } from 'react'
import { findMyFacultyMember, getMyOpenOfferings } from '../lib/professorApi'

export default function useMyOfferings() {
  const [facultyMember, setFacultyMember] = useState(null)
  const [offerings,     setOfferings]     = useState([])
  const [loading,       setLoading]       = useState(true)
  const [error,         setError]         = useState('')

  const load = useCallback(async () => {
    setLoading(true); setError('')
    try {
      const user = JSON.parse(localStorage.getItem('user') || '{}')
      const fm = await findMyFacultyMember(user.employee_id)
      setFacultyMember(fm)
      if (!fm) { setOfferings([]); return }
      setOfferings(await getMyOpenOfferings(fm.faculty_member_id))
    } catch {
      setError('تعذّر الاتصال بالخادم')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  return { facultyMember, offerings, loading, error, reload: load }
}
