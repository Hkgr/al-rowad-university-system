import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import * as D from '../src/features/owner-portal/lib/payrollDecimal.js'
import { formatAmount, formatSyp, formatValue, parseInput, editText, pctText } from '../src/features/owner-portal/lib/payrollMoney.js'
import { createCalculator } from '../src/features/owner-portal/lib/payrollFormula.js'
import { matrixToClipboardText, parseClipboardText, planPaste } from '../src/features/owner-portal/lib/payrollClipboard.js'
import { PayrollSheetController } from '../src/features/owner-portal/lib/payrollSheetController.js'
import { buildColumns, buildSheetQuery, emptyFilters, filtersFromParams, rowStatus } from '../src/features/owner-portal/lib/payrollView.js'

const fixture = name => JSON.parse(readFileSync(new URL(`../../backend/tests/Fixtures/${name}`, import.meta.url), 'utf8'))
const vectors = fixture('payroll_formula_vectors.json')
const template = fixture('payroll_template_reference.json')

// ── exact decimals ──────────────────────────────────────────────────────────

test('decimal arithmetic is exact and rounds half away from zero', () => {
  const d = D.parseDec
  assert.equal(D.toFixed(D.plus(d('0.1'), d('0.2')), 2), '0.30', 'no binary float drift')
  assert.equal(D.toPlain(D.times(d('96600'), d('0.07'))), '6762')
  assert.equal(D.toFixed(d('0.005'), 2), '0.01')
  assert.equal(D.toFixed(d('-0.005'), 2), '-0.01')
  assert.equal(D.toFixed(d('2.5'), 0), '3')
  assert.equal(D.toFixed(d('-2.5'), 0), '-3')
  assert.equal(D.toFixed(D.divide(d('1'), d('3')), 10), '0.3333333333')
  assert.equal(D.toFixed(D.divide(d('-1'), d('200')), 2), '-0.01')
  assert.equal(D.toPlain(D.movePointLeft(d('15'), 2)), '0.15')
  assert.equal(D.compare(d('1.10'), d('1.1')), 0)
  assert.equal(D.toPlain(d('-0.00')), '0')
  assert.throws(() => d('1e3'))
  assert.throws(() => d('1,5'))
})

// ── input parsing and Syrian-pound formatting ───────────────────────────────

const amount = { value_type: 'amount', allow_negative: false }
const signed = { value_type: 'amount', allow_negative: true }

test('amounts parse to exact decimal text; blank stays blank; zero stays zero', () => {
  assert.deepEqual(parseInput(amount, ''), { ok: true, value: null })
  assert.deepEqual(parseInput(amount, '   '), { ok: true, value: null })
  assert.deepEqual(parseInput(amount, null), { ok: true, value: null })
  assert.deepEqual(parseInput(amount, '0'), { ok: true, value: '0.00' })
  assert.deepEqual(parseInput(amount, '1234.5'), { ok: true, value: '1234.50' })
  assert.deepEqual(parseInput(amount, '0.10'), { ok: true, value: '0.10' })
  assert.deepEqual(parseInput(amount, '999999999.99'), { ok: true, value: '999999999.99' })
  assert.deepEqual(parseInput(signed, '-1500.50'), { ok: true, value: '-1500.50' })
})

test('pasted spreadsheet formatting and the Syrian-pound symbol are tolerated; ambiguous or unsafe text is rejected', () => {
  assert.equal(parseInput(amount, '1,234.50').value, '1234.50')
  assert.equal(parseInput(amount, '1,234.50 ل.س').value, '1234.50')
  assert.equal(parseInput(amount, '١٢٣٫٤٥').value, '123.45', 'Arabic-Indic digits and decimal separator')
  assert.equal(parseInput(amount, '1,234').value, '1234.00')
  assert.equal(parseInput(signed, '(5.00)').value, '-5.00', 'accounting negative, where negatives are allowed')
  for (const bad of ['1e3', '12.345', 'abc', '1,5', '1.2.3', '+5', '.5', '5.', '1 000', '0x10', 'NaN', 'Infinity', '12,34.5', '$5', '5 USD']) {
    assert.equal(parseInput(amount, bad).ok, false, bad)
  }
  for (const negative of ['-1', '-0.01', '(5.00)']) assert.equal(parseInput(amount, negative).error, 'negative', negative)
  assert.equal(parseInput(amount, '1000000000').error, 'range')
})

