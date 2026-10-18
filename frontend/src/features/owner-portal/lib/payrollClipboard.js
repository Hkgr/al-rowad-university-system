// Clipboard text <-> cell matrices for the payroll grid, and the all-or-nothing paste plan.
import { INPUT_ERRORS, parseInput } from './payrollMoney.js'

/** Parse tab/newline text (Excel, Google Sheets, LibreOffice) including quoted cells. Trailing empty line dropped. */
export function parseClipboardText(text) {
  const source = String(text ?? '').replace(/\r\n?/g, '\n')
  const rows = []
  let row = []
  let cell = ''
  let quoted = false
  let cellStart = true
  for (let i = 0; i < source.length; i += 1) {
    const char = source[i]
    if (quoted) {
      if (char === '"' && source[i + 1] === '"') { cell += '"'; i += 1 } else if (char === '"') quoted = false
      else cell += char
    } else if (char === '"' && cellStart) { quoted = true; cellStart = false } else if (char === '\t') { row.push(cell); cell = ''; cellStart = true } else if (char === '\n') {
      row.push(cell); rows.push(row); row = []; cell = ''; cellStart = true
    } else { cell += char; cellStart = false }
  }
  if (cell !== '' || row.length > 0) { row.push(cell); rows.push(row) }
  return rows
}

const quoteIfNeeded = value => (/[\t\n\r"]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value)

/** Matrix → text Excel pastes as cells. */
export function matrixToClipboardText(matrix) {
  return matrix.map(row => row.map(value => quoteIfNeeded(value ?? '')).join('\t')).join('\n')
}

/**
 * Plan a paste. `columns` is the logical (reading-order) column list [{ prop, title, editable, meta: { value_type, allow_negative } }]; `rowIds` the
 * ids of the rows in display order. `anchor` = { row, col } (indexes into those lists) of the top-left target.
 * With a single clipboard cell and a larger selection, the value fills the whole selection (Excel behaviour).
 *
 * Returns { changes, errors }. When errors is non-empty the caller must commit NOTHING from this paste. Errors
 * name the offending cells: { row, col, prop, id, kind, message }.
 */
export function planPaste({ matrix, anchor, selection = null, columns, rowIds, currentValue }) {
  const errors = []
  if (!matrix.length || !matrix.some(row => row.length)) return { changes: [], errors: [{ row: anchor.row, col: anchor.col, kind: 'empty', message: 'لا توجد بيانات في الحافظة.' }] }
  const single = matrix.length === 1 && matrix[0].length === 1
  let grid = matrix
  let origin = anchor
  if (single && selection && (selection.rows > 1 || selection.cols > 1)) {
    grid = Array.from({ length: selection.rows }, () => Array.from({ length: selection.cols }, () => matrix[0][0]))
    origin = { row: selection.row, col: selection.col }
  }
  const changes = []
  grid.forEach((line, dy) => line.forEach((raw, dx) => {
    const row = origin.row + dy
    const col = origin.col + dx
    const column = columns[col]
    if (row >= rowIds.length) return errors.push({ row, col, prop: column?.prop, kind: 'rows', message: 'اللصق يتجاوز آخر صف في الجدول.' })
    if (!column) return errors.push({ row, col, kind: 'columns', message: 'اللصق يتجاوز آخر عمود في الجدول.' })
    if (!column.editable) return errors.push({ row, col, prop: column.prop, id: rowIds[row], kind: 'protected', message: `العمود «${column.title}» محمي ولا يقبل اللصق.` })
    const parsed = parseInput(column.meta ?? { value_type: 'amount', allow_negative: false }, raw)
    if (!parsed.ok) return errors.push({ row, col, prop: column.prop, id: rowIds[row], kind: 'invalid', message: INPUT_ERRORS[parsed.error] })
    changes.push({ id: rowIds[row], key: column.prop, value: parsed.value, row, col })
  }))
  return errors.length ? { changes: [], errors } : { changes: changes.filter(change => currentValue(change.id, change.key) !== change.value), errors: [] }
}
