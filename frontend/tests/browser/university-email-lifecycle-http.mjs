// Real Laravel HTTP and upstream-only Mailcow fake; NOT a React/browser/PDF layout test.
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { join } from 'node:path'
const directory = process.env.UNIVERSITY_EMAIL_PHASE2_BROWSER_DIR
assert.ok(directory, 'Fresh isolated synthetic export required')
const actors = JSON.parse(await readFile(join(directory, 'identities.json'), 'utf8'))
const base = 'http://127.0.0.1:8109/api/v1/technical/university-email'
async function request(path, method = 'GET', body, actor = actors.technical, status = 200) {
  const response = await fetch(base + path, { method, headers: { Accept: 'application/json', Authorization: `Bearer ${actor.token}`,
    ...(body ? { 'Content-Type': 'application/json' } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) })
  assert.equal(response.status, status, `${method} ${path}: unexpected status`)
  return response.json()
}
for (const query of ['', '?q=', '?q=%20%20']) {
  const list = await request('/students' + query)
  assert.ok(list.meta.total > 0)
}
const root = '/students/1'
await request(root + '/draft', 'PUT', { english_first_name: 'Oldname', revision: 0 })
let old = (await request(root + '/provisioning/password', 'POST', { revision: 1 })).data
const created = (await request(root + '/retry-create', 'POST', { english_first_name: 'Abdulrahman'.repeat(4),
  operation_id: old.operation_id, generation: old.generation, confirmed: true })).data
let credentials = created.credentials
assert.equal(credentials.password.length, 64)
assert.equal(created.creation_operation_id, created.credential_operation_id)
await request(root + '/provisioning/execute', 'POST', { ...old, kind: undefined, confirmed: true }, actors.technical, 409)
const receiptInput = c => ({ operation_id: c.operation_id, generation: c.generation })
let receipt = (await request(root + '/provisioning/receipt', 'POST', receiptInput(credentials))).data
assert.ok(receipt.employee && receipt.student.program && !JSON.stringify(receipt).includes(credentials.password))
assert.equal(receipt.receipt_purpose, 'initial_credentials')
let state = (await request(root + '/account-action', 'POST', { revision: created.revision, kind: 'suspend', reason: 'Synthetic control', confirmed: true })).data
assert.equal(state.remote_snapshot.active, false)
assert.equal((await request('/students?status=suspended')).meta.total, 1)
state = (await request(root + '/account-action', 'POST', { revision: state.revision, kind: 'activate', reason: 'Synthetic control', confirmed: true })).data
state = (await request(root + '/reset-password-now', 'POST', { revision: state.revision, reason: 'Synthetic reset', confirmed: true })).data
credentials = state.credentials
assert.equal(credentials.password.length, 64)
receipt = (await request(root + '/provisioning/receipt', 'POST', receiptInput(credentials))).data
assert.equal(receipt.receipt_purpose, 'password_reset')
const historicalRoot = (await request(root)).data.draft.university_email_id
state = (await request(root + '/delete-mailbox', 'POST', { revision: state.revision, student_number_confirmation: 'R24011002', reason: 'Synthetic delete', confirmed: true })).data
assert.equal(state.provisioning_status, 'deleted')
assert.equal(state.creation_operation_id, null)
assert.equal(state.credential_operation_id, null)
assert.equal((await request('/students?status=deleted')).meta.total, 1)
await request(root + '/provisioning/receipt', 'POST', receiptInput(credentials), actors.technical, 409)
state = (await request(root + '/recreate', 'POST', { revision: state.revision, english_first_name: 'Corrected', confirmed: true })).data
assert.equal(state.provisioning_status, 'created')
assert.notEqual(state.creation_operation_id, created.creation_operation_id)
assert.equal((await request(root)).data.draft.university_email_id, historicalRoot)
assert.equal(state.handover_status, 'not_delivered')
await request(root + '/provisioning/receipt', 'POST', receiptInput(old), actors.technical, 409)
await request('/students', 'GET', undefined, actors.unauthorized, 403)
console.log('Isolated live Laravel HTTP + fake Mailcow lifecycle, search, safe retry, controls, reset, receipts, delete and same-root recreation passed; React/browser/PDF NOT verified')
