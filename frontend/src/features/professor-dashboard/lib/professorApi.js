const API = 'https://rust.alrowaduni.edu.sy/api/v1'

function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

// Resolves the faculty_members row for this employee_id, or null (including
// on any network/parse error) — callers must treat null as "not a professor."
async function findMyFacultyMember(employeeId) {
  if (!employeeId) return null
  try {
    const res  = await fetch(`${API}/faculty-members?per_page=100`, { headers: authHeaders() })
    const json = await res.json()
    if (!json.success) return null
    const list = json.data?.data ?? json.data ?? []
    return list.find(f => f.employee_id === employeeId) ?? null
  } catch {
    return null
  }
}

// Finds "the currently open term" by probing every (year × semester) combo
// for offerings, same pattern GradeEntryPage.jsx uses within one selected
// year — here probed across all years since there's no manual year picker.
async function getOpenTermOfferings() {
  const [y, s] = await Promise.all([
    fetch(`${API}/academic-years?per_page=50`, { headers: authHeaders() }).then(r => r.json()),
    fetch(`${API}/semesters?per_page=20`,      { headers: authHeaders() }).then(r => r.json()),
  ])
  const years   = y.success ? (y.data?.data ?? y.data ?? []) : []
  const allSems = s.success ? (s.data?.data ?? s.data ?? []) : []

  const probes = []
  for (const yr of years) {
    for (const sem of allSems) {
      probes.push(
        fetch(`${API}/course-offerings/by-semester?academic_year_id=${yr.academic_year_id}&semester_id=${sem.semester_id}&per_page=1`, { headers: authHeaders() })
          .then(r => r.json())
          .then(json => ({ yr, sem, has: json.success && (json.data?.data?.length ?? 0) > 0 }))
          .catch(() => ({ yr, sem, has: false }))
      )
    }
  }
  const results = await Promise.all(probes)
  const match = results.find(r => r.has)
  if (!match) return { academicYear: null, semester: null, offerings: [] }

  const offRes  = await fetch(`${API}/course-offerings/by-semester?academic_year_id=${match.yr.academic_year_id}&semester_id=${match.sem.semester_id}&per_page=100`, { headers: authHeaders() })
  const offJson = await offRes.json()
  return {
    academicYear: match.yr,
    semester: match.sem,
    offerings: offJson.success ? (offJson.data?.data ?? offJson.data ?? []) : [],
  }
}

async function getMyOpenTermOfferings(facultyMemberId) {
  const { academicYear, semester, offerings } = await getOpenTermOfferings()
  return {
    academicYear, semester,
    offerings: offerings.filter(o => o.faculty_member_id === facultyMemberId),
  }
}

export { API, authHeaders, findMyFacultyMember, getOpenTermOfferings, getMyOpenTermOfferings }
