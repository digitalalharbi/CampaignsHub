/**
 * CAMPAIGNS-DOWNLOAD-001 — download exactly what the table shows.
 *
 * The reader chose the columns, the filter and the order; the file is that table and nothing else, read from the
 * rendered cells so a figure in the file is the figure on the screen (same currency, same rounding, same «—»).
 * A cell holding only a control (the selection checkbox) is left out, so the file has the table's own columns.
 * A UTF-8 byte-order mark leads the file so a spreadsheet opens Arabic as Arabic.
 */
function cellText(cell: Element): string {
  return (cell.textContent ?? '').replace(/\s+/g, ' ').trim()
}

function quote(value: string): string {
  return /[",\n\r]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value
}

/** The table's rows as CSV text, without the byte-order mark. */
export function tableToCsv(table: HTMLTableElement): string {
  const rows = Array.from(table.querySelectorAll('tr'))
  const header = rows[0]
  // A column whose header cell holds a control and no words (the «select all» box) is not data.
  const skip = new Set<number>()
  if (header) {
    Array.from(header.children).forEach((cell, i) => {
      if (cellText(cell) === '' && cell.querySelector('input, button')) skip.add(i)
    })
  }

  return rows
    .map((row) => Array.from(row.children).filter((_, i) => !skip.has(i)).map((cell) => quote(cellText(cell))).join(','))
    .filter((line) => line.replace(/,/g, '') !== '')
    .join('\r\n')
}

/** Saves the table as `<name>.csv` through the browser. */
export function downloadTableCsv(table: HTMLTableElement, name: string): void {
  const blob = new Blob(['\uFEFF', tableToCsv(table)], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `${name}.csv`
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
}
