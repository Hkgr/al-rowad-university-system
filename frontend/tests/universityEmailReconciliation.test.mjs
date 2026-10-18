import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { creationMode, loadCreationState, sendCreation } from '../src/features/technical-portal/lib/emailCreation.js'
import { fastCreationRead, initialCredentialsLost, pendingCreationReconciliation, reconciliationNoDuplicate, reconciliationUnresolved, reconciliationWaiting, startCreationReconciliation } from '../src/features/technical-portal/lib/emailReconciliation.js'

const api = '/v1/technical/university-email/students/1'
const waiting = { provisioning_status: 'draft', creation: { status: 'verify' },
  pending_operation: { operation_id: 'synthetic-op', kind: 'create', status: 'in_progress', write_started_at: 'synthetic' },
  reconciliation: { status: 'waiting', retry_after_seconds: 60, ready_at: 'synthetic' } }
const confirmed = { provisioning_status: 'created', creation: { status: 'existing' }, pending_operation: null,
  reconciliation: { status: 'ready', retry_after_seconds: 0 }, credential_operation_id: null }
const unresolved = { ...waiting, reconciliation: { status: 'unresolved', retry_after_seconds: 0 } }
const fast = { ...waiting, pending_operation: { ...waiting.pending_operation, status: 'uncertain' },
  reconciliation: { ...waiting.reconciliation, mode: 'fast_read_check', retry_after_seconds: 2 } }

function polling(responses = [unresolved], initial = waiting) {
  const timers = new Map(), delays = [], calls = [], states = []
  let id = 0, current = true, exhausted = false, checking = false, errors = 0
  const stop = startCreationReconciliation({ api, initialState: initial, isCurrent: () => current,
    schedule: (fn, delay) => { delays.push(delay); timers.set(++id, fn); return id }, clear: id => timers.delete(id),
    request: async (url, options) => {
      calls.push({ url, options }); const response = responses[Math.min(calls.length - 1, responses.length - 1)]
      if (response instanceof Error) throw response
      return { data: typeof response === 'function' ? await response() : response }
    },
    onState: state => states.push(state), onChecking: value => { checking = value },
    onExhausted: () => { exhausted = true }, onError: failure => { errors++; return [401, 403].includes(failure.status) },
  })
  return { calls, states, delays, timers, stop, exhausted: () => exhausted, checking: () => checking,
    errors: () => errors, revoke: () => { current = false },
    tick: async () => { assert.equal(timers.size, 1); const [key, fn] = [...timers][0]; timers.delete(key); await fn() },
  }
}

test('started uncertain create checks immediately then 2/3/5/8 seconds, never waits a minute or writes', async () => {
  const p = polling([fast], fast)
  assert.deepEqual(p.delays, [0])
  for (let i = 0; i < 5; i++) await p.tick()
  assert.deepEqual(p.delays, [0, 2000, 3000, 5000, 8000])
  assert.equal(p.calls.length, 5); assert.equal(p.exhausted(), true); assert.equal(p.timers.size, 0)
  assert.ok(p.calls.every(c => c.url === `${api}/creation-check`))
})

test('inside grace is waiting rather than a retry/error; cannot create', () => {
  assert.equal(creationMode(waiting), 'waiting')
  assert.equal(pendingCreationReconciliation(waiting), true)
  assert.throws(() => sendCreation(api, waiting, 'Synthetic', () => assert.fail('No write')))
})

test('opening a recent uncertain create delegates its one immediate read to the bounded controller', async () => {
  const calls = []
  const state = await loadCreationState(api, async url => {
    calls.push(url); return { data: fast }
  }, () => true, true)
  assert.equal(state, fast)
  assert.deepEqual(calls, [`${api}/provisioning`])
  const p = polling([confirmed], state)
  assert.deepEqual(p.delays, [0]); await p.tick()
  assert.equal(p.calls.length, 1); assert.equal(p.timers.size, 0)
})

test('fast confirmation on the second read cancels all remaining timers', async () => {
  const p = polling([fast, confirmed], fast)
  await p.tick(); assert.deepEqual(p.delays, [0, 2000]); await p.tick()
  assert.equal(p.states[1], confirmed); assert.equal(p.timers.size, 0); assert.equal(p.exhausted(), false)
})

test('fast transport errors use only 2/3/5/8, even with legacy 60-second metadata', async () => {
  const p = polling([new Error('Synthetic offline')], { ...fast, reconciliation: waiting.reconciliation })
  for (let i = 0; i < 5; i++) await p.tick()
  assert.deepEqual(p.delays, [0, 2000, 3000, 5000, 8000]); assert.equal(p.exhausted(), true)
  assert.ok(p.calls.every(c => c.url === `${api}/creation-check`))
})

