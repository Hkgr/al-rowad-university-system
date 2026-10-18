// Client-side evaluation of the payroll sheet's calculated columns. It evaluates the AST the server sends with every formula column
// (the server parses and validates the text), with the same semantics as App\Services\Payroll\Formula\FormulaEvaluator and
// App\Services\Payroll\PayrollCalculator — proven equal by the shared vectors in tests/Fixtures/payroll_formula_vectors.json.
//
// Used to show pending (unsaved) edits and recalculated totals immediately; the server stays authoritative and its rows replace
// these figures as soon as a save is confirmed.
import {
  ZERO, abs, compare, divide, exceedsLimit, isNegative, isZero, minus, movePointLeft, negate, parseDec, plus, round, times, toFixed, toPlain,
} from './payrollDecimal.js'

const ok = value => ({ state: 'ok', value })
const missing = message => ({ state: 'missing', message })
const failed = (reason, message) => ({ state: 'error', reason, message })

/** Static guard: every reference is inspected whichever IF branch would run. Errors first, then merged missing messages. */
function evaluateAst(ast, resolve) {
  const refs = []
  const collect = node => {
    if (!node || typeof node !== 'object') return
    if (node.t === 'ref') refs.push(node.k)
    for (const child of [node.a, node.l, node.r]) collect(child)
    for (const arg of node.args ?? []) collect(arg)
  }
  collect(ast)
  const cache = new Map()
  const get = key => { if (!cache.has(key)) cache.set(key, resolve(key)); return cache.get(key) }
  const resolved = [...new Set(refs)].map(get)
  const error = resolved.find(v => v.state === 'error')
  if (error) return error
  const absent = resolved.filter(v => v.state === 'missing')
  if (absent.length) return missing([...new Set(absent.map(v => v.message))].join('، '))
  const result = run(ast, get)
  if (result.state === 'ok' && typeof result.value === 'object' && !('bool' in result) && exceedsLimit(result.value)) return failed('overflow', 'القيمة الناتجة كبيرة جدًا.')
  return result
}

const isNum = v => v.state === 'ok' && v.value && typeof v.value === 'object' && 'n' in v.value

function run(node, get) {
  switch (node.t) {
    case 'num': return ok(parseDec(node.v))
    case 'str': return ok(node.v)
    case 'ref': return get(node.k)
    case 'neg': { const a = run(node.a, get); return a.state === 'ok' ? ok(negate(a.value)) : a }
    case 'pct': { const a = run(node.a, get); return a.state === 'ok' ? ok(movePointLeft(a.value, 2)) : a }
    case 'bin': {
      const l = run(node.l, get)
      if (l.state !== 'ok') return l
      const r = run(node.r, get)
      if (r.state !== 'ok') return r
      switch (node.op) {
        case '+': return ok(plus(l.value, r.value))
        case '-': return ok(minus(l.value, r.value))
        case '*': return ok(times(l.value, r.value))
        default: return isZero(r.value) ? failed('div0', 'قسمة على صفر') : ok(divide(l.value, r.value))
      }
    }
    case 'cmp': {
      const l = run(node.l, get)
      if (l.state !== 'ok') return l
      const r = run(node.r, get)
      if (r.state !== 'ok') return r
      const c = typeof l.value === 'object' ? compare(l.value, r.value) : (l.value === r.value ? 0 : (l.value < r.value ? -1 : 1))
      const result = { '=': c === 0, '<>': c !== 0, '<': c < 0, '<=': c <= 0, '>': c > 0, '>=': c >= 0 }[node.op]
      return { state: 'ok', value: result, bool: true }
    }
    case 'call': return call(node, get)
    default: return failed('syntax', 'عنصر غير معروف.')
  }
}

