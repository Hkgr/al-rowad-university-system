import { apiRequest } from '../../../services/apiClient'

export class GradeApprovalApiError extends Error {
  constructor(message, status, errorCode, details = {}) {
    super(message)
    this.name = 'GradeApprovalApiError'
    this.status = status
    this.errorCode = errorCode
    this.details = details
  }
}

async function request(path, options = {}) {
  try {
    return await apiRequest(path, options)
  } catch (error) {
    // apiRequest intentionally exposes a small interface. Preserve richer API errors
    // here by repeating the request only through the shared base is not possible, so
    // apiRequest attaches response metadata for feature clients (see apiClient).
    throw new GradeApprovalApiError(
      error.message,
      error.status,
      error.errorCode,
      error.details ?? {},
    )
  }
}

function queryString(filters) {
  const params = new URLSearchParams()
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined) params.set(key, value)
  })
  return params.toString()
}

export function getGradePartApprovals(filters, options = {}) {
  return request(`/v1/grade-part-approvals?${queryString(filters)}`, options)
}

export function getGradePartApprovalDetails(approvalId, options = {}) {
  return request(`/v1/grade-part-approvals/${approvalId}`, options)
}

export function approveGradePartApproval(approvalId, reviewNotes) {
  return request(`/v1/grade-part-approvals/${approvalId}/approve`, {
    method: 'POST',
    body: JSON.stringify({ review_notes: reviewNotes }),
  })
}

export function returnGradePartApprovalForCorrection(approvalId, reviewNotes) {
  return request(`/v1/grade-part-approvals/${approvalId}/return-for-correction`, {
    method: 'POST',
    body: JSON.stringify({ review_notes: reviewNotes }),
  })
}

export function getApprovalFilterOptions(options = {}) {
  return Promise.all([
    request('/v1/academic-years?per_page=100', options),
    request('/v1/semesters?per_page=100', options),
    request('/v1/departments?per_page=100', options),
  ])
}
