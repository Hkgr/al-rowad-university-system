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

export const updateAccountLogin = (userId, { username, email }) =>
  apiRequest(`${BASE}/${userId}/login`, {
    method: 'PATCH',
    body: JSON.stringify(Object.fromEntries(Object.entries({ username, email }).filter(([, value]) => value !== undefined))),
  })

// The new password travels once, in the JSON body; the server hashes it and ends the target's sessions.
export const resetAccountPassword = (userId, { password, passwordConfirmation }) =>
  apiRequest(`${BASE}/${userId}/password`, {
    method: 'PUT',
    body: JSON.stringify({ password, password_confirmation: passwordConfirmation }),
  })

export const correctHolderName = (userId, { personType, firstName, lastName, fatherName, motherName }) =>
  apiRequest(`${BASE}/${userId}/holder-name`, {
    method: 'PATCH',
    body: JSON.stringify({ person_type: personType, first_name: firstName, last_name: lastName, father_name: fatherName || null, mother_name: motherName || null }),
  })

/** Re-read the signed-in identity so roles/permissions never outlive a revocation. */
export const fetchCurrentIdentity = () => apiRequest('/user')
