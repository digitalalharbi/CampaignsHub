import { describe, expect, it } from 'vitest'
import { tableToCsv } from './tableCsv'

function table(html: string): HTMLTableElement {
  const host = document.createElement('div')
  host.innerHTML = `<table>${html}</table>`

  return host.querySelector('table')!
}

/** CAMPAIGNS-DOWNLOAD-001 — the file is the table on the screen. */
describe('tableToCsv', () => {
  it('writes the shown header and rows, and leaves out the selection column', () => {
    const t = table(`
      <thead><tr><th><input type="checkbox" aria-label="select all"></th><th>الحملة</th><th>الإنفاق</th></tr></thead>
      <tbody>
        <tr><td><input type="checkbox"></td><td>رمضان</td><td>1,200 ر.س</td></tr>
        <tr><td><input type="checkbox"></td><td>الصيف  الجديد</td><td>—</td></tr>
      </tbody>`)

    expect(tableToCsv(t)).toBe('الحملة,الإنفاق\r\nرمضان,"1,200 ر.س"\r\nالصيف الجديد,—')
  })

  it('quotes a cell holding a quote or a line break', () => {
    const t = table('<tbody><tr><td>say "hi"</td><td>a</td></tr></tbody>')

    expect(tableToCsv(t)).toBe('"say ""hi""",a')
  })
})
