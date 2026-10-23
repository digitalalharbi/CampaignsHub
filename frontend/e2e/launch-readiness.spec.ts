import { expect, test, type Page } from '@playwright/test'
import { AUTH, seededProject, selectProject, switchToEnglish } from './helpers'

/**
 * LAUNCH-READINESS-001 — the Tracking step's readiness table, drawn from FIXTURES.
 *
 * The seeded demo accounts cannot be chosen (no platform credentials), so the step is unreachable on real data; the launch endpoints are
 * answered here with fixed accounts instead — selectable ones, one per readiness story — and nothing is sent anywhere: no write route is
 * intercepted because none is ever called, and the page only reads. Production data is never touched.
 *
 * What is asserted is what the Owner looked at: the account list stays clean, the table carries one monochrome mark per event with the
 * words in the legend and the accessible name, nothing says an absent event «does not exist», and it fits a phone.
 */
test.use({ storageState: AUTH.owner })

const ok = (data: unknown) => ({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, message: 'ok', data, meta: null, errors: null }) })

const events = (s: Record<string, [string, number]>) => Object.fromEntries(
  Object.entries({ page_views: 'PageView', add_to_cart: 'AddToCart', leads: 'Lead', purchases: 'Purchase' }).map(([k, label]) => [k, { event: label, status: s[k]![0], observed: s[k]![1] }]),
)

const account = (id: string, name: string, tier: string, data: string, ev: Record<string, [string, number]>) => ({
  id, provider: 'meta', name, currency: 'SAR', state: 'available', launch_permitted: true, objectives: ['OUTCOME_SALES', 'OUTCOME_TRAFFIC'],
  readiness: { provider: 'meta', tier, data, pixel: { status: 'unavailable', reason: 'not_readable_from_platform_api' }, events: events(ev) },
})

const OPTIONS = {
  accounts: [
    account('fx-ready', 'Fixture — ready for sales', 'sales_ready', 'synced', { page_views: ['eligible', 900], add_to_cart: ['eligible', 300], leads: ['not_reported', 0], purchases: ['eligible', 90] }),
    account('fx-partial', 'Fixture — partial tracking', 'partial', 'synced', { page_views: ['eligible', 700], add_to_cart: ['eligible', 140], leads: ['not_reported', 0], purchases: ['proven', 4] }),
    account('fx-new', 'Fixture — nothing synced yet', 'none', 'none', { page_views: ['no_data', 0], add_to_cart: ['no_data', 0], leads: ['no_data', 0], purchases: ['no_data', 0] }),
  ],
  readiness: { window_days: 30, eligible_events: 50, project: { store_linked: false, ga4_linked: false, live_conversion_destinations: [] } },
  providers: [{ provider: 'meta', objectives: ['OUTCOME_SALES', 'OUTCOME_TRAFFIC'], optimization_goals: ['OFFSITE_CONVERSIONS'], bid_strategies: [], placement_families: [], ad_set_refusal: null, ad_refusal: null, ad_fields: 'creative', countries_in_ad_set: true, bid_required: false }],
  creatives: { meta: [] },
  launch_permitted: true,
}

async function toTrackingStep(page: Page, projectId: string) {
  await selectProject(page, projectId)
  await page.route('**/api/v1/projects/*/launch/options', (route) => route.fulfill(ok(OPTIONS)))
  await page.route('**/api/v1/projects/*/launch/plans', (route) => (route.request().method() === 'GET' ? route.fulfill(ok([])) : route.abort()))
  await page.goto('/agency/launch')
  await expect(page.getByTestId('launch-step-accounts')).toBeVisible({ timeout: 30_000 })

  // The account list: just the accounts — no event text, no readiness widget.
  const list = page.getByTestId('launch-accounts')
  await expect(list.locator('li')).toHaveCount(3)
  await expect(list.getByTestId('launch-readiness')).toHaveCount(0)
  expect(await list.innerText()).not.toMatch(/Purchase|PageView|الشراء|No data|لا بيانات/)

  await page.getByTestId('launch-account-fx-ready').check()
  await page.getByTestId('launch-account-fx-partial').check()
  await page.getByTestId('launch-account-fx-new').check()
  await page.getByTestId('launch-next').click()

  await page.getByTestId('launch-name').fill('Fixture campaign')
  // The objective picker of the step itself (the shell has comboboxes of its own — the client and project switchers).
  await page.locator('[data-testid^="launch-step-"]').getByRole('combobox').first().click()
  await page.getByRole('option', { name: /OUTCOME_SALES|المبيعات|Sales/ }).first().click()
  for (let i = 0; i < 3; i += 1) await page.getByTestId('launch-next').click()
  await expect(page.getByTestId('launch-step-tracking')).toBeVisible()
}

async function assertTable(page: Page) {
  const table = page.getByTestId('launch-readiness')
  await expect(table).toBeVisible()
  await expect(table.locator('tbody tr')).toHaveCount(3)
  const status = (id: string, k: string) => table.getByTestId(`launch-event-${id}-${k}`).getAttribute('data-status')
  expect(await status('fx-ready', 'purchases')).toBe('eligible')
  expect(await status('fx-partial', 'purchases')).toBe('proven')
  expect(await status('fx-partial', 'leads')).toBe('not_reported')
  expect(await status('fx-new', 'purchases')).toBe('no_data')
  // The four states are told apart, and the legend names all four.
  await expect(table.getByTestId('launch-readiness-legend').locator('span')).toHaveCount(4)
  // One monochrome SVG per cell, no emoji anywhere in the table.
  await expect(table.locator('tbody td svg')).toHaveCount(12)
  expect(await table.innerText()).not.toMatch(/\p{Extended_Pictographic}/u)
  // Nothing claims an event does not exist, and nothing claims the pixel.
  expect(await table.innerText()).not.toMatch(/does not exist|غير موجود|pixel/i)
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow, 'page-level horizontal overflow').toBeLessThanOrEqual(0)
}

test('the readiness table in Arabic on a desktop', async ({ page, request }, testInfo) => {
  await toTrackingStep(page, await seededProject(request, 'متجر تجريبي — Demo'))
  await assertTable(page)
  await page.getByTestId('launch-step-tracking').screenshot({ path: testInfo.outputPath('tracking-ar-desktop.png') })
})

test('the readiness table in English on a desktop', async ({ page, request }, testInfo) => {
  await toTrackingStep(page, await seededProject(request, 'متجر تجريبي — Demo'))
  await switchToEnglish(page)
  await expect(page.getByTestId('launch-step-tracking')).toBeVisible()
  await assertTable(page)
  await expect(page.getByTestId('launch-readiness-legend')).toContainText('No data yet')
  await page.getByTestId('launch-step-tracking').screenshot({ path: testInfo.outputPath('tracking-en-desktop.png') })
})

test('the readiness table fits a 375px phone', async ({ page, request }, testInfo) => {
  await page.setViewportSize({ width: 375, height: 812 })
  await toTrackingStep(page, await seededProject(request, 'متجر تجريبي — Demo'))
  await assertTable(page)
  await page.getByTestId('launch-step-tracking').screenshot({ path: testInfo.outputPath('tracking-ar-375.png') })
})
