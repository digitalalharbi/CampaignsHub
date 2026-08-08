import { expect, test } from '@playwright/test'
import { createHash } from 'node:crypto'
import { mkdirSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
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

  /*
   * The scope is chosen through the SHELL's own controls — SCOPE-BUILDER-001's other lesson.
   *
   * Two previous gates failed here, on two different browsers, and both times the cause was the
   * same: the spec picked a project from `/api/v1/projects` and pinned it in localStorage, and
   * `AgencyScopeSwitcher` then dropped it as orphaned because that project was not in the list the
   * SWITCHER had loaded. Two endpoints, two lists, and the one the test trusted was not the one the
   * page obeys. It looked like a three-minute hang, because a builder with no project posts nothing.
   *
   * Selecting from the switcher's own options cannot disagree with the switcher. It is also what a
   * person does, which is the better reason.
   */
  await page.goto('/reports')

  const clientSelect = page.getByLabel(/^العميل$|^Client$/)
  await expect(clientSelect).toBeVisible({ timeout: 20_000 })
  const projectSelect = page.getByLabel(/^المشروع$|^Project$/)

  /*
   * Walk the clients until one has a project.
   *
   * Not every client does: earlier specs in the same gate create client workspaces with no project
   * under them, and they sort to the top. Taking «the first client» therefore picked an empty one
   * and left the project select disabled — which is the switcher behaving correctly and the test
   * assuming otherwise.
   */
  const clientValues = (await clientSelect.locator('option').evaluateAll((os) =>
    os.map((o) => (o as HTMLOptionElement).value),
  )).filter(Boolean)
  expect(clientValues.length, 'the agency owner can reach no clients').toBeGreaterThan(0)

  /*
   * After choosing, the choice is VERIFIED — the switcher is allowed to disagree.
   *
   * The previous attempt read the project options straight after selecting a client, and the list
   * is refetched per client: the first read could still hold the PREVIOUS client's options, so the
   * project selected belonged to another client and `AgencyScopeSwitcher` dropped it as
   * `belongsElsewhere`. That produced the same three-minute hang one browser further along, which
   * is how a fix that guesses at timing looks when it is really a fix that never asserted its
   * postcondition.
   *
   * `toHaveValue` after each selection is that postcondition. If the switcher resets the project,
   * this client is skipped rather than proceeding into a builder that cannot build.
   */
  let projectId = ''
  for (const clientValue of clientValues) {
    await clientSelect.selectOption(clientValue)
    await expect(clientSelect).toHaveValue(clientValue)

    // The options arrive with the per-client refetch, so read until they do.
    const readOptions = () =>
      projectSelect.locator('option').evaluateAll((os) =>
        os.map((o) => (o as HTMLOptionElement).value).filter(Boolean),
      )

    let values: string[] = []
    for (let attempt = 0; attempt < 20 && values.length === 0; attempt++) {
      values = await readOptions()
      if (values.length === 0) await page.waitForTimeout(200)
    }

    if (values.length === 0) continue

    await projectSelect.selectOption(values[0])

    /*
     * The switcher runs after its queries settle and clears a project it does not recognise, so the
     * selection is re-read a moment later. If it survives, the scope is genuinely consistent and the
     * builder will work; if not, this client is skipped rather than walking into a dead dialog.
     */
    await page.waitForTimeout(600)
    const held = (await projectSelect.evaluate((el) => (el as HTMLSelectElement).value)) === values[0]

    if (held) {
      projectId = values[0]
      break
    }
  }

  expect(projectId, 'no reachable client has a project the switcher will hold').not.toBe('')

  // The page reloads its reports for the chosen project; the builder is only offered once it has one.
  await expect(page.getByRole('button', { name: /تقرير محفوظ|Saved report/ })).toBeEnabled({ timeout: 20_000 })



  // 1. Create a fresh CLIENT report via the builder (a new report has no exports → export button shows).
  //    Report type & audience are now taxonomy-fed SelectField comboboxes; the builder already defaults the
  //    audience to «العميل» (client), so no explicit audience selection is needed for a client report.
  const name = `E2E PDF ${Date.now()}`
  /*
   * «تقرير محفوظ», not «تقرير جديد» (LIVEREP-002).
   *
   * The reports page now offers two things, and the difference matters: a LIVE client link built from
   * a choice, and a SAVED document that can be generated and exported. This test is about the export
   * pipeline, so it wants the saved document — and the button was renamed to say which one it is,
   * because «new report» stopped being unambiguous the moment there were two kinds.
   */
  await page.getByRole('button', { name: /تقرير محفوظ|Saved report/ }).click()
  await page.getByPlaceholder(/التقرير الشهري/).fill(name)
  const [createRes] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/reports') && r.request().method() === 'POST'),
    page.getByRole('button', { name: 'إنشاء وتوليد' }).click(),
  ])
  const reportId = (await createRes.json()).data.id as string
  expect(reportId).toBeTruthy()

  // 2. Wait for generation to complete (queue job) via the API.
  await expect.poll(async () => {
    const r = await page.request.get(`/api/v1/projects/${projectId}/reports/${reportId}`, { headers: API_HEADERS })
    return r.ok() ? (await r.json()).data.status : 'error'
  }, { timeout: 90_000, intervals: [2000] }).toBe('completed')

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
  await expect.poll(async () => {
    const r = await page.request.get(`/api/v1/projects/${projectId}/reports/${reportId}`, { headers: API_HEADERS })
    if (!r.ok()) return null
    const exp = ((await r.json()).data.exports as Array<{ format: string; status: string; token: string | null }>)
      .find((e) => e.format === 'pdf' && e.status === 'completed' && e.token)
    token = exp?.token ?? ''
    return token || null
  }, { timeout: 90_000, intervals: [2000] }).not.toBeNull()

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
