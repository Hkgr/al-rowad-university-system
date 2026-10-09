import { apiRequest } from '../../../services/apiClient'
const BASE = '/v1/vice-presidency/administrative/hr'
export const hrRead = (path, query = {}, signal) => {
  const params = new URLSearchParams(Object.entries(query).filter(([, value]) => value !== '' && value !== null && value !== undefined))
  return apiRequest(`${BASE}/${path}${params.size ? `?${params}` : ''}`, { signal })
}
export const hrWrite = (path, body, method = 'POST') => apiRequest(`${BASE}/${path}`, { method, body: JSON.stringify(body) })