for (const status of ['preflight', 'in_progress', 'uncertain']) test(`${status} without write start retains worker grace`, async () => {
  const worker = { ...waiting, pending_operation: { ...waiting.pending_operation, status, write_started_at: null } }
  assert.equal(fastCreationRead(worker), false)
  const p = polling([confirmed], worker)
  assert.deepEqual(p.delays, [60000]); await p.tick(); assert.equal(p.timers.size, 0)
})

for (const status of [401, 403]) test(`fast authorization ${status} stops on its immediate read`, async () => {
  const error = new Error('Synthetic denied'); error.status = status
  const p = polling([error], fast); await p.tick()
  assert.equal(p.calls.length, 1); assert.equal(p.timers.size, 0); assert.equal(p.exhausted(), false)
})

test('normal creation success with RAM credentials does not enter recovery', async () => {
  const state = { ...confirmed, credentials: { password: 'Synthetic RAM only' } }
  const calls = []
  assert.equal(await loadCreationState(api, async url => { calls.push(url); return { data: state } }, () => true, true), state)
  assert.deepEqual(calls, [`${api}/provisioning`]); assert.equal(polling([], state).timers.size, 0)
})

test('user-facing recovery language is plain, with no extra-write jargon', () => {
  assert.equal(reconciliationWaiting, 'يجري التحقق من إنشاء البريد…')
  assert.equal(reconciliationUnresolved, 'تعذر التأكد من نتيجة إنشاء البريد حتى الآن.')
  assert.equal(reconciliationNoDuplicate, 'لم تتم إعادة محاولة الإنشاء لتجنب تكرار الحساب.')
  for (const file of ['components/UniversityEmailMailboxDialog.jsx', 'lib/emailReconciliation.js', 'lib/emailCreation.js']) {
    const source = readFileSync(new URL(`../src/features/technical-portal/${file}`, import.meta.url), 'utf8')
    assert.doesNotMatch(source, /كتابة إضافية|تعذر حسم حالة البريد/)
  }
})

test('server deadline schedules one read-only automatic check, confirmation needs no click', async () => {
  const p = polling([confirmed])
  assert.deepEqual(p.delays, [60000]); assert.equal(p.calls.length, 0); assert.equal(p.checking(), true)
  await p.tick()
  assert.deepEqual(p.calls.map(c => c.url), [`${api}/creation-check`])
  assert.equal(p.calls[0].options.method, 'POST'); assert.deepEqual(JSON.parse(p.calls[0].options.body), {})
  assert.equal(creationMode(p.states[0]), 'existing'); assert.equal(p.checking(), false)
  assert.equal(p.exhausted(), false); assert.equal(p.timers.size, 0)
  assert.equal(p.states[0].credentials, undefined)
})

test('four unresolved reads end at manual fallback, never a create/reset/cancel/delete', async () => {
  const p = polling()
  for (let i = 0; i < 4; i++) await p.tick()
  assert.deepEqual(p.delays, [60000, 3000, 5000, 10000]); assert.equal(p.calls.length, 4)
  assert.ok(p.calls.every(c => c.url.endsWith('/creation-check')))
  assert.equal(p.exhausted(), true); assert.equal(p.checking(), false); assert.equal(p.timers.size, 0)
})

test('later presence confirms and terminates polling without another write', async () => {
  const p = polling([unresolved, confirmed]); await p.tick(); await p.tick()
  assert.equal(p.calls.length, 2); assert.equal(p.states[1], confirmed); assert.equal(p.timers.size, 0)
})

test('a server waiting delay takes precedence over the small local backoff', async () => {
  const p = polling([{ ...waiting, reconciliation: { ...waiting.reconciliation, retry_after_seconds: 15 } }, confirmed])
  await p.tick(); assert.deepEqual(p.delays, [60000, 15000]); await p.tick()
})

for (const context of ['unmount', 'student change']) test(`${context} cancels timer and cannot read or publish`, () => {
  const p = polling(); p.stop(); assert.equal(p.timers.size, 0); assert.equal(p.calls.length, 0)
})

test('authorization loss before timer fires suppresses the request', async () => {
  const p = polling(); p.revoke(); await p.tick(); assert.equal(p.calls.length, 0); assert.equal(p.timers.size, 0)
})

for (const status of [401, 403]) test(`authorization ${status} on a read stops without further polling`, async () => {
  const error = new Error('Synthetic denied'); error.status = status
  const p = polling([error]); await p.tick(); assert.equal(p.calls.length, 1); assert.equal(p.timers.size, 0)
  assert.equal(p.exhausted(), false)
})

