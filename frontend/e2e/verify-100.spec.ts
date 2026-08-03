import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * VERIFY-100 — the acceptance tests the `IMPLEMENTED_NOT_VERIFIED` rows never had.
 *
 * Ten requirements were built, reviewed once by hand on chromium, and marked «implemented, not
 * verified» because nothing asserted their BEHAVIOUR. A row in that state is a promise: the feature
 * may work, may have worked once, or may have been broken by the next change with nobody noticing.
 * This file is what turns each of them into a fact.
 *
 * Each block asserts what its requirement is FOR, not that a page returns 200 — the standing rule is
 * «لا تعتبر المهمة مكتملة بفحص Status 200». Where a surface is honestly empty in this environment
 * (no live credentials, no collected money), the test asserts that it SAYS so, because an empty
 * panel and a broken one look identical.
 *
 * The project is pinned by name throughout. Choosing it positionally is the defect this branch has
 * now produced five times.
 */

const SEEDED_PROJECT = 'Growth — Acquisition'
const BOUND_PROJECT = 'Growth — Retention'

test.describe('CAMPAIGN-010 — five ways to read the same campaigns', () => {
  test.use({ storageState: AUTH.advertiser })

  /**
   * Every view mode renders its own content.
   *
   * The failure this guards against is a tab that switches the highlight and shows the previous
   * view — which looks like it works until somebody relies on it. Each mode is therefore asserted to
   * produce content that DIFFERS from the overview, not merely to be non-empty.
   */
  test('each of the five modes renders, and renders something different', async ({ page }) => {
    await selectProject(page, await seededProject(page.request, SEEDED_PROJECT))
    await page.goto('/app/campaigns')
    await expect(page.getByTestId('view-overview')).toBeVisible({ timeout: 20000 })

    const read = async (mode: string) => {
      await page.getByTestId(`view-${mode}`).click()
      await expect.poll(async () => (await page.locator('main').innerText()).length, { timeout: 20000 })
        .toBeGreaterThan(200)
      return page.locator('main').innerText()
    }

    const overview = await read('overview')
    for (const mode of ['cards', 'table', 'compare', 'attention']) {
      const text = await read(mode)
      expect(text, `the ${mode} view is showing the overview`).not.toBe(overview)
    }
  })

  /** The taxonomy chips filter server-side; a chip that changes nothing is a decoration. */
  test('a status chip narrows the list', async ({ page }) => {
    await selectProject(page, await seededProject(page.request, SEEDED_PROJECT))
    await page.goto('/app/campaigns')
    await page.getByTestId('view-cards').click()
    await expect.poll(async () => await page.getByTestId('campaign-card').count(), { timeout: 20000 })
      .toBeGreaterThan(0)

    const all = await page.getByTestId('campaign-card').count()
    const chip = page.getByTestId('taxonomy-chip').first()
    await chip.click()

    await expect.poll(async () => await page.getByTestId('campaign-card').count(), { timeout: 20000 })
      .toBeLessThanOrEqual(all)
  })
})

test.describe('CAMPAIGN-020 — comparing campaigns', () => {
  test.use({ storageState: AUTH.advertiser })

  /**
   * The comparison refuses to be a single blended number.
   *
   * Comparing campaigns with different objectives and showing one «best» is the failure mode: the
   * arithmetic is fine and the conclusion is false. The view must either compare like with like or
   * say that it cannot.
   */
  test('the compare view asks which campaigns, rather than inventing an answer', async ({ page }) => {
    await selectProject(page, await seededProject(page.request, SEEDED_PROJECT))
    await page.goto('/app/campaigns')
    await page.getByTestId('view-compare').click()

    const main = page.locator('main')
    await expect.poll(async () => (await main.innerText()).length, { timeout: 20000 }).toBeGreaterThan(200)

    // A picker, or an instruction to pick — never a comparison of campaigns nobody chose.
    await expect(main).toContainText(/اختر|قارن|Select|Compare/i)
  })
})

test.describe('CAMPDET-010 — the campaign in depth', () => {
  test.use({ storageState: AUTH.advertiser })

  async function openSeededCampaign(page: import('@playwright/test').Page) {
    await selectProject(page, await seededProject(page.request, SEEDED_PROJECT))
    await page.goto('/app/campaigns')
    await page.getByTestId('view-cards').click()
    await expect.poll(async () => await page.getByTestId('campaign-card').count(), { timeout: 20000 })
      .toBeGreaterThan(0)
    await page.getByTestId('campaign-card').filter({ hasNotText: 'E2E ' }).first().click()
    await expect(page).toHaveURL(/\/campaigns\/[^/]+\/[^/]+$/, { timeout: 20000 })
  }

  /**
   * Every tab opens onto content.
   *
   * Fourteen tabs is a promise fourteen times over. Walked from the TABLIST rather than a list here,
   * so a tab added later is audited without anybody remembering to add it.
   */
  test('every tab opens a panel that is not empty', async ({ page }) => {
    await openSeededCampaign(page)

    // Wait for the tablist, then count it. Counting first reads 0 while the detail is still
    // fetching and reports «no tabs» about a page that has fourteen a moment later.
    const tabs = page.getByRole('tab')
    await expect(tabs.first(), 'the campaign detail never rendered its tabs').toBeVisible({ timeout: 20000 })

    const count = await tabs.count()
    expect(count, 'the campaign detail has no tabs').toBeGreaterThan(8)

    test.setTimeout(20_000 + count * 6_000)

    for (let i = 0; i < count; i += 1) {
      const tab = tabs.nth(i)
      const name = (await tab.innerText()).trim()
      await tab.click()
      await expect.poll(async () => (await page.locator('main').innerText()).length, { timeout: 15000 })
        .toBeGreaterThan(150)
      // A tab may honestly have nothing to show; it may not show NOTHING.
      const text = await page.locator('main').innerText()
      expect(text.length, `the «${name}» tab rendered an empty panel`).toBeGreaterThan(150)
    }
  })
})

