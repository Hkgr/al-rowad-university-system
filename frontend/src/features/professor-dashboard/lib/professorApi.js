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

async function getMyCourseOfferings() {
  return apiRequest('/professor/course-offerings')
}

const getGradePartsWorkflow = offeringId => apiRequest(`/course-offerings/${offeringId}/grade-parts-workflow`)
const saveRegistrationGradePart = (registrationId, part, payload) => apiRequest(`/registrations/${registrationId}/grade-parts/${part}`, {
  method: 'PUT',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify(payload),
})
const submitGradePart = (offeringId, part) => apiRequest(`/course-offerings/${offeringId}/grade-parts/${part}/submit`, { method: 'POST' })
const submitMyGradeParts = offeringId => apiRequest(`/course-offerings/${offeringId}/grade-parts/submit-my-parts`, { method: 'POST' })

export {
  API, authHeaders, ProfessorApiError, getMyCourseOfferings,
  getGradePartsWorkflow, saveRegistrationGradePart, submitGradePart, submitMyGradeParts,
}
