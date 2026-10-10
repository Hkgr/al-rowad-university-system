import { createCalculator } from './payrollFormula'
import { compare, minus, parseDec, plus, toFixed, toPlain, ZERO } from './payrollDecimal'
import { rowStatus } from './payrollView'

const sameValue = (column, a, b) => a == null || b == null ? a == null && b == null : column.value_type === 'text' ? a === b : compare(parseDec(a), parseDec(b)) === 0
export const initialMonthlyDrafts = { drafts: {}, undo: [], redo: [] }

/** In-memory editing history only. Network writes stay at the existing confirmed monthly boundary. */
export function monthlyDraftReducer(state, action) {
  if (action.type === 'undo' || action.type === 'redo') {
    const from = action.type === 'undo' ? 'undo' : 'redo'; const to = from === 'undo' ? 'redo' : 'undo'
    if (!state[from].length) return state
    return { drafts: state[from].at(-1), [from]: state[from].slice(0, -1), [to]: [...state[to], state.drafts] }
  }
  if (action.type === 'reset') return initialMonthlyDrafts
  let next
  if (action.type === 'set') next = action.update(state.drafts)
  else if (action.type === 'edit') {
    next = { ...state.drafts }
    for (const change of action.changes) {
      const shown = action.rows.find(r => r.id === change.id)
      if (!shown) continue
      const source = next[shown.employee_id]?.row || shown
      const config = next[shown.employee_id]?.config || action.config
      const column = config.columns.find(c => c.key === change.key)
      if (!column || column.kind !== 'input') continue
      const values = { ...(next[shown.employee_id]?.values || source.inputs) }
      const original = source.inputs?.[change.key] ?? null
      const value = sameValue(column, original, change.value) ? original : change.value
      if (value === null) delete values[change.key]; else values[change.key] = value
      const changed = config.columns.filter(c => c.kind === 'input').some(c => !sameValue(c, source.inputs?.[c.key] ?? null, values[c.key] ?? null))
      if (!changed) delete next[source.employee_id]
      else next[source.employee_id] = { row: source, values, config, period_revision: next[source.employee_id]?.period_revision ?? action.revision }
    }
  } else return state
  if (JSON.stringify(next) === JSON.stringify(state.drafts)) return state
  return { drafts: next, undo: [...state.undo.slice(-99), state.drafts], redo: [] }
}

export function monthlyGridRows(rows, drafts, config, stale) {
  const calculator = config ? createCalculator(config) : null
  return rows.map(row => {
    const draft = drafts[row.employee_id]
    const cells = draft ? (stale ? createCalculator(draft.config || config) : calculator).evaluateRow(draft.values) : row.cells
    const states = draft ? Object.fromEntries(Object.keys(draft.values).concat(Object.keys(draft.row.inputs || {})).filter(k => (draft.values[k] ?? null) !== (draft.row.inputs?.[k] ?? null)).map(k => [k, stale ? 'conflict' : 'queued'])) : {}
    const net = cells?.total_net_payable?.v
    const remaining = draft && row.disbursed != null ? net == null ? null : toFixed(minus(parseDec(net), parseDec(row.disbursed)), 2) : row.remaining
    return { ...row, cells, states, remaining, workplace_label: row.college_name ?? row.unit_name ?? 'غير محدد', academic_level: row.academic_level ?? '' }
  })
}

const included = (row, query) => (!query.employee_id || String(row.employee_id) === String(query.employee_id)) && (!query.body || (query.body === 'unknown' ? row.body == null : row.body === query.body)) && (!query.college_id || String(row.college_id) === String(query.college_id)) && (!query.unit_id || String(row.organizational_unit_id) === String(query.unit_id)) && (!query.q || `${row.full_name} ${row.employee_number}`.toLowerCase().includes(query.q.trim().toLowerCase())) && (!query.payment_status || row.payment_status === query.payment_status) && (!query.completeness || (query.completeness === 'complete' ? rowStatus(row) !== 'incomplete' : rowStatus(row) === query.completeness))

/** Saved complete-selection totals plus exact draft deltas; not a persisted approval or export source. */
export function monthlyDraftTotals(report, drafts, query, stale) {
  if (!report?.totals || stale) return report?.totals
  const totals = structuredClone(report.totals); const calculator = createCalculator(report.config)
  for (const draft of Object.values(drafts)) {
    if (!included(draft.row, query)) continue
    const before = draft.row; const after = { ...before, cells: calculator.evaluateRow(draft.values) }
    for (const c of report.config.columns.filter(c => c.aggregation === 'sum')) {
      const total = totals.columns[c.key]; if (!total) continue
      let sum = total.sum == null ? ZERO : parseDec(total.sum)
      const a = before.cells[c.key]?.v; const b = after.cells[c.key]?.v
      if (a != null) { sum = minus(sum, parseDec(a)); total.excluded++ }
      if (b != null) { sum = plus(sum, parseDec(b)); total.excluded-- }
      total.sum = total.excluded === totals.employees ? null : c.value_type === 'amount' ? toFixed(sum, 2) : toPlain(sum)
    }
    for (const [key, check] of [['complete', r => rowStatus(r) !== 'incomplete'], ['incomplete', r => rowStatus(r) === 'incomplete'], ['warnings', r => rowStatus(r) === 'warning']]) totals[key] += Number(check(after)) - Number(check(before))
    const oldRemaining = before.remaining
    const net = after.cells.total_net_payable?.v
    const newRemaining = net == null || before.disbursed == null ? null : toFixed(minus(parseDec(net), parseDec(before.disbursed)), 2)
    let remaining = totals.remaining.sum == null ? ZERO : parseDec(totals.remaining.sum)
    if (oldRemaining != null) { remaining = minus(remaining, parseDec(oldRemaining)); totals.remaining.excluded++ }
    if (newRemaining != null) { remaining = plus(remaining, parseDec(newRemaining)); totals.remaining.excluded-- }
    totals.remaining.sum = totals.remaining.excluded === totals.employees ? null : toFixed(remaining, 2)
  }
  return totals
}

export function monthlyExportColumns(config, detailed) {
  const identity = ['employee_number', 'full_name']
  const extra = config.columns.filter(c => c.visible_grid && c.visible_export && (detailed || (!c.is_system && c.compact) || c.key === 'total_net_payable')).map(c => c.key)
  return detailed ? [...identity, 'job_title', 'body_name', 'workplace_label', 'academic_level', ...extra] : [...identity, 'employee_status', 'placement', ...(extra.includes('total_net_payable') ? ['total_net_payable'] : []), 'disbursed', 'received', 'remaining', 'completeness', 'payment_status', ...extra.filter(k => k !== 'total_net_payable')]
}
