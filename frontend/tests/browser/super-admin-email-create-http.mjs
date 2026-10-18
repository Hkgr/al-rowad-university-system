// Opt-in real Laravel HTTP verification, not a React/browser or PDF visual test.
// Use ONLY the existing guarded testing router with a fresh synthetic export.
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { join } from 'node:path'

const directory = process.env.UNIVERSITY_EMAIL_PHASE2_BROWSER_DIR
assert.ok(directory, 'Fresh isolated fixture required')
const actors = JSON.parse(await readFile(join(directory, 'identities.json'), 'utf8'))
assert.ok(actors.administrator, 'Active actual admin with no additional role/scope required')
const root = 'http://127.0.0.1:8099/api/v1'
async function request(path, method = 'GET', body, actor = actors.administrator, status = 200) {
  const response = await fetch(root + path, {
    method, headers: { Authorization: `Bearer ${actor.token}`, Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
    ...(body ? { body: JSON.stringify(body) } : {}),
  })
  assert.equal(response.status, status, `${method} ${path}`)
  const json = await response.json()
  return { json, response }
}
const base = '/technical/university-email/students'
for (const query of ['', '?q=', '?q=%20%20', '?q=' + encodeURIComponent('أحمد'), '?q=SYNTHETIC', '?per_page=1&page=2']) await request(base + query)
await request(base + '/2')
await request('/ministry/colleges')
await request('/vice-presidency/reports/definitions')
await request(base + '/2/create', 'POST', { english_first_name: 'Ahmad', confirmed: true }, actors.unauthorized, 403)
await request(base + '/2/create', 'POST', { english_first_name: 'Ahmad', confirmed: false }, actors.administrator, 422)
const { json: created, response } = await request(base + '/2/create', 'POST', { english_first_name: 'Ahmad', confirmed: true })
assert.ok(response.headers.get('cache-control').includes('no-store'))
assert.equal(created.data.provisioning_status, 'created')
assert.equal(created.data.operations[0].status, 'confirmed')
const credentials = created.data.credentials
assert.equal(credentials.password.length, 24)
const { json: state } = await request(base + '/2/provisioning')
assert.ok(!JSON.stringify(state).includes(credentials.password))
const { json: receipt } = await request(base + '/2/provisioning/receipt', 'POST', { operation_id: credentials.operation_id, generation: credentials.generation })
assert.equal(receipt.data.student.student_id, 2)
assert.ok(!JSON.stringify(receipt).includes(credentials.password))
const { json: finalState } = await request(base + '/2/provisioning')
assert.equal(finalState.data.handover_status, 'not_delivered')
await request(base + '/2/create', 'POST', { english_first_name: 'Ahmad', confirmed: true }, actors.administrator, 409)
await request('/student/university-email', 'GET', undefined, actors.administrator, 403)
console.log('Actual Laravel HTTP/middleware + isolated SQLite + upstream-only fake Mailcow passed. React rendering and PDF visual acceptance not exercised.')
