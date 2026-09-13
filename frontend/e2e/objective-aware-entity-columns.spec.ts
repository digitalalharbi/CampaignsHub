import { expect, test, type Page } from '@playwright/test'
import { AUTH, seededProject, selectProject, switchToEnglish } from './helpers'

/**
 * OBJECTIVE-ANALYTICS-DEPTH-001 — the Ads table's columns follow what the rows were bought for.
 *
 * ## What this proves that the unit guards cannot
 *
 * `entityObjectiveColumns.test.ts` proves the column PLAN and `entityTabs.test.tsx` proves the table
 * obeys it from a fixture. Neither proves that the objective reaches the page at all: it travels from
 * `unified_campaigns` through `EntityMetricsAggregator` into the row, and a fixture supplies it
 * directly. If that journey broke, both unit files would stay green and every table would silently
 * fall back to the mixed set — the generic columns this row exists to replace.
 *
 * So this reads the rendered table on real seeded data, through the real endpoint, with the real
 * objective filter.
 *
 * ## The seed had to be able to produce a mixture
 *
 * Every campaign in `DemoAnalyticsSeeder::CAMPAIGNS` is `sales`, and only ads with
 * `entity_daily_metrics` reach this table — which meant one objective in the whole demo world. An
 * awareness campaign is seeded now, and `DemoAdCreativeLinkSeeder` gives at least one ad per objective
 * its ad-level rows, so the unfiltered table genuinely spans two families and the narrowed ones
 * genuinely hold a single family. Without that, «mixed» was unreachable and «single family» could not
 * be told apart from the fixed column set it replaced.
 */
const PROJECT = 'متجر تجريبي — Demo'

const COPY = {
  ar: {
    ads: /^الإعلانات$/,
    spend: 'الإنفاق',
    roas: 'العائد على الإنفاق',
    costPerResult: 'تكلفة النتيجة',
    reach: 'الوصول',
    mixed: /أهدافًا مختلفة/,
  },
  en: {
    ads: /^Ads$/,
    spend: 'Spend',
    roas: 'Return on ad spend',
    costPerResult: 'Cost per result',
    reach: 'Reach',
    mixed: /span different objectives/i,
  },
}

async function openAds(page: Page, locale: 'ar' | 'en') {
  await page.goto('/agency/analytics')

  if (locale === 'en') {
    await switchToEnglish(page)
  }

  const tab = page.getByRole('tab', { name: COPY[locale].ads })
  await tab.click()
  await expect(tab).toHaveAttribute('aria-selected', 'true')

  return page.getByTestId('entity-table-ad')
}

/**
 * The page's own objective control, driven by VALUE rather than by label.
 *
 * `FilterSelect` renders a NATIVE `<select>`, so this is `selectOption` and not a click on a listbox
 * option. A first version clicked a `role="combobox"` inside the testid and timed out on every case
 * that narrowed the filter — seven of fourteen — which read like the columns failing to follow the
 * objective when nothing had been narrowed at all. Driving by value also keeps this working in both
 * locales, where the option LABELS differ.
 */
async function narrowObjective(page: Page, value: string) {
  await page.getByTestId('analytics-objective').selectOption(value)
  await page.waitForLoadState('networkidle')
}

for (const locale of ['ar', 'en'] as const) {
  const t = COPY[locale]

  test.describe(`the ads table follows the objective (${locale})`, () => {
    test.use({ storageState: AUTH.owner })

    test('refuses a blended verdict across objectives, and says why', async ({ page, request }) => {
      await selectProject(page, await seededProject(request, PROJECT))
      const table = await openAds(page, locale)
      await expect(table).toBeVisible({ timeout: 30000 })

      /* The demo now holds a sales family and an awareness family, so the unfiltered table is mixed. */
      await expect(page.getByTestId('entity-mixed-objectives-ad')).toBeVisible()
      await expect(page.getByTestId('entity-mixed-objectives-ad')).toHaveText(t.mixed)

      /*
       * Neither family's verdict, because neither is true of the whole list: a return over a scope half
       * of which was never bought to earn is not a return, and a cost per result spanning a brand budget
       * and a sales budget divides one objective's money by another objective's events.
       *
       * Asserted on the HEADER ROW, not on the table's text. The explanatory sentence names the two
       * metrics it is refusing — «العائد وتكلفة النتيجة لا يصحّان عبر أهداف اشترت أشياء مختلفة» — so a
       * whole-table absence check fails on the very notice that proves the refusal happened. A first
       * version did exactly that and reported the Arabic case as a defect.
       */
      const headings = table.locator('thead')
      await expect(headings).not.toContainText(t.roas)
      await expect(headings).not.toContainText(t.costPerResult)

      /* A refusal to blend, not a refusal to report. */
      await expect(table).toContainText(t.spend)
    })

    test('gives a sales-only table its own return, with no excuse attached', async ({ page, request }) => {
      await selectProject(page, await seededProject(request, PROJECT))
      const table = await openAds(page, locale)
      await expect(table).toBeVisible({ timeout: 30000 })

      await narrowObjective(page, 'sales')

      await expect(table.locator('thead')).toContainText(t.roas)
      await expect(page.getByTestId('entity-mixed-objectives-ad')).toHaveCount(0)
      await expect(table).toContainText(t.spend)
    })

    test('gives an awareness-only table reach, and no cost per order', async ({ page, request }) => {
      await selectProject(page, await seededProject(request, PROJECT))
      const table = await openAds(page, locale)
      await expect(table).toBeVisible({ timeout: 30000 })

      await narrowObjective(page, 'awareness_engagement')

      await expect(table.locator('thead')).toContainText(t.reach)
      /* The defect in one assertion: an awareness buy priced on orders it never bought. */
      await expect(table.locator('thead')).not.toContainText(t.costPerResult)
      await expect(table).toContainText(t.spend)
    })

    /** Spend is the operational fact, and it survives every narrowing and the mixed state alike. */
    test('keeps spend through every objective state', async ({ page, request }) => {
      await selectProject(page, await seededProject(request, PROJECT))
      const table = await openAds(page, locale)
      await expect(table).toBeVisible({ timeout: 30000 })

      await expect(table).toContainText(t.spend)

      for (const value of ['sales', 'awareness_engagement', 'all']) {
        await narrowObjective(page, value)
        await expect(table, `spend vanished under «${value}»`).toContainText(t.spend)
      }

      await expect(page.locator('html')).toHaveAttribute('dir', locale === 'ar' ? 'rtl' : 'ltr')
    })
  })
}
