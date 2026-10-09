import { apiDownload, apiRequest } from '../../../services/apiClient'

export function createPayrollApi(BASE = '/v1/owner') {
const json = (method, path, body) => apiRequest(`${BASE}/${path}`, { method, body: body === undefined ? undefined : JSON.stringify(body) })

const fetchOwnerHome = () => apiRequest(`${BASE}/home`)
const fetchPayrollOptions = () => apiRequest(`${BASE}/payroll/options`)
const fetchPayrollBodies = () => apiRequest(`${BASE}/payroll/bodies`)
const fetchPayrollSheet = query => apiRequest(`${BASE}/payroll/sheet${query ? `?${query}` : ''}`)

const createPayrollBody = name => json('POST', 'payroll/bodies', { name })
const renamePayrollBody = (id, name, revision) => json('PATCH', `payroll/bodies/${id}`, { name, expected_revision: revision })
const setPayrollBodyActive = (id, active, revision) => json('POST', `payroll/bodies/${id}/${active ? 'activate' : 'deactivate'}`, { expected_revision: revision })
const deletePayrollBody = (id, revision) => json('DELETE', `payroll/bodies/${id}`, { expected_revision: revision })

const createPayrollEmployee = payload => json('POST', 'payroll/employees', payload)
const updatePayrollEmployee = (id, payload, revision) => json('PATCH', `payroll/employees/${id}`, { ...payload, expected_revision: revision })

/** One atomic request: every change commits, or none does. Carries the configuration revision the values were calculated under. */
const saveValueChanges = async (changes, configRevision) => (await json('PATCH', 'payroll/values', { changes, config_revision: configRevision })).data

const fetchPayrollConfig = () => apiRequest(`${BASE}/payroll/config`)
const previewPayrollConfig = payload => json('POST', 'payroll/config/preview', payload)
const createPayrollColumn = (column, revision) => json('POST', 'payroll/config/columns', { ...column, config_revision: revision })
const updatePayrollColumn = (key, column, revision) => json('PATCH', `payroll/config/columns/${key}`, { ...column, config_revision: revision })
const restorePayrollColumnFormula = (key, revision) => json('POST', `payroll/config/columns/${key}/restore-formula`, { config_revision: revision })
const deletePayrollColumn = (key, revision, confirmValues = false) => json('DELETE', `payroll/config/columns/${key}`, { config_revision: revision, confirm_values: confirmValues })
const savePayrollLayout = (columns, revision) => json('PUT', 'payroll/config/layout', { columns, config_revision: revision })
const savePayrollSettings = (settings, revision) => json('PATCH', 'payroll/config/settings', { settings, config_revision: revision })

const downloadPayrollExport = (kind, query) => apiDownload(`${BASE}/payroll/export/${kind}${query ? `?${query}` : ''}`)
return { fetchOwnerHome, fetchPayrollOptions, fetchPayrollBodies, fetchPayrollSheet, createPayrollBody, renamePayrollBody, setPayrollBodyActive, deletePayrollBody, createPayrollEmployee, updatePayrollEmployee, saveValueChanges, fetchPayrollConfig, previewPayrollConfig, createPayrollColumn, updatePayrollColumn, restorePayrollColumnFormula, deletePayrollColumn, savePayrollLayout, savePayrollSettings, downloadPayrollExport }
}
export const { fetchOwnerHome, fetchPayrollOptions, fetchPayrollBodies, fetchPayrollSheet, createPayrollBody, renamePayrollBody, setPayrollBodyActive, deletePayrollBody, createPayrollEmployee, updatePayrollEmployee, saveValueChanges, fetchPayrollConfig, previewPayrollConfig, createPayrollColumn, updatePayrollColumn, restorePayrollColumnFormula, deletePayrollColumn, savePayrollLayout, savePayrollSettings, downloadPayrollExport } = createPayrollApi()
