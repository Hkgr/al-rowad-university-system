import { apiDownload, apiRequest } from '../../../services/apiClient'

const BASE = '/v1/owner'
const json = (method, path, body) => apiRequest(`${BASE}/${path}`, { method, body: body === undefined ? undefined : JSON.stringify(body) })

export const fetchOwnerHome = () => apiRequest(`${BASE}/home`)
export const fetchPayrollOptions = () => apiRequest(`${BASE}/payroll/options`)
export const fetchPayrollBodies = () => apiRequest(`${BASE}/payroll/bodies`)
export const fetchPayrollSheet = query => apiRequest(`${BASE}/payroll/sheet${query ? `?${query}` : ''}`)

export const createPayrollBody = name => json('POST', 'payroll/bodies', { name })
export const renamePayrollBody = (id, name, revision) => json('PATCH', `payroll/bodies/${id}`, { name, expected_revision: revision })
export const setPayrollBodyActive = (id, active, revision) => json('POST', `payroll/bodies/${id}/${active ? 'activate' : 'deactivate'}`, { expected_revision: revision })
export const deletePayrollBody = (id, revision) => json('DELETE', `payroll/bodies/${id}`, { expected_revision: revision })

export const createPayrollEmployee = payload => json('POST', 'payroll/employees', payload)
export const updatePayrollEmployee = (id, payload, revision) => json('PATCH', `payroll/employees/${id}`, { ...payload, expected_revision: revision })

/** One atomic request: every change commits, or none does. Resolves with the updated rows. */
export const saveAmountChanges = async changes => (await json('PATCH', 'payroll/amounts', { changes })).data

export const downloadPayrollExport = (kind, query) => apiDownload(`${BASE}/payroll/export/${kind}${query ? `?${query}` : ''}`)
