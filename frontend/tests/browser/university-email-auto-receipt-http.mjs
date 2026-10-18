// Production orchestration helper + live isolated Laravel. Renderer/PDF injected; NOT React or visual acceptance.
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { join } from 'node:path'
import { downloadCurrentCredentialReceipt, runMailboxAction } from '../../src/features/technical-portal/lib/emailCredentialReceipt.js'
import { sendCreation } from '../../src/features/technical-portal/lib/emailCreation.js'

const directory = process.env.UNIVERSITY_EMAIL_PHASE2_BROWSER_DIR
assert.ok(directory, 'Fresh private synthetic fixture required')
const actors = JSON.parse(await readFile(join(directory, 'identities.json'), 'utf8'))
const api = '/api/v1/technical/university-email/students/1', base = 'http://127.0.0.1:8109'
const calls = [], inFlight = { current: false }
let state, credentials, pending = false, rendered = 0, downloads = 0, receiptFailure = false, pdfFails = false
async function request(url, options = {}) {
  assert.ok(url.startsWith('/api/v1/technical/university-email/'))
  calls.push(url)
  const response = await fetch(base + url, { ...options, headers: { Accept: 'application/json',
    'Content-Type': 'application/json', Authorization: `Bearer ${actors.technical.token}` } })
  const json = await response.json()
  if (!response.ok) { const error = new Error('Synthetic HTTP request failed'); error.status = response.status; throw error }
  return json
}
const downloadReceipt = (s, c) => downloadCurrentCredentialReceipt({ api, studentId: 1, state: s, credentials: c,
  request, isCurrent: () => true,
  render: receipt => {
    assert.equal(receipt.operation_id, c.operation_id)
    assert.equal(receipt.generation, c.generation)
    assert.ok(receipt.student.program && receipt.employee)
    assert.equal(JSON.stringify(receipt).includes(c.password), false)
    rendered++
  },
  download: async () => { assert.equal(pending, true); if (pdfFails) throw new Error('Synthetic PDF failure'); downloads++; return true },
})
const perform = task => runMailboxAction({ task, returnsCredentials: true, inFlight, blocked: false, isCurrent: () => true,
  onStart: () => { pending = true; receiptFailure = false },
  onConfirmed: (s, c) => { state = s; credentials = c }, downloadReceipt,
  onReceiptFailure: () => { receiptFailure = true },
  onWriteFailure: () => assert.fail('Unexpected write failure'), onFinish: () => { pending = false },
})

state = (await request(`${api}/provisioning`)).data
assert.equal(await perform(() => sendCreation(api, state, 'Abdulrahman'.repeat(4), request)), true)
assert.equal(downloads, 1); assert.equal(rendered, 1); assert.equal(credentials.password.length, 64)
assert.equal(state.provisioning_status, 'created'); assert.equal(pending, false)
assert.equal(calls.filter(url => url.endsWith('/create')).length, 1)

pdfFails = true
assert.equal(await perform(() => request(`${api}/reset-password-now`, { method: 'POST', body: JSON.stringify({
  revision: state.revision, reason: 'Synthetic reset', confirmed: true,
}) })), true)
assert.equal(receiptFailure, true); assert.equal(credentials.kind, 'password_reset'); assert.equal(credentials.password.length, 64)
assert.equal(state.handover_status, 'not_delivered'); assert.equal(state.provisioning_status, 'created')
const remoteAfterReset = await readFile(join(directory, 'remote-mailboxes.json'), 'utf8')
pdfFails = false; pending = true
assert.equal(await downloadReceipt(state, credentials), true); pending = false
assert.equal(await readFile(join(directory, 'remote-mailboxes.json'), 'utf8'), remoteAfterReset)
assert.equal(calls.filter(url => url.endsWith('/reset-password-now')).length, 1)

const rootId = (await request(api)).data.draft.university_email_id
state = (await request(`${api}/delete-mailbox`, { method: 'POST', body: JSON.stringify({ revision: state.revision,
  student_number_confirmation: 'R24011002', reason: 'Synthetic lifecycle preparation', confirmed: true }) })).data
assert.equal(state.provisioning_status, 'deleted')
assert.equal(await perform(() => sendCreation(api, state, 'Corrected', request)), true)
assert.equal(calls.filter(url => url.endsWith('/recreate')).length, 1)
assert.equal((await request(api)).data.draft.university_email_id, rootId)
assert.equal(downloads, 3); assert.equal(rendered, 4)
assert.equal(state.handover_status, 'not_delivered'); assert.equal(inFlight.current, false); assert.equal(pending, false)
console.log('Live isolated Laravel + actual orchestration helper: create/reset/recreate automatic metadata; injected PDF failure/retry passed. No React rendering or actual PDF/browser download verified.')
