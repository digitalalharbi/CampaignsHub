import { expect, test, type Page } from '@playwright/test'
import { AUTH, seededProject, selectProject, switchToEnglish } from './helpers'

/**
 * REPORT-SCOPE-SELECTION-001 §B — the grouping, in a browser, on real seeded data.
 *
 * ## Why this exists beside the unit guard
 *
 * The unit guard proves the component's three states from a fixture. This proves the thing an
 * operator actually meets: the builder opened on a real project, the campaign list rendered by the
 * real `scope/options` endpoint with `last_active_on` computed by the real aggregator, and the
 * heading either present or absent because of what that endpoint said — not because a fixture said
 * it.
 *
 * That distinction has mattered on this product before: a guard can be true about the thing it
 * measures and say nothing about what a person sees. It also covers the half a fixture cannot reach
 * at all — the SERVER's axis search, which returns `{id, name}` and therefore no activity answer, so
 * its results must arrive unlabelled rather than filed under «recorded no activity».
 *
 * ## The mixed state is seeded, not hoped for
 *
 * Every campaign in `DemoAnalyticsSeeder::CAMPAIGNS` reports metrics on every day of the window, so
 * «recorded no activity» was unreachable on real data. The seeder now also creates one campaign that
 * exists and never ran — «حملة الإطلاق — لم تُشغَّل بعد» — which is a state an operator meets
 * constantly and which makes BOTH headings appear in one list deterministically.
 *
 * Three browsers and both directions, because a heading is a rendered thing: it can be present in the
 * DOM and clipped, and it can be correct in English and addressed by the wrong name in Arabic.
 */
const PROJECT = 'متجر تجريبي — Demo'

/** A window inside the seeded range, so the campaigns that do run have run in it. */
const day = (back: number): string => {
  const d = new Date()
  d.setDate(d.getDate() - back)

  return d.toISOString().slice(0, 10)
}

const COPY = {
  ar: { axis: 'الحملات', ran: 'عملت في هذه الفترة', quiet: 'لم تُسجّل نشاطًا في هذه الفترة' },
  en: { axis: 'Campaigns', ran: 'Ran in this period', quiet: 'No activity recorded in this period' },
}

async function openBuilder(page: Page, locale: 'ar' | 'en') {
  await page.goto('/agency/reports')

  if (locale === 'en') {
    await switchToEnglish(page)
  }

  await page.getByTestId('open-report-builder').click()
  await expect(page.getByTestId('report-scope-picker')).toBeVisible({ timeout: 30000 })

  /*
   * INTERNAL, because a CLIENT report may not name the hierarchy at all.
   *
   * CLIENT-REPORT-AUDIENCE withholds the campaign, ad-set, ad and creative axes from a client-facing
   * scope — «a campaign called Meta — Prospecting Broad KSA 3.2 is the agency's own working
   * vocabulary» — and the builder opens on `client`. So the campaigns control genuinely is not there
   * to begin with, and the first version of this spec failed all eight cases on its absence while
   * claiming the grouping was missing. Switching the audience is what an operator does to reach it.
   *
   * Addressed by the option's VALUE rather than its label: the labels come from the taxonomy engine
   * and are localized, so clicking «Internal» would pass in English and address nothing in Arabic.
   */
  await page.getByTestId('builder-audience').getByRole('combobox').click()
  await page.locator('[role="option"][data-value="internal"]').click()
}

/** The campaigns control, addressed by its own testid — which carries the LOCALIZED axis label. */
const campaignsBox = (page: Page, locale: 'ar' | 'en') =>
  page.getByTestId(`scope-select-${COPY[locale].axis}`)

async function openCampaigns(page: Page, locale: 'ar' | 'en') {
  const box = campaignsBox(page, locale)
  await expect(box).toBeVisible({ timeout: 30000 })
  await box.getByRole('combobox').first().click()

  return box
}

async function setPeriod(page: Page) {
  await page.locator('#scope-from').fill(day(30))
  await page.locator('#scope-to').fill(day(1))
  /* The options query is keyed on the period, so the list is re-fetched for the window just named. */
  await page.waitForLoadState('networkidle')
}

