import assert from 'node:assert/strict'
import test from 'node:test'
import { centsFromString, centsToString, formatMoney, parseAmount, payableCents, sumTotals } from '../src/features/owner-portal/lib/payrollMoney.js'
import { matrixToClipboardText, parseClipboardText, planPaste } from '../src/features/owner-portal/lib/payrollClipboard.js'
import { PayrollSheetController } from '../src/features/owner-portal/lib/payrollSheetController.js'

// ── money ───────────────────────────────────────────────────────────────────

test('amounts parse to exact cents; blank stays blank; zero stays zero', () => {
  assert.deepEqual(parseAmount(''), { ok: true, cents: null })
  assert.deepEqual(parseAmount('   '), { ok: true, cents: null })
  assert.deepEqual(parseAmount(null), { ok: true, cents: null })
  assert.deepEqual(parseAmount('0'), { ok: true, cents: 0 })
  assert.deepEqual(parseAmount('0.00'), { ok: true, cents: 0 })
  assert.deepEqual(parseAmount('1234.5'), { ok: true, cents: 123450 })
  assert.deepEqual(parseAmount('0.10'), { ok: true, cents: 10 })
  assert.deepEqual(parseAmount('19.99'), { ok: true, cents: 1999 })
  assert.deepEqual(parseAmount('999999999.99'), { ok: true, cents: 99_999_999_999 })
})

test('pasted Excel formatting is tolerated; ambiguous or unsafe text is rejected', () => {
  assert.equal(parseAmount('$1,234.50').cents, 123450)
  assert.equal(parseAmount('١٢٣٫٤٥').cents, 12345, 'Arabic-Indic digits and decimal separator')
  assert.equal(parseAmount('1,234').cents, 123400)
  for (const bad of ['-1', '-0.01', '$-5', '(5.00)', '1e3', '12.345', 'abc', '1,5', '1.2.3', '+5', '.5', '5.', '1 000', '0x10', 'NaN', 'Infinity', '12,34.5']) {
    assert.equal(parseAmount(bad).ok, false, bad)
  }
  assert.equal(parseAmount('-1').error, 'negative')
  assert.equal(parseAmount('1000000000').ok, false)
})

test('formatting: dollar sign, grouping, signed negatives, exact two decimals', () => {
  assert.equal(formatMoney(null), '')
  assert.equal(formatMoney(0), '$0.00')
  assert.equal(formatMoney(123456789), '$1,234,567.89')
  assert.equal(formatMoney(-15050), '-$150.50')
  assert.equal(centsToString(-15050), '-150.50')
  assert.equal(centsToString(5), '0.05')
  assert.equal(centsToString(null), null)
  assert.equal(centsFromString('-150.50'), -15050)
  assert.equal(centsFromString('0.05'), 5)
  assert.equal(centsFromString(null), null)
})

test('payable = salary - deduction + compensation with the blank rules; no float drift; negative is not clamped', () => {
  assert.equal(payableCents(null, 500, 500), null, 'blank salary → blank payable')
  assert.equal(payableCents(10000, null, null), 10000, 'blank deduction/compensation count as zero')
  assert.equal(payableCents(10, 30, 20), 0, '0.10 - 0.30 + 0.20 is exactly 0')
  assert.equal(payableCents(10000, 25075, 25), -15050)
  assert.equal(payableCents(0, null, null), 0, 'explicit zero salary is a real value')
  const totals = sumTotals([
    { fixed_salary: 100050, deduction: 10025, compensation: 5000 },
    { fixed_salary: 80000, deduction: 0, compensation: null },
    { fixed_salary: null, deduction: 3000, compensation: 1000 },
  ])
  assert.deepEqual(totals, { employees: 3, fixed_salary: 180050, deduction: 13025, compensation: 6000, payable: 175025 })
})

// ── clipboard ───────────────────────────────────────────────────────────────

