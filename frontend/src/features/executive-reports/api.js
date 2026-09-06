import { apiRequest } from '../../services/apiClient'

export const EXECUTIVE_REPORT_CONTRACT_VERSION = 'executive-reports.v1'

function dataFromEnvelope(response, endpoint) {
  if (!response || response.success !== true || !response.data || typeof response.data !== 'object') {
    const error = new Error(`invalid_executive_report_response:${endpoint}`)
    error.errorCode = 'executive_report_contract_invalid'
    throw error
  }
  return response.data
}

export async function fetchExecutiveReportDefinitions(signal) {
  const data = dataFromEnvelope(await apiRequest('/v1/vice-presidency/reports/definitions', { signal }), 'definitions')
  if (data.version !== EXECUTIVE_REPORT_CONTRACT_VERSION || !data.subjects || typeof data.subjects !== 'object' || !data.limits) {
    const error = new Error('executive_report_contract_incompatible')
    error.errorCode = 'executive_report_contract_incompatible'
    throw error
  }
  return data
}

export async function fetchExecutiveReportOptions(parameters, signal) {
  const query = new URLSearchParams()
  Object.entries(parameters).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined) query.set(key, String(value))
  })
  const data = dataFromEnvelope(await apiRequest(`/v1/vice-presidency/reports/filters?${query}`, { signal }), 'filters')
  if (!Array.isArray(data.options) || !data.pagination) {
    const error = new Error('executive_report_filter_contract_invalid')
    error.errorCode = 'executive_report_contract_invalid'
    throw error
  }
  return data
}

export async function fetchAllExecutiveReportOptions(parameters, signal, maxPages = 100, maxItems = 10000) {
  const byId = new Map()
  let page = 1
  let lastPage = 1
  do {
    if (page > maxPages) throw new Error('executive_report_filter_page_limit')
    const result = await fetchExecutiveReportOptions({ ...parameters, page, per_page: 100 }, signal)
    lastPage = Number(result.pagination.last_page)
    if (!Number.isInteger(lastPage) || lastPage < page) throw new Error('executive_report_filter_pagination_invalid')
    result.options.forEach(option => byId.set(String(option.id), option))
    if (byId.size > maxItems) throw new Error('executive_report_filter_item_limit')
    page += 1
  } while (page <= lastPage)
  return [...byId.values()]
}

export async function executeExecutiveReport(payload, signal) {
  const data = dataFromEnvelope(await apiRequest('/v1/vice-presidency/reports/query', {
    method: 'POST',
    body: JSON.stringify(payload),
    signal,
  }), 'query')
  const requiredObjects = ['report', 'scope', 'grouping', 'summary', 'pagination']
  if (requiredObjects.some(key => !data[key] || typeof data[key] !== 'object') || !Array.isArray(data.metrics) || !Array.isArray(data.series) || !Array.isArray(data.rows) || typeof data.generated_at !== 'string') {
    const error = new Error('executive_report_query_contract_invalid')
    error.errorCode = 'executive_report_contract_invalid'
    throw error
  }
  return data
}

export async function fetchExecutiveOverview(signal) {
  const data = dataFromEnvelope(await apiRequest('/v1/vice-presidency/analytics/overview', { signal }), 'overview')
  if (!data.current_snapshots || !data.academic_sections || typeof data.generated_at !== 'string') {
    const error = new Error('executive_report_overview_contract_invalid')
    error.errorCode = 'executive_report_contract_invalid'
    throw error
  }
  return data
}
