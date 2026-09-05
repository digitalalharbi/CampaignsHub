import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'
import { RAIL_PAINT_TIMEOUT, RAIL_PATH_BUDGET } from './railWalkTimeout'

/**
 * `/app`, `/admin` and `/portal` — UX-FILTERS-001, which narrowed the scope of SIMPLIFY-003/004/005.
 *
 * These tests used to assert the opposite of what they assert now, and that is deliberate rather
 * than a repair. SIMPLIFY folded EVERY filter on these pages behind one button; the rule since is a
 * division: **the axes somebody reaches for daily are on the page, and only the rare ones fold.**
 *
 * What survives from the original intent, unchanged, is the thing those tests were really protecting
 * — a page must not drift back to settings-first, and a narrowed page must say what narrowed it. It
 * says so with removable chips now instead of a sentence, which is strictly more: a sentence could
 * report «2 platforms» and offer no way to drop one of them.
 *
 * The regression this still prevents is a daily control disappearing behind a dialog.
 */

test.describe('the advertiser portal opens on its data, with its filters on it', () => {
  test.use({ storageState: AUTH.advertiser })

  /**
   * Campaigns is deliberately NOT in this list. Its chips carry live counts («الكل ٢٥», «نشطة ١٢»),
   * which makes them information rather than configuration, and it never folded them.
   */
  const PAGES = [
    { id: 'reports', path: '/app/reports' },
    { id: 'files', path: '/app/files' },
    { id: 'tasks', path: '/app/tasks' },
  ] as const

  for (const p of PAGES) {
    test(`${p.id}: the filters are on the page, not behind a button`, async ({ page }) => {
      await page.goto(p.path)

      const bar = page.getByTestId(`${p.id}-filters`)
      await expect(bar, 'no visible filter bar').toBeVisible({ timeout: RAIL_PAINT_TIMEOUT })

      // At least one real narrowing control, reachable without opening anything.
      await expect(bar.locator('select').first()).toBeVisible()

      // Nothing is applied on arrival, so there is no chip row and nothing to reset.
      await expect(page.getByTestId(`${p.id}-applied`)).toHaveCount(0)
      await expect(page.getByRole('dialog')).toHaveCount(0)
    })

    test(`${p.id}: narrowing names itself as a chip that can be undone`, async ({ page }) => {
      await page.goto(p.path)
      const bar = page.getByTestId(`${p.id}-filters`)
      await expect(bar).toBeVisible({ timeout: RAIL_PAINT_TIMEOUT })

      // Pick the first non-default value of the first select on the bar.
      const select = bar.locator('select').first()
      const values = await select.locator('option').evaluateAll((os) =>
        os.map((o) => (o as HTMLOptionElement).value),
      )
      const chosen = values.find((v) => v !== '' && v !== 'all')
      expect(chosen, 'the filter offers nothing to choose').toBeTruthy()
      await select.selectOption(chosen!)

      // The page now says what is narrowing it, and offers the way back.
      await expect(page.getByTestId(`${p.id}-applied`)).toBeVisible({ timeout: RAIL_PAINT_TIMEOUT })
      await page.getByTestId(`${p.id}-reset`).click()
      await expect(page.getByTestId(`${p.id}-applied`)).toHaveCount(0)
    })
  }

  /**
   * Search is never folded away.
   *
   * Searching is how somebody finds a row they already have in mind. Burying it would make the page
   * harder to use, which is the opposite of the point — so it is asserted to stay on the page.
   */
  test('search stays on the page, beside the filters', async ({ page }) => {
    await page.goto('/app/files')
    await expect(page.getByTestId('files-filters')).toBeVisible({ timeout: RAIL_PAINT_TIMEOUT })
    await expect(page.getByTestId('files-search'), 'the search box was folded away').toBeVisible()
  })
})

