const API = 'https://rust.alrowaduni.edu.sy/api/v1'

function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

class ProfessorApiError extends Error {
  constructor(message, { status, errorCode, details } = {}) {
    super(message)
    this.name = 'ProfessorApiError'
    this.status = status
    this.errorCode = errorCode
    this.details = details
  }
}

async function apiRequest(path, options = {}) {
  const res = await fetch(`${API}${path}`, {
    ...options,
    headers: { ...authHeaders(), ...options.headers },
  })
  const json = await res.json().catch(() => ({}))
  if (!res.ok || !json.success) {
    throw new ProfessorApiError(json.message || `فشل الطلب (${res.status})`, {
      status: res.status,
      errorCode: json.error_code,
      details: json.errors ?? json.data ?? {},
    })
  }
  return json.data
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

const getGradePartsWorkflow = offeringId => apiRequest(`/course-offerings/${offeringId}/grade-parts-workflow`)
const saveRegistrationGradePart = (registrationId, part, payload) => apiRequest(`/registrations/${registrationId}/grade-parts/${part}`, {
  method: 'PUT',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify(payload),
})
const submitOfferingGradePart = (offeringId, part) => apiRequest(`/course-offerings/${offeringId}/grade-parts/${part}/submit`, { method: 'POST' })

export {
  API, authHeaders, ProfessorApiError, findMyFacultyMember, getMyOpenOfferings,
  getGradePartsWorkflow, saveRegistrationGradePart, submitOfferingGradePart,
}
