import { useState, useEffect, useCallback } from 'react'
import { findMyFacultyMember, getMyOpenTermOfferings } from '../lib/professorApi'

export default function useMyOfferings() {
  const [facultyMember, setFacultyMember] = useState(null)
  const [academicYear,  setAcademicYear]  = useState(null)
  const [semester,      setSemester]      = useState(null)
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
      const { academicYear, semester, offerings } = await getMyOpenTermOfferings(fm.faculty_member_id)
      setAcademicYear(academicYear); setSemester(semester); setOfferings(offerings)
    } catch {
      setError('تعذّر الاتصال بالخادم')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  return { facultyMember, academicYear, semester, offerings, loading, error, reload: load }
}
