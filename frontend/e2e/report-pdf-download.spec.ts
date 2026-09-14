import { expect, test } from '@playwright/test'
import { createHash } from 'node:crypto'
import { mkdirSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { buildGeneratedClientReport } from './report-builder'
import { API_HEADERS, AUTH } from './helpers'

const sha256 = (b: Buffer) => createHash('sha256').update(b).digest('hex')

// Playwright runs from the frontend/ dir; the deliverables folder is one level up in the repo.
const DELIVER_DIR = resolve(process.cwd(), '../deliverables/report-audit/final-v2/browser-downloads')

/**
 * Acceptance-critical: the Arabic client PDF a user ACTUALLY downloads from the export button.
 *
 * Drives the whole system chain through the real UI + browser download — never calls ReportExporter
 * directly: create a client report in the builder → generate → click "export PDF" → queue job →
 * Chromium renderer → validators → storage → click "download" → browser download. Then audits the
 * DOWNLOADED bytes and the export-row provenance.
 */
test.use({ storageState: AUTH.owner })

test('export button → queue → download → the downloaded Arabic PDF is a valid Chromium file', async ({ page }) => {
  test.setTimeout(180_000)

  const { projectId, reportId } = await buildGeneratedClientReport(page, `E2E PDF ${Date.now()}`)

  // 3. Click THIS report's PDF export button (stable per-report test-id — real UI action).
  await page.reload()
  const exportBtn = page.getByTestId(`export-pdf-${reportId}`)
  await expect(exportBtn).toBeVisible({ timeout: 15_000 })
  const [exportReq] = await Promise.all([
    page.waitForRequest((r) => r.url().includes(`/reports/${reportId}/export`) && r.method() === 'POST'),
    exportBtn.click(),
  ])
  expect(JSON.parse(exportReq.postData() || '{}')).toMatchObject({ format: 'pdf' })

  // 4. Wait for the queue job to finish, then resolve THIS report's fresh download token via the API
  //    (unambiguous — the list can show many reports' PDF links).
  let token = ''
  /*
   * The last state the export reached, kept for the failure message.
   *
   * Waiting for a token and reporting «expected null not to be null» says nothing about WHY: an
   * export that failed to render and one whose queue never ran look identical from here, and the
   * difference is the whole diagnosis. This carries the row's own status and error into the message.
   */
  let lastSeen = 'no export row was created'
  await expect.poll(async () => {
    const r = await page.request.get(`/api/v1/projects/${projectId}/reports/${reportId}`, { headers: API_HEADERS })
    if (!r.ok()) return null
    const exports = (await r.json()).data.exports as Array<{ format: string; status: string; token: string | null; error?: string | null }>
    const pdf = exports.filter((e) => e.format === 'pdf')
    if (pdf.length > 0) {
      lastSeen = pdf.map((e) => `${e.status}${e.error ? `: ${e.error}` : ''}`).join(' | ')
    }
    const exp = pdf.find((e) => e.status === 'completed' && e.token)
    token = exp?.token ?? ''
    return token || null
  }, { message: () => `the PDF export never produced a token — last seen ${lastSeen}`, timeout: 90_000, intervals: [2000] }).not.toBeNull()

  // 5. Click THIS report's actual UI download link and capture the file the browser receives.
  expect(token).not.toBe('')
  await page.reload()
  const downloadLink = page.getByTestId(`download-pdf-${reportId}`)
  await expect(downloadLink).toBeVisible({ timeout: 15_000 })
  await expect(downloadLink).toHaveAttribute('href', new RegExp(`${token}$`))  // fresh token, this export
  const [download] = await Promise.all([page.waitForEvent('download'), downloadLink.click()])
  const downloadedPath = await download.path()
  expect(downloadedPath).toBeTruthy()
  const bytes = readFileSync(downloadedPath!)

  // Persist the ACTUAL browser-downloaded file as a deliverable (proof it came from the UI button).
  mkdirSync(DELIVER_DIR, { recursive: true })
  await download.saveAs(resolve(DELIVER_DIR, 'client-monthly-ar-ui-download.pdf'))

  // Downloaded SHA == the stored export's SHA (re-fetch the same token) — proves the browser got the
  // stored file, not a re-render or a different artifact.
  const refetch = Buffer.from(await (await page.request.get(download.url(), { headers: API_HEADERS })).body())
  expect(sha256(refetch)).toBe(sha256(bytes))

  // 6. Assert on the downloaded bytes themselves.
  expect(bytes.subarray(0, 5).toString('latin1')).toBe('%PDF-')
  expect(bytes.length).toBeGreaterThan(200_000)                     // Chromium file, not a tiny Dompdf one
  const raw = bytes.toString('latin1')
  expect(raw).toContain('IBMPlexSansArabic')                        // Arabic font embedded
  expect(raw.toLowerCase()).not.toContain('dompdf')                 // NOT the legacy renderer
  expect(raw).toContain('/MarkInfo')                                // tagged
  for (const term of ['burner', 'checksum', 'request_id', 'stack_trace']) {
    expect(raw.toLowerCase()).not.toContain(term)                   // no internal leakage
  }

  // 7. Cross-check the export row provenance via the API — a NEW, current, valid Chromium export.
  const after = (await (await page.request.get(`/api/v1/projects/${projectId}/reports/${reportId}`, { headers: API_HEADERS })).json()).data as {
    exports: Array<{ format: string; status: string; renderer: string; template_version: string; layout_mode: string; validation_status: string }>
  }
  const pdfExport = after.exports.find((e) => e.format === 'pdf' && e.status === 'completed')
  expect(pdfExport, 'a completed pdf export row').toBeTruthy()
  expect(pdfExport!.renderer).toBe('chromium')
  expect(pdfExport!.template_version).toBe('2')
  expect(pdfExport!.validation_status).toBe('passed')
  expect(pdfExport!.layout_mode).toBe('presentation')
})