test.describe('the platform console separates daily work from rare tools', () => {
  test.use({ storageState: AUTH.admin })

  /**
   * The one-time migration tool and a settings sub-page are under «متقدم», not beside the queue.
   *
   * `/admin/cutover` retires the client portal's OTP engine — it is run once, ever. Payment methods
   * is a page inside System settings that also had its own rail entry, which is the same destination
   * under two headings.
   */
  test('rare tools are separated but still reachable', async ({ page }) => {
    await page.goto('/admin')

    const advanced = page.getByTestId('admin-advanced-nav')
    await expect(advanced, 'there is no advanced section').toBeVisible({ timeout: RAIL_PAINT_TIMEOUT })
    await expect(advanced).toContainText(/انتقال بوابة العملاء|Portal cutover/)
    await expect(advanced).toContainText(/وسائل الدفع|Payment methods/)
  })

  /** Separated is not hidden: they still open. */
  test('the advanced destinations still open', async ({ page }) => {
    for (const path of [
      '/admin/cutover',
      '/admin/settings/integrations/payments',
      // FX-FEED-001 — the rate supply. Advanced because once a source is configured nobody opens it
      // again, and it must still render on an install where no source has ever been configured.
      '/admin/settings/currency-rates',
    ]) {
      await page.goto(path)
      await expect(page.locator('main'), `${path} rendered nothing`).toBeVisible({ timeout: RAIL_PAINT_TIMEOUT })
      await expect(page.locator('main'), `${path} is a not-found page`)
        .not.toContainText(/الصفحة غير موجودة|Page not found/i)
    }
  })

  /** Every daily entry still opens — reordering a rail must not strand a page. */
  test('every daily destination still opens', async ({ page }) => {
    const paths = ['/admin', '/admin/registrations', '/admin/tenants', '/admin/billing', '/admin/audit', '/admin/settings']
    test.setTimeout(RAIL_PAINT_TIMEOUT + paths.length * RAIL_PATH_BUDGET)

    for (const path of paths) {
      await page.goto(path)
      await expect(page.locator('main'), `${path} rendered nothing`).toBeVisible({ timeout: RAIL_PAINT_TIMEOUT })
    }
  })
})

test.describe('the client portal leads with results, not paperwork', () => {
  test.use({ storageState: AUTH.client })

  /**
   * A client signs in to learn how their advertising is doing.
   *
   * The rail put Quotes and Invoices above Campaigns and Reports; the results were fifth and eighth,
   * below two pages about billing. Asserted on ORDER rather than presence, because every one of these
   * was already there — being present in the wrong place is exactly the defect.
   */
  test('campaigns and reports come before quotes and invoices', async ({ page }) => {
    await page.goto('/portal')

    const nav = page.getByRole('navigation').first()
    await expect(nav).toBeVisible({ timeout: RAIL_PAINT_TIMEOUT })

    const labels = await nav.locator('a').evaluateAll((els) =>
      els.map((e) => (e as HTMLElement).innerText.trim()).filter(Boolean),
    )

    const at = (re: RegExp) => labels.findIndex((l) => re.test(l))
    const campaigns = at(/الحملات|Campaigns/)
    const reports = at(/التقارير|Reports/)
    const quotes = at(/عروض الأسعار|Quotes/)
    const invoices = at(/الفواتير|Invoices/)

    expect(campaigns, 'campaigns is missing from the client rail').toBeGreaterThan(-1)
    expect(reports, 'reports is missing from the client rail').toBeGreaterThan(-1)
    expect(campaigns, 'the client meets quotes before their campaigns').toBeLessThan(quotes)
    expect(reports, 'the client meets invoices before their results').toBeLessThan(invoices)
  })

  /**
   * The client is never shown the agency's plumbing.
   *
   * Provider keys, bindings and sync internals are things a client cannot act on and should not have
   * to read past. This walks their pages and asserts none of that vocabulary appears.
   */
  test('no operator vocabulary reaches the client', async ({ page }) => {
    const paths = ['/portal', '/portal/campaigns', '/portal/reports', '/portal/invoices']
    test.setTimeout(RAIL_PAINT_TIMEOUT + paths.length * RAIL_PATH_BUDGET)

    for (const path of paths) {
      await page.goto(path)
      await expect(page.locator('main')).toBeVisible({ timeout: RAIL_PAINT_TIMEOUT })

      const text = await page.locator('main').innerText()
      expect(text, `${path} shows the client internal plumbing`)
        .not.toMatch(/provider_key|binding|external_account|sync_run|tenant_id|awaiting_credentials/i)
    }
  })
})
