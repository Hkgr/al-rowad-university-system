// Framework-free state machine of the payroll working sheet: pending edits, an ordered save queue, undo/redo,
// optimistic-concurrency conflicts (row and configuration) and failed saves. React reads it through subscribe/getSnapshot.
//
// Rules it enforces (see docs/owner-payroll.md):
//  - every user operation (typed edit, multi-cell paste, clear, undo, redo) is ONE atomic request;
//  - edited values show immediately (calculated columns and totals recalculate locally) while the request is pending;
//  - a failed or conflicting operation halts the queue and KEEPS the user's pending values on screen;
//  - nothing is shown as saved until the server confirmed it;
//  - "use the server's values" first RELOADS the authoritative rows; the pending values are discarded only once that succeeded
//    (an uncertain failure may in fact have been saved, so the screen must never fall back to stale local values);
//  - saves are keyed by the stable employee id and the last-known entry revision, never by visible row index, and carry the
//    configuration revision they were calculated under.
import { createCalculator, sumColumn } from './payrollFormula.js'
import { rowStatus } from './payrollView.js'

const TOTAL_KEY = 'total_net_payable'

const metaOf = row => ({
  id: row.id, employee_number: row.employee_number, full_name: row.full_name, job_title: row.job_title, body_id: row.body_id, body_name: row.body_name,
  body_is_active: row.body_is_active, workplace: row.workplace, workplace_other: row.workplace_other, workplace_label: row.workplace_label,
  academic_level: row.academic_level, employee_revision: row.employee_revision,
})

export class PayrollSheetController {
  #saveValues
  #reload
  #config = null
  #calculator = null
  #rows = new Map() // id -> { meta, cells (server), inputs (server), revision }
  #order = []
  #pending = new Map() // id -> Map(key -> { value, opId })
  #ops = [] // unresolved operations in order: queued | saving | failed | conflict | config_conflict
  #undo = []
  #redo = []
  #listeners = new Set()
  #waiters = []
  #version = 0
  #cache = null
  #draining = false
  #nextOp = 1
  #savedAt = null
  #resolving = false
  #resolveError = ''

