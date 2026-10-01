// Real Laravel HTTP integration only: not React/browser/PDF visual verification.
// Only run against the guarded testing router and a FRESH isolated synthetic export.
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { join } from 'node:path'
const directory = process.env.UNIVERSITY_EMAIL_PHASE2_BROWSER_DIR
assert.ok(directory, 'Fresh synthetic fixture required')
const actors = JSON.parse(await readFile(join(directory, 'identities.json'), 'utf8'))
const root = 'http://127.0.0.1:8099/api/v1/technical/university-email/students/1'
async function request(path, method = 'GET', body, actor = actors.technical, status = 200) {
  const response = await fetch(root+path, { method, headers: { Authorization: `Bearer ${actor.token}`, Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) })
  assert.equal(response.status, status, `Unexpected HTTP status for ${method} ${path}`)
  if (status === 200 && path.startsWith('/provisioning')) assert.ok(response.headers.get('cache-control').includes('no-store'))
  return (await response.json()).data
}
await request('/draft', 'PUT', { english_first_name: 'Ahmad', revision: 0 })
let c = await request('/provisioning/password', 'POST', { revision: 1 })
await request('/provisioning/receipt', 'POST', { operation_id: c.operation_id, generation: c.generation }, actors.technical, 409)
await request('/provisioning/cancel', 'POST', { operation_id: c.operation_id, generation: c.generation, confirmed: true })
await request('/draft', 'PUT', { english_first_name: 'Abdulrahman'.repeat(4), revision: 1 })
c = await request('/provisioning/password', 'POST', { revision: 2 })
assert.equal(c.password.length, 64)
const execute = credentials => request('/provisioning/execute', 'POST', { operation_id: credentials.operation_id, generation: credentials.generation, password: credentials.password, credential_proof: credentials.credential_proof, confirmed: true })
await execute(c)
const old = c
let state = await request('/provisioning/refresh-account', 'POST', {})
assert.equal(state.remote_snapshot.usage_percent, 2)
c = await request('/provisioning/reset-password', 'POST', { revision: 2, reason: 'Synthetic real HTTP general reset' })
await request('/provisioning/receipt', 'POST', { operation_id: old.operation_id, generation: old.generation }, actors.technical, 409)
await execute(c)
const receipt = await request('/provisioning/receipt', 'POST', { operation_id: c.operation_id, generation: c.generation })
assert.equal(receipt.receipt_purpose, 'password_reset')
assert.ok(receipt.student.full_name.length > 50)
assert.ok(!JSON.stringify(receipt).includes(c.password))
for (const kind of ['suspend', 'activate']) {
  state = await request('/provisioning/prepare-account', 'POST', { kind, revision: 2, reason: 'Synthetic HTTP account control', confirmed: true })
  const op = state.operations.find(item => item.kind === kind && item.status === 'prepared')
  state = await request('/provisioning/execute-account', 'POST', { operation_id: op.operation_id, generation: op.generation, confirmed: true })
  assert.equal(state.remote_snapshot.active, kind === 'activate')
  assert.equal(state.remote_snapshot.quota_bytes, 50 * 1048576)
  assert.equal(state.handover_status, 'not_delivered')
}
await request('/provisioning/refresh-account', 'POST', {}, actors.unauthorized, 403)
state = await request('/provisioning')
assert.ok(!JSON.stringify(state).includes(c.password))
console.log('Real Laravel HTTP + isolated SQLite + upstream-only Mailcow fake passed; React/browser/PDF NOT verified')
