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
   * The KPI card, the band chip and the landing strip all print «needs attention». They used to come
   * from two engines — operational flags for the card and the list, a metrics weakness for the strip
   * — so a reader could see two different counts under the same words and had no way to tell which
   * was wrong.
   *
   * Asserted in a real browser against the real API, because that disagreement only ever appeared
   * where both numbers were rendered from the same data at the same moment.
   *
   * ## Two defects this test had, and why the shape below is the fix
   *
   * It read all three on the OVERVIEW view, and `landing-attention` is not on that view: the strip
   * lives inside `LandingAnswer`, which `CampaignsPage` renders in the branch for every mode EXCEPT
   * overview and compare — the product's own unit test has to click into the card list before it can
   * see it. So the branch that asserted the strip was VISIBLE could never pass from here. It survived
   * because the count is normally zero and the other branch, `toBeHidden()`, is trivially true of an
   * element that does not exist. The three are still compared; the strip is now read where it is
   * rendered.
   *
   * And the settle condition was `card === band`, which both read the same `attentionIds` — so they
   * agreed at the wrong number while the per-campaign metrics were still in flight, the poll passed
   * on the first look, and the dead branch ran against a transient. That transient was itself a
   * defect (ATTENTION-REQUEST-STATE-001) and is fixed in the page: no verdict is produced until the
   * figures are in, and the card reads «—» until then. Waiting for the card to be a NUMBER is
   * therefore waiting for the page to have judged, which is the fact this test needs and could not
   * previously express. It is not a longer timeout: a page that never judges still fails here.
   */
  test('the attention count is the same number wherever it appears', async ({ page, request }) => {
    test.setTimeout(180_000)

    await openCampaigns(page, request)

    /*
     * Sampled in ONE evaluate, so two readings are never taken from two paints.
     *
     * The first version read the card and then the band in two separate round trips and failed on
     * webkit under CI load, comparing a number from one render against a number from the next. That
     * is a flaw in the measurement, not a disagreement in the product.
     */
    const readAll = () => page.evaluate(() => {
      const text = (id: string): string | null => {
        const el = document.querySelector(`[data-testid="${id}"]`)

        return el === null ? null : (el.textContent ?? '')
      }
      const digits = (id: string): string | null => text(id)?.match(/\d+/)?.[0] ?? null

      return {
        card: digits('campaigns-attention'),
        cardText: text('campaigns-attention'),
        band: digits('campaigns-band-attention'),
        strip: digits('landing-attention'),
        stripPresent: document.querySelector('[data-testid="landing-attention"]') !== null,
        /*
         * The rows the page is classifying — two readings, because the two views say it differently.
         * The bands exist on the overview only; the landing answer exists on the list only, and is
         * absent exactly when there is no campaign to describe.
         */
        classified: [...document.querySelectorAll('[data-testid^="campaigns-band-"]')]
          .reduce((n, el) => n + Number(el.getAttribute('data-count') ?? 0), 0),
        answered: document.querySelector('[data-testid="campaigns-landing-answer"]') !== null,
      }
    })

    /*
     * Wait for the page to have JUDGED A LIST, not merely to have rendered.
     *
     * BOTH halves are load-bearing and each one alone is satisfied by a loading state — which is how
     * the previous settle condition let a transient through, and then how its first replacement did.
     *
     *   - `card !== null`: the card reads «—» while the per-campaign metrics are pending or failed,
     *     because no verdict can be made out of a request that has not answered. A digit is the page
     *     saying it now has an answer.
     *   - `classified > 0`: the figures can land BEFORE the campaign list does, and an attention count
     *     over an empty list is «0» — the branch below would then prove the strip is absent, which is
     *     true of a page holding no campaigns and says nothing about this one. Measured locally: the
     *     card reads «0» for about four seconds on webkit before the rows arrive and it becomes «1».
     *
     * Not a longer timeout. A page that never judges, or never classifies a row, still fails here.
     */
    await expect.poll(
      async () => {
        const { card, classified } = await readAll()

        if (card === null) return 'no verdict yet — the card is still «—»'

        return classified > 0 ? 'judged' : 'no campaign classified yet — the list has not arrived'
      },
      { message: 'the workspace never judged a campaign list' },
    ).toBe('judged')

    const onOverview = await readAll()

    /* The card counts the project's campaigns; the band counts the same rows, one classification down. */
    expect(onOverview.band, 'the KPI card and the band chip disagree about the attention count').toBe(onOverview.card)

    /*
     * The strip is on the LIST, so the comparison is made there.
     *
     * `view-table` rather than `view-cards`: both render the strip, and the table does not have to
     * paint a card per campaign to do it.
     */
    await page.getByTestId('view-table').click()
    await expect(page.getByTestId('campaigns-landing-answer').or(page.getByTestId('landing-unexamined'))).toBeVisible({ timeout: 30000 })

    /*
     * Polled, because switching view remounts the branch and its queries settle again — and the claim
     * is «once settled, the three agree», which a poll states honestly. A page that never agrees still
     * fails here; it simply cannot fail for having looked during a repaint.
     *
     * The strip renders its chip only above zero, which is itself the contract — so «nothing needs
     * attention» is proven by the chip's ABSENCE rather than by a zero, and the card is re-read on the
     * list view so the two readings describe one paint.
     */
    await expect.poll(async () => {
      const { card, strip, stripPresent, answered } = await readAll()

      if (card === null) return 'the card stopped stating a verdict'
      if (!answered) return 'the landing answer has not described a list yet'
      if (card === '0') return stripPresent ? 'the strip named an attention count where the card said none' : 'agreed'

      return strip === card ? 'agreed' : `card ${card} vs strip ${stripPresent ? strip : '(absent)'}`
    }, { message: 'the KPI card and the landing strip never agreed about the attention count' }).toBe('agreed')
  })
})
