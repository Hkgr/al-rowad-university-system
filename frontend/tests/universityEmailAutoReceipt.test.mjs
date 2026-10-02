// Executable UI orchestration with injected transport/renderer/PDF. NOT a React/browser acceptance test.
import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { downloadCurrentCredentialReceipt, runMailboxAction } from '../src/features/technical-portal/lib/emailCredentialReceipt.js'
import { sendCreation } from '../src/features/technical-portal/lib/emailCreation.js'

const api = '/v1/technical/university-email/students/1'
const deferred = () => { let resolve, reject; const promise = new Promise((r, j) => { resolve = r; reject = j }); return { promise, resolve, reject } }
function fixture(kind = 'create', options = {}) {
  const credentials = { kind, operation_id: 'exact-confirmed-operation', generation: 3, password: 'Synthetic!234TestOnlyWord' }
  const state = { provisioning_status: 'created', email_address: 'test.synthetic@alrowaduni.edu.sy', creation_operation_id: 'current-create',
    credential_operation_id: credentials.operation_id, operations: [{ ...credentials, password: undefined, status: 'confirmed' }], credentials }
  const metadata = { receipt_id: 'synthetic-receipt', operation_id: credentials.operation_id, generation: 3,
    email_address: state.email_address, student: { student_id: 1 }, issued_at: '2026-10-02T00:00:00Z', employee: 'موظف اصطناعي' }
  const events = [], calls = [], inFlight = { current: false }
  let current = true, visible = null, confirmedState = null, pending = false
  const request = async (url, requestOptions) => {
    calls.push({ url, body: JSON.parse(requestOptions.body) })
    if (url.endsWith('/provisioning/receipt')) {
      events.push('receipt-request')
      if (options.receiptFailure) throw options.receiptFailure
      if (options.receiptWait) await options.receiptWait.promise
      return { data: options.metadata ?? metadata }
    }
    events.push('write')
    if (options.writeFailure) throw options.writeFailure
    if (options.writeWait) await options.writeWait.promise
    return { data: state }
  }
  const downloadReceipt = (s, c) => downloadCurrentCredentialReceipt({ api, studentId: 1, state: s, credentials: c, request,
    isCurrent: () => current,
    render: receipt => { assert.equal(receipt.operation_id, c.operation_id); events.push('render') },
    download: async isCurrent => {
      assert.equal(isCurrent(), true); assert.equal(pending, true)
      events.push('pdf')
      if (options.pdfFailure) throw options.pdfFailure
      if (options.pdfWait) await options.pdfWait.promise
      return true
    },
  })
  const action = task => runMailboxAction({ task, returnsCredentials: true, inFlight, blocked: false, isCurrent: () => current,
    onStart: () => { pending = true; events.push('pending') },
    onConfirmed: (s, c) => { visible = c; confirmedState = s; events.push('confirmed') },
    downloadReceipt,
    onReceiptFailure: () => { events.push('receipt-failed') },
    onWriteFailure: () => { events.push('write-failed') },
    onFinish: () => { pending = false; events.push('finish') },
  })
  return { credentials, state, events, calls, request, action, downloadReceipt, inFlight,
    visible: () => visible, confirmedState: () => confirmedState, pending: () => pending,
    revoke: () => { current = false; visible = null; confirmedState = null },
    retryReceipt: async () => { pending = true; try { return await downloadReceipt(confirmedState, visible) } finally { pending = false } },
  }
}

for (const [label, initial, suffix] of [
  ['create', { provisioning_status: 'draft', creation: { status: 'ready' } }, 'create'],
  ['recreate', { provisioning_status: 'deleted', revision: 7 }, 'recreate'],
  ['safe retry', { provisioning_status: 'draft', creation: { status: 'retry', operation_id: 'old-safe', generation: 1 } }, 'retry-create'],
]) test(`${label}: one click automatically requests exact receipt and downloads after confirmation`, async () => {
  const f = fixture()
  assert.equal(await f.action(() => sendCreation(api, initial, 'Test', f.request)), true)
  assert.deepEqual(f.events, ['pending', 'write', 'confirmed', 'receipt-request', 'render', 'pdf', 'finish'])
  assert.equal(f.calls[0].url, `${api}/${suffix}`)
  assert.deepEqual(f.calls[1], { url: `${api}/provisioning/receipt`, body: { operation_id: f.credentials.operation_id, generation: 3 } })
  assert.equal(f.visible(), f.credentials)
  assert.equal(f.pending(), false)
})

test('password_reset: one confirmation automatically downloads without another credential request', async () => {
  const f = fixture('password_reset')
  assert.equal(await f.action(() => f.request(`${api}/reset-password-now`, { body: JSON.stringify({ revision: 2, reason: 'Synthetic reason', confirmed: true }) })), true)
  assert.deepEqual(f.events, ['pending', 'write', 'confirmed', 'receipt-request', 'render', 'pdf', 'finish'])
  assert.equal(f.calls.length, 2)
  assert.equal(f.visible().password, f.credentials.password)
})

for (const failure of ['receiptFailure', 'pdfFailure']) test(`${failure}: account remains confirmed, credentials visible; retry only requests receipt`, async () => {
  const options = { [failure]: new Error('Synthetic failure') }, f = fixture('password_reset', options)
  assert.equal(await f.action(() => f.request(`${api}/reset-password-now`, { body: '{}' })), true)
  assert.equal(f.confirmedState().provisioning_status, 'created')
  assert.equal(f.visible(), f.credentials)
  assert.equal(f.pending(), false)
  assert.equal(f.events.includes('write-failed'), false)
  assert.equal(f.events.includes('receipt-failed'), true)
  options[failure] = null
  assert.equal(await f.retryReceipt(), true)
  assert.equal(f.calls.filter(c => !c.url.endsWith('/provisioning/receipt')).length, 1)
  assert.equal(f.calls.filter(c => c.url.endsWith('/provisioning/receipt')).length, 2)
})

