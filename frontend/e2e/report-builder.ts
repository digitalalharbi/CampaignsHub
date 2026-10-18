import { expect, type Page } from '@playwright/test'
import { API_HEADERS } from './helpers'

/**
 * Create a CLIENT report through the real reports builder and wait until it has generated.
 *
 * Extracted from `report-pdf-download.spec.ts`, where every line of it was paid for by a failing
 * gate — the switcher walk, the per-client refetch, the postcondition after each selection. A
 * second spec needed the same setup, and the wrong way to get it is a second copy that learns the
 * same lessons again on a different browser.
 *
 * Returns the ids the caller needs to address THIS report unambiguously.
 */
export async function buildGeneratedClientReport(page: Page, name: string): Promise<{ projectId: string; reportId: string }> {
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

    // REPORT-SCOPE-HIERARCHY-001 — a saved report names its ad accounts, so take a project that has one.
    // The project with the most accounts, so the report covers as much as the seed offers.
    let chosen = ''
    let most = 0
    for (const v of values) {
      const r = await page.request.get(`/api/v1/projects/${v}/reports/scope/options`, { headers: API_HEADERS })
      const accounts = r.ok() ? ((await r.json()).data?.accounts ?? []) : []
      if (accounts.length > most) {
        chosen = v
        most = accounts.length
      }
    }
    if (chosen === '') continue

    await projectSelect.selectOption(chosen)

    /*
     * The switcher runs after its queries settle and clears a project it does not recognise, so the
     * selection is re-read a moment later. If it survives, the scope is genuinely consistent and the
     * builder will work; if not, this client is skipped rather than walking into a dead dialog.
     */
    await page.waitForTimeout(600)
    const held = (await projectSelect.evaluate((el) => (el as HTMLSelectElement).value)) === chosen

    if (held) {
      projectId = chosen
      break
    }
  }

  expect(projectId, 'no reachable client has a project the switcher will hold').not.toBe('')

  // The page reloads its reports for the chosen project; the builder is only offered once it has one.
  await expect(page.getByRole('button', { name: /تقرير محفوظ|Saved report/ })).toBeEnabled({ timeout: 20_000 })



  // 1. Create a fresh CLIENT report via the builder (a new report has no exports → export button shows).
  //    Report type & audience are now taxonomy-fed SelectField comboboxes; the builder already defaults the
  //    audience to «العميل» (client), so no explicit audience selection is needed for a client report.
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
  await chooseFirstAccount(page)
  const [createRes] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/reports') && r.request().method() === 'POST'),
    page.getByRole('button', { name: 'إنشاء وتوليد' }).click(),
  ])
  const reportId = (await createRes.json()).data.id as string
  expect(reportId).toBeTruthy()

  // Generation runs on the queue; the report is not exportable until it completes.
  await expect.poll(async () => {
    const r = await page.request.get(`/api/v1/projects/${projectId}/reports/${reportId}`, { headers: API_HEADERS })
    return r.ok() ? (await r.json()).data.status : 'error'
  }, { timeout: 90_000, intervals: [2000] }).toBe('completed')

  return { projectId, reportId }
}


/**
 * REPORT-SCOPE-HIERARCHY-001 — a saved report names its ad accounts, so the builder will not create
 * one until an account is chosen. Picks every account the project offers.
 */
export async function chooseFirstAccount(page: import('@playwright/test').Page): Promise<void> {
  const box = page.locator('[data-kind="accounts"]')
  await expect(box, 'the project offers no ad account to build a report from').toBeVisible({ timeout: 20_000 })
  // Visible checkboxes: tick every account the project offers, as a report covered before accounts were chosen.
  const boxes = box.getByRole('checkbox')
  const count = await boxes.count()
  for (let i = 0; i < count; i++) {
    /*
     * click + a retrying assertion, not `check()`.
     *
     * These are CONTROLLED inputs: the page renders the tick a moment after the click. `check()` reads
     * the state straight after clicking and, on a slow runner, saw the not-yet-rendered one and failed
     * with «did not change its state» (WebKit CI). The assertion waits for the render, and still
     * fails if the tick never lands — which is the property that matters.
     */
    const box = boxes.nth(i)
    // Re-press only while it is NOT ticked: under heavy load (Firefox CI) a click can land on a row that
    // is still being laid out and miss. The assertion is the same one — the tick must be there.
    await expect(async () => {
      if (!(await box.isChecked())) await box.click()
      await expect(box).toBeChecked({ timeout: 4_000 })
    }).toPass({ timeout: 40_000 })
  }
}
