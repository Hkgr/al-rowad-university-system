import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { RevoGrid } from '@revolist/react-datagrid'
import { COLUMNS } from '../lib/payrollView'
import { centsToString, formatMoney, parseAmount, AMOUNT_ERRORS } from '../lib/payrollMoney'
import { matrixToClipboardText, parseClipboardText, planPaste } from '../lib/payrollClipboard'
import { createMoneyEditor } from './moneyEditor'
import './payrollGrid.css'

// Spreadsheet surface of the payroll sheet, built on RevoGrid (MIT): rendering, virtual scrolling, RTL layout,
// frozen columns, column resizing, cell/range selection and the in-cell editor come from the library.
// The application layer on top of it (this file + lib/) owns what must be exact:
//  - every edit/paste/clear/undo goes through the PayrollSheetController (atomic request, revisions, conflicts);
//  - keyboard order follows the Arabic reading direction (RevoGrid's Tab/Enter order is not RTL-aware);
//  - clipboard copy/paste is handled here (Excel tab/newline text, validated as ONE all-or-nothing batch).
// RevoGrid's own clipboard plugin is switched off (useClipboard={false}) so nothing can bypass validation.

const LOGICAL = COLUMNS.map(column => column.prop)

/**
 * Frozen columns: employee number and name on a normal screen. On a phone the frozen band would leave no room to scroll
 * (and to see the money columns), so only the employee number is frozen there.
 */