  /**
   * saveValues(changes, configRevision) → Promise<rows>  one atomic request.
   * reload() → Promise<{ rows, config }>  the authoritative sheet for the dataset currently on screen.
   */
  constructor({ saveValues, reload }) { this.#saveValues = saveValues; this.#reload = reload }

  // ── subscription ────────────────────────────────────────────────────────
  subscribe = listener => { this.#listeners.add(listener); return () => this.#listeners.delete(listener) }

  #changed() {
    this.#version += 1
    this.#cache = null
    this.#listeners.forEach(listener => listener())
    if (!this.#busy()) { const waiters = this.#waiters.splice(0); waiters.forEach(resolve => resolve({ ok: !this.#halted() })) }
  }

  #halted() { return this.#ops.find(op => ['failed', 'conflict', 'config_conflict'].includes(op.state)) ?? null }
  #busy() { return !this.#halted() && this.#ops.some(op => op.state === 'queued' || op.state === 'saving') }

  get config() { return this.#config }
  get configRevision() { return this.#config?.revision ?? 0 }

  getSnapshot = () => {
    if (this.#cache) return this.#cache
    const rows = this.#order.map(id => this.#display(id)).filter(Boolean)
    const halted = this.#halted()
    const phase = halted ? halted.state : this.#busy() ? 'saving' : 'idle'
    this.#cache = {
      version: this.#version, rows, config: this.#config, totals: this.#totals(rows),
      status: {
        phase, pendingCount: this.#ops.length, savedAt: this.#savedAt, resolving: this.#resolving, resolveError: this.#resolveError,
        conflict: halted?.state === 'conflict' ? { opId: halted.id, rows: halted.conflictRows } : null,
        configConflict: halted?.state === 'config_conflict' ? { opId: halted.id, message: halted.error } : null,
        failure: halted?.state === 'failed' ? { opId: halted.id, message: halted.error, retryable: halted.retryable } : null,
      },
      canUndo: this.#undo.length > 0 && !halted, canRedo: this.#redo.length > 0 && !halted,
    }
    return this.#cache
  }

  #totals(rows) {
    const columns = {}
    for (const column of this.#config?.columns ?? []) {
      if (column.aggregation === 'sum') columns[column.key] = sumColumn(rows, column.key, column.value_type)
    }
    const statuses = rows.map(row => rowStatus(row, TOTAL_KEY))
    return {
      employees: rows.length, complete: statuses.filter(s => s !== 'incomplete').length, incomplete: statuses.filter(s => s === 'incomplete').length,
      warnings: statuses.filter(s => s === 'warning').length, columns,
    }
  }

  #inputsOf(entry, id) {
    const inputs = { ...entry.inputs }
    const pending = this.#pending.get(id)
    if (pending) for (const [key, p] of pending) inputs[key] = p.value
    return inputs
  }

  #display(id) {
    const entry = this.#rows.get(id)
    if (!entry) return null
    const pending = this.#pending.get(id)
    const states = {}
    let cells = entry.cells
    if (pending?.size) {
      cells = this.#calculator.evaluateRow(this.#inputsOf(entry, id))
      for (const [key, p] of pending) states[key] = this.#ops.find(op => op.id === p.opId)?.state ?? 'queued'
    }
    return { ...entry.meta, cells, revision: entry.revision, states }
  }

  /** Current displayed wire value of one input cell (pending overlay included). */
  valueOf(id, key) {
    const p = this.#pending.get(id)?.get(key)
    if (p) return p.value
    return this.#rows.get(id)?.inputs[key] ?? null
  }

  // ── loading ─────────────────────────────────────────────────────────────
  #entryOf(row) {
    const inputs = {}
    for (const column of this.#config.columns) if (column.kind === 'input') inputs[column.key] = row.cells[column.key]?.v ?? null
    return { meta: metaOf(row), cells: row.cells, inputs, revision: row.entry_revision }
  }

  /**
   * Replace the dataset with an authoritative server response. Pending (unsaved) values stay; for rows that carry pending values the
   * revision they were EDITED FROM is kept, so a change made by someone else meanwhile is still detected as a conflict at save time.
   */
  load(apiRows, config = this.#config) {
    this.#applyConfig(config)
    this.#order = apiRows.map(row => row.id)
    const next = new Map()
    for (const row of apiRows) {
      const entry = this.#entryOf(row)
      const previous = this.#rows.get(row.id)
      if (previous && this.#pending.get(row.id)?.size) entry.revision = previous.revision
      next.set(row.id, entry)
    }
    this.#rows = next
    for (const id of [...this.#pending.keys()]) if (!next.has(id)) this.#pending.delete(id)
    this.#changed()
  }

  #applyConfig(config) {
    if (!config) return
    this.#config = config
    this.#calculator = createCalculator(config)
  }

  /** Replace one row from an API response (metadata edit). Unsaved values are untouched. */
  upsertRow(apiRow) {
    const entry = this.#entryOf(apiRow)
    const previous = this.#rows.get(apiRow.id)
    this.#rows.set(apiRow.id, previous ? { ...previous, meta: entry.meta } : entry)
    if (!this.#order.includes(apiRow.id)) this.#order.push(apiRow.id)
    this.#changed()
  }

  get busy() { return this.#busy() || this.#ops.some(op => op.state === 'saving') }
  get hasUnresolved() { return this.#halted() !== null }
  get idle() { return this.#ops.length === 0 }
  get unsavedCount() { return this.#ops.length }

  // ── editing ─────────────────────────────────────────────────────────────
  /** Apply one atomic operation: changes = [{ id, key, value }] (value already validated: wire string or null = blank). */
  edit(changes, { history = false } = {}) {
    const real = []
    const seen = new Set()
    for (const { id, key, value } of changes) {
      const column = this.#config?.columns.find(c => c.key === key)
      if (!this.#rows.has(id) || column?.kind !== 'input') continue
      const mark = `${id}:${key}`
      if (seen.has(mark)) continue
      seen.add(mark)
      const before = this.valueOf(id, key)
      if (before !== value) real.push({ id, key, before, after: value })
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
    for (const { id, key, after } of op.changes) {
      if (!this.#pending.has(id)) this.#pending.set(id, new Map())
      this.#pending.get(id).set(key, { value: after, opId: op.id })
    }
    this.#changed()
  }

  undo() {
    if (!this.#canActOnHistory(this.#undo)) return null
    const record = this.#undo.pop()
    this.#redo.push(record)
    return this.edit(record.map(({ id, key, before }) => ({ id, key, value: before })), { history: true })
  }

  redo() {
    if (!this.#canActOnHistory(this.#redo)) return null
    const record = this.#redo.pop()
    this.#undo.push(record)
    return this.edit(record.map(({ id, key, after }) => ({ id, key, value: after })), { history: true })
  }

  #canActOnHistory(stack) { return stack.length > 0 && !this.#halted() }

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
          const rows = await this.#saveValues(this.#payload(op), this.configRevision)
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
    for (const { id, key, after } of op.changes) {
      if (!byId.has(id)) byId.set(id, { employee_id: id, expected_revision: this.#rows.get(id).revision, values: {} })
      byId.get(id).values[key] = after
    }
    return [...byId.values()]
  }

  #confirm(op, apiRows) {
    for (const row of apiRows) {
      if (this.#rows.has(row.id)) this.#rows.set(row.id, { ...this.#entryOf(row), meta: this.#rows.get(row.id).meta })
    }
    this.#dropPending(op)
    this.#ops = this.#ops.filter(item => item !== op)
    this.#savedAt = Date.now()
    this.#changed()
  }

  #dropPending(op) {
    for (const { id, key } of op.changes) {
      const p = this.#pending.get(id)?.get(key)
      if (p && p.opId === op.id) this.#pending.get(id).delete(key)
    }
  }

  #fail(op, error) {
    if (error?.status === 409 && error.errorCode === 'payroll_config_conflict') {
      op.state = 'config_conflict'
      op.error = error.message
      op.newConfig = error.details?.config ?? null
      this.#changed()
      return
    }
    const conflicts = error?.status === 409 && error.errorCode === 'payroll_conflict' ? (error.details?.conflicts ?? []) : null
    if (conflicts) {
      // A retried request whose first attempt actually succeeded: the server already holds exactly our values.
      const same = conflicts.length > 0 && conflicts.every(({ current }) => current && op.changes.filter(c => c.id === current.id).every(c => (current.cells[c.key]?.v ?? null) === c.after))
      if (same && conflicts.length === new Set(op.changes.map(c => c.id)).size) {
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
    this.#resolveError = ''
    this.#changed()
    this.#drain()
  }

  /** Conflict: deliberately re-apply MY values on top of the latest server revision. */
  keepMine() {
    const op = this.#halted()
    if (op?.state !== 'conflict') return
    for (const row of op.conflictRows ?? []) {
      if (this.#rows.has(row.id)) this.#rows.set(row.id, { ...this.#entryOf(row), meta: this.#rows.get(row.id).meta })
    }
    // Server values changed under us: a stale undo history could silently resurrect old values, so drop it.
    this.#undo = []
    this.#redo = []
    op.state = 'queued'
    op.conflictRows = []
    this.#changed()
    this.#drain()
  }

  /**
   * Configuration changed on the server while I was editing: adopt the new configuration, reload the rows under it and re-apply my
   * pending values that still make sense (a column that was deleted or turned into a formula can no longer take a value).
   * Returns { dropped } — the labels of values that could not be re-applied.
   */
  async adoptConfigAndRetry() {
    const op = this.#halted()
    if (op?.state !== 'config_conflict' || this.#resolving) return { dropped: [] }
    this.#resolving = true
    this.#resolveError = ''
    this.#changed()
    try {
      const fresh = await this.#reload()
      this.#applyConfig(fresh.config)
      const usable = new Set(fresh.config.columns.filter(c => c.kind === 'input').map(c => c.key))
      const dropped = []
      for (const o of this.#ops) {
        o.changes = o.changes.filter(c => {
          if (usable.has(c.key)) return true
          dropped.push(c.key)
          const p = this.#pending.get(c.id)?.get(c.key)
          if (p && p.opId === o.id) this.#pending.get(c.id).delete(c.key)
          return false
        })
      }
      this.#ops = this.#ops.filter(o => o.changes.length > 0)
      this.load(fresh.rows, fresh.config)
      this.#undo = []
      this.#redo = []
      const again = this.#ops.find(o => o.state === 'config_conflict')
      if (again) again.state = 'queued'
      this.#resolving = false
      this.#changed()
      this.#drain()
      return { dropped }
    } catch (error) {
      this.#resolving = false
      this.#resolveError = `تعذّر تحميل الإعدادات الحالية من الخادم: ${error?.message || 'تحقق من الاتصال'}. لم يتغيّر شيء.`
      this.#changed()
      return { dropped: [], failed: true }
    }
  }

  /**
   * Conflict, config conflict or failure: drop my unsaved values and show what the server holds. The authoritative rows are fetched
   * FIRST. An uncertain failure (timeout, dropped connection) may have been saved after all, so falling back to the values this browser
   * last saw would show stale data as if it were current. If the reload fails nothing is discarded and the problem stays visible.
   */
  async useServer() {
    const op = this.#halted()
    if (!op || this.#resolving) return { ok: false }
    this.#resolving = true
    this.#resolveError = ''
    this.#changed()
    let fresh
    try {
      fresh = await this.#reload()
    } catch (error) {
      this.#resolving = false
      this.#resolveError = `تعذّر تحميل القيم الحالية من الخادم: ${error?.message || 'تحقق من الاتصال'}. لم تُتجاهل تعديلاتك؛ أعد المحاولة.`
      this.#changed()
      return { ok: false }
    }
    this.#dropPending(op)
    this.#ops = this.#ops.filter(item => item !== op)
    this.#undo = []
    this.#redo = []
    this.#resolving = false
    this.load(fresh.rows, fresh.config)
    this.#drain()
    return { ok: true }
  }

  /** Resolves when every queued operation has been confirmed (ok) or the queue halted on an error (not ok). */
  flush() {
    if (!this.#busy()) return Promise.resolve({ ok: !this.#halted() })
    return new Promise(resolve => this.#waiters.push(resolve))
  }
}