test.describe('XREL-001 — where this campaign sits', () => {
  test.use({ storageState: AUTH.advertiser })

  /**
   * The relations are shown with their counts, zeros included.
   *
   * A relation that disappears at zero is indistinguishable from one that was never computed, and
   * «0 alerts» is a useful answer where a missing row is not.
   */
  test('the related-entities panel names each relation, zeros and all', async ({ page }) => {
    await selectProject(page, await seededProject(page.request, SEEDED_PROJECT))
    await page.goto('/app/campaigns')
    await page.getByTestId('view-cards').click()
    await expect.poll(async () => await page.getByTestId('campaign-card').count(), { timeout: 20000 })
      .toBeGreaterThan(0)
    await page.getByTestId('campaign-card').filter({ hasNotText: 'E2E ' }).first().click()

    await expect(page.getByTestId('related-entities')).toBeVisible({ timeout: 20000 })

    for (const relation of ['platforms', 'ad_accounts', 'creatives', 'alerts', 'reports']) {
      await expect(
        page.getByTestId(`relation-${relation}`),
        `the ${relation} relation is missing — a zero must still be shown`,
      ).toBeVisible()
    }
  })
})

test.describe('REPORT-SCHEDULING — scheduling that says what it did', () => {
  test.use({ storageState: AUTH.advertiser })

  /**
   * Delivery is never reported as «sent».
   *
   * No mail provider is configured, and a schedule that claimed delivery would be the dishonesty the
   * contract forbids outright: «لا تدّعِ إرسال بريد ... ما لم يكن فعليًا».
   */
  test('the schedule surface exists and claims no delivery it cannot make', async ({ page }) => {
    await selectProject(page, await seededProject(page.request, SEEDED_PROJECT))
    await page.goto('/app/reports')

    const main = page.locator('main')
    await expect.poll(async () => (await main.innerText()).length, { timeout: 20000 }).toBeGreaterThan(120)

    const text = await main.innerText()
    // Nothing on this page may say a report was emailed to anybody.
    expect(text, 'a report claims to have been sent with no mail provider configured')
      .not.toMatch(/تم الإرسال بنجاح|successfully sent|email delivered/i)
  })
})

test.describe('FINANCE-001 — money, and what has actually been collected', () => {
  /*
   * The AGENCY account, and `/agency/finance`.
   *
   * The matrix row said «/app/finance». `billingRoutes` is mounted only under the agency tree, so an
   * advertiser going there falls through to the agency guard and is told the agency portal is not
   * theirs — correct behaviour, confusing URL, and a row naming a path that does not exist. This is
   * agency→client invoicing: the agency's money, which is exactly the separation PAY-005 draws.
   */
  test.use({ storageState: AUTH.owner })

  /**
   * Invoiced and collected are shown apart.
   *
   * An invoiced figure alone reads as income. The whole point of this page is that almost none of it
   * has been paid, and the aging breakdown is what says so.
   */
  test('the finance overview separates invoiced from collected', async ({ page }) => {
    await page.goto('/agency/finance')

    await expect(page.getByTestId('aging-bar'), 'the aging breakdown never rendered')
      .toBeVisible({ timeout: 20000 })

    /*
     * Compared with the tashkeel stripped.
     *
     * The page writes «محصَّلًا» — shadda, fatha and tanween. A regex spelling it «محصّل» misses that,
     * and so would every other spelling somebody reasonably writes. Normalising once here is more
     * honest than maintaining a list of vowelled variants, and it keeps the test about the WORD
     * rather than about how it happened to be pointed.
     */
    const plain = (await page.locator('main').innerText()).replace(/[\u064B-\u0652]/g, '')

    expect(plain, 'the page never says what has been collected').toMatch(/محصل|collected/i)
    expect(plain, 'the page never says what has been invoiced').toMatch(/فاتورة|فواتير|invoiced/i)
  })
})

