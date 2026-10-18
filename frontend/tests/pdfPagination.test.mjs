import assert from 'node:assert/strict'
import test from 'node:test'
import { assertPageFits, paginateMeasuredSections } from '../src/utils/pdfPagination.js'

function table(rows, overrides = {}) {
  return {
    kind: 'table',
    id: 'term-1',
    headingHeight: 12,
    continuationHeadingHeight: 12,
    headerHeight: 8,
    rows: rows.map(([id, height]) => ({ id, height })),
    ...overrides,
  }
}

test('variable-height Arabic rows remain atomic and overflow to a new page', () => {
  const pages = paginateMeasuredSections({
    contentHeight: 100,
    sections: [table([['قصير', 30], ['سطر عربي طويل متعدد الأسطر', 55]])],
  })

  assert.equal(pages.length, 2)
  assert.deepEqual(pages[0].fragments[0].rowIds, ['قصير'])
  assert.deepEqual(pages[1].fragments[0].rowIds, ['سطر عربي طويل متعدد الأسطر'])
  assert.equal(pages[1].fragments[0].continuation, true)
})

test('a table heading, column header, and first row are never orphaned', () => {
  const pages = paginateMeasuredSections({
    contentHeight: 100,
    sections: [
      { kind: 'atomic', id: 'identity', height: 75 },
      table([['first-row', 12]]),
    ],
  })

  assert.equal(pages.length, 2)
  assert.deepEqual(pages[0].fragments.map(fragment => fragment.id), ['identity'])
  assert.deepEqual(pages[1].fragments[0].rowIds, ['first-row'])
})

test('continued tables repeat semantic headers and produce deterministic three-page numbering input', () => {
  const document = {
    contentHeight: 80,
    sections: [table([['r1', 60], ['r2', 60], ['r3', 60]])],
  }
  const first = paginateMeasuredSections(document)
  const second = paginateMeasuredSections(document)

  assert.deepEqual(first, second)
  assert.equal(first.length, 3)
  assert.deepEqual(first.map(page => page.fragments[0].continuation), [false, true, true])
  assert.deepEqual(first.map((_, index) => `صفحة ${index + 1} من ${first.length}`), [
    'صفحة 1 من 3', 'صفحة 2 من 3', 'صفحة 3 من 3',
  ])
})

test('reserved footer height reduces content capacity before pagination', () => {
  const fullSheet = paginateMeasuredSections({
    contentHeight: 100,
    sections: [table([['r1', 35], ['r2', 35]])],
  })
  const footerReserved = paginateMeasuredSections({
    contentHeight: 80,
    sections: [table([['r1', 35], ['r2', 35]])],
  })

  assert.equal(fullSheet.length, 1)
  assert.equal(footerReserved.length, 2)
})

test('summary and disclaimer/signature groups move intact', () => {
  const pages = paginateMeasuredSections({
    contentHeight: 100,
    sections: [
      { kind: 'atomic', id: 'terms', height: 70 },
      { kind: 'keepTogether', id: 'summary', height: 40 },
      { kind: 'keepTogether', id: 'disclaimer-signatures', height: 55 },
    ],
  })

  assert.deepEqual(pages.map(page => page.fragments.map(fragment => fragment.id)), [
    ['terms'], ['summary', 'disclaimer-signatures'],
  ])
})

test('keep-with-next blocks reserve their dependent first item', () => {
  const pages = paginateMeasuredSections({
    contentHeight: 100,
    sections: [
      { kind: 'atomic', id: 'prior', height: 75 },
      { kind: 'atomic', id: 'heading', height: 15, keepWithNextHeight: 15 },
      { kind: 'atomic', id: 'first-item', height: 15 },
    ],
  })
  assert.deepEqual(pages.map(page => page.fragments.map(fragment => fragment.id)), [
    ['prior'], ['heading', 'first-item'],
  ])
})

test('atomic blocks and rows that cannot fit a full usable page fail closed', () => {
  assert.throws(
    () => paginateMeasuredSections({ contentHeight: 100, sections: [{ kind: 'atomic', id: 'oversized', height: 101 }] }),
    error => error.code === 'pdf_atomic_block_too_tall',
  )
  assert.throws(
    () => paginateMeasuredSections({ contentHeight: 100, sections: [table([['oversized-row', 81]])] }),
    error => error.code === 'pdf_atomic_block_too_tall',
  )
})

test('overflow verification rejects clipping with only a two-pixel rounding tolerance', () => {
  assert.doesNotThrow(() => assertPageFits({ clientHeight: 100, scrollHeight: 102 }))
  assert.throws(
    () => assertPageFits({ clientHeight: 100, scrollHeight: 103 }),
    error => error.code === 'pdf_page_overflow',
  )
})
