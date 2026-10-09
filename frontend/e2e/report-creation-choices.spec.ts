import { expect, test, type Page } from '@playwright/test'
import { AUTH, seededProject, selectProject, switchToEnglish } from './helpers'

/**
 * REPORT-CREATION-UX-001 — the builder's choices reach the report it creates, in a real browser.
 *
 * The row's acceptance has two halves: a test that each choice reaches the produced report, and
 * browser evidence of the flow. The backend half is `ReportFormTest` (mode, form) and
 * `ReportCreationBrandingChoiceTest` (the identity). This is the other half — the modal, pressed
 * the way an operator presses it, and the request it actually sends.
 *
 * What is asserted is the CREATED ROW the server answers with, not the controls' own state: a
 * pressed button proves the button, and the Owner's complaint about this screen was that «changing
 * settings does not change the product». So the response — `mode`, `form`, `config.branding.prefer`
 * — is read off the POST that the press produced.
 *
 * Section selection is deliberately absent from this flow and from this spec: the creation screen
 * records that decision in its own source («a full section picker inside a modal that already
 * carries seven decisions would trade one silence for a wall»), states the sections the audience
 * implies, and hands the fine control to the sections panel on the created report. The sentence is
 * asserted; a picker is not expected.
 */
const PROJECT = 'متجر تجريبي — Demo'

test.describe('report builder — the choices reach the report', () => {
  test.use({ storageState: AUTH.owner })

  async function openBuilder(page: Page) {
    await page.goto('/agency/reports')
    await switchToEnglish(page)
    await page.getByTestId('open-report-builder').click()
    await expect(page.getByTestId('report-scope-picker')).toBeVisible({ timeout: 30000 })
  }

  test('mode, form and identity, as pressed, are what the server stores', async ({ page, request }) => {
    await page.goto('/agency/reports')
    await selectProject(page, await seededProject(request, PROJECT))
    await openBuilder(page)

    /* The defaults, so a later assertion cannot pass because nothing was pressed. */
    await expect(page.getByTestId('rb-mode-snapshot')).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByTestId('rb-identity-auto')).toHaveAttribute('aria-pressed', 'true')

    /* The screen says what it is about to make, before anything is created. */
    await expect(page.getByTestId('rb-section-preview')).toContainText('Will include')
    await expect(page.getByTestId('rb-section-preview')).toContainText('sections can be changed after it is created')

    await page.getByTestId('rb-mode-live').click()
    await page.getByTestId('rb-form-executive_summary').click()
    await page.getByTestId('rb-identity-agency').click()
    await page.locator('#rb-name').fill('E2E creation choices')

    const created = page.waitForResponse(
      (r) => r.request().method() === 'POST' && /\/api\/v1\/projects\/[^/]+\/reports$/.test(r.url()),
    )
    await page.getByRole('button', { name: 'Create and generate' }).click()
    const response = await created

    expect(response.status(), 'the builder’s request was refused').toBe(201)
    const data = (await response.json()).data as {
      name: string
      mode: string
      form: string
      config: { branding?: { prefer?: string } } | null
    }

    expect(data.name).toBe('E2E creation choices')
    expect(data.mode, 'the mode pressed in the modal is not the mode stored').toBe('live')
    expect(data.form, 'the form pressed in the modal is not the form stored').toBe('executive_summary')
    expect(data.config?.branding?.prefer, 'the identity pressed in the modal was not frozen into the report').toBe('agency')

    /* And the list the operator is looking at shows what they just made. */
    await expect(page.getByText('E2E creation choices').first()).toBeVisible({ timeout: 30000 })
  })

  test('an unpressed identity sends no preference, so the hierarchy keeps deciding', async ({ page, request }) => {
    await page.goto('/agency/reports')
    await selectProject(page, await seededProject(request, PROJECT))
    await openBuilder(page)

    await page.locator('#rb-name').fill('E2E default identity')

    const created = page.waitForResponse(
      (r) => r.request().method() === 'POST' && /\/api\/v1\/projects\/[^/]+\/reports$/.test(r.url()),
    )
    await page.getByRole('button', { name: 'Create and generate' }).click()
    const response = await created

    expect(response.status()).toBe(201)
    const body = response.request().postDataJSON() as Record<string, unknown>
    expect(body, 'an unmade choice was sent as if it had been made').not.toHaveProperty('branding_identity')

    const data = (await response.json()).data as { config: { branding?: { prefer?: string } } | null }
    expect(data.config?.branding?.prefer ?? null).toBeNull()
  })
})