test('clipboard text from Excel parses into a matrix; copy text round-trips', () => {
  assert.deepEqual(parseClipboardText('1\t2\t3\r\n4\t5\t6\r\n'), [['1', '2', '3'], ['4', '5', '6']])
  assert.deepEqual(parseClipboardText('a\t\tc'), [['a', '', 'c']], 'empty middle cell kept')
  assert.deepEqual(parseClipboardText('\t5'), [['', '5']])
  assert.deepEqual(parseClipboardText('"1\t2"\t"x ""q"""'), [['1\t2', 'x "q"']], 'quoted cells')
  assert.deepEqual(parseClipboardText('7'), [['7']])
  assert.deepEqual(parseClipboardText(''), [])
  const matrix = [['00123', 'محمد'], ['', '1.50']]
  assert.deepEqual(parseClipboardText(matrixToClipboardText(matrix)), matrix)
})

const COLUMNS = [
  { prop: 'employee_number', title: 'رقم الموظف', editable: false },
  { prop: 'full_name', title: 'الاسم الكامل', editable: false },
  { prop: 'fixed_salary', title: 'الراتب المقطوع $', editable: true },
  { prop: 'deduction', title: 'الاقتطاع $', editable: true },
  { prop: 'compensation', title: 'التعويض $', editable: true },
  { prop: 'payable', title: 'المستحق $', editable: false },
]
const current = () => null
const plan = (matrix, anchor, extra = {}) => planPaste({ matrix, anchor, columns: COLUMNS, rowIds: [11, 12, 13], currentValue: current, ...extra })

test('multi-cell paste maps logical columns and stable row ids', () => {
  const { changes, errors } = plan([['100', '5', ''], ['200.5', '', '7']], { row: 1, col: 2 })
  assert.deepEqual(errors, [])
  assert.deepEqual(changes.map(c => [c.id, c.field, c.cents]), [
    [12, 'fixed_salary', 10000], [12, 'deduction', 500],
    [13, 'fixed_salary', 20050], [13, 'compensation', 700],
  ], 'blank over blank is not a change')
  const clearing = planPaste({ matrix: [['', '5']], anchor: { row: 0, col: 2 }, columns: COLUMNS, rowIds: [11], currentValue: (id, field) => (field === 'fixed_salary' ? 900 : null) })
  assert.deepEqual(clearing.changes.map(c => [c.field, c.cents]), [['fixed_salary', null], ['deduction', 500]], 'a blank pasted over a value clears it')
})

test('an invalid paste names the cells and commits nothing', () => {
  const result = plan([['100', 'abc'], ['-5', '2']], { row: 0, col: 2 })
  assert.deepEqual(result.changes, [])
  assert.deepEqual(result.errors.map(e => [e.row, e.col, e.kind]), [[0, 3, 'invalid'], [1, 2, 'invalid']])
})

test('metadata and computed columns are protected from financial paste (whole batch refused)', () => {
  for (const col of [0, 1, 5]) {
    const { changes, errors } = plan([['1']], { row: 0, col })
    assert.deepEqual(changes, [])
    assert.equal(errors[0].kind, 'protected')
  }
  // A block that starts in an editable column but runs into the computed column is refused entirely.
  const spill = plan([['1', '2', '3', '4']], { row: 0, col: 2 })
  assert.deepEqual(spill.changes, [])
  assert.deepEqual(spill.errors.map(e => [e.col, e.kind]), [[5, 'protected']])
})

test('paste beyond the last row or column is refused; a single value fills a selected range', () => {
  assert.equal(plan([['1'], ['2']], { row: 2, col: 2 }).errors[0].kind, 'rows')
  assert.equal(plan([['1', '2', '3', '4', '5']], { row: 0, col: 2 }).errors.some(e => e.kind === 'columns' || e.kind === 'protected'), true)
  const fill = plan([['9']], { row: 0, col: 2 }, { selection: { row: 0, col: 2, rows: 3, cols: 2 } })
  assert.equal(fill.changes.length, 6)
  assert.deepEqual([...new Set(fill.changes.map(c => c.cents))], [900])
  assert.equal(plan([], { row: 0, col: 2 }).errors[0].kind, 'empty')
})

