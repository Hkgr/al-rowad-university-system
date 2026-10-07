import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { RevoGrid } from '@revolist/react-datagrid'
import { GROUP_LABELS, GROUP_ORDER } from '../lib/payrollView'
import { INPUT_ERRORS, editText, formatValue, parseInput, pctText } from '../lib/payrollMoney'
import { parseDec } from '../lib/payrollDecimal'
import { matrixToClipboardText, parseClipboardText, planPaste } from '../lib/payrollClipboard'
import { createValueEditor } from './valueEditor'
import './payrollGrid.css'

// Spreadsheet surface of the payroll sheet, built on RevoGrid (MIT): rendering, virtual scrolling, RTL layout,
// frozen columns, column resizing, cell/range selection and the in-cell editor come from the library.
// The application layer on top of it (this file + lib/) owns what must be exact:
//  - every edit/paste/clear/undo goes through the PayrollSheetController (atomic request, revisions, conflicts);
//  - keyboard order follows the Arabic reading direction (RevoGrid's Tab/Enter order is not RTL-aware);
//  - clipboard copy/paste is handled here (Excel tab/newline text, validated as ONE all-or-nothing batch).
// RevoGrid's own clipboard plugin is switched off (useClipboard={false}) so nothing can bypass validation.

/**
 * Frozen columns: employee number and name on a normal screen. On a phone the frozen band would leave no room to scroll
 * (and to see the figures), so only the employee number is frozen there.
 */
const layoutFor = (columns, compactScreen) => {
  const pinned = columns.filter(column => column.pin && (!compactScreen || column.prop === 'employee_number'))
  return { compactScreen, pinned, free: columns.filter(column => !pinned.includes(column)) }
}
const COMPACT_QUERY = '(max-width: 640px)'

/** RevoGrid indexes an RTL column group from its LEFT edge; reading order starts at the right. */
const coordOf = (prop, layout) => {
  const pinnedIndex = layout.pinned.findIndex(column => column.prop === prop)
  if (pinnedIndex >= 0) return { colType: 'colPinStart', x: layout.pinned.length - 1 - pinnedIndex }
  return { colType: 'rgCol', x: layout.free.length - 1 - layout.free.findIndex(column => column.prop === prop) }
}
const propAt = (colType, x, layout) => (colType === 'colPinStart' ? layout.pinned[layout.pinned.length - 1 - x] : layout.free[layout.free.length - 1 - x])?.prop

const sortMark = (sort, column) => (sort.key === column.sort ? (sort.direction === 'asc' ? ' ▲' : ' ▼') : '')
const NUMERIC = new Set(['amount', 'number', 'percent'])

/** Wire/clipboard text of a cell by column type (percentages as "85%", amounts plain, unavailable as empty). */
function clipboardText(column, row) {
  if (column.identity) return String(row[column.prop] ?? '')
  const cell = row.cells[column.prop]
  if (!cell || cell.v === null || cell.st === 'missing' || cell.st === 'error') return ''
  return column.type === 'percent' ? `${pctText(parseDec(cell.v))}%` : cell.v
}

