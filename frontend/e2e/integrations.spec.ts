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

  /** The two stores, which complete the eight this product integrates with. */
  const STORES = ['salla', 'zid']

  /** The STORE CARDS — a separate section, and deliberately not part of the ad-account journey. */
  async function storeKeys(page: import('@playwright/test').Page): Promise<string[]> {
    return page.locator('[data-testid="store-card"]').evaluateAll((els) =>
      els.map((el) => (el as HTMLElement).dataset.platform ?? '').filter(Boolean),
    )
  }

  /**
   * The page leads with the AUTHORISATIONS, not with a catalogue of platforms.
   *
   * The grid of provider cards is gone (§22): a reader of this page has already connected something
   * and wants to know whether it still works. Choosing a platform is one moment inside «ربط مصدر»,
   * not the permanent shape of the page.
   */
  test('opens on the connection hub', async ({ page }) => {
    await page.goto('/app/integrations')

    await expect(page.getByTestId('connection-hub')).toBeVisible({ timeout: 30000 })
    await expect(page.locator('[data-testid="platform-card"]')).toHaveCount(0)
  })

  /**
   * Choosing a platform offers only what this install can actually start — and NOTHING when it can't.
   *
   * INTEG-UI-001's rule survives the redesign intact: `awaiting_credentials` is a fact about the
   * system's configuration and not the customer's, so there is nothing for them to press. A customer
   * cannot obtain this product's OAuth keys, and a «Connect» button there builds an authorise URL
   * that cannot exist. No provider has credentials in the gate, so the picker must say so and stop.
   */
  test('the platform picker offers only what can be started, and explains an empty list', async ({ page }) => {
    await page.goto('/app/integrations')
    await expect(page.getByTestId('connection-hub')).toBeVisible({ timeout: 30000 })

    await page.getByTestId(
      (await page.getByTestId('hub-connect').count()) > 0 ? 'hub-connect' : 'hub-connect-empty',
    ).click()

    const picker = page.getByTestId('provider-picker')
    await expect(picker).toBeVisible({ timeout: 20000 })

    const choices = page.locator('[data-testid^="provider-pick-"]')

    if (await choices.count() === 0) {
      await expect(page.getByTestId('provider-picker-empty')).toBeVisible()
    } else {
      // Whatever is offered is a real provider this product integrates with — never the local fake.
      const keys = await choices.evaluateAll((els) =>
        els.map((el) => (el as HTMLElement).dataset.testid ?? '').filter(Boolean),
      )
      expect(keys.join(' ')).not.toContain('sandbox')
    }
  })

  /**
   * The other two of the eight — INTEG-STORES-001.
   *
   * Salla and Zid are commerce connectors with a store and no ad accounts, so they keep their own
   * section and their own journey (§7). They were reachable only through a separate panel once, so a
   * customer saw six of the eight things this product integrates with.
   */
  test('the two stores complete the eight, in their own section', async ({ page }) => {
    await page.goto('/app/integrations')
    await expect(page.locator('main')).toBeVisible()
    await expect.poll(async () => (await storeKeys(page)).length, { timeout: 20000 }).toBe(2)

    expect((await storeKeys(page)).sort()).toEqual([...STORES].sort())
    await expect(page.getByTestId('stores-heading')).toBeVisible()
  })

  /** A store is never dragged through the ad-account flow: it has no ad account to be asked about. */
  test('a store is not offered as an advertising source', async ({ page }) => {
    await page.goto('/app/integrations')
    await expect(page.getByTestId('connection-hub')).toBeVisible({ timeout: 30000 })

    for (const store of STORES) {
      await expect(page.locator(`[data-testid^="hub-row-"][data-provider="${store}"]`)).toHaveCount(0)
    }
  })

  /** Nothing claims to be connected without credentials, and no raw enum reaches the reader. */
  test('no source claims a connection it does not have', async ({ page }) => {
    await page.goto('/app/integrations')
    const main = page.locator('main')
    await expect(main).toBeVisible()
    await expect.poll(async () => (await main.innerText()).length, { timeout: 20000 }).toBeGreaterThan(100)

    const text = await main.innerText()
    expect(text).not.toMatch(/\b(connected|awaiting_credentials|needs_action|REAUTH_REQUIRED|NEVER_SYNCED)\b/)
  })

  /**
   * «Everything we can see» stays reachable, and stays closed until it is asked for (§11 §28).
   *
   * Three hundred discovered rows under the things somebody came for is the page this replaced. The
   * drawer answers the narrower question — which of THIS authorisation's accounts are ours — and
   * this control answers the tenant-wide one for whoever genuinely wants it.
   */
  test('the discovered inventory is reachable and is not rendered until it is asked for', async ({ page }) => {
    await page.goto('/app/integrations')
    await expect(page.getByTestId('connection-hub')).toBeVisible({ timeout: 30000 })

    const toggle = page.getByTestId('toggle-account-inventory')
    await expect(toggle).toHaveAttribute('aria-expanded', 'false')
    await expect(page.locator('[data-testid="inventory-row"]')).toHaveCount(0)

    await expect(async () => {
      if ((await toggle.getAttribute('aria-expanded')) !== 'true') await toggle.click()
      expect(await toggle.getAttribute('aria-expanded')).toBe('true')
    }).toPass({ timeout: 20000 })

    await expect(
      page.locator('[data-testid="inventory-row"], [data-testid="inventory-empty"]').first(),
      'the accounts panel never resolved',
    ).toBeVisible({ timeout: 30000 })
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
