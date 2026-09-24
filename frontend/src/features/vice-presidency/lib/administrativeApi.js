import { apiRequest } from '../../../services/apiClient'

const BASE = '/v1/vice-presidency/administrative'

export function buildQuery(params = {}) {
  const query = new URLSearchParams()
  Object.entries(params).forEach(([key, value]) => {
    if (value === undefined || value === null || value === '') return
    query.set(key, String(value))
  })
  const text = query.toString()
  return text ? `?${text}` : ''
}

const post = (path, body) => apiRequest(path, { method: 'POST', body: JSON.stringify(body) })

export const fetchAdministrativeDashboard = filters => apiRequest(`${BASE}/dashboard${buildQuery(filters)}`)

export const fetchFaculty = params => apiRequest(`${BASE}/faculty${buildQuery(params)}`)
export const fetchFacultyMember = id => apiRequest(`${BASE}/faculty/${encodeURIComponent(id)}`)
export const lookupEmployee = employeeNumber => apiRequest(`${BASE}/faculty/employee-lookup${buildQuery({ employee_number: employeeNumber })}`)
export const createFaculty = payload => post(`${BASE}/faculty`, payload)
export const updateFaculty = (id, payload) => apiRequest(`${BASE}/faculty/${encodeURIComponent(id)}`, { method: 'PATCH', body: JSON.stringify(payload) })
export const changeAffiliation = (id, payload) => post(`${BASE}/faculty/${encodeURIComponent(id)}/affiliation`, payload)

export const fetchDeans = () => apiRequest(`${BASE}/deans`)
export const lookupAccount = username => apiRequest(`${BASE}/deans/account-lookup${buildQuery({ username })}`)
export const appointDean = payload => post(`${BASE}/deans`, payload)
export const transferDean = (collegeId, payload) => post(`${BASE}/deans/${encodeURIComponent(collegeId)}/transfer`, payload)
export const endDean = (collegeId, payload) => post(`${BASE}/deans/${encodeURIComponent(collegeId)}/end`, payload)
