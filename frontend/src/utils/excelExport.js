import * as XLSX from 'xlsx'

function cellValue(value) {
  if (value === null || value === undefined || value === '') return '—'
  return String(value)
}

function applyColumnWidths(worksheet, columns, rows) {
  worksheet['!cols'] = columns.map(column => {
    const headerWidth = String(column.header ?? '').length
    const contentWidth = rows.reduce((max, row) => {
      const value = cellValue(column.value(row))
      return Math.max(max, value.length)
    }, 0)
    return { wch: Math.min(Math.max(headerWidth, contentWidth, 10) + 2, 40) }
  })
}

function forceTextCells(worksheet, columns, headerRowIndex, rowCount) {
  columns.forEach((column, columnIndex) => {
    if (!column.text) return

    for (let rowOffset = 0; rowOffset < rowCount; rowOffset += 1) {
      const address = XLSX.utils.encode_cell({
        r: headerRowIndex + 1 + rowOffset,
        c: columnIndex,
      })
      const cell = worksheet[address]
      if (!cell) continue
      cell.t = 's'
      cell.v = cellValue(cell.v)
      cell.z = '@'
    }
  })
}

/**
 * Create and download a genuine .xlsx workbook.
 * columns: [{ header, value: (row) => any, text?: boolean }]
 */
export function exportRowsToExcel({
  title,
  subtitleLines = [],
  sheetName = 'Sheet1',
  columns,
  rows,
  filename,
}) {
  const metaRows = [
    [title],
    ...subtitleLines.filter(Boolean).map(line => [line]),
    [],
  ]
  const headerRowIndex = metaRows.length
  const headerRow = columns.map(column => column.header)
  const dataRows = rows.map(row => columns.map(column => cellValue(column.value(row))))

  const worksheet = XLSX.utils.aoa_to_sheet([
    ...metaRows,
    headerRow,
    ...dataRows,
  ])

  applyColumnWidths(worksheet, columns, rows)
  forceTextCells(worksheet, columns, headerRowIndex, rows.length)

  if (columns.length > 1) {
    worksheet['!merges'] = [{
      s: { r: 0, c: 0 },
      e: { r: 0, c: columns.length - 1 },
    }]
  }

  const workbook = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(workbook, worksheet, sheetName.slice(0, 31))
  XLSX.writeFile(workbook, filename, { bookType: 'xlsx' })
}