function call(node, get) {
  if (node.f === 'IF') {
    const cond = run(node.args[0], get)
    if (cond.state !== 'ok') return cond
    return run(node.args[cond.value ? 1 : 2], get)
  }
  const values = []
  for (const arg of node.args) {
    const v = run(arg, get)
    if (v.state !== 'ok') return v
    values.push(v.value)
  }
  switch (node.f) {
    case 'SUM': return ok(values.reduce((acc, v) => plus(acc, v), ZERO))
    case 'MAX': return ok(values.reduce((acc, v) => (acc === null || compare(v, acc) > 0 ? v : acc), null))
    case 'MIN': return ok(values.reduce((acc, v) => (acc === null || compare(v, acc) < 0 ? v : acc), null))
    case 'ROUND': return ok(round(values[0], Number(node.args[1].v)))
    default: return failed('syntax', 'دالة غير معروفة.')
  }
}

const AMOUNT_SCALE = 2
const NUMBER_SCALE = 6

/** Wire text of a numeric result: amounts two decimals; numbers/percentages without trailing zeros (6 max). */
export const formatWire = (value, type) => (type === 'amount' ? toFixed(value, AMOUNT_SCALE) : toPlain(round(value, NUMBER_SCALE)))

/** Topological order of the formula columns (the server already rejected cycles). */
function order(columns) {
  const formulas = new Map(columns.filter(c => c.kind === 'formula').map(c => [c.key, c]))
  const result = []
  const seen = new Set()
  const visit = key => {
    if (seen.has(key)) return
    seen.add(key)
    for (const ref of formulas.get(key).references ?? []) if (formulas.has(ref)) visit(ref)
    result.push(key)
  }
  for (const key of formulas.keys()) visit(key)
  return result
}

/** Build an evaluator for one configuration ({ columns, settings } as returned by GET /payroll/config). */
export function createCalculator(config) {
  const columns = config.columns
  const byKey = new Map(columns.map(c => [c.key, c]))
  const settings = new Map(config.settings.map(s => [s.key, ok(parseDec(s.value))]))
  const sequence = order(columns)

  /** inputs: { key: wire string | null } for input columns. Returns { key: { v, st, m } } for every column. */
  function evaluateRow(inputs) {
    const values = new Map()
    for (const column of columns) {
      if (column.kind !== 'input') continue
      const raw = inputs[column.key] ?? null
      if (column.value_type === 'text') values.set(column.key, ok(raw === null ? '' : String(raw)))
      else if (raw === null) values.set(column.key, column.blank_as_zero ? ok(ZERO) : missing(`مدخل ناقص: ${column.label}`))
      else values.set(column.key, ok(parseDec(raw)))
    }
    const resolve = key => settings.get(key) ?? values.get(key) ?? failed('unknown_reference', 'مرجع غير معروف.')
    for (const key of sequence) {
      const column = byKey.get(key)
      let result = evaluateAst(column.ast, resolve)
      if (isNum(result)) result = ok(round(result.value, column.value_type === 'amount' ? AMOUNT_SCALE : NUMBER_SCALE))
      values.set(key, result)
    }
    const cells = {}
    for (const column of columns) {
      const value = values.get(column.key)
      const raw = inputs[column.key] ?? null
      if (value.state !== 'ok') cells[column.key] = { v: null, st: value.state, m: value.message }
      else if (column.kind === 'input' && raw === null) cells[column.key] = { v: null, st: null, m: null }
      else if (!isNum(value)) cells[column.key] = { v: String(value.value), st: null, m: null }
      else {
        const warn = Boolean(column.warn_negative) && isNegative(value.value)
        cells[column.key] = { v: formatWire(value.value, column.value_type), st: warn ? 'warning' : null, m: warn ? 'قيمة سالبة: لا يوجد حدّ أدنى صفري في المعادلة (تحذير حسابي).' : null }
      }
    }
    return cells
  }

  return { evaluateRow, columns, settings: config.settings }
}

/** Sum a column over rows' cells: unavailable cells are excluded and counted, never treated as zero. */
export function sumColumn(rows, key, type) {
  let sum = ZERO
  let excluded = 0
  for (const row of rows) {
    const cell = row.cells[key]
    if (cell.st === 'missing' || cell.st === 'error') excluded += 1
    else if (cell.v !== null) sum = plus(sum, parseDec(cell.v))
  }
  return { sum: formatWire(sum, type), excluded }
}

export { abs }
