export const reconciliationWaiting = 'يجري التحقق من إنشاء البريد…'
export const reconciliationUnresolved = 'تعذر التأكد من نتيجة إنشاء البريد حتى الآن.'
export const reconciliationNoDuplicate = 'لم تتم إعادة محاولة الإنشاء لتجنب تكرار الحساب.'
export const lostInitialCredentials = 'تم إنشاء البريد وتأكيده، لكن تعذر استعادة كلمة المرور الأولية بسبب انقطاع الاستجابة.'

/** Only the server's durable evidence identifies this recovery case. RAM still governs receipt use. */
export function initialCredentialsLost(state, usable = false) {
  return !usable && state?.provisioning_status === 'created'
    && state.credential_state?.status === 'lost_after_reconciliation'
}

export function pendingCreationReconciliation(state) {
  const op = state?.pending_operation
  return !!op && op.kind === 'create' && ['preflight', 'in_progress', 'uncertain'].includes(op.status)
    && ['waiting', 'ready', 'unresolved'].includes(state.reconciliation?.status)
}

export function fastCreationRead(state) {
  const op = state?.pending_operation
  return op?.kind === 'create' && op.status === 'uncertain' && !!op.write_started_at
}

/** Bounded read-only polling. No write/cancel/reset callbacks or automatic replays.
 * One controller per student/operation; it is cancelled on unmount/authorization loss.
 */
export function startCreationReconciliation({ api, initialState, request, isCurrent, onState, onChecking, onExhausted,
  onError, schedule = setTimeout, clear = clearTimeout }) {
  let active = true, timer = null, attempts = 0
  const current = () => active && isCurrent()
  const stop = () => { active = false; if (timer !== null) clear(timer); timer = null }
  const operation = initialState?.pending_operation?.operation_id
  const fast = fastCreationRead(initialState)
  const backoffs = fast ? [2000, 3000, 5000, 8000] : [3000, 5000, 10000]
  const delay = (state, fallback) => {
    // Only a started uncertain create can skip the live-worker safety grace.
    if (state && fastCreationRead(state)) return fallback
    const seconds = Number(state?.reconciliation?.retry_after_seconds)
    return Math.max(fallback, Number.isFinite(seconds) && seconds > 0 ? seconds * 1000 : 0)
  }
  const queue = (state, fallback) => {
    if (!current()) { stop(); return }
    onChecking(true)
    timer = schedule(check, delay(state, fallback))
  }
  const check = async () => {
    timer = null
    if (!current()) { stop(); return }
    attempts++
    let next
    try {
      const json = await request(`${api}/creation-check`, { method: 'POST', cache: 'no-store', body: '{}' })
      if (!current()) return
      const received = json?.data
      if (!received?.creation || !received?.reconciliation) throw new Error('استجابة التحقق غير صالحة.')
      next = received // Only a fresh validated response may supply another server delay.
      if (next.pending_operation && next.pending_operation.operation_id !== operation) { stop(); onExhausted(); return }
      await onState(next)
      if (!current()) return
      if (!pendingCreationReconciliation(next)) { stop(); onChecking(false); return }
    } catch (failure) {
      if (!current()) return
      if (onError(failure) || [401, 403].includes(failure.status)) { stop(); return }
      next = null // Expired initial/server deadlines cannot extend a transport-error backoff.
    }
    if (attempts >= backoffs.length + 1) { stop(); onChecking(false); onExhausted(); return }
    queue(next, backoffs[attempts - 1])
  }
  if (pendingCreationReconciliation(initialState) && current()) queue(initialState, 0)
  return stop
}
