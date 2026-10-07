// Framework-free state machine of the payroll working sheet: pending edits, an ordered save queue,
// undo/redo, optimistic-concurrency conflicts and failed saves. React reads it through subscribe/getSnapshot.
//
// Rules it enforces (see docs/owner-payroll.md):
//  - every user operation (typed edit, multi-cell paste, clear, undo, redo) is ONE atomic request;
//  - edited values show immediately (and totals/payable recalculate) while the request is pending;
//  - a failed or conflicting operation halts the queue and KEEPS the user's pending values on screen;
//  - nothing is shown as saved until the server confirmed it;
//  - saves are keyed by the stable employee id and the last-known entry revision, never by visible row index.
import { centsFromString, centsToString, payableCents, sumTotals } from './payrollMoney.js'

export const AMOUNT_FIELDS = ['fixed_salary', 'deduction', 'compensation']

const metaOf = row => ({
  id: row.id, employee_number: row.employee_number, full_name: row.full_name, job_title: row.job_title, body_id: row.body_id, body_name: row.body_name,
  body_is_active: row.body_is_active, workplace: row.workplace, workplace_other: row.workplace_other, workplace_label: row.workplace_label,
  academic_level: row.academic_level, employee_revision: row.employee_revision,
})
const valuesOf = row => Object.fromEntries(AMOUNT_FIELDS.map(field => [field, centsFromString(row[field])]))

export class PayrollSheetController {
  #saveAmounts
  #rows = new Map() // id -> { meta, values, revision }
  #order = []
  #pending = new Map() // id -> Map(field -> { cents, opId })
  #ops = [] // unresolved operations in order: queued | saving | failed | conflict
  #undo = []
  #redo = []
  #listeners = new Set()
  #waiters = []
  #version = 0
  #cache = null
  #draining = false
  #nextOp = 1
  #savedAt = null
  #lastMessage = ''

