import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { creationMode, loadCreationState, sendCreation, unresolvedCreation } from '../src/features/technical-portal/lib/emailCreation.js'
import { ACCESS, canAccess } from '../src/features/auth/auth.js'

const api = '/v1/technical/university-email/students/1'
const retry = { provisioning_status: 'draft', creation: { status: 'retry', operation_id: 'old', generation: 3 } }
const verify = { provisioning_status: 'draft', creation: { status: 'verify' } }
const existing = { provisioning_status: 'created', creation: { status: 'existing' } }
const admin = { roles: ['super_admin'], permissions: [], access_scopes: [], is_super_admin: true }

test('safe prior attempts share one explicit retry action with exact old generation', async () => {
  for (const status of ['prepared', 'failed', 'preflight', 'conflict']) {
    const calls = [], state = { ...retry, operations: [{ status, write_started_at: null }] }
    const request = async (url, options) => { calls.push({ url, options }); return { data: existing } }
    assert.equal(creationMode(state), 'retry')
    await sendCreation(api, state, 'Corrected', request)
    assert.equal(calls.length, 1)
    assert.equal(calls[0].url, `${api}/retry-create`)
    assert.deepEqual(JSON.parse(calls[0].options.body), { english_first_name: 'Corrected', confirmed: true, operation_id: 'old', generation: 3 })
  }
})

test('safe previous attempt is not cancelled or executed just by opening the window', async () => {
  const calls = []
  assert.equal(await loadCreationState(api, async (url, options) => { calls.push({ url, options }); return { data: retry } }, () => true, true), retry)
  assert.deepEqual(calls.map(c => c.url), [`${api}/provisioning`])
  assert.equal(calls[0].options.method, undefined)
})

test('uncertain/write-started automatically checks only; confirmed mailbox switches mode', async () => {
  const calls = []
  const state = await loadCreationState(api, async (url, options) => {
    calls.push({ url, options }); return { data: url.endsWith('/creation-check') ? existing : verify }
  }, () => true, true)
  assert.equal(creationMode(state), 'existing')
  assert.deepEqual(calls.map(c => c.url), [`${api}/provisioning`, `${api}/creation-check`])
  assert.deepEqual(JSON.parse(calls[1].options.body), {})
  assert.equal(calls[1].options.method, 'POST')
})

test('unresolved check never offers a duplicate create, even on repeated checks', async () => {
  const calls = [], request = async url => { calls.push(url); return { data: verify } }
  for (let i = 0; i < 2; i++) {
    assert.equal(creationMode(await loadCreationState(api, request, () => true, true)), 'verify')
    assert.throws(() => sendCreation(api, verify, 'Ahmad', request))
  }
  assert.equal(calls.length, 4)
  assert.ok(calls.every(url => !url.endsWith('/create') && !url.endsWith('/retry-create')))
  assert.match(unresolvedCreation, /لم يتم إرسال طلب إنشاء جديد/)
})

test('new creation stays one explicit user action, never a browser multi-step cancellation', async () => {
  const calls = [], ready = { creation: { status: 'ready' }, provisioning_status: 'draft' }
  await sendCreation(api, ready, 'Ahmad', async (url, options) => { calls.push({ url, options }) })
  assert.equal(calls.length, 1)
  assert.equal(calls[0].url, `${api}/create`)
  assert.deepEqual(JSON.parse(calls[0].options.body), { english_first_name: 'Ahmad', confirmed: true })
})

test('failed/malformed status checks fail closed and never automatically retry writes', async () => {
  assert.equal(creationMode(null), 'verify')
  assert.equal(creationMode({ operations: [] }), 'verify')
  assert.equal(creationMode({ creation: { status: 'retry' } }), 'verify')
  assert.equal(creationMode(retry, true), 'verify')
  const calls = [], request = async url => { calls.push(url); if (url.endsWith('/creation-check')) throw new Error('offline'); return { data: verify } }
  await assert.rejects(loadCreationState(api, request, () => true, true), /offline/)
  assert.equal(calls.length, 2)
})

test('obsolete student state cannot start a check or repopulate the new window', async () => {
  let resolve, current = true
  const calls = [], request = url => { calls.push(url); return new Promise(r => { resolve = r }) }
  const old = loadCreationState(api, request, () => current, true)
  current = false; resolve({ data: verify })
  assert.equal(await old, null)
  assert.equal(calls.length, 1)
})

test('late reconciliation cannot populate another student context', async () => {
  let resolve, current = true
  const pending = loadCreationState(api, async url => url.endsWith('/creation-check') ? new Promise(r => { resolve = r }) : { data: verify }, () => current, true)
  await new Promise(r => setImmediate(r))
  current = false; resolve({ data: existing })
  assert.equal(await pending, null)
})

test('admin needs no extra roles/scope; ordinary technical restrictions are unchanged', async () => {
  assert.equal(canAccess(ACCESS.universityEmailCreate, admin), true)
  assert.equal(canAccess(ACCESS.universityEmailManage, admin), true)
  assert.equal(canAccess(ACCESS.universityEmailReceipt, admin), true)
  assert.equal(canAccess(ACCESS.universityEmailCreate, { roles: ['technical_team'], permissions: [], access_scopes: [] }), false)
  let calls = 0
  await loadCreationState(api, async () => { calls++; return { data: verify } }, () => true, false)
  assert.equal(calls, 1)
})

test('normal dialog hides technical history, exposes one mode-specific action and retains credentials cleanup', () => {
  const dialog = readFileSync(new URL('../src/features/technical-portal/components/UniversityEmailMailboxDialog.jsx', import.meta.url), 'utf8')
  const manager = readFileSync(new URL('../src/features/technical-portal/components/UniversityEmailProvisioning.jsx', import.meta.url), 'utf8')
  assert.doesNotMatch(dialog, /تفاصيل متقدمة|مراجعة الحالة|توجد محاولة إنشاء سابقة|operations\.map/)
  assert.match(dialog, /mode === 'retry' \? 'إعادة المحاولة'/)
  assert.match(dialog, /mode === 'verify' && technical/)
  assert.match(dialog, /diagnostics=\{mode === 'verify'\}/)
  assert.match(dialog, /البريد موجود وتم تأكيده/)
  assert.match(dialog, /setCredentials\(null\); setReceipt\(null\)/)
  assert.match(manager, /showDiagnostics && <details/)
  assert.match(manager, /simple && !showDiagnostics && \(pendingAccount \|\| pendingCancellation\)/)
  assert.match(manager, /pendingCancellation.*find\(item => canCancelOperation\(item, authority\)\)/)
})
