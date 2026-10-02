export const accountStatusLabels = { not_created: 'لم يُنشأ', active: 'فعال', suspended: 'موقوف', deleted: 'محذوف', needs_check: 'يحتاج تحقق', unavailable: 'غير متاح' }
export function deletionAllowed(state, allowed) {
  return !!(allowed && state?.deletion_schema_ready && state.provisioning_status === 'created'
    && !state.pending_operation && state.check?.owned && state.check.status === 'verified')
}
export function deletionConfirmation(value, studentNumber) { return value === studentNumber }
export function pendingAccountCheck(state) {
  const op = state?.pending_operation
  return op && (op.write_started_at || ['preflight', 'in_progress', 'uncertain'].includes(op.status)) ? op : null
}
export function accountPayload(kind, state, reason, confirmation) {
  if (!state || state.provisioning_status !== 'created' || state.pending_operation || !reason.trim()) throw new Error('تحقق من حالة البريد وأدخل سبب الإجراء.')
  return { revision: state.revision, reason: reason.trim(), confirmed: true,
    ...(kind === 'delete' ? { student_number_confirmation: confirmation } : kind === 'password_reset' ? {} : { kind }) }
}