  constructor({ saveAmounts }) { this.#saveAmounts = saveAmounts }

  // ── subscription ────────────────────────────────────────────────────────
  subscribe = listener => { this.#listeners.add(listener); return () => this.#listeners.delete(listener) }

  #changed() {
    this.#version += 1
    this.#cache = null
    this.#listeners.forEach(listener => listener())
    if (!this.#busy()) { const waiters = this.#waiters.splice(0); waiters.forEach(resolve => resolve({ ok: !this.#halted() })) }
  }

  #halted() { return this.#ops.find(op => op.state === 'failed' || op.state === 'conflict') ?? null }
  #busy() { return !this.#halted() && this.#ops.some(op => op.state === 'queued' || op.state === 'saving') }

  getSnapshot = () => {
    if (this.#cache) return this.#cache
    const rows = this.#order.map(id => this.#display(id)).filter(Boolean)
    const halted = this.#halted()
    const phase = halted ? halted.state : this.#busy() ? 'saving' : 'idle'
    this.#cache = {
      version: this.#version, rows, totals: sumTotals(rows),
      status: {
        phase, pendingCount: this.#ops.length, savedAt: this.#savedAt, message: this.#lastMessage,
        conflict: halted?.state === 'conflict' ? { opId: halted.id, rows: halted.conflictRows } : null,
        failure: halted?.state === 'failed' ? { opId: halted.id, message: halted.error, retryable: halted.retryable } : null,
      },
      canUndo: this.#undo.length > 0 && !halted, canRedo: this.#redo.length > 0 && !halted,
    }
    return this.#cache
  }

  #display(id) {
    const entry = this.#rows.get(id)
    if (!entry) return null
    const pending = this.#pending.get(id)
    const values = {}
    const states = {}
    for (const field of AMOUNT_FIELDS) {
      const p = pending?.get(field)
      values[field] = p ? p.cents : entry.values[field]
      if (p) states[field] = this.#ops.find(op => op.id === p.opId)?.state ?? 'queued'
    }
    return { ...entry.meta, ...values, payable: payableCents(values.fixed_salary, values.deduction, values.compensation), revision: entry.revision, states }
  }

  /** Current displayed value (pending overlay included) of one cell, in cents. */
  valueOf(id, field) {
    const p = this.#pending.get(id)?.get(field)
    return p ? p.cents : (this.#rows.get(id)?.values[field] ?? null)
  }

  // ── loading ─────────────────────────────────────────────────────────────
  load(apiRows) {
    this.#order = apiRows.map(row => row.id)
    for (const row of apiRows) this.#rows.set(row.id, { meta: metaOf(row), values: valuesOf(row), revision: row.entry_revision })
    this.#changed()
  }

  /** Replace one row from an API response (metadata edit). Unsaved amounts are untouched. */
  upsertRow(apiRow) {
    const entry = this.#rows.get(apiRow.id)
    this.#rows.set(apiRow.id, { meta: metaOf(apiRow), values: entry?.values ?? valuesOf(apiRow), revision: entry?.revision ?? apiRow.entry_revision })
    if (!this.#order.includes(apiRow.id)) this.#order.push(apiRow.id)
    this.#changed()
  }

  get busy() { return this.#busy() || this.#ops.some(op => op.state === 'saving') }
  get hasUnresolved() { return this.#halted() !== null }
  get idle() { return this.#ops.length === 0 }

  // ── editing ─────────────────────────────────────────────────────────────
  /** Apply one atomic operation: changes = [{ id, field, cents }] (cents already validated). */
  edit(changes, { history = false } = {}) {
    const real = []
    const seen = new Set()
    for (const { id, field, cents } of changes) {
      if (!this.#rows.has(id) || !AMOUNT_FIELDS.includes(field)) continue
      const key = `${id}:${field}`
      if (seen.has(key)) continue
      seen.add(key)
      const before = this.valueOf(id, field)
      if (before !== cents) real.push({ id, field, before, after: cents })
    }
    if (!real.length) return null
    const op = { id: this.#nextOp++, changes: real, state: 'queued', history }
    if (!history) { this.#undo.push(op.changes); this.#redo = [] }
    this.#apply(op)
    this.#drain()
    return op
  }

  #apply(op) {
    this.#ops.push(op)
    for (const { id, field, after } of op.changes) {
      if (!this.#pending.has(id)) this.#pending.set(id, new Map())
      this.#pending.get(id).set(field, { cents: after, opId: op.id })
    }
    this.#lastMessage = ''
    this.#changed()
  }

  undo() {
    if (!this.canActOnHistory(this.#undo)) return null
    const record = this.#undo.pop()
    this.#redo.push(record)
    return this.edit(record.map(({ id, field, before }) => ({ id, field, cents: before })), { history: true })
  }

  redo() {
    if (!this.canActOnHistory(this.#redo)) return null
    const record = this.#redo.pop()
    this.#undo.push(record)
    return this.edit(record.map(({ id, field, after }) => ({ id, field, cents: after })), { history: true })
  }

  canActOnHistory(stack) { return stack.length > 0 && !this.#halted() }

  // ── queue ───────────────────────────────────────────────────────────────
  async #drain() {
    if (this.#draining) return
    this.#draining = true
    try {
      for (;;) {
        if (this.#halted()) break
        const op = this.#ops.find(item => item.state === 'queued')
        if (!op) break
        op.state = 'saving'
        this.#changed()
        try {
          const rows = await this.#saveAmounts(this.#payload(op))
          this.#confirm(op, rows)
        } catch (error) {
          this.#fail(op, error)
          break
        }
      }
    } finally {
      this.#draining = false
      this.#changed()
    }
  }

  #payload(op) {
    const byId = new Map()
    for (const { id, field, after } of op.changes) {
      if (!byId.has(id)) byId.set(id, { employee_id: id, expected_revision: this.#rows.get(id).revision })
      byId.get(id)[field] = centsToString(after)
    }
    return [...byId.values()]
  }

  #confirm(op, apiRows) {
    for (const row of apiRows) {
      const entry = this.#rows.get(row.id)
      if (entry) { entry.values = valuesOf(row); entry.revision = row.entry_revision }
    }
    for (const { id, field } of op.changes) {
      const p = this.#pending.get(id)?.get(field)
      if (p && p.opId === op.id) this.#pending.get(id).delete(field)
    }
    this.#ops = this.#ops.filter(item => item !== op)
    this.#savedAt = Date.now()
    this.#changed()
  }

  #fail(op, error) {
    const conflicts = error?.status === 409 && error.errorCode === 'payroll_conflict' ? (error.details?.conflicts ?? []) : null
    if (conflicts) {
      // A retried request whose first attempt actually succeeded: the server already holds exactly our values.
      const identical = conflicts.length > 0 && conflicts.every(({ current }) => current && op.changes.filter(c => c.id === current.id).every(c => centsFromString(current[c.field]) === c.after))
      if (identical && conflicts.length === new Set(op.changes.map(c => c.id)).size) {
        this.#confirm(op, conflicts.map(c => c.current))
        return
      }
      op.state = 'conflict'
      op.conflictRows = conflicts.map(({ current }) => current).filter(Boolean)
      op.error = error.message
    } else {
      op.state = 'failed'
      op.error = error?.status ? (error.message || 'تعذّر حفظ التعديل.') : 'تعذّر الاتصال بالخادم. تحقق من الشبكة ثم أعد المحاولة.'
      op.retryable = !(error?.status >= 400 && error?.status < 500)
    }
    this.#changed()
  }

  /** Retry the failed operation (network / server errors). */
  retry() {
    const op = this.#halted()
    if (op?.state !== 'failed') return
    op.state = 'queued'
    this.#changed()
    this.#drain()
  }

  /** Conflict: deliberately re-apply MY values on top of the latest server revision. */
  keepMine() {
    const op = this.#halted()
    if (op?.state !== 'conflict') return
    for (const row of op.conflictRows ?? []) {
      const entry = this.#rows.get(row.id)
      if (entry) { entry.values = valuesOf(row); entry.revision = row.entry_revision }
    }
    // Server values changed under us: a stale undo history could silently resurrect old values, so drop it.
    this.#undo = []
    this.#redo = []
    op.state = 'queued'
    op.conflictRows = []
    this.#changed()
    this.#drain()
  }

  /** Conflict or failure: drop my unsaved values and show what the server holds. */
  useServer() {
    const op = this.#halted()
    if (!op) return
    for (const row of op.conflictRows ?? []) {
      const entry = this.#rows.get(row.id)
      if (entry) { entry.values = valuesOf(row); entry.revision = row.entry_revision }
    }
    for (const { id, field } of op.changes) {
      const p = this.#pending.get(id)?.get(field)
      if (p && p.opId === op.id) this.#pending.get(id).delete(field)
    }
    this.#ops = this.#ops.filter(item => item !== op)
    this.#undo = []
    this.#redo = []
    this.#changed()
    this.#drain()
  }

  /** Resolves when every queued operation has been confirmed (ok) or the queue halted on an error (not ok). */
  flush() {
    if (!this.#busy()) return Promise.resolve({ ok: !this.#halted() })
    return new Promise(resolve => this.#waiters.push(resolve))
  }

  /** Unsaved values grouped for the page-leave warning. */
  get unsavedCount() { return this.#ops.length }
}