test('double click and close/student switching remain blocked through both write and PDF stages', async () => {
  const writeWait = deferred(), pdfWait = deferred(), f = fixture('create', { writeWait, pdfWait })
  const task = () => f.request(`${api}/create`, { body: '{}' })
  const first = f.action(task)
  assert.equal(f.inFlight.current, true); assert.equal(f.pending(), true)
  assert.equal(await f.action(task), false)
  writeWait.resolve()
  while (!f.events.includes('pdf')) await new Promise(resolve => setImmediate(resolve))
  assert.equal(f.visible(), f.credentials)
  assert.equal(f.inFlight.current, true); assert.equal(f.pending(), true)
  assert.equal(await f.action(task), false)
  pdfWait.resolve()
  assert.equal(await first, true)
  assert.equal(f.inFlight.current, false); assert.equal(f.pending(), false)
  assert.equal(f.calls.filter(c => c.url.endsWith('/create')).length, 1)
})

test('lost write response never requests receipt or retries the write', async () => {
  const f = fixture('create', { writeFailure: new Error('Synthetic lost response') })
  assert.equal(await f.action(() => f.request(`${api}/create`, { body: '{}' })), false)
  assert.deepEqual(f.events, ['pending', 'write', 'write-failed', 'finish'])
  assert.equal(f.visible(), null); assert.equal(f.calls.length, 1)
})

test('unconfirmed credentials never render or download a receipt', async () => {
  const f = fixture()
  f.state.operations[0].status = 'uncertain'
  assert.equal(await f.action(() => f.request(`${api}/create`, { body: '{}' })), false)
  assert.equal(f.visible(), null)
  assert.equal(f.events.includes('receipt-request'), false)
})

for (const field of ['operation_id', 'generation', 'email_address', 'student']) test(`mismatched receipt ${field} is rejected without losing confirmed credentials`, async () => {
  const f = fixture('create', { metadata: { operation_id: 'exact-confirmed-operation', generation: 3,
    email_address: 'test.synthetic@alrowaduni.edu.sy', student: { student_id: 1 }, receipt_id: 'r', issued_at: 'now', employee: 'Test',
    [field]: field === 'student' ? { student_id: 2 } : field === 'generation' ? 4 : 'other' } })
  assert.equal(await f.action(() => f.request(`${api}/create`, { body: '{}' })), true)
  assert.equal(f.visible(), f.credentials)
  assert.equal(f.events.includes('render'), false)
  assert.equal(f.events.includes('receipt-failed'), true)
})

test('authorization loss during receipt request suppresses rendering/download and old credentials', async () => {
  const receiptWait = deferred(), f = fixture('create', { receiptWait })
  const pending = f.action(() => f.request(`${api}/create`, { body: '{}' }))
  while (!f.events.includes('receipt-request')) await new Promise(resolve => setImmediate(resolve))
  f.revoke(); receiptWait.resolve(); await pending
  assert.equal(f.visible(), null)
  assert.equal(f.events.includes('render'), false)
  assert.equal(f.events.includes('pdf'), false)
})

for (const kind of ['delete', 'suspend', 'activate', 'link']) test(`${kind}: unchanged noncredential action never requests a PDF`, async () => {
  const f = fixture(kind)
  await runMailboxAction({ task: async () => ({ data: f.state }), returnsCredentials: false, inFlight: f.inFlight,
    blocked: false, isCurrent: () => true, onStart: () => {}, onConfirmed: (_, c) => assert.equal(c, null),
    downloadReceipt: () => assert.fail('No receipt for this action'), onReceiptFailure: () => assert.fail(),
    onWriteFailure: () => assert.fail(), onFinish: () => {} })
  assert.equal(f.calls.length, 0)
})

test('component wiring preserves pending navigation, RAM cleanup and secondary receipt-only fallback (static)', () => {
  const source = path => readFileSync(new URL(`../src/features/technical-portal/${path}`, import.meta.url), 'utf8')
  const dialog = source('components/UniversityEmailMailboxDialog.jsx')
  assert.match(dialog, /runMailboxAction\(/)
  assert.match(dialog, /downloadReceipt, onReceiptFailure: receiptFailure/)
  assert.match(dialog, /render: metadata => flushSync\(\(\) => setReceipt\(metadata\)\)/)
  assert.match(dialog, /downloadEmailReceipt\(receiptElement.current, isStillCurrent\)/)
  assert.match(dialog, /await downloadReceipt\(state, credentials\)/)
  assert.match(dialog, /if \(writing.current \|\| externalPending\) return/)
  assert.match(dialog, /\['downloaded', 'failed'\]\.includes\(receiptStatus\)/)
  assert.doesNotMatch(dialog, /تنزيل الإيصال PDF|localStorage|sessionStorage|window.print/)
  for (const text of ['جارٍ تجهيز الإيصال…', 'تم تنزيل الإيصال', 'إعادة تنزيل الإيصال', 'تأكيد إعادة التعيين', 'جارٍ إعادة تعيين كلمة المرور…']) assert.ok(dialog.includes(text))
  const page = source('pages/UniversityEmailPage.jsx')
  assert.match(page, /if \(pending\).*return/)
  assert.match(page, /disabled=\{pending\}/)
  const pdf = source('lib/emailReceiptPdf.js')
  assert.match(pdf, /await pdf.save\('university-email-receipt.pdf', \{ returnPromise: true \}\)/)
})
