import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { canCancelOperation, printableCredentials, provisioningFailure, operationLabels } from '../src/features/technical-portal/lib/emailProvisioning.js'

test('cancel UI requires server capability, original operation permission and no write start', () => {
  const op = { kind: 'create', status: 'prepared', can_cancel: true, write_started_at: null }
  assert.equal(canCancelOperation(op, { mayCreate: true, mayReset: false }), true)
  assert.equal(canCancelOperation(op, { mayCreate: false, mayReset: true }), false)
  for (const status of ['in_progress', 'uncertain', 'confirmed', 'cancelled']) assert.equal(canCancelOperation({ ...op, status }, { mayCreate: true }), false)
  assert.equal(canCancelOperation({ ...op, write_started_at: 'now' }, { mayCreate: true }), false)
  assert.equal(canCancelOperation({ ...op, can_cancel: false }, { mayCreate: true }), false)
  assert.equal(canCancelOperation({ ...op, kind: 'reset' }, { mayReset: true }), true)
})

test('receipt preserves exact credential text with LTR isolation and wrap instead of slicing', () => {
  const src = readFileSync(new URL('../src/features/technical-portal/components/UniversityEmailReceipt.jsx', import.meta.url), 'utf8')
  assert.match(src, /data-receipt-password dir="ltr"/)
  assert.match(src, /overflowWrap: 'anywhere', whiteSpace: 'pre-wrap', unicodeBidi: 'isolate', hyphens: 'none'/)
  assert.match(src, /\{password\}/)
  assert.doesNotMatch(src, /password\.(slice|substring|replace)|textOverflow|overflow: 'hidden'/)
})

test('only currently confirmed credentials can produce a PDF', () => {
  const c = { operation_id: 'one', generation: 2 }
  const state = { provisioning_status: 'created', credential_operation_id: 'one', operations: [{ operation_id: 'one', generation: 2, status: 'confirmed' }] }
  assert.equal(printableCredentials(state, c), true)
  for (const patch of [{ credential_operation_id: null }, { credential_operation_id: 'reset' }, { provisioning_status: 'draft' },
    { operations: [{ operation_id: 'one', generation: 1, status: 'confirmed' }] },
    { operations: [...state.operations, { operation_id: 'other', status: 'uncertain' }] }]) assert.equal(printableCredentials({ ...state, ...patch }, c), false)
  assert.equal(printableCredentials(null, c), false)
  assert.equal(printableCredentials(state, null), false)
})
test('uncertainty never suggests automatic retry and statuses distinguish draft and remote confirmation', () => {
  assert.match(provisioningFailure({}), /لا تُعد إرسال/)
  assert.match(provisioningFailure({ status: 403 }), /أُخفيت/)
  assert.match(provisioningFailure({ status: 503 }), /معطّل/)
  assert.notEqual(operationLabels.confirmed, operationLabels.uncertain)
})
test('source contract: RAM credentials, explicit PDF download, no delivery confirmation', () => {
  const src = readFileSync(new URL('../src/features/technical-portal/components/UniversityEmailProvisioning.jsx', import.meta.url), 'utf8')
  assert.match(src, /تنزيل الإيصال PDF/)
  assert.match(src, /onSensitive\(!!credentials\)/)
  assert.match(src, /cache: 'no-store'/)
  assert.match(src, /setCredentials\(null\)/)
  assert.doesNotMatch(src, /localStorage|sessionStorage|indexedDB|afterprint|paper_signed|confirm_delivery/)
  const receipt = readFileSync(new URL('../src/features/technical-portal/components/UniversityEmailReceipt.jsx', import.meta.url), 'utf8')
  assert.match(receipt, /بيانات الدخول شخصية وسرية\. لا تشارك كلمة المرور مع أي شخص، وغيّر كلمة المرور الأولية بعد استلام الحساب\./)
  assert.match(receipt, /تنزيل هذا المستند لا يثبت استلام/)
  assert.match(receipt, /dir="ltr"/)
  const pdf = readFileSync(new URL('../src/features/technical-portal/lib/emailReceiptPdf.js', import.meta.url), 'utf8')
  assert.match(pdf, /format: 'a4'/); assert.match(pdf, /pdf.save/); assert.match(pdf, /isCurrent\(\)/)
  assert.doesNotMatch(pdf, /fetch\(|apiRequest|window.print/)
})
test('student endpoint and page cannot choose another student or display credentials', () => {
  const src = readFileSync(new URL('../src/features/student-dashboard/pages/StudentUniversityEmail.jsx', import.meta.url), 'utf8')
  assert.match(src, /apiRequest\('\/v1\/student\/university-email'/)
  assert.doesNotMatch(src, /\?student_id|data.password|draft/)
  const nav = readFileSync(new URL('../src/features/student-dashboard/nav.js', import.meta.url), 'utf8')
  assert.match(nav, /\/student\/university-email.*studentIdentity: true.*allRoles: \['student'\]/)
})