test('percent columns take points (7 or 7%) and store a fraction; numbers and text have their own rules', () => {
  const percent = { value_type: 'percent', allow_negative: false }
  assert.equal(parseInput(percent, '7').value, '0.07')
  assert.equal(parseInput(percent, '7%').value, '0.07')
  assert.equal(parseInput(percent, '85.5 %').value, '0.855')
  assert.equal(parseInput(percent, '100').value, '1')
  assert.equal(parseInput(percent, '101').ok, false)
  assert.equal(parseInput(percent, '-1').ok, false)
  assert.equal(pctText(D.parseDec('0.0725')), '7.25')
  assert.equal(editText('percent', '0.07'), '7')
  const number = { value_type: 'number', allow_negative: false }
  assert.equal(parseInput(number, '22.5').value, '22.5')
  assert.equal(parseInput(number, '1.1234567').ok, false, 'six decimals at most')
  const text = { value_type: 'text' }
  assert.deepEqual(parseInput(text, '  ملاحظة   طويلة '), { ok: true, value: 'ملاحظة طويلة' })
  assert.equal(parseInput(text, 'x'.repeat(256)).ok, false)
  assert.equal(parseInput(text, '=1+1').value, '=1+1', 'user text is literal')
})

test('formatting: Syrian pounds, grouping, signed negatives, exact two decimals; no dollar sign anywhere', () => {
  assert.equal(formatAmount(null), '')
  assert.equal(formatAmount('0'), '0.00')
  assert.equal(formatAmount('1234567.891'), '1,234,567.89')
  assert.equal(formatAmount('-150.5'), '-150.50')
  assert.equal(formatSyp('125166.30'), '125,166.30 ل.س')
  assert.equal(formatSyp('-150.5'), '-150.50 ل.س')
  assert.equal(formatSyp(null), '')
  assert.equal(formatValue('percent', '0.07'), '7%')
  assert.equal(formatValue('number', '1234.5'), '1,234.5')
  for (const text of [formatSyp('1'), formatValue('amount', '1'), formatAmount('1')]) assert.doesNotMatch(text, /\$|USD/)
})

// ── the same calculations as the server ─────────────────────────────────────

test('the browser engine reproduces every shared formula vector', () => {
  for (const c of vectors.cases) {
    const columns = vectors.columns.map(col => ({ ...col, kind: 'input', warn_negative: false, ast: null, references: [] }))
    columns.push({ key: 'out', label: 'ناتج', kind: 'formula', value_type: c.value_type, ast: c.ast, references: [], blank_as_zero: false, warn_negative: false })
    const cell = createCalculator({ columns, settings: vectors.settings }).evaluateRow({ ...c.inputs }).out
    assert.equal(cell.st ?? 'ok', c.state, c.name)
    assert.equal(cell.v, c.value, c.name)
  }
})

test('the workbook template evaluates exactly like the server, case by case (reference case: 125,166.30)', () => {
  const calculator = createCalculator(template.config)
  const inputs = ['fixed_salary', 'salary_adjustment', 'compensation', 'compensation_adjustment', 'other_deductions']
  for (const c of template.cases) {
    const given = Object.fromEntries(inputs.map(key => [key, c.inputs[key] ?? null]))
    const cells = calculator.evaluateRow(given)
    for (const [key, expected] of Object.entries(c.cells)) assert.deepEqual(cells[key], expected, `${c.name} / ${key}`)
  }
  const reference = template.cases.find(c => c.name === 'reference')
  assert.equal(reference.cells.total_net_payable.v, '125166.30')
  assert.equal(reference.cells.total_deductions.v, '26633.70')
})