for (const locale of ['ar', 'en'] as const) {
  const t = COPY[locale]

  test.describe(`the report builder groups campaigns by what ran (${locale})`, () => {
    test.use({ storageState: AUTH.owner })

    test('both headings appear once a period is named, and every campaign is still offered', async ({ page, request }) => {
      await selectProject(page, await seededProject(request, PROJECT))
      await openBuilder(page, locale)
      await setPeriod(page)

      const box = await openCampaigns(page, locale)

      await expect(box.getByText(t.ran, { exact: false }).first()).toBeVisible()
      /*
       * The never-run campaign is what makes this heading reachable. Its presence is asserted as well
       * as the heading's, so a seed that stopped creating it fails here rather than quietly removing
       * half the coverage.
       */
      await expect(box.getByText(t.quiet, { exact: false }).first()).toBeVisible()
      await expect(box.getByText('حملة الإطلاق — لم تُشغَّل بعد', { exact: false }).first()).toBeVisible()

      /* The heading decides emphasis, never membership. */
      const options = box.getByRole('option')
      expect(await options.count(), 'the grouping dropped campaigns from the list').toBeGreaterThan(1)
    })

    test('no heading claims a window nobody asked about', async ({ page, request }) => {
      await selectProject(page, await seededProject(request, PROJECT))
      await openBuilder(page, locale)

      /* No period set: the scope's own from/to are empty when the builder opens. */
      await expect(page.locator('#scope-from')).toHaveValue('')

      const box = await openCampaigns(page, locale)

      await expect(box.getByText(t.ran, { exact: false })).toHaveCount(0)
      await expect(box.getByText(t.quiet, { exact: false })).toHaveCount(0)
      /* The control still works — this is «no claim», not «no list». */
      expect(await box.getByRole('option').count()).toBeGreaterThan(0)
    })

    /**
     * The server's axis search cannot answer «did it run in this window», so its results may not be
     * filed under either heading. Three characters is the threshold the control asks the server at.
     */
    test('a server-searched row carries no reportability claim', async ({ page, request }) => {
      await selectProject(page, await seededProject(request, PROJECT))
      await openBuilder(page, locale)
      await setPeriod(page)

      const box = await openCampaigns(page, locale)
      await expect(box.getByText(t.ran, { exact: false }).first()).toBeVisible()

      /*
       * The panel's search is the SECOND combobox in the control, not a searchbox.
       *
       * `PanelSearch` renders `<input type="text" role="combobox">` — the trigger is the first
       * combobox and the search is the second. A first version asked for `getByRole('searchbox')`,
       * matched nothing, and failed both locales on a missing input while claiming the search results
       * carried a heading.
       */
      const search = box.getByRole('combobox').nth(1)
      await expect(search).toBeVisible()
      await search.fill('الإطلاق')
      await page.waitForLoadState('networkidle')

      /*
       * Once the server has answered, the list it replaced the page's own with is unlabelled: the
       * endpoint was asked which rows MATCH, not which ran, and printing a heading over its answer
       * would state something nobody asked it.
       */
      await expect(box.getByRole('option').first()).toBeVisible()
      await expect(box.getByText(t.quiet, { exact: false })).toHaveCount(0)
    })

    /** A heading in the DOM that the reader cannot see is not a heading. */
    test('the headings are visible, not merely present', async ({ page, request }) => {
      await selectProject(page, await seededProject(request, PROJECT))
      await openBuilder(page, locale)
      await setPeriod(page)

      const box = await openCampaigns(page, locale)
      const heading = box.getByText(t.ran, { exact: false }).first()

      await expect(heading).toBeVisible()

      const shape = await heading.boundingBox()
      expect(shape, 'the heading has no box, so nothing was drawn').not.toBeNull()
      expect(shape!.height, 'the heading is collapsed to no height').toBeGreaterThan(6)
      expect(shape!.width, 'the heading is collapsed to no width').toBeGreaterThan(20)

      /* RTL in Arabic, LTR in English — the direction the headings are read in. */
      await expect(page.locator('html')).toHaveAttribute('dir', locale === 'ar' ? 'rtl' : 'ltr')
    })
  })
}