test('paste skips cells whose value does not change', () => {
  const unchanged = planPaste({ matrix: [['5']], anchor: { row: 0, col: 3 }, columns: COLUMNS, rowIds: [11], currentValue: () => 500 })
  assert.deepEqual(unchanged, { changes: [], errors: [] })
})

// ── controller ─────────────────────────────────────────────────────────────

const apiRow = (id, extra = {}) => ({
  id, employee_number: String(id).padStart(3, '0'), full_name: `موظف ${id}`, job_title: 'x', body_id: 1, body_name: 'هيئة', body_is_active: true,
  workplace: 'afrin', workplace_other: null, workplace_label: 'عفرين', academic_level: null, employee_revision: 1,
  fixed_salary: null, deduction: null, compensation: null, payable: null, entry_revision: 1, ...extra,
})

/** In-memory server with the real contract: atomic batches, revision conflicts, 422 for bad values. */
function fakeServer(rows) {
  const store = new Map(rows.map(r => [r.id, { ...r }]))
  const log = []
  let fail = null
  const server = {
    store, log,
    failNext(error) { fail = error },
    async save(changes) {
      log.push(JSON.parse(JSON.stringify(changes)))
      if (fail) { const error = fail; fail = null; throw error }
      const stale = changes.filter(c => store.get(c.employee_id).entry_revision !== c.expected_revision)
      if (stale.length) {
        throw Object.assign(new Error('conflict'), { status: 409, errorCode: 'payroll_conflict', details: { conflicts: stale.map(c => ({ employee_id: c.employee_id, current: { ...store.get(c.employee_id) } })) } })
      }
      return changes.map(c => {
        const row = store.get(c.employee_id)
        for (const f of ['fixed_salary', 'deduction', 'compensation']) if (f in c) row[f] = c[f]
        row.entry_revision += 1
        return { ...row }
      })
    },
  }
  return server
}
const setup = (n = 3) => {
  const server = fakeServer(Array.from({ length: n }, (_, i) => apiRow(i + 1)))
  const controller = new PayrollSheetController({ saveAmounts: changes => server.save(changes) })
  controller.load([...server.store.values()])
  return { server, controller }
}

test('an edit shows immediately, recalculates payable and totals, then saves and clears the pending state', async () => {
  const { server, controller } = setup()
  assert.equal(controller.getSnapshot().rows[0].payable, null)
  controller.edit([{ id: 1, field: 'fixed_salary', cents: 100000 }, { id: 1, field: 'deduction', cents: 2500 }])
  const during = controller.getSnapshot()
  assert.equal(during.rows[0].payable, 97500, 'local recalculation before the server answers')
  assert.equal(during.totals.payable, 97500)
  assert.equal(during.status.phase, 'saving')
  assert.equal(during.rows[0].states.fixed_salary, 'saving')
  assert.deepEqual((await controller.flush()), { ok: true })
  const after = controller.getSnapshot()
  assert.equal(after.status.phase, 'idle')
  assert.equal(after.rows[0].states.fixed_salary, undefined)
  assert.equal(server.store.get(1).fixed_salary, '1000.00')
  assert.equal(server.store.get(1).entry_revision, 2)
  assert.equal(after.rows[0].compensation, null, 'untouched cells stay blank, not zero')
})

test('a multi-cell operation is one request keyed by employee id and revision; no-op edits send nothing', async () => {
  const { server, controller } = setup()
  assert.equal(controller.edit([{ id: 2, field: 'deduction', cents: null }]), null)
  controller.edit([{ id: 3, field: 'fixed_salary', cents: 500 }, { id: 1, field: 'fixed_salary', cents: 100 }, { id: 3, field: 'compensation', cents: 0 }])
  await controller.flush()
  assert.equal(server.log.length, 1)
  assert.deepEqual(server.log[0], [
    { employee_id: 3, expected_revision: 1, fixed_salary: '5.00', compensation: '0.00' },
    { employee_id: 1, expected_revision: 1, fixed_salary: '1.00' },
  ])
  assert.equal(controller.getSnapshot().rows[2].compensation, 0, 'explicit zero kept, distinct from blank')
})

