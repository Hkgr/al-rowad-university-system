import { apiRequest } from '../../services/apiClient'

const READ = '/v1/academic-calendar'
const MANAGE = '/v1/vice-presidency/scientific/academic-calendar'
const query = params => {
  const search = new URLSearchParams(Object.entries(params).filter(([, value]) => value !== '' && value != null))
  return search.size ? `?${search}` : ''
}

export const calendarApi = {
  catalog: manage => apiRequest(`${manage ? MANAGE : READ}/catalog`),
  events: (manage, filters) => apiRequest(`${manage ? MANAGE : READ}/events${query(filters)}`),
  create: payload => apiRequest(`${MANAGE}/events`, { method: 'POST', body: JSON.stringify(payload) }),
  editDraft: (eventId, versionId, payload) => apiRequest(`${MANAGE}/events/${eventId}/drafts/${versionId}`, { method: 'PUT', body: JSON.stringify(payload) }),
  replacement: (eventId, payload) => apiRequest(`${MANAGE}/events/${eventId}/replacement-drafts`, { method: 'POST', body: JSON.stringify(payload) }),
  publish: (eventId, versionId) => apiRequest(`${MANAGE}/events/${eventId}/drafts/${versionId}/publish`, { method: 'POST' }),
  deleteDraft: (eventId, versionId) => apiRequest(`${MANAGE}/events/${eventId}/drafts/${versionId}`, { method: 'DELETE' }),
  cancel: (eventId, reason) => apiRequest(`${MANAGE}/events/${eventId}/cancel`, { method: 'POST', body: JSON.stringify({ cancellation_reason: reason }) }),
  history: eventId => apiRequest(`${MANAGE}/events/${eventId}/history`),
  yearAction: (yearId, action, reason) => apiRequest(`${MANAGE}/academic-years/${yearId}/${action}`, { method: 'POST', body: JSON.stringify({ reason }) }),
}