test('late in-flight response cannot populate a closed/changed student', async () => {
  let resolve
  const p = polling([() => new Promise(r => { resolve = r })])
  const tick = p.tick(); p.stop(); resolve(confirmed); await tick
  assert.equal(p.states.length, 0); assert.equal(p.timers.size, 0)
})

test('transport failures consume the bounded budget rather than reissuing a write', async () => {
  const p = polling([new Error('Synthetic offline')], unresolved)
  for (let i = 0; i < 4; i++) await p.tick()
  assert.equal(p.errors(), 4); assert.equal(p.exhausted(), true); assert.equal(p.calls.length, 4)
})

test('expired initial 60-second deadline is never reused after transport failures', async () => {
  const p = polling([new Error('Synthetic transport failure')], waiting)
  assert.deepEqual(p.delays, [60000])
  await p.tick(); assert.deepEqual(p.delays, [60000, 3000])
  await p.tick(); assert.deepEqual(p.delays, [60000, 3000, 5000])
  await p.tick(); assert.deepEqual(p.delays, [60000, 3000, 5000, 10000])
  await p.tick()
  assert.equal(p.exhausted(), true); assert.equal(p.timers.size, 0); assert.equal(p.calls.length, 4)
  assert.ok(p.calls.every(c => c.url.endsWith('/creation-check')))
})

test('fresh server delay is still honoured after a prior transport error', async () => {
  const fresh = { ...waiting, reconciliation: { ...waiting.reconciliation, retry_after_seconds: 12 } }
  const p = polling([new Error('Synthetic offline'), fresh, confirmed], waiting)
  await p.tick(); await p.tick()
  assert.deepEqual(p.delays, [60000, 3000, 12000])
  await p.tick(); assert.equal(p.timers.size, 0); assert.equal(p.exhausted(), false)
})

test('invalid response cannot reuse initial delay either', async () => {
  const p = polling([{ malformed: true }], waiting)
  await p.tick(); assert.deepEqual(p.delays, [60000, 3000])
})

test('lost-create wording requires explicit server evidence, never a null maintenance reference', () => {
  for (const status of ['none', 'available', undefined]) {
    const normal = { ...confirmed, linkage_origin: 'created', credential_state: { status } }
    assert.equal(initialCredentialsLost(normal), false)
  }
  const lost = { ...confirmed, credential_state: { status: 'lost_after_reconciliation' } }
  assert.equal(initialCredentialsLost(lost), true)
  assert.equal(initialCredentialsLost(lost, true), false) // Visible confirmed RAM credentials take precedence.
  assert.equal(initialCredentialsLost({ ...lost, provisioning_status: 'deleted' }), false)
  assert.equal(initialCredentialsLost({ ...lost, provisioning_status: 'draft' }), false)
  assert.equal(initialCredentialsLost({ ...lost, credential_state: { status: 'available' } }), false) // Explicit reset completed.
})

test('a different operation response is not followed or attached to the previous timer', async () => {
  const p = polling([{ ...unresolved, pending_operation: { ...waiting.pending_operation, operation_id: 'newer' } }])
  await p.tick(); assert.equal(p.states.length, 0); assert.equal(p.timers.size, 0); assert.equal(p.exhausted(), true)
})

for (const kind of ['password_reset', 'suspend', 'activate', 'delete', 'link']) test(`${kind}: no new automatic recovery policy`, () => {
  const p = polling([], { ...waiting, pending_operation: { ...waiting.pending_operation, kind } })
  assert.equal(p.timers.size, 0); assert.equal(p.calls.length, 0)
})

test('dialog waiting/explicit reset and existing auto receipt wiring remain separated (static)', () => {
  const dialog = readFileSync(new URL('../src/features/technical-portal/components/UniversityEmailMailboxDialog.jsx', import.meta.url), 'utf8')
  assert.match(dialog, /return stop/)
  assert.match(dialog, /requiresCheck && !waiting/)
  assert.match(dialog, /!recoveryOperation \|\| exhausted/)
  assert.match(dialog, /recoveryFast, recoveryEpoch/)
  assert.match(dialog, /reconciliationNoDuplicate/)
  assert.match(dialog, /waiting && <div/)
  assert.match(dialog, /reconciliationWaiting/)
  assert.match(dialog, /lostInitialCredentials/)
  assert.match(dialog, /إصدار كلمة مرور جديدة/)
  assert.match(dialog, /onClick=\{\(\) => startAction\('password_reset'\)\}/)
  assert.match(dialog, /downloadReceipt, onReceiptFailure: receiptFailure/)
  assert.match(dialog, /const usable = .*printableCredentials\(state, credentials\)/)
  assert.match(dialog, /const lostCredentials = confirmed && initialCredentialsLost\(state, usable\)/)
  assert.doesNotMatch(dialog, /const lostCredentials = .*credential_operation_id/)
})
