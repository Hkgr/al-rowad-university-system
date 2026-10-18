import assert from 'node:assert/strict'
import test from 'node:test'
import {
  BULK_PREPARE_FULL_SUCCESS_PREFIX,
  BULK_PREPARE_REFRESH_WARNING,
  executeBulkPreparationPhases,
} from '../src/features/dean-dashboard/utils/deanOfferingPlanner.js'

const partialResult = {
  created_count: 1,
  existing_count: 0,
  failed_count: 1,
  items: [
    { program_course_id: 101, result: 'created' },
    { program_course_id: 301, result: 'failed', error_code: 'conflict' },
  ],
}

test('OPSRC-DEAN-11 mutation failure remains a preparation failure and skips refresh', async () => {
  const mutationError = new Error('write failed')
  let refreshCalls = 0
  const execution = await executeBulkPreparationPhases({
    mutate: async () => { throw mutationError },
    refresh: async () => { refreshCalls += 1 },
  })

  assert.equal(execution.kind, 'mutation-failed')
  assert.equal(execution.error, mutationError)
  assert.equal(execution.outcome, undefined)
  assert.equal(refreshCalls, 0)
})

test('OPSRC-DEAN-12 successful write plus refresh failure preserves retryable failures', async () => {
  let mutationCalls = 0
  let refreshCalls = 0
  const execution = await executeBulkPreparationPhases({
    mutate: async () => {
      mutationCalls += 1
      return partialResult
    },
    refresh: async () => {
      refreshCalls += 1
      throw new Error('GET failed')
    },
  })

  assert.equal(execution.kind, 'refresh-failed')
  assert.deepEqual(execution.outcome.draftIds, [301])
  assert.ok(execution.outcome.prepareErrors[301])
  assert.equal(mutationCalls, 1)
  assert.equal(refreshCalls, 1)
  assert.match(BULK_PREPARE_REFRESH_WARNING, /تم حفظ نتيجة التجهيز على الخادم/)
  assert.doesNotMatch(BULK_PREPARE_REFRESH_WARNING, /تعذّر تجهيز طروحات المواد/)
})

test('OPSRC-DEAN-13 successful write and refresh returns the normal authoritative outcome', async () => {
  const execution = await executeBulkPreparationPhases({
    mutate: async () => partialResult,
    refresh: async () => undefined,
  })

  assert.equal(execution.kind, 'refreshed')
  assert.deepEqual(execution.outcome.draftIds, [301])
  assert.ok(execution.outcome.prepareErrors[301])
  assert.equal(execution.outcome.notice.includes(BULK_PREPARE_FULL_SUCCESS_PREFIX), false)

  const fullSuccess = await executeBulkPreparationPhases({
    mutate: async () => ({
      created_count: 1,
      existing_count: 0,
      failed_count: 0,
      items: [{ program_course_id: 101, result: 'created' }],
    }),
    refresh: async () => undefined,
  })
  assert.deepEqual(fullSuccess.outcome.draftIds, [])
  assert.equal(fullSuccess.outcome.notice.includes(BULK_PREPARE_FULL_SUCCESS_PREFIX), true)
})
