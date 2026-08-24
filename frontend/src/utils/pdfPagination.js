export class PdfPaginationError extends Error {
  constructor(code, message) {
    super(message)
    this.name = 'PdfPaginationError'
    this.code = code
  }
}

function positiveHeight(value, label, { allowZero = false } = {}) {
  const height = Number(value)
  if (!Number.isFinite(height) || (allowZero ? height < 0 : height <= 0)) {
    throw new PdfPaginationError('pdf_invalid_measurement', `${label} has an invalid measured height.`)
  }
  return height
}

function newPage() {
  return { usedHeight: 0, fragments: [] }
}

export function assertPageFits(box, tolerance = 2) {
  const clientHeight = Number(box?.clientHeight)
  const scrollHeight = Number(box?.scrollHeight)
  if (!Number.isFinite(clientHeight) || !Number.isFinite(scrollHeight)) {
    throw new PdfPaginationError('pdf_invalid_measurement', 'A rendered PDF page has invalid dimensions.')
  }
  if (scrollHeight > clientHeight + tolerance) {
    throw new PdfPaginationError('pdf_page_overflow', 'A rendered PDF page exceeds its reserved height.')
  }
}

export function paginateMeasuredSections({ sections, contentHeight }) {
  const pageHeight = positiveHeight(contentHeight, 'contentHeight')
  if (!Array.isArray(sections)) {
    throw new PdfPaginationError('pdf_invalid_sections', 'PDF sections must be an array.')
  }

  const pages = [newPage()]
  const current = () => pages[pages.length - 1]
  const remaining = () => pageHeight - current().usedHeight
  const advance = () => {
    if (current().fragments.length === 0) return current()
    pages.push(newPage())
    return current()
  }

  const appendAtomic = section => {
    const height = positiveHeight(section.height, `section ${section.id}`)
    const keepWithNextHeight = positiveHeight(
      section.keepWithNextHeight ?? 0,
      `section ${section.id} keepWithNextHeight`,
      { allowZero: true },
    )
    if (height > pageHeight || height + keepWithNextHeight > pageHeight) {
      throw new PdfPaginationError('pdf_atomic_block_too_tall', `Section ${section.id} cannot fit on one PDF page.`)
    }
    if (height + keepWithNextHeight > remaining()) advance()
    if (height > remaining()) advance()
    current().fragments.push({ kind: 'atomic', id: section.id, height })
    current().usedHeight += height
  }

  const appendTable = section => {
    const rows = Array.isArray(section.rows) ? section.rows : []
    if (rows.length === 0) {
      throw new PdfPaginationError('pdf_invalid_sections', `Table ${section.id} must contain at least one row.`)
    }
    const headingHeight = positiveHeight(section.headingHeight, `table ${section.id} heading`)
    const continuationHeadingHeight = positiveHeight(
      section.continuationHeadingHeight,
      `table ${section.id} continuation heading`,
    )
    const headerHeight = positiveHeight(section.headerHeight, `table ${section.id} header`)
    const measuredRows = rows.map(row => ({
      ...row,
      height: positiveHeight(row.height, `table ${section.id} row ${row.id}`),
    }))

    let rowIndex = 0
    let continuation = false
    while (rowIndex < measuredRows.length) {
      const titleHeight = continuation ? continuationHeadingHeight : headingHeight
      const setupHeight = titleHeight + headerHeight
      const firstRow = measuredRows[rowIndex]
      if (setupHeight + firstRow.height > pageHeight) {
        throw new PdfPaginationError(
          'pdf_atomic_block_too_tall',
          `A row in table ${section.id} cannot fit with its required heading and header.`,
        )
      }
      if (setupHeight + firstRow.height > remaining()) advance()

      const fragment = {
        kind: 'table',
        id: section.id,
        continuation,
        rowIds: [],
        height: setupHeight,
      }
      current().fragments.push(fragment)
      current().usedHeight += setupHeight

      while (rowIndex < measuredRows.length && measuredRows[rowIndex].height <= remaining()) {
        const row = measuredRows[rowIndex]
        fragment.rowIds.push(row.id)
        fragment.height += row.height
        current().usedHeight += row.height
        rowIndex += 1
      }

      continuation = rowIndex < measuredRows.length
      if (continuation) advance()
    }
  }

  for (const section of sections) {
    if (section?.kind === 'atomic' || section?.kind === 'keepTogether') {
      appendAtomic(section)
    } else if (section?.kind === 'table') {
      appendTable(section)
    } else {
      throw new PdfPaginationError('pdf_invalid_sections', 'Unknown PDF section type.')
    }
  }

  return pages.filter(page => page.fragments.length > 0)
}
