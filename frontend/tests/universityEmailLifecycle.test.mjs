import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { ACCESS, canAccess } from '../src/features/auth/auth.js'
import { accountStatusLabels, deletionAllowed, deletionConfirmation, accountPayload, pendingAccountCheck } from '../src/features/technical-portal/lib/emailAccount.js'
import { creationMode, sendCreation, loadCreationState } from '../src/features/technical-portal/lib/emailCreation.js'
import { studentSearchQuery } from '../src/features/technical-portal/lib/universityEmail.js'
import { printableCredentials } from '../src/features/technical-portal/lib/emailProvisioning.js'
const source = path => readFileSync(new URL('../src/'+path, import.meta.url), 'utf8')

test('deletion has independent authority and preserves the central admin bypass', () => {
  assert.equal(canAccess(ACCESS.universityEmailDelete, { roles: ['super_admin'], permissions: [], access_scopes: [] }), true)
  const actor = { roles: ['technical_team'], permissions: ['technical_portal.access', 'university_email.view', 'university_email.delete'] }
  assert.equal(canAccess(ACCESS.universityEmailDelete, actor), true)
  assert.equal(canAccess(ACCESS.universityEmailDelete, { ...actor, roles: ['student'] }), false)
  for (const permission of ['manage', 'provision']) assert.equal(canAccess(ACCESS.universityEmailDelete, { ...actor, permissions: ['technical_portal.access', 'university_email.view', 'university_email.'+permission] }), false)
})
test('five mailbox lifecycle states are distinct, including deleted and unavailable', () => {
  assert.equal(new Set(Object.values(accountStatusLabels)).size, 6)
  assert.equal(accountStatusLabels.deleted, 'محذوف'); assert.equal(accountStatusLabels.not_created, 'لم يُنشأ')
  const query = new URLSearchParams(studentSearchQuery('  أحمد  ', 3, 'deleted'))
  assert.equal(query.get('q'), 'أحمد'); assert.equal(query.get('page'), '3'); assert.equal(query.get('status'), 'deleted')
})
test('delete requires fresh verified ownership, permission, installed schema and no pending operation', () => {
  const state = { provisioning_status: 'created', deletion_schema_ready: true, check: { status: 'verified', owned: true } }
  assert.equal(deletionAllowed(state, true), true)
  for (const patch of [{ provisioning_status: 'deleted' }, { check: { status: 'verified', owned: false } }, { deletion_schema_ready: false }, { pending_operation: { kind: 'delete' } }]) assert.equal(deletionAllowed({ ...state, ...patch }, true), false)
  assert.equal(deletionAllowed(state, false), false)
  assert.equal(deletionConfirmation('RTEST', 'RTEST'), true)
  assert.equal(deletionConfirmation(' RTEST ', 'RTEST'), false)
  assert.equal(deletionConfirmation('rtest', 'RTEST'), false)
})
test('deleted root is recreated only by explicit action with current revision', async () => {
  const state = { provisioning_status: 'deleted', revision: 7 }
  assert.equal(creationMode(state), 'deleted')
  const requests = []
  await sendCreation('/student', state, 'Ahmad', (url, options) => { requests.push({ url, body: JSON.parse(options.body) }) })
  assert.deepEqual(requests, [{ url: '/student/recreate', body: { english_first_name: 'Ahmad', confirmed: true, revision: 7 } }])
  assert.throws(() => sendCreation('/student', { ...state, pending_operation: { kind: 'delete' } }, 'Ahmad', () => assert.fail()), /التحقق/)
})
test('opening a deleted account never creates, prepares, or deletes automatically', async () => {
  const calls = [], state = { provisioning_status: 'deleted', revision: 3 }
  await loadCreationState('/student', async url => { calls.push(url); return { data: state } }, () => true, true)
  assert.deepEqual(calls, ['/student/provisioning'])
})
test('uncertainty authorizes only checking, never a destructive second write', () => {
  for (const kind of ['create', 'delete', 'password_reset']) {
    const op = { kind, status: 'uncertain', write_started_at: 'server-time' }
    assert.equal(pendingAccountCheck({ pending_operation: op }), op)
    assert.throws(() => accountPayload(kind, { provisioning_status: 'created', pending_operation: op, revision: 4 }, 'reason', 'S'), /التحقق|تحقق/)
  }
})
test('server-authoritative action payload does not accept addresses, actors or bypass flags', () => {
  const state = { provisioning_status: 'created', revision: 4 }
  assert.deepEqual(accountPayload('delete', state, '  reason  ', 'S1'), { revision: 4, reason: 'reason', confirmed: true, student_number_confirmation: 'S1' })
  assert.deepEqual(accountPayload('password_reset', state, 'reason'), { revision: 4, reason: 'reason', confirmed: true })
  assert.throws(() => accountPayload('delete', state, ' ', 'S1'))
})
test('deleted credentials and previous-cycle operation cannot produce a receipt', () => {
  const c = { operation_id: 'old', generation: 1 }
  assert.equal(printableCredentials({ provisioning_status: 'deleted', credential_operation_id: null, operations: [{ ...c, status: 'confirmed' }] }, c), false)
  assert.equal(printableCredentials({ provisioning_status: 'created', credential_operation_id: 'new', operations: [{ ...c, status: 'confirmed' }] }, c), false)
})
test('normal modal hides operation history and isolates destructive confirmation', () => {
  const dialog = source('features/technical-portal/components/UniversityEmailMailboxDialog.jsx')
  assert.doesNotMatch(dialog, /<UniversityEmailProvisioning|operations\.map|تفاصيل متقدمة|localStorage|sessionStorage|indexedDB/)
  for (const text of ['إجراءات الحساب', 'إجراءات حساسة', 'حذف البريد نهائيًا', 'سبب الإجراء', 'إعادة تنزيل الإيصال']) assert.ok(dialog.includes(text))
  assert.match(dialog, /deletionConfirmation\(confirmation, student.student_number\)/)
  assert.match(dialog, /writing.current \|\| blocked/)
  const shell = source('features/technical-portal/components/UniversityEmailDialog.jsx')
  assert.match(shell, /max-w-\[780px\]/); assert.match(shell, /overflow-y-auto/); assert.match(shell, /if \(!busy\) onClose/)
})
test('receipt preserves issuer identity, reset title, manual signatures and non-handover footer', () => {
  const receipt = source('features/technical-portal/components/UniversityEmailReceipt.jsx')
  for (const text of ['مسؤول إنشاء البريد', 'مسؤول إعادة تعيين كلمة المرور', 'توقيع الطالب', 'ختم الجهة', 'receipt.student.program', 'receipt.employee', 'receipt.issued_at', 'تنزيل الإيصال لا يعني تسجيل الاستلام إلكترونيًا']) assert.ok(receipt.includes(text))
  assert.doesNotMatch(receipt, /new Date|password\.(slice|replace|substring)|QR|apiRequest/)
  const pdf = source('features/technical-portal/lib/emailReceiptPdf.js')
  assert.match(pdf, /document.fonts.load/); assert.match(pdf, /image.decode/); assert.match(pdf, /scrollHeight > documentElement.clientHeight/)
  assert.doesNotMatch(pdf, /Math.min\(277/)
})
