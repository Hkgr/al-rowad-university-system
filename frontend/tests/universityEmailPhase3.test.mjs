import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { ACCESS, canAccess } from '../src/features/auth/auth.js'
import { canCancelOperation, credentialVisible, kindLabels, printableCredentials, usageLabel } from '../src/features/technical-portal/lib/emailProvisioning.js'

test('four management actions require actual technical role plus their own assigned permission', () => {
  for (const [access, permission] of [[ACCESS.universityEmailReset, 'reset_password'], [ACCESS.universityEmailSuspend, 'suspend'], [ACCESS.universityEmailActivate, 'activate'], [ACCESS.universityEmailLink, 'link_existing']]) {
    const actor = { roles: ['technical_team'], permissions: ['technical_portal.access', 'university_email.view', 'university_email.'+permission] }
    assert.equal(canAccess(access, actor), true)
    for (const roles of [['super_admin'], ['student'], ['dean'], []]) assert.equal(canAccess(access, { ...actor, roles }), false)
    assert.equal(canAccess(access, { ...actor, permissions: ['technical_portal.access', 'university_email.view'] }), false)
  }
})
test('general reset hides proposed password until the exact operation is confirmed', () => {
  const c = { kind: 'password_reset', operation_id: 'new', generation: 1 }
  const state = { provisioning_status: 'created', credential_operation_id: 'old', operations: [{ operation_id: 'new', generation: 1, status: 'prepared' }] }
  assert.equal(credentialVisible(state, c), false)
  assert.equal(printableCredentials(state, c), false)
  const confirmed = { ...state, credential_operation_id: 'new', operations: [{ ...state.operations[0], status: 'confirmed' }] }
  assert.equal(credentialVisible(confirmed, c), true)
  assert.equal(credentialVisible({ ...confirmed, credential_operation_id: null }, c), false)
})
test('new operation cancellation requires exact management authority and never write-start', () => {
  for (const [kind, permission] of [['password_reset', 'mayGeneralReset'], ['suspend', 'maySuspend'], ['activate', 'mayActivate'], ['link', 'mayLink']]) {
    const op = { kind, status: 'prepared', can_cancel: true, write_started_at: null }
    assert.equal(canCancelOperation(op, { [permission]: true }), true)
    assert.equal(canCancelOperation(op, { mayCreate: true, mayReset: true }), false)
    assert.equal(canCancelOperation({ ...op, write_started_at: 'started' }, { [permission]: true }), false)
    assert.ok(kindLabels[kind])
  }
})
test('usage distinguishes zero from unavailable and uses binary MiB', () => {
  assert.equal(usageLabel(null), 'غير متاح'); assert.equal(usageLabel(undefined), 'غير متاح')
  assert.notEqual(usageLabel(0), 'غير متاح'); assert.match(usageLabel(1048576), /MiB/)
})
test('management UI reuses existing API/dialogs and never stores secrets or calls Mailcow', () => {
  const source = readFileSync(new URL('../src/features/technical-portal/components/UniversityEmailProvisioning.jsx', import.meta.url), 'utf8')
  for (const path of ['refresh-account', 'reset-password', 'preview-link', 'prepare-account', 'execute-account']) assert.ok(source.includes(path))
  assert.match(source, /ownership_confirmed: attested/)
  assert.match(source, /setCredentials\(null\); setReceipt\(null\)/)
  assert.match(source, /credentialVisible\(state, credentials\)/)
  assert.match(source, /linkPreview.email_address !== address/)
  assert.match(source, /معاملات|آخر تحقق ناجح/)
  assert.doesNotMatch(source, /localStorage|sessionStorage|indexedDB|https:\/\/mail\.|confirm_delivery|handover_status\s*=/)
})
test('receipt states password-reset purpose without adding handover controls', () => {
  const source = readFileSync(new URL('../src/features/technical-portal/components/UniversityEmailReceipt.jsx', import.meta.url), 'utf8')
  assert.match(source, /receipt_purpose === 'password_reset'/)
  assert.match(source, /إيصال إعادة تعيين كلمة مرور/)
  assert.match(source, /تنزيل هذا المستند لا يثبت استلام/)
  assert.doesNotMatch(source, /onClick|apiRequest|window.print|QR/)
})
