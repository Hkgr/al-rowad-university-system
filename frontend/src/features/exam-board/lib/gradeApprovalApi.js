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

export function getGradeApprovals(filters, options = {}) {
  return request(`/v1/grade-approvals?${queryString(filters)}`, options)
}

export function getGradeApprovalDetails(gradeApprovalId, options = {}) {
  return request(`/v1/grade-approvals/${gradeApprovalId}`, options)
}

export function approveGradeApproval(gradeApprovalId, approvalNotes) {
  return request(`/v1/grade-approvals/${gradeApprovalId}/approve`, {
    method: 'POST',
    body: JSON.stringify({ approval_notes: approvalNotes }),
  })
}

export function returnGradeApprovalForCorrection(gradeApprovalId, approvalNotes) {
  return request(`/v1/grade-approvals/${gradeApprovalId}/return-for-correction`, {
    method: 'POST',
    body: JSON.stringify({ approval_notes: approvalNotes }),
  })
}

export function getApprovalFilterOptions(options = {}) {
  return Promise.all([
    request('/v1/academic-years?per_page=100', options),
    request('/v1/semesters?per_page=100', options),
    request('/v1/departments?per_page=100', options),
  ])
}