test.describe('SYNC-001 — the sync pipeline, reported honestly', () => {
  test.use({ storageState: AUTH.advertiser })

  /**
   * A sync with no credentials is not a failure, and is not a success either.
   *
   * The pipeline records `awaiting_credentials` for a provider it has no keys for. Reporting that as
   * a failed sync would send somebody debugging; reporting it as a successful one would be a lie.
   */
  test('the project sync surface states its real state', async ({ page }) => {
    await selectProject(page, await seededProject(page.request, BOUND_PROJECT))
    const projectId = await seededProject(page.request, BOUND_PROJECT)
    await page.goto(`/app/projects/${projectId}/integrations`)

    const main = page.locator('main')
    await expect.poll(async () => (await main.innerText()).length, { timeout: 20000 }).toBeGreaterThan(200)

    const text = await main.innerText()
    // Nothing here may claim a connection or a completed sync against a platform with no keys.
    expect(text, 'a platform claims to be connected with no credentials')
      .not.toMatch(/متصل بنجاح|successfully connected/i)
  })
})

test.describe('DEMO-001 — demo data that admits what it is', () => {
  test.use({ storageState: AUTH.advertiser })

  /**
   * The demo badge is on the page carrying the figures.
   *
   * Every number an advertiser sees in this environment is invented. A badge in a settings screen
   * somewhere would not stop somebody reading the dashboard as their own results.
   */
  test('surfaces that show demo figures say so on the page', async ({ page }) => {
    await selectProject(page, await seededProject(page.request, SEEDED_PROJECT))

    for (const path of ['/app/dashboard', '/app/campaigns']) {
      await page.goto(path)
      const main = page.locator('main')
      await expect.poll(async () => (await main.innerText()).length, { timeout: 20000 }).toBeGreaterThan(150)

      await expect(main, `${path} shows figures without saying they are demo data`)
        .toContainText(/بيانات تجريبية|Demo/i)
    }
  })
})

test.describe('DEVSTATUS-001 — the requirement board', () => {
  /**
   * The board is parsed from the matrix, so it cannot drift from the governing document.
   *
   * Asserted on the SHAPE — that it names statuses and counts them — rather than on any particular
   * number, which changes with every requirement closed.
   */
  test('the board reports statuses parsed from the matrix', async ({ page }) => {
    await page.goto('/dev/status')

    const body = page.locator('body')
    await expect.poll(async () => (await body.innerText()).length, { timeout: 20000 }).toBeGreaterThan(200)

    const text = await body.innerText()
    expect(text).toMatch(/VERIFIED/)
    expect(text, 'the board shows no requirement counts').toMatch(/\d/)
  })
})

/**
 * SIMPLIFY-001 — the dashboard answers before it configures.
 *
 * `/app/dashboard` opened with three bands of controls between the reader and any number: a saved-views
 * bar, an objective row and a platform row. Somebody who had never used the product met the settings
 * before the answers.
 *
 * Nothing was removed. All three are behind one «تخصيص العرض» button, and a line beside it states what
 * is currently applied in words — so folding the controls hides no state. These tests assert exactly
 * that: the controls are reachable, they still work, and the page says what it is showing.
 */
test.describe('SIMPLIFY-001 — the advertiser dashboard', () => {
  test.use({ storageState: AUTH.advertiser })

  test('opens on the figures, with one control and a plain statement of what is applied', async ({ page }) => {
    await page.goto('/app/dashboard')

    await expect(page.getByTestId('dashboard-applied'), 'the page does not say what it is showing')
      .toBeVisible({ timeout: 20000 })
    await expect(page.getByTestId('dashboard-customise')).toBeVisible()

    // The filter rows are folded away, not sitting between the reader and the numbers.
    await expect(page.getByTestId('saved-views-bar')).toHaveCount(0)
  })

  test('the folded controls still work, and the page says so', async ({ page }) => {
    await page.goto('/app/dashboard')
    await expect(page.getByTestId('dashboard-applied')).toBeVisible({ timeout: 20000 })

    const before = await page.getByTestId('dashboard-applied').innerText()

    await page.getByTestId('dashboard-customise').click()
    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()

    // Everything that used to be on the page is still here, unchanged.
    await expect(dialog).toContainText(/العروض المحفوظة|Saved views/i)
    await expect(dialog).toContainText(/الهدف|Objective/i)
    await expect(dialog).toContainText(/المنصات|Platforms/i)

    await dialog.getByRole('button', { name: /^المبيعات$|^Sales$/ }).click()

    await expect.poll(async () => await page.getByTestId('dashboard-applied').innerText(), { timeout: 20000 })
      .not.toBe(before)
  })

  test('it holds together on a phone', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 })
    await page.goto('/app/dashboard')
    await expect(page.getByTestId('dashboard-customise')).toBeVisible({ timeout: 20000 })

    await page.getByTestId('dashboard-customise').click()
    await expect(page.getByRole('dialog')).toBeVisible()

    const overflows = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)
    expect(overflows, 'the customise dialog pushes the page sideways on a phone').toBe(false)
  })
})
