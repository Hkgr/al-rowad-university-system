import { printableCredentials } from './emailProvisioning.js'

/** Receipt-only retry: never prepares credentials or writes to the mail server. */
export async function downloadCurrentCredentialReceipt({ api, studentId, state, credentials, request, render, download, isCurrent }) {
  if (!isCurrent()) return false
  if (!['create', 'password_reset'].includes(credentials?.kind) || !printableCredentials(state, credentials)) {
    throw new Error('بيانات الإيصال الحالية غير متاحة.')
  }
  const json = await request(`${api}/provisioning/receipt`, {
    method: 'POST', cache: 'no-store',
    body: JSON.stringify({ operation_id: credentials.operation_id, generation: credentials.generation }),
  })
  if (!isCurrent()) return false
  const receipt = json?.data
  if (receipt?.operation_id !== credentials.operation_id || receipt.generation !== credentials.generation
    || Number(receipt.student?.student_id) !== Number(studentId) || receipt.email_address !== state.email_address
    || !receipt.receipt_id || !receipt.issued_at || !receipt.employee) throw new Error('بيانات الإيصال لا تطابق الحساب المؤكد.')
  render(receipt)
  if (!isCurrent()) return false
  const downloaded = await download(isCurrent)
  if (!isCurrent()) return false
  if (downloaded !== true) throw new Error('تعذر بدء تنزيل الإيصال.')
  return true
}

/** One explicit action stays pending through confirmation AND the automatic receipt attempt.
 * Confirmed credentials survive receipt failure. A receipt error never invokes mutation recovery.
 * Hooks belong to the current mounted/student/authorized UI; passwords remain in its RAM only.
 */
export async function runMailboxAction({ task, returnsCredentials, inFlight, blocked, isCurrent,
  onStart, onConfirmed, downloadReceipt, onReceiptFailure, onWriteFailure, onFinish }) {
  if (inFlight.current || blocked || !isCurrent()) return false
  inFlight.current = true
  onStart()
  try {
    const json = await task()
    if (!isCurrent()) return false
    const credentials = returnsCredentials ? json.data?.credentials : null
    if (returnsCredentials && (!['create', 'password_reset'].includes(credentials?.kind) || !printableCredentials(json.data, credentials))) {
      throw new Error('لم يصل تأكيد صالح لبيانات الدخول.')
    }
    await onConfirmed(json.data, credentials)
    if (returnsCredentials && isCurrent()) {
      try { await downloadReceipt(json.data, credentials) }
      catch (failure) { if (isCurrent()) onReceiptFailure(failure, credentials) }
    }
    return true // The account action is confirmed even if receipt/PDF fails.
  } catch (failure) {
    if (isCurrent()) await onWriteFailure(failure)
    return false
  } finally {
    inFlight.current = false
    if (isCurrent()) onFinish()
  }
}
