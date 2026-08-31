import { expect, test } from '@playwright/test'
import { AUTH, E2E_ORIGIN, seededProject } from './helpers'

/**
 * The integrations surface offers the EIGHT providers this product integrates with — INTEG-RUNTIME §2.
 *
 * ## What this spec used to be asserting
 *
 * It read `main li[data-testid="connector-card"]` from a grid of sixteen «connectors» built out of
 * `config/connectors.php`, in which every real platform was a `NullConnector` that could not
 * authorise, could not sync and existed only to be listed. Six of the sixteen were providers this
 * product does not integrate with at all, and one was the sandbox — a local fake, at the head of the
 * list, wearing a green «connected» chip, above the platforms a customer came for.
 *
 * That grid is gone with its runtime. The six ad platforms below are the real connectors, and each
 * card is the one a customer actually acts on.
 */
test.describe('the integrations surface', () => {
  test.use({ storageState: AUTH.advertiser })

  /**
   * The product's order (PLATFORM-ORDER-001) — سناب شات، تيك توك، ميتا، جوجل أدز، إكس، لينكدإن.
   *
   * The order lives in `@/lib/platforms`; this asserts the rendered result against it. Registry keys,
   * not labels: `google_ads` is one platform however it is spelled, and a label is translated.
   */
  const AD_PLATFORMS = ['snapchat', 'tiktok', 'meta', 'google_ads', 'x', 'linkedin']

  /** The two stores, which complete the eight this product integrates with. */
  const STORES = ['salla', 'zid']

  /** The STORE CARDS — a separate section, deliberately not `platform-card`. */
  async function storeKeys(page: import('@playwright/test').Page): Promise<string[]> {
    return page.locator('[data-testid="store-card"]').evaluateAll((els) =>
      els.map((el) => (el as HTMLElement).dataset.platform ?? '').filter(Boolean),
    )
  }

  /** The PLATFORM CARDS, in the order the page offers them. */
  async function platformKeys(page: import('@playwright/test').Page): Promise<string[]> {
    return page.locator('[data-testid="platform-card"]').evaluateAll((els) =>
      els.map((el) => (el as HTMLElement).dataset.platform ?? '').filter(Boolean),
    )
  }

  test('the six ad platforms are offered, in the product order', async ({ page }) => {
    await page.goto('/app/integrations')
    await expect(page.locator('main')).toBeVisible()
    await expect.poll(async () => (await platformKeys(page)).length, { timeout: 20000 }).toBe(6)

    expect(await platformKeys(page)).toEqual(AD_PLATFORMS)
  })

  /**
   * The other two of the eight — INTEG-STORES-001.
   *
   * Salla and Zid are declared in the same catalogue as the ad platforms and were reachable only
   * through a separate Stores panel, so a customer on this page saw six of the eight things this
   * product integrates with and had no way to learn the other two existed.
   */
  test('the two stores complete the eight, in their own section', async ({ page }) => {
    await page.goto('/app/integrations')
    await expect(page.locator('main')).toBeVisible()
    await expect.poll(async () => (await storeKeys(page)).length, { timeout: 20000 }).toBe(2)

    expect((await storeKeys(page)).sort()).toEqual([...STORES].sort())
    await expect(page.getByTestId('stores-heading')).toBeVisible()
  })

  /**
   * A store is not an ad platform that failed to connect.
   *
   * It has no ad account and none of the five ad-platform states. Rendered as an ad-platform card
   * those fields come out blank, and a blank on a connection card reads as a failure rather than as a
   * field that does not apply — which is why the store keys must NOT appear among the platform cards.
   */
  test('a store is never rendered as an ad platform', async ({ page }) => {
    await page.goto('/app/integrations')
    await expect.poll(async () => (await platformKeys(page)).length, { timeout: 20000 }).toBe(6)

    const platforms = await platformKeys(page)
    for (const store of STORES) {
      expect(platforms).not.toContain(store)
    }
  })

  /**
   * **The ninth provider, gone.** The sandbox is not one of the eight and is not offered as one.
   *
   * It still exists in the registry outside production, because the end-to-end suite and the demo
   * seeder need a connection to drive without a real platform credential. That is a development
   * need; listing it here made it a provider the customer could choose.
   */
  test('the local fake is not offered as a provider', async ({ page }) => {
    await page.goto('/app/integrations')
    await expect.poll(async () => (await platformKeys(page)).length, { timeout: 20000 }).toBe(6)

    expect(await platformKeys(page)).not.toContain('sandbox')
    await expect(page.locator('[data-testid="platform-card"]').filter({ hasText: /sandbox/i })).toHaveCount(0)
  })

  /**
   * Nothing claims to be connected without credentials, and no raw enum reaches the reader.
   *
   * No provider has credentials in any environment, so nothing on this page may read as connected.
   */
  test('no platform claims a connection it does not have', async ({ page }) => {
    await page.goto('/app/integrations')
    const main = page.locator('main')
    await expect(main).toBeVisible()
    await expect.poll(async () => (await main.innerText()).length, { timeout: 20000 }).toBeGreaterThan(100)

    const text = await main.innerText()
    expect(text).not.toMatch(/\bالحساب:\s*(connected|awaiting_credentials|needs_action)\b/)
  })

  /**
   * Every ad platform's card offers the action its state allows — and for two states that is NONE.
   *
   * ## What the first cut of this test got wrong
   *
   * It asserted every card carries at least one button, and every card in the gate has zero. That is
   * the PRODUCT being right: `awaiting_credentials` and `unavailable` are facts about the system's
   * configuration, not the customer's, and INTEG-UI-001 deliberately offers nothing to press for
   * either — a customer cannot obtain our OAuth app's keys, so a «Connect» button there leads to an
   * authorise URL that cannot be built. The card says the platform operator is setting it up, and
   * stops.
   *
   * No provider has credentials in any environment here, so all six are in that state and the real
   * assertion is the one below: the state is stated, the explanation is present, and there is no
   * dead control.
   */
  test('an operator-blocked platform explains itself and offers no dead control', async ({ page }) => {
    await page.goto('/app/integrations')
    await expect.poll(async () => (await platformKeys(page)).length, { timeout: 20000 }).toBe(6)

    for (const platform of AD_PLATFORMS) {
      const card = page.locator(`[data-testid="platform-card"][data-platform="${platform}"]`)
      await expect(card, `${platform} has no card`).toBeVisible()

      // The state is named on the card, never left to be inferred.
      await expect(card.getByTestId(`connector-state-${platform}`)).toBeVisible()

      const blocked = card.getByTestId(`connector-needs-operator-${platform}`)

      if (await blocked.count() > 0) {
        await expect(blocked).toBeVisible()
        await expect(
          card.getByRole('button'),
          `${platform} is waiting on the platform operator and must offer nothing to press`,
        ).toHaveCount(0)
      } else {
        await expect(
          card.getByRole('button'),
          `${platform} is in an actionable state and must offer its action`,
        ).not.toHaveCount(0)
      }
    }
  })

  /**
   * The stores and the accounts live on the same page — one place manages every source (§3).
   *
   * The inventory is behind its own control now (INTEGRATION-DATASOURCE-WIZARD-001 §11): the page
   * answers «what is connected, and does anything need me?» first, and every discovered account is
   * one deliberate click away rather than three hundred rows under the cards.
   */
  test('stores and the discovered accounts are on the same page as the platforms', async ({ page }) => {
    await page.goto('/app/integrations')
    await expect(page.getByTestId('ad-platforms-panel')).toBeVisible()

    const toggle = page.getByTestId('toggle-account-inventory')
    await expect(async () => {
      if ((await toggle.getAttribute('aria-expanded')) !== 'true') await toggle.click()
      expect(await toggle.getAttribute('aria-expanded')).toBe('true')
    }).toPass({ timeout: 20000 })

    await expect(
      page.locator('[data-testid="inventory-row"], [data-testid="inventory-empty"]').first(),
      'the accounts panel never resolved',
    ).toBeVisible({ timeout: 30000 })
  })

  /** And it is CLOSED until somebody asks: the page is a catalogue, not an inventory. */
  test('the account inventory is not rendered until it is asked for', async ({ page }) => {
    await page.goto('/app/integrations')
    await expect(page.getByTestId('ad-platforms-panel')).toBeVisible()

    await expect(page.getByTestId('toggle-account-inventory')).toHaveAttribute('aria-expanded', 'false')
    await expect(page.locator('[data-testid="inventory-row"]')).toHaveCount(0)
  })
})