test('a negative taxable base is flagged, never floored; unavailable results are not zero', () => {
  const calculator = createCalculator(template.config)
  const cells = calculator.evaluateRow({ fixed_salary: '10000' })
  assert.equal(cells.salary_taxable_base.v, '-3260.00')
  assert.equal(cells.salary_taxable_base.st, 'warning')
  assert.equal(cells.salary_tax.v, '-489.00')
  const missing = calculator.evaluateRow({ compensation: '500' })
  assert.equal(missing.total_net_payable.v, null)
  assert.equal(missing.total_net_payable.st, 'missing')
  assert.match(missing.total_net_payable.m, /الأجر المقطوع/)
  assert.equal(rowStatus({ cells: missing }), 'incomplete')
  assert.equal(rowStatus({ cells }), 'warning')
  assert.equal(rowStatus({ cells: calculator.evaluateRow({ fixed_salary: '96600' }) }), 'complete')
})

// ── view state ──────────────────────────────────────────────────────────────

test('the academic-level blank filter is a flag of its own and never a magic string', () => {
  const f = { ...emptyFilters(), academic_level_blank: true }
  assert.equal(buildSheetQuery(f), 'sort=employee_number&direction=asc&academic_level_blank=1')
  assert.equal(buildSheetQuery({ ...emptyFilters(), academic_level: '__blank__' }), 'academic_level=__blank__&sort=employee_number&direction=asc', 'a level that reads __blank__ is an ordinary value')
  assert.deepEqual(filtersFromParams('body_id=3&workplace=afrin&completeness=incomplete&evil=1'), { ...emptyFilters(), body_id: '3', workplace: 'afrin', completeness: 'incomplete' })
  assert.deepEqual(filtersFromParams('body_id=abc&workplace=mars&completeness=x'), emptyFilters())
})