test('consecutive edits of one row chain the returned revision', async () => {
  const { server, controller } = setup()
  controller.edit([{ id: 1, field: 'fixed_salary', cents: 100 }])
  controller.edit([{ id: 1, field: 'deduction', cents: 50 }])
  controller.edit([{ id: 1, field: 'fixed_salary', cents: 300 }])
  assert.deepEqual(await controller.flush(), { ok: true })
  assert.deepEqual(server.log.map(l => l[0].expected_revision), [1, 2, 3])
  assert.equal(server.store.get(1).fixed_salary, '3.00')
})

test('undo and redo (including a pasted range) go through the server like any edit', async () => {
  const { server, controller } = setup()
  controller.edit([{ id: 1, field: 'fixed_salary', cents: 1000 }, { id: 2, field: 'fixed_salary', cents: 2000 }, { id: 3, field: 'fixed_salary', cents: 3000 }])
  await controller.flush()
  controller.edit([{ id: 2, field: 'fixed_salary', cents: 2500 }])
  await controller.flush()
  assert.equal(controller.undo() !== null, true)
  await controller.flush()
  assert.equal(server.store.get(2).fixed_salary, '20.00')
  assert.equal(controller.undo() !== null, true)
  await controller.flush()
  assert.deepEqual([1, 2, 3].map(id => server.store.get(id).fixed_salary), [null, null, null], 'the whole pasted range is undone as ONE step')
  assert.equal(controller.getSnapshot().canUndo, false)
  controller.redo()
  await controller.flush()
  assert.deepEqual([1, 2, 3].map(id => server.store.get(id).fixed_salary), ['10.00', '20.00', '30.00'])
  assert.equal(server.log.at(-1)[0].expected_revision, server.store.get(1).entry_revision - 1, 'undo/redo use current revisions')
  controller.edit([{ id: 1, field: 'deduction', cents: 1 }])
  assert.equal(controller.getSnapshot().canRedo, false, 'a new edit clears redo')
})

test('a stale revision becomes an explicit conflict that keeps the pending values and halts the queue', async () => {
  const { server, controller } = setup()
  server.store.get(1).entry_revision = 5 // someone else saved row 1 in the meantime
  server.store.get(1).fixed_salary = '9.00'
  controller.edit([{ id: 1, field: 'fixed_salary', cents: 100 }, { id: 2, field: 'fixed_salary', cents: 200 }])
  controller.edit([{ id: 3, field: 'fixed_salary', cents: 300 }])
  assert.deepEqual(await controller.flush(), { ok: false })
  const snap = controller.getSnapshot()
  assert.equal(snap.status.phase, 'conflict')
  assert.equal(snap.rows[0].fixed_salary, 100, 'my pending value is preserved, not overwritten')
  assert.equal(snap.rows[0].states.fixed_salary, 'conflict')
  assert.equal(snap.status.conflict.rows[0].fixed_salary, '9.00')
  assert.equal(server.store.get(2).fixed_salary, null, 'nothing from the batch was written')
  assert.equal(server.log.length, 1, 'the following queued operation did not run')
  assert.equal(snap.canUndo, false, 'history is frozen while a conflict is open')

  controller.keepMine() // explicit decision: overwrite on top of the latest revision
  assert.deepEqual(await controller.flush(), { ok: true })
  assert.equal(server.store.get(1).fixed_salary, '1.00')
  assert.equal(server.store.get(2).fixed_salary, '2.00')
  assert.equal(server.store.get(3).fixed_salary, '3.00', 'the queued op ran afterwards')
})

test('choosing the server values on a conflict drops my pending cells', async () => {
  const { server, controller } = setup()
  server.store.get(1).entry_revision = 4
  server.store.get(1).fixed_salary = '9.00'
  controller.edit([{ id: 1, field: 'fixed_salary', cents: 100 }])
  await controller.flush()
  controller.useServer()
  const snap = controller.getSnapshot()
  assert.equal(snap.rows[0].fixed_salary, 900)
  assert.equal(snap.status.phase, 'idle')
  assert.equal(server.store.get(1).fixed_salary, '9.00')
})

