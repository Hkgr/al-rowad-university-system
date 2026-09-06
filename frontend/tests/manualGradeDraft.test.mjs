import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createGradeDraft, gradeDraftReducer as reduce, hasGradeDraft, navigationDecision } from '../src/features/exam-board/lib/manualGradeDraft.js'
import { savePayload } from '../src/features/exam-board/lib/manualGradeEntry.js'

const row = (id, revision = 'a'.repeat(64), mark = null) => ({ registration_id: id, revision,
  components: [{ grade_component_id: id, mark, max_mark: 60 }] })
const edit = (draft, value) => reduce(draft, { type: 'edit', id: draft.baseline.registration_id, value })
const receive = (draft, server) => reduce(draft, { type: 'receive', row: server })

test('saving B and refreshing all rows preserves the original baseline and edits of A', () => {
  let a = edit(createGradeDraft(row(1)), '25')
  let b = edit(createGradeDraft(row(2)), '30')
  b = reduce(b, { type: 'saved', version: b.version, revision: b.baseline.revision, row: row(2, 'b'.repeat(64), 30) })
  a = receive(a, row(1))
  assert.equal(hasGradeDraft(b), false)
  assert.equal(a.edits[1], '25')
  assert.equal(a.baseline.revision, 'a'.repeat(64))
  assert.equal(a.conflict, false)
  assert.equal(savePayload(a.baseline, a.edits, true).components[0].mark, 25)
  // Even a refresh changing A cannot silently attach its draft to the new revision.
  a = receive(a, row(1, 'c'.repeat(64), 11))
  assert.equal(a.conflict, true)
  assert.equal(a.baseline.components[0].mark, null)
  assert.equal(a.server.components[0].mark, 11)
  assert.equal(a.edits[1], '25')
})

test('409 preserves proposals until explicit rebase or discard, without retrying a write', () => {
  const original = edit(createGradeDraft(row(1)), '25')
  const conflict = receive(reduce(original, { type: 'conflict' }), row(1, 'b'.repeat(64), 10))
  assert.equal(conflict.edits[1], '25')
  assert.equal(conflict.baseline, original.baseline)
  assert.equal(conflict.conflict, true)
  const rebased = reduce(conflict, { type: 'rebase' })
  assert.equal(rebased.edits[1], '25')
  assert.equal(rebased.baseline.revision, 'b'.repeat(64))
  assert.equal(rebased.conflict, false)
  assert.throws(() => savePayload(rebased.baseline, rebased.edits, true)) // new correction confirmation required
  const discarded = reduce(conflict, { type: 'discard' })
  assert.equal(hasGradeDraft(discarded), false)
  assert.equal(discarded.baseline.components[0].mark, 10)
})

test('same-revision uncertain writes still require a decision and removed components cannot be silently rebased', () => {
  let draft = edit(createGradeDraft(row(1)), '25')
  draft = receive(reduce(draft, { type: 'conflict' }), row(1))
  assert.equal(draft.conflict, true)
  draft = receive(draft, { ...row(1, 'b'.repeat(64)), components: [] })
  assert.equal(reduce(draft, { type: 'rebase' }), draft)
})

test('a successful response clears only the exact submitted draft', () => {
  const sent = edit(createGradeDraft(row(1)), '25')
  const newer = edit(sent, '26')
  const saved = { type: 'saved', version: sent.version, revision: sent.baseline.revision, row: row(1, 'b'.repeat(64), 25) }
  assert.equal(reduce(newer, saved), newer)
  assert.equal(hasGradeDraft(reduce(sent, saved)), false)
})

test('navigation cancellation is nonmutating and permits a subsequent save; pending writes block discard', () => {
  const draft = edit(createGradeDraft(row(1)), '25')
  for (const intent of ['sidebar', 'back', 'forward', 'approvals']) {
    assert.equal(navigationDecision({ authorized: true, dirty: hasGradeDraft(draft), pending: false }), 'confirm', intent)
    // Cancel does not dispatch a draft action or a blocking data error.
    assert.equal(savePayload(draft.baseline, draft.edits, true).components[0].mark, 25)
  }
  assert.equal(navigationDecision({ authorized: true, dirty: true, pending: true }), 'wait')
  assert.equal(navigationDecision({ authorized: false, dirty: true, pending: true }), 'allow')
})

test('static integration: real router blocker, separate errors, explicit draft decisions', () => {
  const read = path => readFileSync(new URL(path, import.meta.url), 'utf8')
  const page = read('../src/features/exam-board/pages/ManualGradeEntryPage.jsx')
  const app = read('../src/app/App.jsx')
  assert.match(app, /createBrowserRouter\(createRoutesFromElements\(/)
  assert.match(app, /RouterProvider router=\{router\}/)
  for (const text of ['useBlocker(', 'blocker.reset()', 'blocker.proceed()', 'pendingCount > 0', 'beforeunload', 'setNotice(', 'setLookupError(', 'setDataError(', "resolve('rebase')", "resolve('discard')", 'savePayload(baseline, edits', "dispatch({ type: 'conflict' })"]) assert.ok(page.includes(text), text)
  assert.match(page, /readOnly=\{loading \|\| !!dataError\}/)
  assert.ok(!page.includes('setEdits({})'))
  assert.ok(!page.includes('setError('))
  assert.ok(!page.includes('change(() => reload())'))
})
