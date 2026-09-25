import { apiRequest } from '../../../services/apiClient'

const BASE = '/v1/technical/activity'

// Read-only: the activity feed has no write endpoints.
export const fetchActivity = query => apiRequest(`${BASE}?${query}`)
export const fetchActivityOptions = () => apiRequest(`${BASE}/options`)
export const fetchActivityEvent = (source, id) => apiRequest(`${BASE}/${encodeURIComponent(source)}/${encodeURIComponent(id)}`)