test('a failed request keeps the value on screen, is never shown as saved, and can be retried', async () => {
  const { server, controller } = setup()
  server.failNext(Object.assign(new Error('تعذّر الاتصال بالخادم'), { status: undefined }))
  controller.edit([{ id: 2, field: 'deduction', cents: 750 }])
  assert.deepEqual(await controller.flush(), { ok: false })
  let snap = controller.getSnapshot()
  assert.equal(snap.status.phase, 'failed')
  assert.equal(snap.rows[1].deduction, 750, 'entered value is not lost')
  assert.equal(snap.rows[1].states.deduction, 'failed')
  assert.equal(snap.status.savedAt, null, 'nothing is reported as saved')
  assert.equal(snap.status.failure.retryable, true)
  assert.equal(server.store.get(2).deduction, null)
  controller.retry()
  assert.deepEqual(await controller.flush(), { ok: true })
  snap = controller.getSnapshot()
  assert.equal(snap.status.phase, 'idle')
  assert.equal(server.store.get(2).deduction, '7.50')
})

test('a retry after a lost response is recognised as already saved, not as a conflict', async () => {
  const { server, controller } = setup()
  const original = server.save.bind(server)
  let lost = true
  const flaky = new PayrollSheetController({
    saveAmounts: async changes => {
      const rows = await original(changes)
      if (lost) { lost = false; throw Object.assign(new Error('network'), { status: undefined }) }
      return rows
    },
  })
  flaky.load([...server.store.values()])
  flaky.edit([{ id: 1, field: 'fixed_salary', cents: 4200 }, { id: 2, field: 'compensation', cents: 100 }])
  assert.deepEqual(await flaky.flush(), { ok: false })
  flaky.retry()
  assert.deepEqual(await flaky.flush(), { ok: true })
  assert.equal(flaky.getSnapshot().status.phase, 'idle')
  assert.equal(server.store.get(1).entry_revision, 2, 'written exactly once')
})

test('discarding a failed operation reverts to the server values', async () => {
  const { server, controller } = setup()
  server.failNext(Object.assign(new Error('boom'), { status: 500 }))
  controller.edit([{ id: 1, field: 'fixed_salary', cents: 100 }])
  await controller.flush()
  controller.useServer()
  assert.equal(controller.getSnapshot().rows[0].fixed_salary, null)
  assert.equal(controller.getSnapshot().status.phase, 'idle')
})

test('server-side validation errors surface as a non-retryable failure', async () => {
  const { server, controller } = setup()
  server.failNext(Object.assign(new Error('قيم غير صالحة'), { status: 422, errorCode: 'payroll_validation' }))
  controller.edit([{ id: 1, field: 'fixed_salary', cents: 100 }])
  await controller.flush()
  const { failure } = controller.getSnapshot().status
  assert.equal(failure.retryable, false)
  assert.equal(failure.message, 'قيم غير صالحة')
})

test('reloading rows (sort/filter) keeps unresolved pending values and ids stay stable', async () => {
  const { server, controller } = setup()
  server.failNext(Object.assign(new Error('x'), { status: 500 }))
  controller.edit([{ id: 2, field: 'fixed_salary', cents: 777 }])
  await controller.flush()
  // The same employees come back in a different order (sorted/filtered): the unsaved cell follows its id.
  controller.load([apiRow(3), apiRow(2), apiRow(1)])
  const snap = controller.getSnapshot()
  assert.deepEqual(snap.rows.map(r => r.id), [3, 2, 1])
  assert.equal(snap.rows[1].fixed_salary, 777)
  controller.retry()
  await controller.flush()
  assert.equal(server.store.get(2).fixed_salary, '7.77')
  assert.equal(server.store.get(1).fixed_salary, null)
  assert.equal(server.store.get(3).fixed_salary, null)
})
