import test from 'node:test'
import assert from 'node:assert/strict'
import { studentSearchLifecycle } from '../src/features/exam-board/lib/studentSearchLifecycle.js'

test('normalized whitespace edits preserve results, in-flight reads and applied pagination', () => {
  const search = studentSearchLifecycle()
  const first = search.change('  Student  ')
  const page = search.paginate(first, 2)
  const abort = new AbortController()
  search.bind(page, abort)
  assert.equal(search.change('Student'), null)
  assert.equal(search.change(' Student '), null)
  assert.equal(search.valid(page), true)
  assert.equal(page.page, 2)
  assert.equal(abort.signal.aborted, false)
})

test('clearing an in-flight search rejects its response and pagination immediately', async () => {
  const search = studentSearchLifecycle()
  const first = search.change('Student')
  const abort = new AbortController()
  search.bind(first, abort)
  let resolve, results = null
  const response = new Promise(r => { resolve = r }).then(data => { if (search.valid(first)) results = data })
  const empty = search.change('   ')
  assert.equal(empty.query, '')
  assert.equal(abort.signal.aborted, true)
  resolve(['obsolete']); await response
  assert.equal(results, null)
  assert.equal(search.paginate(first, 2), null)
  assert.equal(search.paginate(empty, 2), null)
})

test('delayed response during debounce cannot repopulate or clear the new intent state', async () => {
  const search = studentSearchLifecycle()
  const first = search.change('A')
  let resolve, results = null, loading = true, error = ''
  const response = new Promise(r => { resolve = r }).then(data => { if (search.valid(first)) results = data })
    .catch(() => { if (search.valid(first)) error = 'obsolete failure' })
    .finally(() => { if (search.valid(first)) loading = false })
  const next = search.change('B') // No applied/debounced B read yet.
  resolve(['A']); await response
  assert.equal(results, null)
  assert.equal(loading, true)
  assert.equal(error, '')
  assert.equal(search.valid(next), true)
  assert.equal(next.page, 1)
  assert.equal(search.paginate(first, 2), null)
  const returning = search.change('A')
  assert.equal(search.valid(first), false, 'return to the same string must not revive an old generation')
  assert.equal(search.valid(returning), true)
})
