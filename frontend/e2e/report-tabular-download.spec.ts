import { expect, test } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { API_HEADERS, AUTH } from './helpers'
import { buildGeneratedClientReport } from './report-builder'

/**
 * The other two export formats a person can actually click.
 *
 * The PDF was downloaded and audited byte-for-byte; XLSX and CSV were proved only where nobody
 * clicks — `ReportExportParityTest` renders them through `ReportExporter` directly and compares
 * them to the snapshot. That proves the renderer and says nothing about the chain the owner
 * described: button → queue → storage → token → download → a file that opens.
 *
 * So this drives both formats end to end and then opens what the browser received. The division of
 * labour between the two assertions is deliberate: CSV is plain text, so its FIGURES are read and
 * reconciled against the report's own API; XLSX is a zip, so its STRUCTURE is asserted here and its
 * figures stay where they are already proved — against the snapshot, in the parity test. Claiming
 * to have read numbers out of a deflated stream would be the more impressive sentence and the less
 * true one.
 */
test.use({ storageState: AUTH.owner })

test('export → download → the XLSX and CSV a person receives are real files carrying the real figures', async ({ page, browserName }) => {
  /*
   * The budget, and why webkit gets more of it — GATE-WK-001's pattern, not a product difference.
   *
   * This test does two complete export cycles: build the report, queue a render, poll the queue,
   * reload, click the download, read the bytes — twice. On this machine webkit finishes the whole
   * thing in 54 seconds. On the gate, sharing a runner with the rest of the suite, the same test
   * took seven minutes and died on `waitForEvent('download')` at 420 seconds.
   *
   * That is load, not Safari: the download fires there, and the two faster engines pass the same
   * assertions on the same file. Raising the budget for the slow engine is the honest fix; trimming
   * the assertions or skipping webkit would trade away the coverage this test exists for.
   */
  test.setTimeout(browserName === 'webkit' ? 900_000 : 420_000)

  const { projectId, reportId } = await buildGeneratedClientReport(page, `E2E TABULAR ${Date.now()}`)

  const reportUrl = `/api/v1/projects/${projectId}/reports/${reportId}`
  type ExportRow = { format: string; status: string; token: string | null; is_demo?: boolean; error?: string | null }

  /** Click a format's export button, wait for the queue, and download through the UI link. */
  const downloadThrough = async (format: 'xlsx' | 'csv'): Promise<Buffer> => {
    await page.reload()
    const exportBtn = page.getByTestId(`export-${format}-${reportId}`)
    await expect(exportBtn, `the ${format} export button`).toBeVisible({ timeout: 20_000 })
    const [exportReq] = await Promise.all([
      page.waitForRequest((r) => r.url().includes(`/reports/${reportId}/export`) && r.method() === 'POST'),
      exportBtn.click(),
    ])
    expect(JSON.parse(exportReq.postData() || '{}')).toMatchObject({ format })

    // Carry the row's own status and error into the failure message: an export that never rendered
    // and one whose queue never ran look identical from a null token, and that is the diagnosis.
    let token = ''
    let lastSeen = 'no export row was created'
    await expect.poll(async () => {
      const r = await page.request.get(reportUrl, { headers: API_HEADERS })
      if (!r.ok()) return null
      const rows = ((await r.json()).data.exports as ExportRow[]).filter((e) => e.format === format)
      if (rows.length > 0) lastSeen = rows.map((e) => `${e.status}${e.error ? `: ${e.error}` : ''}`).join(' | ')
      token = rows.find((e) => e.status === 'completed' && e.token)?.token ?? ''
      return token || null
      // `message` is a string, not a callback: Playwright prints a function here verbatim, so the
      // first version of this reported its own source instead of the export's last known state.
    }, { timeout: 90_000, intervals: [2000] }).not.toBeNull().catch((e: Error) => {
      throw new Error(`the ${format} export never produced a token — last seen ${lastSeen}\n${e.message}`)
    })

    await page.reload()
    const link = page.getByTestId(`download-${format}-${reportId}`)
    await expect(link).toBeVisible({ timeout: 20_000 })
    await expect(link).toHaveAttribute('href', new RegExp(`${token}$`))   // fresh token, this export
    const [download] = await Promise.all([page.waitForEvent('download'), link.click()])
    const path = await download.path()
    expect(path, `the browser saved no ${format} file`).toBeTruthy()

    // The download names the REPORT, not the uuid blob underneath it (REPORT-TITLE-METADATA-001).
    expect(download.suggestedFilename()).toMatch(new RegExp(`\\.${format}$`))
    expect(download.suggestedFilename()).not.toMatch(/^[0-9a-f-]{36}\./)

    return readFileSync(path!)
  }

  // ---- XLSX: a real OOXML package, not an HTML table with a spreadsheet extension. ----
  const xlsx = await downloadThrough('xlsx')
  expect(xlsx.subarray(0, 4)).toEqual(Buffer.from([0x50, 0x4b, 0x03, 0x04]))   // PK.. — a zip
  const xlsxRaw = xlsx.toString('latin1')
  // Zip entry names live uncompressed in each local file header, so they are readable without
  // inflating anything — which is exactly as far as this assertion is entitled to go.
  expect(xlsxRaw).toContain('xl/workbook.xml')
  expect(xlsxRaw).toContain('xl/worksheets/sheet1.xml')
  expect(xlsx.length).toBeGreaterThan(4_000)

  // ---- CSV: plain text, so the figures themselves are read back. ----
  const csv = (await downloadThrough('csv')).toString('utf8')
  const lines = csv.split(/\r?\n/)

  const cells = (line: string): string[] =>
    (line.match(/("([^"]|"")*"|[^,]*)(,|$)/g) ?? [])
      .map((c) => c.replace(/,$/, '').trim())
      .map((c) => (c.startsWith('"') ? c.slice(1, -1).replace(/""/g, '"') : c))
      .slice(0, -1)

  const headerAt = lines.findIndex((l) => l.startsWith('Platforms,'))
  expect(headerAt, 'the CSV has no platform header row').toBeGreaterThan(-1)
  expect(cells(lines[headerAt])).toEqual(['Platforms', 'spend', 'revenue', 'conversions', 'roas', 'cpa', 'ctr', 'share'])

  const platformRows: string[][] = []
  for (let i = headerAt + 1; i < lines.length && lines[i].trim() !== ''; i++) platformRows.push(cells(lines[i]))

  /*
   * The figures in the file are the figures the product reports.
   *
   * Reconciled against the report's OWN api rather than against a fixture: a fixture would prove the
   * exporter agrees with the test, and the question is whether the exporter agrees with the product.
   */
  const data = (await (await page.request.get(reportUrl, { headers: API_HEADERS })).json()).data as {
    is_demo?: boolean
    exports: ExportRow[]
    data?: { platforms?: Array<{ provider?: string; spend?: number | string }> }
  }
  /*
   * Reconciled in BOTH directions, which is why there is no «and there must be at least one».
   *
   * The first version required a platform row outright, and the gate's freshly seeded project does
   * not always have spend in the window the builder picks — so the test failed on three browsers
   * for a report that was entirely correct and empty. Requiring rows asserts the seed, not the
   * product.
   *
   * Dropping the requirement without replacing it would leave a test that passes on an empty file,
   * which is the worse failure. So the file and the API are held to the SAME answer: every provider
   * the API reports appears in the CSV, no provider appears that the API does not, and the money
   * adds up. An empty report then passes only if the CSV is empty too, and a report with figures
   * cannot pass unless the file carries them.
   */
  /*
   * `show()` puts the generated payload under `data`, the same key the report model uses. The first
   * version of this read `snapshot`, which does not exist — so the list was always empty and the
   * comparison guarded by it never ran at all. A reconciliation against an always-empty array is
   * the vacuous pass this file exists to avoid.
   */
  const apiPlatforms = data.data?.platforms ?? []
  const round = (n: number) => Math.round(n * 100) / 100

  expect(platformRows.map((r) => r[0]).sort(), 'the CSV and the API disagree about which platforms ran')
    .toEqual(apiPlatforms.map((p) => String(p.provider ?? '')).sort())

  expect(
    round(platformRows.reduce((sum, r) => sum + (Number(r[1]) || 0), 0)),
    'the CSV spend does not reconcile with the report the API serves',
  ).toBeCloseTo(round(apiPlatforms.reduce((sum, p) => sum + (Number(p.spend) || 0), 0)), 2)

  /*
   * And the file is not a demo file.
   *
   * An export inherits `is_demo` from the report it renders, so a demo report can produce a perfectly
   * valid spreadsheet full of invented money. A downloaded file that opens is not the same claim as a
   * downloaded file that is true.
   */
  for (const format of ['xlsx', 'csv']) {
    const row = data.exports.filter((e) => e.format === format).at(-1)
    expect(row, `a ${format} export row`).toBeTruthy()
    expect(row!.is_demo ?? false, `the ${format} export is a demo artefact`).toBe(false)
  }
  expect(data.is_demo ?? false).toBe(false)
})
