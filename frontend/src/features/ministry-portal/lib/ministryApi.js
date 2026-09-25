import { apiRequest } from '../../../services/apiClient'

// Read-only: every ministry endpoint is GET. There is no write call in this portal.
const BASE = '/v1/ministry'
const get = (path, query = '') => apiRequest(`${BASE}/${path}${query ? `?${query}` : ''}`)

export const fetchMinistryFilters = () => get('filters')
export const fetchMinistryDashboard = query => get('dashboard', query)
export const fetchMinistryList = (page, query) => get(page, query)
export const fetchMinistryDetail = (page, id) => get(`${page}/${encodeURIComponent(id)}`)
export const fetchMinistryLeadership = () => get('leadership')
export const fetchMinistryUnit = id => get(`leadership/units/${encodeURIComponent(id)}`)
