const API = 'https://rust.alrowaduni.edu.sy/api/v1'

function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

async function getMyFacultyMember(facultyMemberId) {
  if (!facultyMemberId) return null
  try {
    const res  = await fetch(`${API}/me/faculty-member`, { headers: authHeaders() })
    const json = await res.json()
    return json.success ? json.data : null
  } catch {
    return null
  }
}

async function getMyOpenOfferings() {
  const res  = await fetch(`${API}/me/course-offerings/open?per_page=200`, { headers: authHeaders() })
  const json = await res.json()
  return json.success ? (json.data?.data ?? json.data ?? []) : []
}

export { API, authHeaders, getMyFacultyMember, getMyOpenOfferings }