test('columns follow the saved configuration: groups, visibility, compact view', () => {
  const full = buildColumns(template.config, { compact: false })
  const compact = buildColumns(template.config, { compact: true })
  assert.equal(full.filter(c => c.identity).length, 6)
  assert.deepEqual(compact.filter(c => c.identity).map(c => c.prop), ['employee_number', 'full_name', 'body_name', 'workplace_label'])
  assert.ok(full.length > compact.length)
  const keys = compact.filter(c => !c.identity).map(c => c.key)
  assert.deepEqual(keys, ['fixed_salary', 'compensation', 'other_deductions', 'total_deductions', 'total_net_payable'], 'major inputs, total deductions and total net payable')
  assert.equal(full.find(c => c.key === 'salary_tax').computed, true)
  assert.equal(full.find(c => c.key === 'fixed_salary').editable, true)
  const hidden = { ...template.config, columns: template.config.columns.map(c => (c.key === 'insurance' ? { ...c, visible_grid: false } : c)) }
  assert.equal(buildColumns(hidden).some(c => c.key === 'insurance'), false)
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
  { prop: 'fixed_salary', title: 'الأجر المقطوع', editable: true, meta: { value_type: 'amount', allow_negative: false } },
  { prop: 'salary_adjustment', title: 'فروقات الراتب', editable: true, meta: { value_type: 'amount', allow_negative: true } },
  { prop: 'note', title: 'ملاحظات', editable: true, meta: { value_type: 'text' } },
  { prop: 'attendance', title: 'الحضور', editable: true, meta: { value_type: 'percent', allow_negative: false } },
  { prop: 'salary_entitlement', title: 'الراتب المستحق', editable: false },
]
const current = () => null
const plan = (matrix, anchor, extra = {}) => planPaste({ matrix, anchor, columns: COLUMNS, rowIds: [11, 12, 13], currentValue: current, ...extra })

test('multi-cell paste maps logical columns, stable row ids and each column type', () => {
  const { changes, errors } = plan([['100', '-5', 'جيد', '85%'], ['200.5', '', '', '']], { row: 1, col: 2 })
  assert.deepEqual(errors, [])
  assert.deepEqual(changes.map(c => [c.id, c.key, c.value]), [
    [12, 'fixed_salary', '100.00'], [12, 'salary_adjustment', '-5.00'], [12, 'note', 'جيد'], [12, 'attendance', '0.85'],
    [13, 'fixed_salary', '200.50'],
  ], 'blank over blank is not a change; percentages become fractions')
  const clearing = planPaste({ matrix: [['', '5']], anchor: { row: 0, col: 2 }, columns: COLUMNS, rowIds: [11], currentValue: (id, key) => (key === 'fixed_salary' ? '900.00' : null) })
  assert.deepEqual(clearing.changes.map(c => [c.key, c.value]), [['fixed_salary', null], ['salary_adjustment', '5.00']], 'a blank pasted over a value clears it')
})

test('an invalid paste names the cells and commits nothing; signed columns accept negatives, others do not', () => {
  const result = plan([['100', 'abc'], ['-5', '2']], { row: 0, col: 2 })
  assert.deepEqual(result.changes, [])
  assert.deepEqual(result.errors.map(e => [e.row, e.col, e.kind]), [[0, 3, 'invalid'], [1, 2, 'invalid']])
})

test('metadata and calculated columns are protected from paste (whole batch refused)', () => {
  for (const col of [0, 1, 6]) {
    const { changes, errors } = plan([['1']], { row: 0, col })
    assert.deepEqual(changes, [])
    assert.equal(errors[0].kind, 'protected')
  }
  const spill = plan([['1', '2', 'x', '4', '5']], { row: 0, col: 2 })
  assert.deepEqual(spill.changes, [])
  assert.deepEqual(spill.errors.map(e => [e.col, e.kind]), [[6, 'protected']], 'a block that runs into the calculated column is refused entirely')
  const pastTheEnd = plan([['1', '2', '3', '4', '5']], { row: 0, col: 3 })
  assert.deepEqual(pastTheEnd.changes, [])
  assert.deepEqual(pastTheEnd.errors.map(e => [e.col, e.kind]), [[6, 'protected'], [7, 'columns']])
})

test('paste beyond the last row is refused; a single value fills a selected range; unchanged cells are skipped', () => {
  assert.equal(plan([['1'], ['2']], { row: 2, col: 2 }).errors[0].kind, 'rows')
  const fill = plan([['9']], { row: 0, col: 2 }, { selection: { row: 0, col: 2, rows: 3, cols: 2 } })
  assert.equal(fill.changes.length, 6)
  assert.deepEqual([...new Set(fill.changes.map(c => c.value))], ['9.00'])
  assert.equal(plan([], { row: 0, col: 2 }).errors[0].kind, 'empty')
  const unchanged = planPaste({ matrix: [['5']], anchor: { row: 0, col: 2 }, columns: COLUMNS, rowIds: [11], currentValue: () => '5.00' })
  assert.deepEqual(unchanged, { changes: [], errors: [] })
})

// ── controller ──────────────────────────────────────────────────────────────

const config = template.config
const calculator = createCalculator(config)
const INPUT_KEYS = config.columns.filter(c => c.kind === 'input').map(c => c.key)

const apiRow = (id, inputs = {}, revision = 1, calc = calculator) => ({
  id, employee_number: String(id).padStart(3, '0'), full_name: `موظف ${id}`, job_title: 'x', body_id: 1, body_name: 'هيئة', body_is_active: true,
  workplace: 'afrin', workplace_other: null, workplace_label: 'عفرين', academic_level: null, employee_revision: 1,
  cells: calc.evaluateRow(Object.fromEntries(INPUT_KEYS.map(k => [k, inputs[k] ?? null]))), entry_revision: revision,
})

/** In-memory server with the real contract: atomic batches, row revisions, a configuration revision, 422 for bad values. */
function fakeServer(count) {
  const store = new Map(Array.from({ length: count }, (_, i) => [i + 1, { inputs: {}, revision: 1 }]))
  const log = []
  const state = { fail: null, configRevision: config.revision, config }
  const rowOf = id => { const r = store.get(id); return apiRow(id, r.inputs, r.revision, createCalculator(state.config)) }
  const server = {
    store, log, state,
    failNext(error) { state.fail = error },
    rows: () => [...store.keys()].map(rowOf),
    rowOf,
    async save(changes, configRevision) {
      log.push({ changes: JSON.parse(JSON.stringify(changes)), configRevision })
      if (state.fail) { const error = state.fail; state.fail = null; throw error }
      if (configRevision !== state.configRevision) {
        throw Object.assign(new Error('config changed'), { status: 409, errorCode: 'payroll_config_conflict', details: { config: state.config } })
      }
      const stale = changes.filter(c => store.get(c.employee_id).revision !== c.expected_revision)
      if (stale.length) throw Object.assign(new Error('conflict'), { status: 409, errorCode: 'payroll_conflict', details: { conflicts: stale.map(c => ({ employee_id: c.employee_id, current: rowOf(c.employee_id) })) } })
      return changes.map(c => {
        const row = store.get(c.employee_id)
        for (const [key, value] of Object.entries(c.values)) { if (value === null) delete row.inputs[key]; else row.inputs[key] = value }
        row.revision += 1
        return rowOf(c.employee_id)
      })
    },
  }
  return server
}
const setup = (n = 3) => {
  const server = fakeServer(n)
  const controller = new PayrollSheetController({
    saveValues: (changes, revision) => server.save(changes, revision),
    reload: async () => ({ rows: server.rows(), config: server.state.config }),
  })
  controller.load(server.rows(), config)
  return { server, controller }
}
const cell = (controller, index, key) => controller.getSnapshot().rows[index].cells[key]

test('an edit shows immediately, recalculates calculated columns and totals locally, then saves and clears the pending state', async () => {
  const { server, controller } = setup()
  assert.equal(cell(controller, 0, 'total_net_payable').st, 'missing')
  controller.edit([{ id: 1, key: 'fixed_salary', value: '96600.00' }, { id: 1, key: 'compensation', value: '55200.00' }])
  const during = controller.getSnapshot()
  assert.equal(during.rows[0].cells.total_net_payable.v, '125166.30', 'local recalculation before the server answers')
  assert.equal(during.totals.columns.total_net_payable.sum, '125166.30')
  assert.equal(during.totals.columns.total_net_payable.excluded, 2, 'unavailable rows are excluded and counted, never zero')
  assert.equal(during.totals.incomplete, 2)
  assert.equal(during.status.phase, 'saving')
  assert.equal(during.rows[0].states.fixed_salary, 'saving')
  assert.deepEqual(await controller.flush(), { ok: true })
  const after = controller.getSnapshot()
  assert.equal(after.status.phase, 'idle')
  assert.equal(after.rows[0].states.fixed_salary, undefined)
  assert.equal(server.store.get(1).inputs.fixed_salary, '96600.00')
  assert.equal(server.store.get(1).revision, 2)
  assert.equal(after.rows[0].cells.salary_adjustment.v, null, 'untouched cells stay blank, not zero')
  assert.equal(server.log[0].configRevision, config.revision, 'each save carries the configuration revision it was calculated under')
})

test('a multi-cell operation is one request keyed by employee id and revision; no-op edits send nothing', async () => {
  const { server, controller } = setup()
  assert.equal(controller.edit([{ id: 2, key: 'other_deductions', value: null }]), null)
  assert.equal(controller.edit([{ id: 2, key: 'total_net_payable', value: '5.00' }]), null, 'a calculated cell cannot be edited')
  controller.edit([{ id: 3, key: 'fixed_salary', value: '5.00' }, { id: 1, key: 'fixed_salary', value: '1.00' }, { id: 3, key: 'compensation', value: '0.00' }])
  await controller.flush()
  assert.equal(server.log.length, 1)
  assert.deepEqual(server.log[0].changes, [
    { employee_id: 3, expected_revision: 1, values: { fixed_salary: '5.00', compensation: '0.00' } },
    { employee_id: 1, expected_revision: 1, values: { fixed_salary: '1.00' } },
  ])
  assert.equal(cell(controller, 2, 'compensation').v, '0.00', 'explicit zero kept, distinct from blank')
})

test('consecutive edits of one row chain the returned revision', async () => {
  const { server, controller } = setup()
  controller.edit([{ id: 1, key: 'fixed_salary', value: '1.00' }])
  controller.edit([{ id: 1, key: 'other_deductions', value: '0.50' }])
  controller.edit([{ id: 1, key: 'fixed_salary', value: '3.00' }])
  assert.deepEqual(await controller.flush(), { ok: true })
  assert.deepEqual(server.log.map(l => l.changes[0].expected_revision), [1, 2, 3])
  assert.equal(server.store.get(1).inputs.fixed_salary, '3.00')
})

test('undo and redo (including a pasted range) go through the server like any edit', async () => {
  const { server, controller } = setup()
  controller.edit([{ id: 1, key: 'fixed_salary', value: '10.00' }, { id: 2, key: 'fixed_salary', value: '20.00' }, { id: 3, key: 'fixed_salary', value: '30.00' }])
  await controller.flush()
  controller.edit([{ id: 2, key: 'fixed_salary', value: '25.00' }])
  await controller.flush()
  assert.notEqual(controller.undo(), null)
  await controller.flush()
  assert.equal(server.store.get(2).inputs.fixed_salary, '20.00')
  assert.notEqual(controller.undo(), null)
  await controller.flush()
  assert.deepEqual([1, 2, 3].map(id => server.store.get(id).inputs.fixed_salary ?? null), [null, null, null], 'the whole pasted range is undone as ONE step')
  assert.equal(controller.getSnapshot().canUndo, false)
  controller.redo()
  await controller.flush()
  assert.deepEqual([1, 2, 3].map(id => server.store.get(id).inputs.fixed_salary), ['10.00', '20.00', '30.00'])
  controller.edit([{ id: 1, key: 'other_deductions', value: '1.00' }])
  assert.equal(controller.getSnapshot().canRedo, false, 'a new edit clears redo')
})

test('a stale row revision becomes an explicit conflict that keeps the pending values and halts the queue', async () => {
  const { server, controller } = setup()
  server.store.get(1).revision = 5 // someone else saved row 1 in the meantime
  server.store.get(1).inputs.fixed_salary = '9.00'
  controller.edit([{ id: 1, key: 'fixed_salary', value: '1.00' }, { id: 2, key: 'fixed_salary', value: '2.00' }])
  controller.edit([{ id: 3, key: 'fixed_salary', value: '3.00' }])
  assert.deepEqual(await controller.flush(), { ok: false })
  const snap = controller.getSnapshot()
  assert.equal(snap.status.phase, 'conflict')
  assert.equal(snap.rows[0].cells.fixed_salary.v, '1.00', 'my pending value is preserved, not overwritten')
  assert.equal(snap.rows[0].states.fixed_salary, 'conflict')
  assert.equal(snap.status.conflict.rows[0].cells.fixed_salary.v, '9.00')
  assert.equal(server.store.get(2).inputs.fixed_salary, undefined, 'nothing from the batch was written')
  assert.equal(server.log.length, 1, 'the following queued operation did not run')
  assert.equal(snap.canUndo, false, 'history is frozen while a conflict is open')

  controller.keepMine() // explicit decision: overwrite on top of the latest revision
  assert.deepEqual(await controller.flush(), { ok: true })
  assert.deepEqual([1, 2, 3].map(id => server.store.get(id).inputs.fixed_salary), ['1.00', '2.00', '3.00'], 'the queued op ran afterwards')
})

test('choosing the server values on a conflict reloads the rows and drops my pending cells', async () => {
  const { server, controller } = setup()
  server.store.get(1).revision = 4
  server.store.get(1).inputs.fixed_salary = '9.00'
  controller.edit([{ id: 1, key: 'fixed_salary', value: '1.00' }])
  await controller.flush()
  assert.deepEqual(await controller.useServer(), { ok: true })
  const snap = controller.getSnapshot()
  assert.equal(snap.rows[0].cells.fixed_salary.v, '9.00')
  assert.equal(snap.status.phase, 'idle')
})

test('a failed request keeps the value on screen, is never shown as saved, and can be retried', async () => {
  const { server, controller } = setup()
  server.failNext(Object.assign(new Error('تعذّر الاتصال بالخادم'), { status: undefined }))
  controller.edit([{ id: 2, key: 'other_deductions', value: '7.50' }])
  assert.deepEqual(await controller.flush(), { ok: false })
  let snap = controller.getSnapshot()
  assert.equal(snap.status.phase, 'failed')
  assert.equal(snap.rows[1].cells.other_deductions.v, '7.50', 'entered value is not lost')
  assert.equal(snap.rows[1].states.other_deductions, 'failed')
  assert.equal(snap.status.savedAt, null, 'nothing is reported as saved')
  assert.equal(snap.status.failure.retryable, true)
  controller.retry()
  assert.deepEqual(await controller.flush(), { ok: true })
  snap = controller.getSnapshot()
  assert.equal(snap.status.phase, 'idle')
  assert.equal(server.store.get(2).inputs.other_deductions, '7.50')
})

test('a retry after a lost response is recognised as already saved, not as a conflict', async () => {
  const { server } = setup()
  let lost = true
  const flaky = new PayrollSheetController({
    saveValues: async (changes, revision) => {
      const rows = await server.save(changes, revision)
      if (lost) { lost = false; throw Object.assign(new Error('network'), { status: undefined }) }
      return rows
    },
    reload: async () => ({ rows: server.rows(), config }),
  })
  flaky.load(server.rows(), config)
  flaky.edit([{ id: 1, key: 'fixed_salary', value: '42.00' }, { id: 2, key: 'compensation', value: '1.00' }])
  assert.deepEqual(await flaky.flush(), { ok: false })
  flaky.retry()
  assert.deepEqual(await flaky.flush(), { ok: true })
  assert.equal(flaky.getSnapshot().status.phase, 'idle')
  assert.equal(server.store.get(1).revision, 2, 'written exactly once')
})

// Review finding 1: discarding after an uncertain failure must show what the SERVER holds, not what this browser last saw.
test('discarding after an uncertain failure reloads first: a save that actually succeeded is shown, not hidden behind stale values', async () => {
  const { server } = setup()
  let lostAnswer = true
  const controller = new PayrollSheetController({
    saveValues: async (changes, revision) => { const rows = await server.save(changes, revision); if (lostAnswer) { lostAnswer = false; throw Object.assign(new Error('timeout'), { status: undefined }) } return rows }, // saved, but the answer was lost
    reload: async () => ({ rows: server.rows(), config }),
  })
  controller.load(server.rows(), config)
  controller.edit([{ id: 1, key: 'fixed_salary', value: '77.00' }])
  assert.deepEqual(await controller.flush(), { ok: false })
  assert.equal(server.store.get(1).inputs.fixed_salary, '77.00', 'the server did save it')
  assert.equal(controller.getSnapshot().rows[0].revision, 1, 'this browser still believes revision 1')
  assert.deepEqual(await controller.useServer(), { ok: true })
  const row = controller.getSnapshot().rows[0]
  assert.equal(row.cells.fixed_salary.v, '77.00', 'authoritative value, not the stale blank')
  assert.equal(row.revision, 2, 'and the authoritative revision, so the next edit does not conflict with itself')
  controller.edit([{ id: 1, key: 'other_deductions', value: '1.00' }])
  assert.deepEqual(await controller.flush(), { ok: true })
})

test('if the reload fails, nothing is discarded and the problem stays visible', async () => {
  const { server } = setup()
  let reloadWorks = false
  const controller = new PayrollSheetController({
    saveValues: (changes, revision) => server.save(changes, revision),
    reload: async () => { if (!reloadWorks) throw Object.assign(new Error('offline'), {}); return { rows: server.rows(), config } },
  })
  controller.load(server.rows(), config)
  server.failNext(Object.assign(new Error('boom'), { status: 500 }))
  controller.edit([{ id: 1, key: 'fixed_salary', value: '5.00' }])
  await controller.flush()
  assert.deepEqual(await controller.useServer(), { ok: false })
  const snap = controller.getSnapshot()
  assert.equal(snap.status.phase, 'failed', 'still halted')
  assert.equal(snap.rows[0].cells.fixed_salary.v, '5.00', 'the pending value is still there')
  assert.match(snap.status.resolveError, /لم تُتجاهل تعديلاتك/)
  reloadWorks = true
  assert.deepEqual(await controller.useServer(), { ok: true })
  assert.equal(controller.getSnapshot().rows[0].cells.fixed_salary.v, null)
  assert.equal(controller.getSnapshot().status.resolveError, '')
})

test('a configuration change while editing is an explicit conflict: nothing saved, values kept, then adopted explicitly', async () => {
  const { server, controller } = setup()
  server.state.configRevision = config.revision + 1
  server.state.config = { ...config, revision: config.revision + 1, settings: config.settings.map(s => (s.key === 'insurance_rate' ? { ...s, value: '0.1' } : s)) }
  controller.edit([{ id: 1, key: 'fixed_salary', value: '96600.00' }])
  assert.deepEqual(await controller.flush(), { ok: false })
  let snap = controller.getSnapshot()
  assert.equal(snap.status.phase, 'config_conflict')
  assert.equal(snap.rows[0].cells.fixed_salary.v, '96600.00', 'pending value kept')
  assert.equal(snap.rows[0].cells.insurance.v, '6762.00', 'still calculated under the old configuration until adopted')
  assert.equal(server.store.get(1).inputs.fixed_salary, undefined, 'nothing was written')
  const result = await controller.adoptConfigAndRetry()
  assert.deepEqual(result.dropped, [])
  assert.deepEqual(await controller.flush(), { ok: true })
  snap = controller.getSnapshot()
  assert.equal(snap.config.revision, config.revision + 1)
  assert.equal(snap.rows[0].cells.insurance.v, '9660.00', 'recalculated under the adopted settings')
  assert.equal(server.log.at(-1).configRevision, config.revision + 1)
})

test('adopting a configuration drops values for columns that no longer accept input and says which', async () => {
  const { server, controller } = setup()
  const custom = { ...config.columns.find(c => c.key === 'compensation'), key: 'c_aaaaaaaaaa', label: 'بدل', is_system: false, id: 99 }
  const withCustom = { ...config, columns: [...config.columns, custom] }
  controller.load(server.rows(), withCustom)
  const next = { ...config, revision: config.revision + 1 } // the custom column was deleted meanwhile
  server.state.configRevision = next.revision
  server.state.config = next
  controller.edit([{ id: 1, key: 'c_aaaaaaaaaa', value: '5.00' }, { id: 1, key: 'fixed_salary', value: '10.00' }])
  assert.deepEqual(await controller.flush(), { ok: false })
  const result = await controller.adoptConfigAndRetry()
  assert.deepEqual(result.dropped, ['c_aaaaaaaaaa'])
  assert.deepEqual(await controller.flush(), { ok: true })
  assert.equal(server.store.get(1).inputs.fixed_salary, '10.00')
  assert.equal(server.store.get(1).inputs.c_aaaaaaaaaa, undefined)
})

test('server-side validation errors surface as a non-retryable failure', async () => {
  const { server, controller } = setup()
  server.failNext(Object.assign(new Error('قيم غير صالحة'), { status: 422, errorCode: 'payroll_validation' }))
  controller.edit([{ id: 1, key: 'fixed_salary', value: '1.00' }])
  await controller.flush()
  const { failure } = controller.getSnapshot().status
  assert.equal(failure.retryable, false)
  assert.equal(failure.message, 'قيم غير صالحة')
})

test('reloading rows (sort/filter) keeps unresolved pending values; ids stay stable; edited-from revisions are kept so a foreign change is still caught', async () => {
  const { server, controller } = setup()
  server.failNext(Object.assign(new Error('x'), { status: 500 }))
  controller.edit([{ id: 2, key: 'fixed_salary', value: '7.77' }])
  await controller.flush()
  // Meanwhile somebody else changes employee 2; the same employees come back in a different order (sorted/filtered).
  server.store.get(2).inputs.compensation = '1.00'
  server.store.get(2).revision = 2
  controller.load([server.rowOf(3), server.rowOf(2), server.rowOf(1)], config)
  const snap = controller.getSnapshot()
  assert.deepEqual(snap.rows.map(r => r.id), [3, 2, 1])
  assert.equal(snap.rows[1].cells.fixed_salary.v, '7.77', 'the unsaved cell follows its id')
  controller.retry()
  await controller.flush()
  assert.equal(controller.getSnapshot().status.phase, 'conflict', 'the retry still carries revision 1 and is reported as a conflict instead of silently overwriting')
})
