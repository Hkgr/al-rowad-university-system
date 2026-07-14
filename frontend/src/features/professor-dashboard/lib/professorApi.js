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

// Every currently-open offering, across every academic year/semester —
// eager-loads course/academicYear/semester/facultyMember server-side
// (CourseOfferingController::open). A professor's subjects aren't
// guaranteed to sit in a single "current" term, so this fetches all open
// offerings rather than guessing at one term and filters client-side.
async function getMyOpenOfferings(facultyMemberId) {
  const res  = await fetch(`${API}/course-offerings/open?per_page=200`, { headers: authHeaders() })
  const json = await res.json()
  const all  = json.success ? (json.data?.data ?? json.data ?? []) : []
  return all.filter(o => Number(o.faculty_member_id) === Number(facultyMemberId))
}

export { API, authHeaders, findMyFacultyMember, getMyOpenOfferings }
