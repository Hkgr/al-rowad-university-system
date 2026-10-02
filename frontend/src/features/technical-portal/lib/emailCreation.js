import { pendingCreationReconciliation } from './emailReconciliation.js'

/** Presentation follows the server's persisted safety decision, never a guessed remote outcome. */
export function creationMode(state, reviewRequired = false) {
  if (reviewRequired || !state) return 'verify'
  if (state.reconciliation?.status === 'waiting' && state.pending_operation?.kind === 'create') return 'waiting'
  if (state.provisioning_status === 'deleted') return 'deleted'
  if (state.provisioning_status === 'created') return 'existing'
  const status = state.creation?.status
  if (status === 'ready') return 'ready'
  if (status === 'retry' && state.creation.operation_id && Number.isInteger(state.creation.generation)) return 'retry'
  return 'verify'
}

export const unresolvedCreation = 'تعذر التأكد من نتيجة المحاولة السابقة. لم يتم إرسال طلب إنشاء جديد حفاظًا على الحساب.'

/** Only the canonical read-only check runs automatically, never create/cancel/execute. */
export async function loadCreationState(api, request, isCurrent, canCheck) {
  const json = await request(`${api}/provisioning`, { cache: 'no-store' })
  if (!isCurrent()) return null
  // The bounded controller owns the immediate uncertain read as well as all later polls.
  // Do not perform a second immediate check here or skip a live worker's grace.
  if (!canCheck || pendingCreationReconciliation(json.data) || creationMode(json.data) !== 'verify') return json.data
  const checked = await request(`${api}/creation-check`, { method: 'POST', cache: 'no-store', body: '{}' })
  return isCurrent() ? checked.data : null
}

/** Called once by an explicit user action; the server cancels/reprepares atomically. */
export function sendCreation(api, state, name, request) {
  const mode = creationMode(state)
  if (!['ready', 'retry', 'deleted'].includes(mode) || (mode === 'deleted' && state.pending_operation)) throw new Error('يلزم التحقق من حالة البريد أولًا.')
  return request(`${api}/${mode === 'retry' ? 'retry-create' : mode === 'deleted' ? 'recreate' : 'create'}`, {
    method: 'POST', cache: 'no-store', body: JSON.stringify({ english_first_name: name, confirmed: true,
      ...(mode === 'retry' ? { operation_id: state.creation.operation_id, generation: state.creation.generation } : {}),
      ...(mode === 'deleted' ? { revision: state.revision } : {}) }),
  })
}
