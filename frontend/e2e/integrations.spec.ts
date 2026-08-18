import { expect, test } from '@playwright/test'
import { AUTH, E2E_ORIGIN, seededProject } from './helpers'

/**
 * The integrations surfaces lead with the six real ad platforms (PROJINT-001, INTEG-UI-001).
 *
 * The grid used to be ordered by whatever the API returned, which put `sandbox` — a local fake
 * provider that exists so the product can be demonstrated without credentials — at the head of the
 * list, above Meta and Google, wearing a green "connected" chip. Somebody opening this page to
 * connect their advertising met a connected generic connector first and the platforms they came for
 * eleventh.
 *
 * The other eleven connectors are real and stay reachable. What is asserted here is which ones the
 * page LEADS with, and that nothing claims a connection it does not have.
 */
test.describe('the integrations centre', () => {
  test.use({ storageState: AUTH.advertiser })

  /**
   * The product's order (PLATFORM-ORDER-001) — سناب شات، تيك توك، ميتا، جوجل أدز، إكس، لينكدإن.
   *
   * This list used to lead with Meta, which is how the drift stayed invisible: the connection centre
   * and the dashboard led with Meta, the report engine led with Snapchat, and each had a test that
   * agreed with the file beside it. The order now lives in `@/lib/platforms`, and this asserts the
   * rendered result against it.
   */
  const AD_PLATFORMS = ['Snapchat Ads', 'TikTok Ads', 'Meta Ads', 'Google Ads', 'X Ads', 'LinkedIn Ads']

  /**
   * The CONNECTOR CARDS, in the order the page offers them.
   *
   * Scoped to `[data-testid="connector-card"]` rather than to `main li`, which is what this used to
   * say. The two meant the same thing only for as long as the connector grid was the only list on
   * the page: the account inventory added its own rows, an assigned one leads with the chip
   * «مرتبط بمشروع», and that string arrived at the head of this list and pushed «LinkedIn Ads» off
   * the end of the slice. It failed identically on all three browsers, which is what a selector
   * reading the wrong elements looks like — and it passed locally, because the inventory only
   * renders that chip once some earlier spec in the full run has assigned an account.
   *
   * The assertions below are unchanged. Only the set they read from is now the set they always
   * described.
   */
  async function connectorNames(page: import('@playwright/test').Page): Promise<string[]> {
    return page.locator('main li[data-testid="connector-card"]').evaluateAll((els) =>
      els
        .map((el) => ((el as HTMLElement).innerText || '').split('\n').filter(Boolean)[1] ?? '')
        .filter(Boolean),
    )
  }

  test('the six ad platforms come first, in order', async ({ page }) => {
    await page.goto('/app/integrations')
    await expect(page.locator('main')).toBeVisible()
    await expect.poll(async () => (await connectorNames(page)).length, { timeout: 20000 }).toBeGreaterThan(6)

    const names = await connectorNames(page)
    expect(names.slice(0, 6)).toEqual(AD_PLATFORMS)
  })

  /** Sandbox is not a customer's integration, so it is last — never the first thing offered. */
  test('the fake provider is last, not first', async ({ page }) => {
    await page.goto('/app/integrations')
    await expect.poll(async () => (await connectorNames(page)).length, { timeout: 20000 }).toBeGreaterThan(6)

    const names = await connectorNames(page)
    expect(names[names.length - 1]).toMatch(/Sandbox/i)
  })

  /**
   * Nothing claims to be connected without credentials.
   *
   * No provider has credentials in any environment, so the summary must read zero connected — and
   * the page must say so rather than leaving the reader to infer it.
   */
  test('no platform claims a connection it does not have', async ({ page }) => {
    await page.goto('/app/integrations')
    const main = page.locator('main')
    await expect(main).toBeVisible()
    await expect.poll(async () => (await main.innerText()).length, { timeout: 20000 }).toBeGreaterThan(100)

    // The honesty note is on the page, in whichever language it opens in.
    await expect(main).toContainText(/الحالات صادقة|states are honest/i)

    // …and the raw connection enum is never printed at the reader.
    const text = await main.innerText()
    expect(text).not.toMatch(/\bالحساب:\s*(connected|awaiting_credentials|needs_action)\b/)
  })

  /** Every ad platform's card offers the action its state allows, and no dead control. */
  test('each ad platform offers a real action for its state', async ({ page }) => {
    await page.goto('/app/integrations')
    await expect.poll(async () => (await connectorNames(page)).length, { timeout: 20000 }).toBeGreaterThan(6)

    for (const platform of AD_PLATFORMS) {
      // Scoped for the same reason as `connectorNames` above: this wants a connector CARD, and
      // `main li` is now several different lists.
      const card = page.locator('main li[data-testid="connector-card"]').filter({ hasText: platform }).first()
      await expect(card, `${platform} has no card`).toBeVisible()
      // Awaiting credentials → the card offers "connect"; it must not offer a sync that cannot run.
      await expect(card.getByRole('button')).not.toHaveCount(0)
    }
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

    // The capability list is per platform, and none of it is claimed as working.
    expect(text).toMatch(/بانتظار بيانات اعتماد|Awaiting credentials/i)
    expect(text).toMatch(/لم تُنفَّذ أي مزامنة|no sync has run/i)
  })

  /**
   * Zero credentials is stated as a NUMBER, not implied by absence.
   *
   * "0 platforms have credentials" is a fact the operator can act on; a page that simply showed
   * nothing would read as still loading.
   */
  test('say plainly that no real keys exist yet', async ({ page }) => {
    const projects = await page.request.get('/api/v1/projects', {
      headers: { Accept: 'application/json', Origin: E2E_ORIGIN },
    })
    const projectId = (await projects.json()).data[0].id as string

    await page.goto(`/app/projects/${projectId}/integrations`)
    await expect(page.locator('main')).toContainText(/لا توجد مفاتيح حقيقية بعد|no real keys yet/i, { timeout: 20000 })
  })
})
