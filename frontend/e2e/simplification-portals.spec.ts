import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * `/app`, `/admin` and `/portal` after the simplification pass (SIMPLIFY-003/004/005).
 *
 * The agency portal has its own file. This one covers the other three and the rule they share:
 * **a page opens on its data, and whatever narrows that data folds behind one control that says what
 * is applied.**
 *
 * The regression these exist to prevent is a page drifting back to settings-first. That is not a
 * hypothetical: it is how every one of these pages got that way — a status row, then a type row, then
 * a toggle, each reasonable on its own.
 */

test.describe('the advertiser portal opens on its data', () => {
  test.use({ storageState: AUTH.advertiser })

  /**
   * Reports and Files fold their filters; search and the view switchers stay out.
   *
   * Campaigns is deliberately NOT in this list. Its chips carry live counts («الكل ٢٥», «نشطة ١٢»),
   * which makes them information rather than configuration — folding them would hide data, not
   * settings. Projects is out for the same kind of reason: one status row beside search is already
   * simple, and folding it would add a click for nothing.
   */
  const PAGES = [
    { id: 'reports', path: '/app/reports', empty: /كل التقارير|All reports/ },
    { id: 'files', path: '/app/files', empty: /كل الملفات|All files/ },
  ] as const

  for (const p of PAGES) {
    test(`${p.id}: one filter control, and a sentence saying what is shown`, async ({ page }) => {
      await page.goto(p.path)

      await expect(page.getByTestId(`${p.id}-customise`), 'no single filter control')
        .toBeVisible({ timeout: 20000 })
      await expect(page.getByTestId(`${p.id}-applied`), 'the page never says what it is showing')
        .toContainText(p.empty)
    })

    test(`${p.id}: the folded controls still work`, async ({ page }) => {
      await page.goto(p.path)
      await expect(page.getByTestId(`${p.id}-customise`)).toBeVisible({ timeout: 20000 })

      const before = await page.getByTestId(`${p.id}-applied`).innerText()
      await page.getByTestId(`${p.id}-customise`).click()

      // Scoped to the body: the modal's close «X» is a text-less button and would otherwise be first.
      const controls = page.getByTestId(`${p.id}-customise-body`)
      await expect(controls).toBeVisible()
      await controls.getByRole('button').filter({ hasNotText: /^\s*(الكل|All)\s*$/ }).first().click()

      await expect
        .poll(async () => page.getByTestId(`${p.id}-applied`).innerText(), { timeout: 20000 })
        .not.toBe(before)
    })
  }

  /**
   * Search is never folded away.
   *
   * Searching is how somebody finds a row they already have in mind. Burying it would make the page
   * harder to use, which is the opposite of the point — so it is asserted to stay on the page.
   */
  test('search stays on the page, outside the dialog', async ({ page }) => {
    await page.goto('/app/files')
    await expect(page.getByTestId('files-customise')).toBeVisible({ timeout: 20000 })

    const search = page.locator('main input[type="text"], main input:not([type])').first()
    await expect(search, 'the search box was folded away with the filters').toBeVisible()
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
    await expect(advanced, 'there is no advanced section').toBeVisible({ timeout: 20000 })
    await expect(advanced).toContainText(/انتقال بوابة العملاء|Portal cutover/)
    await expect(advanced).toContainText(/وسائل الدفع|Payment methods/)
  })

  /** Separated is not hidden: both still open. */
  test('the advanced destinations still open', async ({ page }) => {
    for (const path of ['/admin/cutover', '/admin/settings/integrations/payments']) {
      await page.goto(path)
      await expect(page.locator('main'), `${path} rendered nothing`).toBeVisible({ timeout: 20000 })
      await expect(page.locator('main'), `${path} is a not-found page`)
        .not.toContainText(/الصفحة غير موجودة|Page not found/i)
    }
  })

  /** Every daily entry still opens — reordering a rail must not strand a page. */
  test('every daily destination still opens', async ({ page }) => {
    const paths = ['/admin', '/admin/registrations', '/admin/tenants', '/admin/billing', '/admin/audit', '/admin/settings']
    test.setTimeout(20_000 + paths.length * 8_000)

    for (const path of paths) {
      await page.goto(path)
      await expect(page.locator('main'), `${path} rendered nothing`).toBeVisible({ timeout: 20000 })
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
    await expect(nav).toBeVisible({ timeout: 20000 })

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
    test.setTimeout(20_000 + paths.length * 8_000)

    for (const path of paths) {
      await page.goto(path)
      await expect(page.locator('main')).toBeVisible({ timeout: 20000 })

      const text = await page.locator('main').innerText()
      expect(text, `${path} shows the client internal plumbing`)
        .not.toMatch(/provider_key|binding|external_account|sync_run|tenant_id|awaiting_credentials/i)
    }
  })
})