const layoutFor = compact => {
  const pinned = COLUMNS.filter(column => column.pin && (!compact || column.prop === 'employee_number'))
  return { compact, pinned, free: COLUMNS.filter(column => !pinned.includes(column)) }
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

export default function PayrollGrid({ snapshot, sort, canEdit, onSort, onEditMeta, onUndo, onRedo, onClear, onPaste, onInvalidEdit, controller, invalid, announce }) {
  const gridRef = useRef(null)
  const wrapperRef = useRef(null)
  const latest = useRef({})
  const [compact, setCompact] = useState(() => typeof window !== 'undefined' && window.matchMedia(COMPACT_QUERY).matches)
  const layout = useMemo(() => layoutFor(compact), [compact])
  useEffect(() => {
    const query = window.matchMedia(COMPACT_QUERY)
    const onChange = event => setCompact(event.matches)
    query.addEventListener('change', onChange)
    return () => query.removeEventListener('change', onChange)
  }, [])
  const hooks = useRef({ moveAfterSave: null, onInvalid: null })
  const getHooks = useCallback(() => hooks.current, [])
  const editStartedAt = useRef(0) // RevoGrid mounts the editor input a tick after an edit starts; keys in that window belong to the edit
  // The editor class only reads the ref later (inside key handlers), never during render.
  // eslint-disable-next-line react-hooks/refs
  const editors = useMemo(() => ({ money: createMoneyEditor(getHooks) }), [getHooks])
  const rows = snapshot.rows
  const [refresh, setRefresh] = useState(0) // bumps to rebuild the grid rows after an edit RevoGrid applied to its own copy

  const source = useMemo(() => rows.map(row => ({
    ...row,
    fixed_salary: centsToString(row.fixed_salary) ?? '',
    deduction: centsToString(row.deduction) ?? '',
    compensation: centsToString(row.compensation) ?? '',
    payable: centsToString(row.payable) ?? '',
    academic_level: row.academic_level ?? '',
    _cents: { fixed_salary: row.fixed_salary, deduction: row.deduction, compensation: row.compensation, payable: row.payable },
  // eslint-disable-next-line react-hooks/exhaustive-deps
  })), [rows, refresh])

  const columns = useMemo(() => COLUMNS.map(column => {
    const editable = Boolean(canEdit && column.editable)
    const base = {
      prop: column.prop, name: column.title, size: compact && column.prop === 'full_name' ? 150 : compact && column.prop === 'employee_number' ? 96 : column.size, minSize: 80, resizable: true, readonly: !editable,
      ...(layout.pinned.includes(column) ? { pin: 'colPinStart' } : {}),
      columnTemplate: h => h('span', { class: 'pg-head', 'data-sort': column.sort, title: 'اضغط للترتيب' }, column.title + sortMark(sort, column)),
      cellProperties: ({ model }) => {
        const state = model.states?.[column.prop]
        const negative = column.money && (model._cents?.[column.prop] ?? 0) < 0
        const bad = invalid?.has(`${model.id}:${column.prop}`)
        return {
          'data-id': model.id, 'data-f': column.prop, ...(state ? { 'data-state': state } : {}),
          class: {
            'pg-money': Boolean(column.money), 'pg-computed': Boolean(column.computed), 'pg-readonly': !editable, 'pg-negative': negative, 'pg-invalid': Boolean(bad),
            'pg-saving': state === 'saving' || state === 'queued', 'pg-failed': state === 'failed', 'pg-conflict': state === 'conflict',
          },
        }
      },
    }
    if (column.editable) base.editor = 'money'
    if (column.money) {
      base.cellTemplate = (h, { model, prop }) => {
        const text = formatMoney(model._cents?.[prop])
        return h('span', { class: 'pg-amount', dir: 'ltr' }, text)
      }
    } else if (column.prop === 'full_name') {
      base.cellTemplate = (h, { model }) => h('span', { class: 'pg-name' }, [
        h('span', { class: 'pg-name-text' }, model.full_name),
        h('button', { type: 'button', class: 'pg-edit', 'data-edit-id': model.id, 'aria-label': `تعديل بيانات ${model.full_name}`, title: 'تعديل بيانات الموظف' }, '✎'),
      ])
    } else if (column.prop === 'employee_number') {
      base.cellTemplate = (h, { model }) => h('span', { class: 'pg-code', dir: 'ltr' }, model.employee_number)
    }
    return base
  }), [canEdit, sort, invalid, layout, compact])

  // Latest values for the long-lived DOM listeners.
  useEffect(() => { hooks.current.onInvalid = onInvalidEdit
    latest.current = { layout, rows, canEdit, onUndo, onRedo, onClear, onPaste, onEditMeta, onInvalidEdit, controller, announce } })

  const grid = () => gridRef.current

  /** Focused cell and selection translated into display-row / reading-order-column indexes. */
  const readSelection = useCallback(async () => {
    const el = grid()
    if (!el) return null
    const focused = await el.getFocused()
    if (!focused) return null
    const range = await el.getSelectedRange()
    const lay = latest.current.layout
    const focus = { row: focused.cell.y, col: LOGICAL.indexOf(propAt(focused.colType, focused.cell.x, lay)) }
    if (!range) return { focus, selection: { row: focus.row, col: focus.col, rows: 1, cols: 1 } }
    const group = range.colType === 'colPinStart' ? lay.pinned : lay.free
    const cols = [group.length - 1 - range.x1, group.length - 1 - range.x].map(i => LOGICAL.indexOf(group[i]?.prop))
    return { focus, selection: { row: Math.min(range.y, range.y1), col: Math.min(...cols), rows: Math.abs(range.y1 - range.y) + 1, cols: Math.max(...cols) - Math.min(...cols) + 1 } }
  }, [])

  const focusCell = useCallback(async (row, col) => {
    const el = grid()
    const total = latest.current.rows.length
    if (!el || !total) return
    const r = Math.max(0, Math.min(total - 1, row))
    const c = Math.max(0, Math.min(LOGICAL.length - 1, col))
    const { colType, x } = coordOf(LOGICAL[c], latest.current.layout)
    await el.scrollToRow(r)
    await el.scrollToColumnProp(LOGICAL[c], colType)
    await el.setCellsFocus({ x, y: r }, { x, y: r }, colType, 'rgRow')
  }, [])

  /** Move from a cell by a reading-order offset (wraps to the next/previous row). */
  const moveFrom = useCallback((row, col, dRow, dCol) => {
    let r = row
    let c = col
    if (dCol) {
      c += dCol
      if (c >= LOGICAL.length) { c = 0; r += 1 } else if (c < 0) { c = LOGICAL.length - 1; r -= 1 }
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
      if (dCol) {
        col += dCol
        if (col >= LOGICAL.length) { col = 0; row += 1 } else if (col < 0) { col = LOGICAL.length - 1; row -= 1 }
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
    const mirror = async () => { mirrored = await readSelection() }
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
        if (current) latest.current.onClear(current.selection)
        return
      }
      if (key === 'Escape') {
        editStartedAt.current = 0
        // After the editor closes keep the cell focused so the keyboard keeps working.
        window.setTimeout(async () => { const current = await readSelection(); if (current) focusCell(current.focus.row, current.focus.col) }, 80)
      }
      if (!editing && key === 'F2') {
        const current = await readSelection()
        const prop = current ? LOGICAL[current.focus.col] : null
        const column = COLUMNS.find(item => item.prop === prop)
        if (!column) return
        event.preventDefault(); event.stopPropagation()
        if (column.editable && latest.current.canEdit) {
          editStartedAt.current = Date.now()
          await grid().setCellEdit(current.focus.row, prop)
        } else {
          const row = latest.current.rows[current.focus.row]
          if (row) latest.current.onEditMeta(row)
        }
        return
      }
      // Reading order in an RTL sheet runs right to left: Tab goes to the cell on the LEFT, Shift+Tab to the right.
      // While editing, the cell editor (moneyEditor.js) owns Tab/Enter; here only plain navigation is handled.
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
      const column = COLUMNS.find(item => item.prop === prop)
      if (column && !column.editable) {
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
      const props = LOGICAL.slice(selection.col, selection.col + selection.cols)
      const matrix = rowsList.slice(selection.row, selection.row + selection.rows).map(row => props.map(prop => {
        const column = COLUMNS.find(item => item.prop === prop)
        if (column.money) return centsToString(row[prop]) ?? ''
        return String(row[prop] ?? '')
      }))
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
      const { rows: list, controller: ctl, canEdit: allowed } = latest.current
      if (!allowed) { latest.current.onPaste({ changes: [], errors: [{ kind: 'readonly', message: 'حسابك لا يملك صلاحية تعديل المبالغ.' }] }); return }
      const plan = planPaste({
        matrix: parseClipboardText(text), anchor: current.focus, selection: current.selection,
        columns: COLUMNS.map(column => ({ prop: column.prop, title: column.title, editable: Boolean(column.editable) })),
        rowIds: list.map(row => row.id), currentValue: (id, field) => ctl.valueOf(id, field),
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
    if (move) window.setTimeout(() => moveFrom(rowIndex, LOGICAL.indexOf(prop), move.row, move.col), 30)
    const column = COLUMNS.find(item => item.prop === prop)
    if (!column?.editable || !canEdit) { event.detail.val = model[prop]; return }
    const parsed = parseAmount(val)
    event.detail.val = model[prop]
    if (!parsed.ok) { onInvalidEdit?.({ id: model.id, prop, title: column.title, message: AMOUNT_ERRORS[parsed.error] }); setRefresh(n => n + 1); return }
    controller.edit([{ id: model.id, field: prop, cents: parsed.cents }])
    setRefresh(n => n + 1)
  }
  const onBeforeEditStart = () => { editStartedAt.current = Date.now() }
  const onBeforeRangeEdit = event => event.preventDefault() // multi-cell writes only via our paste/clear/undo paths
  const onBeforeAutofill = event => event.preventDefault() // the fill handle must not bypass validation
  const onHeaderClick = event => {
    const target = event.detail?.prop
    const column = COLUMNS.find(item => item.prop === target)
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
      />
    </div>
  )
}
