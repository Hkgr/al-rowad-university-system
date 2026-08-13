const API = 'https://rust.alrowaduni.edu.sy/api/v1'

function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

// Resolves the authenticated employee's faculty_members row. The API returns
// null only when no matching row exists; request and server errors propagate.
async function findMyFacultyMember() {
  const res  = await fetch(`${API}/faculty-members/me`, { headers: authHeaders() })
  const json = await res.json()

  if (!res.ok || !json.success) {
    throw new Error(json.message || `Failed to load faculty member (${res.status})`)
  }

  return json.data ?? null
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
