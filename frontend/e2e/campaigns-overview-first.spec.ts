import { expect, test, type APIRequestContext, type Page } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * CAMPAIGNS-OVERVIEW-FIRST-001 — what a person sees when they open Campaigns.
 *
 * «The page does not clearly start with Overview first … the first thing the user sees on entering
 * Campaigns must be a strong campaign portfolio overview.» The mode list already began with
 * «نظرة عامة» and the page still landed on CARDS, because the default was hard-coded past it.
 *
 * The unit tests hold the composition against a mocked list. What they cannot hold is the LANDING in
 * a real browser against the real API — which is the whole claim — nor that the Arabic strip reads
 * right-to-left without its counts drifting into the wrong cell.
 *
 * Signed in as the ADVERTISER: `/app` is the advertiser's portal and refuses an agency operator.
 */
test.describe('the campaigns workspace', () => {
  test.use({ storageState: AUTH.advertiser })

  async function openCampaigns(page: Page, request: APIRequestContext) {
    await selectProject(page, await seededProject(request, 'Growth — Acquisition'))
    await page.goto('/app/campaigns')
    await expect(page.getByTestId('campaigns-bands')).toBeVisible({ timeout: 60000 })
  }

  test('opens on the overview, with the portfolio classified before any one campaign', async ({ page, request }) => {
    test.setTimeout(180_000)

    await openCampaigns(page, request)

    /* The landing, not merely the presence of a tab. */
    await expect(page.getByTestId('view-overview')).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByTestId('view-cards')).toHaveAttribute('aria-pressed', 'false')

    /* Every band, including the ones at zero — a strip that hides its good news cannot be trusted. */
    for (const band of ['attention', 'spending', 'weak', 'paused', 'ended']) {
      await expect(page.getByTestId(`campaigns-band-${band}`)).toBeVisible()
    }
  })

  /**
   * The owner's IA, as a sequence.
   *
   * «The structure must feel intentional, not random.» A set of present buttons says nothing about
   * order, and order is the complaint.
   */
  test('offers the modes in the order the work is done in', async ({ page, request }) => {
    test.setTimeout(180_000)

    await openCampaigns(page, request)

    const ids = await page.locator('[data-testid^="view-"]').evaluateAll(
      (nodes) => nodes.map((n) => n.getAttribute('data-testid')),
    )

    expect(ids).toEqual(['view-overview', 'view-table', 'view-cards', 'view-compare', 'view-attention'])
  })

  /** Arabic, right to left, with the counts in Latin digits beside their own band. */
  test('reads correctly in Arabic', async ({ page, request }) => {
    test.setTimeout(180_000)

    await openCampaigns(page, request)

    /* The app opens in Arabic; this asserts what the default reader sees rather than a toggled state. */
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')

    const attention = page.getByTestId('campaigns-band-attention')

    await expect(attention).toContainText('تحتاج تدخلًا')
    await expect(attention).toHaveAttribute('data-count', /^\d+$/)
  })

  /** And a band is a door: it opens the list, rather than being a figure to look at. */
  test('a band opens the campaign list', async ({ page, request }) => {
    test.setTimeout(180_000)

    await openCampaigns(page, request)

    await page.getByTestId('campaigns-band-spending').click()

    await expect(page.getByTestId('view-table')).toHaveAttribute('aria-pressed', 'true')
  })
})