/**
 * The PROJECT's integrations tab is organised by the six platforms too (PROJINT-001).
 *
 * The matrix carried this as NOT_STARTED (redesign). It had in fact been built — a
 * `PlatformOverviewController` serving `projects/{project}/integrations/platforms`, and a panel
 * rendering it above the technical bindings — and simply never verified. This is the acceptance
 * test that was missing, not the feature.
 */
test.describe('a project’s integrations', () => {
  test.use({ storageState: AUTH.advertiser })

  test('lead with the six platforms, each with its own state and capabilities', async ({ page }) => {
    /*
     * The SEEDED project, by name — not `projects[0]`.
     *
     * `data[0]` was whichever project sorted first, and the suite creates projects of its own
     * (`campaigns-linking` makes «E2E Linking»). Once one of those led the list, this opened its
     * integrations page, found the technical-bindings section and no platform panel, and reported
     * «a platform is missing: Meta» — a page that was fine, for a project that was never the subject.
     *
     * Fifth time in this branch a spec has been bitten by choosing data positionally. `seededProject`
     * throws when the project is absent rather than creating an empty one, so a missing fixture reads
     * as a missing fixture instead of as a product defect.
     */
    const projectId = await seededProject(page.request, 'Growth — Retention')

    await page.goto(`/app/projects/${projectId}/integrations`)
    const main = page.locator('main')
    await expect(main).toBeVisible()

    /*
     * Wait for the PANEL, not for the page to have some text on it.
     *
     * This polled `innerText().length > 300`, which the technical-bindings section satisfies on its
     * own — so on webkit, the slowest of the three here, it stopped waiting while the platform panel's
     * query was still in flight, read the page without it, and reported «a platform is missing: Meta»
     * on a page that renders all six perfectly a moment later.
     *
     * Nothing is weakened: if the panel genuinely never renders, this still fails, and it now fails
     * saying the panel never arrived rather than blaming one platform.
     */
    await expect(main.getByText(/ميتا|Meta/).first(), 'the platform panel never rendered').toBeVisible({ timeout: 20000 })

    const text = await main.innerText()

    // All six named, in either language.
    for (const platform of [/ميتا|Meta/, /جوجل|Google/, /تيك توك|TikTok/, /سناب|Snapchat/, /X/, /لينكدإن|LinkedIn/]) {
      expect(text, `a platform is missing: ${platform}`).toMatch(platform)
    }

    /*
      INTEGRATION-DATASOURCE-WIZARD-001 §12 — what this page may say about a silent platform.

      It used to say «بانتظار بيانات اعتماد»: a fact about how many platforms this INSTALL holds keys
      for. It is the platform operator's number, nothing on a project page can change it, and on a
      customer's own project it reads as «none of your platforms work». What a project reader can act
      on is that the platform is not feeding THIS project, and where to choose its accounts — so that
      is what the page says now, and what this asserts.
    */
    expect(text).toMatch(/لا حسابات هنا|No accounts here/i)
    expect(text).toMatch(/لا يُغذّي|is not feeding this project yet/i)
    expect(text).toMatch(/لم تُنفَّذ أي مزامنة|No sync has run/i)

    // And the install's credential state is NOT restated to a project reader.
    expect(text).not.toMatch(/بانتظار بيانات اعتماد|Awaiting credentials/i)
  })

  /**
   * A silent platform is explained as a PROJECT fact, not implied by absence.
   *
   * This asserted «0 platforms have credentials», which was true and was the platform operator's
   * number — unchangeable from this page and readable there as «none of your platforms work»
   * (§12). What replaced it is the sentence a project reader can act on, and it still must be
   * stated rather than left to an empty space, which reads as a page still loading.
   */
  test('say plainly that nothing is feeding this project yet', async ({ page }) => {
    const projects = await page.request.get('/api/v1/projects', {
      headers: { Accept: 'application/json', Origin: E2E_ORIGIN },
    })
    const projectId = (await projects.json()).data[0].id as string

    await page.goto(`/app/projects/${projectId}/integrations`)
    await expect(page.locator('main')).toContainText(/لا يُغذّي|is not feeding this project yet/i, { timeout: 20000 })
  })
})
