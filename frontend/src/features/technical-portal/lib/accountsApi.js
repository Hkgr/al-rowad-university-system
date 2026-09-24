import { apiRequest } from '../../../services/apiClient'

const BASE = '/v1/technical/accounts'

export const fetchAccounts = query => apiRequest(`${BASE}?${query}`)
export const fetchAccountOptions = () => apiRequest(`${BASE}/options`)
export const fetchAccount = userId => apiRequest(`${BASE}/${userId}`)

// The password travels once, in the JSON body of a POST; the server hashes it.
export const createAccount = ({ username, email, password, passwordConfirmation, accountStatus, roleIds }) =>
  apiRequest(BASE, {
    method: 'POST',
    body: JSON.stringify({
      username,
      email,
      password,
      password_confirmation: passwordConfirmation,
      account_status: accountStatus,
      role_ids: roleIds,
    }),
  })

export const assignAccountRole = (userId, roleId) =>
  apiRequest(`${BASE}/${userId}/roles`, { method: 'POST', body: JSON.stringify({ role_id: roleId }) })

export const revokeAccountRole = (userId, roleId) =>
  apiRequest(`${BASE}/${userId}/roles/${roleId}`, { method: 'DELETE' })

export const updateAccountStatus = (userId, accountStatus) =>
  apiRequest(`${BASE}/${userId}/status`, { method: 'PUT', body: JSON.stringify({ account_status: accountStatus }) })

/** Re-read the signed-in identity so roles/permissions never outlive a revocation. */
export const fetchCurrentIdentity = () => apiRequest('/user')
