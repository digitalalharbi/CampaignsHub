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

  /**
   * NEEDS-ATTENTION-ONE-DEFINITION-001 — the screen says the number once.
   *
   * The KPI card, the band chip and the landing strip all print «needs attention» within one
   * viewport of each other. They used to come from two engines — operational flags for the card and
   * the list, a metrics weakness for the strip — so a reader could see two different counts under
   * the same words and had no way to tell which was wrong.
   *
   * Asserted in a real browser against the real API, because that disagreement only ever appeared
   * where both numbers were rendered from the same data at the same moment.
   */
  test('the attention count is the same number wherever it appears', async ({ page, request }) => {
    test.setTimeout(180_000)

    await openCampaigns(page, request)

    /*
     * Sampled in ONE evaluate, and polled until the page settles.
     *
     * The first version read the card and then the band in two separate round trips, and failed on
     * webkit under CI load: the page re-rendered between them — a metrics query resolving — so it
     * compared a number from one paint against a number from the next. That is a flaw in the
     * measurement, not a disagreement in the product.
     *
     * The claim being tested is «once settled, the three agree», so the poll is the honest shape of
     * it: if they never agree it still fails, and it cannot fail for having looked twice.
     */
    const readAll = () => page.evaluate(() => {
      const digits = (id: string): string | null => {
        const el = document.querySelector(`[data-testid="${id}"]`)

        return el === null ? null : (el.textContent ?? '').match(/\d+/)?.[0] ?? null
      }

      return { card: digits('campaigns-attention'), band: digits('campaigns-band-attention'), strip: digits('landing-attention') }
    })

    await expect.poll(async () => {
      const { card, band } = await readAll()

      return card !== null && card === band
    }, { message: 'the KPI card and the band chip never agreed about the attention count' }).toBe(true)

    /*
     * The strip renders its chip only when the count is above zero, which is itself the contract —
     * so «nothing needs attention» is proven by the chip's absence rather than by a zero.
     */
    const { card, strip } = await readAll()

    if (card === '0') {
      await expect(page.getByTestId('landing-attention')).toBeHidden()
    } else {
      await expect(page.getByTestId('landing-attention')).toBeVisible()
      expect(strip).toBe(card)
    }
  })
})