export default function PayrollGrid({ snapshot, columns: columnDefs, totals, sort, canEdit, onSort, onEditMeta, onUndo, onRedo, onClear, onPaste, onInvalidEdit, onFocusCell, controller, invalid, announce }) {
  const gridRef = useRef(null)
  const wrapperRef = useRef(null)
  const latest = useRef({})
  const [compactScreen, setCompactScreen] = useState(() => typeof window !== 'undefined' && window.matchMedia(COMPACT_QUERY).matches)
  const layout = useMemo(() => layoutFor(columnDefs, compactScreen), [columnDefs, compactScreen])
  const logical = useMemo(() => [...layout.pinned, ...layout.free], [layout])
  useEffect(() => {
    const query = window.matchMedia(COMPACT_QUERY)
    const onChange = event => setCompactScreen(event.matches)
    query.addEventListener('change', onChange)
    return () => query.removeEventListener('change', onChange)
  }, [])
  const [widths, setWidths] = useState({}) // widths the user dragged; a rebuilt column list must not reset them
  const hooks = useRef({ moveAfterSave: null, onInvalid: null })
  const getHooks = useCallback(() => hooks.current, [])
  const editStartedAt = useRef(0) // RevoGrid mounts the editor input a tick after an edit starts; keys in that window belong to the edit
  // The editor class only reads the ref later (inside key handlers), never during render.
  // eslint-disable-next-line react-hooks/refs
  const editors = useMemo(() => ({ value: createValueEditor(getHooks) }), [getHooks])
  const rows = snapshot.rows
  const [refresh, setRefresh] = useState(0) // bumps to rebuild the grid rows after an edit RevoGrid applied to its own copy

  const source = useMemo(() => rows.map(row => {
    const model = {
      id: row.id, employee_number: row.employee_number, full_name: row.full_name, job_title: row.job_title, body_name: row.body_name,
      workplace_label: row.workplace_label, academic_level: row.academic_level ?? '', _cells: row.cells, _states: row.states,
    }
    for (const column of columnDefs) if (!column.identity) model[column.prop] = editText(column.type, row.cells[column.prop]?.v ?? null)
    return model
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }), [rows, refresh, columnDefs])

  // Totals row pinned at the bottom of the grid (aligned with the columns, always visible while scrolling).
  const footer = useMemo(() => {
    const model = { id: 'footer', _footer: true, employee_number: '', full_name: `الإجمالي (${totals.employees})`, _totals: {} }
    for (const column of columnDefs) {
      if (column.aggregation === 'sum' && totals.columns[column.prop]) model._totals[column.prop] = totals.columns[column.prop]
    }
    return [model]
  }, [totals, columnDefs])

  const columns = useMemo(() => {
    const leaf = column => {
      const editable = Boolean(canEdit && column.editable)
      const numeric = NUMERIC.has(column.type)
      const base = {
        prop: column.prop, name: column.title, size: widths[column.prop] ?? (compactScreen && column.prop === 'full_name' ? 150 : compactScreen && column.prop === 'employee_number' ? 96 : column.size), minSize: 80, resizable: true, readonly: !editable,
        ...(layout.pinned.includes(column) ? { pin: 'colPinStart' } : {}),
        columnTemplate: h => h('span', { class: 'pg-head', 'data-sort': column.sort, title: column.computed ? `عمود محسوب: ${column.definition.formula_display ?? ''} — اضغط للترتيب` : 'اضغط للترتيب' }, (column.computed ? 'ƒ ' : '') + column.title + sortMark(sort, column)),
        cellProperties: ({ model }) => {
          if (model._footer) return { class: { 'pg-footer': true, 'pg-money': numeric, 'pg-readonly': true }, 'data-f': column.prop }
          const state = model._states?.[column.prop]
          const cell = model._cells?.[column.prop]
          const negative = column.type === 'amount' && typeof cell?.v === 'string' && cell.v.startsWith('-')
          const bad = invalid?.has(`${model.id}:${column.prop}`)
          return {
            'data-id': model.id, 'data-f': column.prop, ...(state ? { 'data-state': state } : {}), ...(cell?.st ? { 'data-calc': cell.st } : {}),
            class: {
              'pg-money': numeric, 'pg-computed': Boolean(column.computed), 'pg-input': Boolean(column.editable) && !column.computed, 'pg-readonly': !editable, 'pg-negative': negative, 'pg-invalid': Boolean(bad),
              'pg-saving': state === 'saving' || state === 'queued', 'pg-failed': state === 'failed', 'pg-conflict': state === 'conflict' || state === 'config_conflict',
              'pg-unavailable-cell': cell?.st === 'missing' && Boolean(column.computed), 'pg-error-cell': cell?.st === 'error', 'pg-warning-cell': cell?.st === 'warning',
            },
          }
        },
      }
      if (column.editable) { base.editor = 'value'; base.meta = { value_type: column.type, allow_negative: Boolean(column.definition?.allow_negative) } }
      if (!column.identity) {
        base.cellTemplate = (h, { model, prop }) => {
          if (model._footer) {
            const total = model._totals[prop]
            if (!total) return h('span', {}, '')
            return h('span', { class: 'pg-amount pg-footer-value', dir: 'ltr', title: total.excluded ? `${total.excluded} سجل غير متاح (ناقص أو به خطأ) مستثنى من هذا المجموع` : 'مجموع كل الصفوف المطابقة' }, [formatValue(column.type, total.sum), total.excluded ? h('sup', { class: 'pg-excluded' }, '*') : null])
          }
          const cell = model._cells?.[prop]
          if (cell?.st === 'missing') return column.computed ? h('span', { class: 'pg-unavailable', title: cell.m }, '—') : h('span', { title: cell.m }, '') // a blank input stays blank; only a calculated result is "unavailable"
          if (cell?.st === 'error') return h('span', { class: 'pg-error', title: cell.m }, 'خطأ')
          const text = formatValue(column.type, cell?.v)
          return h('span', { class: numeric ? 'pg-amount' : 'pg-text', dir: numeric ? 'ltr' : 'rtl', title: cell?.st === 'warning' ? cell.m : undefined }, cell?.st === 'warning' ? `${text} ⚠` : text)
        }
      } else if (column.prop === 'full_name') {
        base.cellTemplate = (h, { model }) => (model._footer
          ? h('span', { class: 'pg-footer-label' }, model.full_name)
          : h('span', { class: 'pg-name' }, [
            h('span', { class: 'pg-name-text' }, model.full_name),
            h('button', { type: 'button', class: 'pg-edit', 'data-edit-id': model.id, 'aria-label': `تعديل بيانات ${model.full_name}`, title: 'تعديل بيانات الموظف' }, '✎'),
          ]))
      } else if (column.prop === 'employee_number') {
        base.cellTemplate = (h, { model }) => h('span', { class: 'pg-code', dir: 'ltr' }, model.employee_number)
      }
      return base
    }
    // Visual groups (employee data, salary, compensation, deductions, net): a header band above the columns of each group.
    const groups = []
    for (const key of GROUP_ORDER) {
      const members = columnDefs.filter(column => column.group === key)
      if (members.length) groups.push({ key, members })
    }
    return groups.map(({ key, members }) => ({ name: GROUP_LABELS[key], children: members.map(leaf), _group: key }))
  }, [canEdit, sort, invalid, layout, columnDefs, compactScreen, widths])

  // Latest values for the long-lived DOM listeners.
  useEffect(() => { hooks.current.onInvalid = onInvalidEdit
    latest.current = { layout, logical, columnDefs, rows, canEdit, onUndo, onRedo, onClear, onPaste, onEditMeta, onInvalidEdit, onFocusCell, controller, announce } })

  const grid = () => gridRef.current

  // An RTL grid must start at its right edge (the first column in reading order); RevoGrid keeps whatever scroll offset it had.
  const firstFree = layout.free[0]?.prop
  const columnSignature = columnDefs.map(column => column.prop).join('|')
  const hasRows = snapshot.rows.length > 0
  useEffect(() => {
    // Twice: the first call can land before RevoGrid has measured its viewport.
    const timers = [150, 600].map(delay => window.setTimeout(() => { if (firstFree) grid()?.scrollToColumnProp?.(firstFree, 'rgCol') }, delay))
    return () => timers.forEach(timer => window.clearTimeout(timer))
  }, [firstFree, columnSignature, compactScreen, hasRows])

  /** Focused cell and selection translated into display-row / reading-order-column indexes. Footer (totals) cells are not selectable data. */
  const readSelection = useCallback(async () => {
    const el = grid()
    if (!el) return null
    const focused = await el.getFocused()
    if (!focused || focused.rowType !== 'rgRow') return null
    const range = await el.getSelectedRange()
    const { layout: lay, logical: order } = latest.current
    const focus = { row: focused.cell.y, col: order.findIndex(c => c.prop === propAt(focused.colType, focused.cell.x, lay)) }
    if (!range) return { focus, selection: { row: focus.row, col: focus.col, rows: 1, cols: 1 } }
    const group = range.colType === 'colPinStart' ? lay.pinned : lay.free
    const cols = [group.length - 1 - range.x1, group.length - 1 - range.x].map(i => order.findIndex(c => c.prop === group[i]?.prop))
    return { focus, selection: { row: Math.min(range.y, range.y1), col: Math.min(...cols), rows: Math.abs(range.y1 - range.y) + 1, cols: Math.max(...cols) - Math.min(...cols) + 1 } }
  }, [])

  const focusCell = useCallback(async (row, col) => {
    const el = grid()
    const total = latest.current.rows.length
    const order = latest.current.logical
    if (!el || !total) return
    const r = Math.max(0, Math.min(total - 1, row))
    const c = Math.max(0, Math.min(order.length - 1, col))
    const { colType, x } = coordOf(order[c].prop, latest.current.layout)
    await el.scrollToRow(r)
    await el.scrollToColumnProp(order[c].prop, colType)
    await el.setCellsFocus({ x, y: r }, { x, y: r }, colType, 'rgRow')
  }, [])

  /** Move from a cell by a reading-order offset (wraps to the next/previous row). */
  const moveFrom = useCallback((row, col, dRow, dCol) => {
    const count = latest.current.logical.length
    let r = row
    let c = col
    if (dCol) {
      c += dCol
      if (c >= count) { c = 0; r += 1 } else if (c < 0) { c = count - 1; r -= 1 }
    }
    return focusCell(r + dRow, c)
  }, [focusCell])

  const isEditing = () => Boolean(wrapperRef.current?.querySelector('revogr-edit input, .edit-input-wrapper input, revogr-edit textarea')) || Date.now() - editStartedAt.current < 350

  // ── keyboard (capture phase, scoped to this grid) ──────────────────────
  useEffect(() => {
    const wrapper = wrapperRef.current
    if (!wrapper) return undefined

    const move = async (dRow, dCol) => {
      const current = await readSelection()
      if (!current) return
      let { row, col } = current.focus
      const count = latest.current.logical.length
      if (dCol) {
        col += dCol
        if (col >= count) { col = 0; row += 1 } else if (col < 0) { col = count - 1; row -= 1 }
      }
      row += dRow
      await focusCell(row, col)
    }

    // RevoGrid drops DOM focus to <body> whenever its editor closes, so "the grid is active" is tracked from the last
    // pointer interaction: keys and clipboard events from <body> belong to the grid only after it was clicked.
    let gridActive = false
    const onPointerDown = event => { gridActive = wrapper.contains(event.target) }
    // Clipboard events are targeted at the document selection (not the focused cell), so scope by the active element.
    const inScope = () => {
      const active = document.activeElement
      return wrapper.contains(active) || (gridActive && (!active || active === document.body || active === document.documentElement))
    }
    // clipboardData is only writable synchronously, so the selection is mirrored here as it changes.
    let mirrored = null
    const mirror = async () => {
      mirrored = await readSelection()
      if (mirrored) {
        const column = latest.current.logical[mirrored.focus.col]
        latest.current.onFocusCell?.({ row: mirrored.focus.row, prop: column?.prop, id: latest.current.rows[mirrored.focus.row]?.id })
      }
    }
    const mirrorLater = () => window.setTimeout(mirror, 220)

    const onKeyDown = async event => {
      if (!inScope()) return
      mirrorLater()
      const editing = isEditing()
      const mod = event.ctrlKey || event.metaKey
      const key = event.key

      if (mod && !editing && (key === 'z' || key === 'Z' || key === 'y' || key === 'Y')) {
        event.preventDefault(); event.stopPropagation()
        if (key.toLowerCase() === 'y' || event.shiftKey) latest.current.onRedo(); else latest.current.onUndo()
        return
      }
      if (mod || event.altKey) return

      if (!editing && (key === 'Delete' || key === 'Backspace')) {
        event.preventDefault(); event.stopPropagation()
        const current = await readSelection()
        if (current) latest.current.onClear(current.selection, latest.current.logical)
        return
      }
      if (key === 'Escape') {
        editStartedAt.current = 0
        // After the editor closes keep the cell focused so the keyboard keeps working.
        window.setTimeout(async () => { const current = await readSelection(); if (current) focusCell(current.focus.row, current.focus.col) }, 80)
      }
      if (!editing && key === 'F2') {
        const current = await readSelection()
        const column = current ? latest.current.logical[current.focus.col] : null
        if (!column) return
        event.preventDefault(); event.stopPropagation()
        if (column.editable && latest.current.canEdit) {
          editStartedAt.current = Date.now()
          await grid().setCellEdit(current.focus.row, column.prop)
        } else if (column.identity) {
          const row = latest.current.rows[current.focus.row]
          if (row) latest.current.onEditMeta(row)
        }
        return
      }
      // Reading order in an RTL sheet runs right to left: Tab goes to the cell on the LEFT, Shift+Tab to the right.
      // While editing, the cell editor (valueEditor.js) owns Tab/Enter; here only plain navigation is handled.
      if ((key === 'Tab' || key === 'Enter') && editing && event.target !== wrapperRef.current?.querySelector('revogr-edit input')) {
        // Keys typed right after an edit starts can land on the cell instead of the (still mounting) input: hand them to the editor.
        event.preventDefault(); event.stopPropagation()
        for (let waited = 0; waited < 500; waited += 25) {
          const input = wrapperRef.current?.querySelector('revogr-edit input')
          if (input) { input.dispatchEvent(new KeyboardEvent('keydown', { key, shiftKey: event.shiftKey, bubbles: true, cancelable: true })); return }
          await new Promise(resolve => setTimeout(resolve, 25))
        }
        return
      }
      if ((key === 'Tab' || key === 'Enter') && !editing) {
        event.preventDefault(); event.stopPropagation()
        await move(key === 'Enter' ? (event.shiftKey ? -1 : 1) : 0, key === 'Tab' ? (event.shiftKey ? -1 : 1) : 0)
      }
    }

    const onDoubleClick = event => {
      const cell = event.target.closest?.('.rgCell')
      const prop = cell?.getAttribute('data-f')
      const column = latest.current.columnDefs.find(item => item.prop === prop)
      if (column?.identity) {
        const id = Number(cell.getAttribute('data-id'))
        const row = latest.current.rows.find(item => item.id === id)
        if (row) latest.current.onEditMeta(row)
      }
    }
    const onClick = event => {
      const button = event.target.closest?.('[data-edit-id]')
      if (!button) return
      const row = latest.current.rows.find(item => item.id === Number(button.getAttribute('data-edit-id')))
      if (row) latest.current.onEditMeta(row)
    }

    // ── clipboard ──
    const textFor = (rowsList, selection) => {
      const cols = latest.current.logical.slice(selection.col, selection.col + selection.cols)
      const matrix = rowsList.slice(selection.row, selection.row + selection.rows).map(row => cols.map(column => clipboardText(column, row)))
      return matrixToClipboardText(matrix)
    }
    const onCopy = async event => {
      if (!inScope() || isEditing()) return
      event.preventDefault()
      const current = mirrored
      if (!current) return
      event.clipboardData.setData('text/plain', textFor(latest.current.rows, current.selection))
      latest.current.announce?.('تم نسخ الخلايا المحددة.')
    }
    const onPasteEvent = async event => {
      if (!inScope() || isEditing()) return
      event.preventDefault()
      const text = event.clipboardData.getData('text/plain')
      const current = await readSelection()
      if (!current) return
      const { rows: list, controller: ctl, canEdit: allowed, logical: order } = latest.current
      if (!allowed) { latest.current.onPaste({ changes: [], errors: [{ kind: 'readonly', message: 'حسابك لا يملك صلاحية تعديل المبالغ.' }] }); return }
      const plan = planPaste({
        matrix: parseClipboardText(text), anchor: current.focus, selection: current.selection,
        columns: order.map(column => ({ prop: column.prop, title: column.title, editable: Boolean(column.editable), meta: { value_type: column.type, allow_negative: Boolean(column.definition?.allow_negative) } })),
        rowIds: list.map(row => row.id), currentValue: (id, key) => ctl.valueOf(id, key),
      })
      latest.current.onPaste(plan, list)
    }
    const onCut = event => { if (inScope() && !isEditing()) event.preventDefault() }

    document.addEventListener('pointerdown', onPointerDown, true)
    document.addEventListener('pointerup', mirrorLater, true)
    wrapper.addEventListener('afterfocus', mirror)
    wrapper.addEventListener('setrange', mirror)
    document.addEventListener('keydown', onKeyDown, true)
    document.addEventListener('copy', onCopy)
    document.addEventListener('paste', onPasteEvent)
    document.addEventListener('cut', onCut)
    wrapper.addEventListener('dblclick', onDoubleClick)
    wrapper.addEventListener('click', onClick)
    return () => {
      document.removeEventListener('pointerdown', onPointerDown, true)
      document.removeEventListener('pointerup', mirrorLater, true)
      wrapper.removeEventListener('afterfocus', mirror)
      wrapper.removeEventListener('setrange', mirror)
      document.removeEventListener('keydown', onKeyDown, true)
      document.removeEventListener('copy', onCopy)
      document.removeEventListener('paste', onPasteEvent)
      document.removeEventListener('cut', onCut)
      wrapper.removeEventListener('dblclick', onDoubleClick)
      wrapper.removeEventListener('click', onClick)
    }
  }, [focusCell, readSelection])

  // ── RevoGrid events ────────────────────────────────────────────────────
  const onBeforeEdit = event => {
    // The grid must never keep what was typed: the edit becomes one controller operation (or is refused) and the cell is
    // re-rendered from controller state. Revo is told to "apply" the previous text, so its own edit state machine finishes
    // normally (cancelling the event leaves its keyboard handling stuck) while the value shown comes from our state.
    const { prop, val, model, rowIndex } = event.detail
    editStartedAt.current = 0
    const move = hooks.current.moveAfterSave
    hooks.current.moveAfterSave = null
    const order = latest.current.logical
    if (move) window.setTimeout(() => moveFrom(rowIndex, order.findIndex(c => c.prop === prop), move.row, move.col), 30)
    const column = order.find(item => item.prop === prop)
    if (model?._footer || !column?.editable || !canEdit) { event.detail.val = model[prop]; return }
    const parsed = parseInput({ value_type: column.type, allow_negative: Boolean(column.definition?.allow_negative) }, val)
    event.detail.val = model[prop]
    if (!parsed.ok) { onInvalidEdit?.({ id: model.id, prop, title: column.title, message: INPUT_ERRORS[parsed.error] }); setRefresh(n => n + 1); return }
    controller.edit([{ id: model.id, key: prop, value: parsed.value }])
    setRefresh(n => n + 1)
  }
  const onBeforeEditStart = () => { editStartedAt.current = Date.now() }
  const onBeforeRangeEdit = event => event.preventDefault() // multi-cell writes only via our paste/clear/undo paths
  const onBeforeAutofill = event => event.preventDefault() // the fill handle must not bypass validation
  const onColumnResize = event => setWidths(current => ({ ...current, ...Object.fromEntries(Object.values(event.detail ?? {}).filter(column => column?.prop).map(column => [column.prop, column.size])) }))
  const onHeaderClick = event => {
    const target = event.detail?.prop
    const column = latest.current.columnDefs.find(item => item.prop === target)
    if (column) onSort(column.sort)
  }

  return (
    <div ref={wrapperRef} className="payroll-grid" dir="rtl" data-testid="payroll-grid">
      <RevoGrid
        ref={gridRef}
        rtl
        range
        resize
        readonly={!canEdit}
        useClipboard={false}
        source={source}
        pinnedBottomSource={footer}
        columns={columns}
        rowSize={38}
        theme="compact"
        canMoveColumns={false}
        onBeforeedit={onBeforeEdit}
        onBeforeeditstart={onBeforeEditStart}
        applyOnClose
        editors={editors}
        onBeforerangeedit={onBeforeRangeEdit}
        onBeforeautofill={onBeforeAutofill}
        onHeaderclick={onHeaderClick}
        onAftercolumnresize={onColumnResize}
      />
    </div>
  )
}
