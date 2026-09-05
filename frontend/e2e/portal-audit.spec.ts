import { expect, test } from '@playwright/test'
import { AUTH, contentLength, untranslatedChrome, walkRail } from './helpers'
import { RAIL_PAINT_TIMEOUT, RAIL_PATH_BUDGET } from './railWalkTimeout'

/**
 * Every route in a portal leads somewhere real (REVIEW-001).
 *
 * Audited by WALKING the product rather than by reading the router: a route can exist, answer 200
 * and still be a page that tells the customer nothing. The two failures this catches are the ones a
 * route table cannot show — a nav link that goes nowhere, and a page that renders but is empty.
 */

test.describe('the advertiser portal', () => {
  test.use({ storageState: AUTH.advertiser })

  /**
   * The four unbuilt modules are GONE, not disguised.
   *
   * They rendered a card saying the module was «part of a later phase» while claiming the
   * foundation was in place — roadmap copy served as a page. None was linked from anywhere, so the
   * only way to reach one was to type its URL and be shown something that looked built.
   */
  test('the removed modules answer as routes that do not exist', async ({ page }) => {
    for (const path of ['/app/approvals', '/app/tracking', '/app/optimization', '/app/opportunities']) {
      await page.goto(path)
      // The not-found page, not a card explaining the roadmap.
      await expect(page.getByText(/later phase|قريبًا/i), `${path} still shows a placeholder`).toHaveCount(0)
    }
  })

  /** Notifications had a real page all along; the placeholder sat in front of it. */
  test('notifications leads to the page that actually exists', async ({ page }) => {
    await page.goto('/app/notifications')
    await expect(page).toHaveURL(/\/app\/account\/notifications/)
    expect(await contentLength(page)).toBeGreaterThan(40)
  })

  /**
   * Every link in the rail resolves to a page with content.
   *
   * Walked from the RAIL rather than from a list in the test, so a link added later is audited
   * without anybody remembering to add it here.
   */
  test('every rail link opens a page that is not empty', async ({ page }) => {
    await page.goto('/app/dashboard')
    await expect(page.getByRole('navigation').first()).toBeVisible()

    const hrefs = await page.getByRole('navigation').first().locator('a[href^="/app"]')
      .evaluateAll((els) => [...new Set(els.map((e) => e.getAttribute('href')!))])

    expect(hrefs.length, 'the advertiser rail has no links').toBeGreaterThan(3)

    /*
     * A budget proportional to the work, not a flat 30s.
     *
     * This test opens EVERY link in the rail — a dozen-odd full page loads — inside one test. The
     * default was already tight and got tighter with each section added to the product, so it expired
     * on whichever portal firefox reached when the machine was busiest: the agency rail in one run,
     * the advertiser's in the next, with nothing wrong on either page.
     *
     * Nothing is relaxed except the clock. Every assertion below still runs on every link, and a page
     * that genuinely fails to render still fails — the difference is that a slow machine no longer
     * looks like a broken page.
     */
    test.setTimeout(RAIL_PAINT_TIMEOUT + hrefs.length * RAIL_PATH_BUDGET)

    await walkRail(page, hrefs)
  })
})

test.describe('the agency portal', () => {
  test.use({ storageState: AUTH.owner })

  test('every rail link opens a page that is not empty', async ({ page }) => {
    await page.goto('/agency')
    await expect(page.getByRole('navigation').first()).toBeVisible()

    const hrefs = await page.getByRole('navigation').first().locator('a[href^="/agency"]')
      .evaluateAll((els) => [...new Set(els.map((e) => e.getAttribute('href')!))])

    expect(hrefs.length, 'the agency rail has no links').toBeGreaterThan(3)

    // Same reasoning as the advertiser walk above: budget the clock to the number of pages opened.
    test.setTimeout(RAIL_PAINT_TIMEOUT + hrefs.length * RAIL_PATH_BUDGET)

    await walkRail(page, hrefs)
  })
})

test.describe('the platform console', () => {
  test.use({ storageState: AUTH.admin })

  test('every rail link opens a page that is not empty', async ({ page }) => {
    await page.goto('/admin')
    await expect(page.getByRole('navigation').first()).toBeVisible()

    const hrefs = await page.getByRole('navigation').first().locator('a[href^="/admin"]')
      .evaluateAll((els) => [...new Set(els.map((e) => e.getAttribute('href')!))])

    expect(hrefs.length, 'the admin rail has no links').toBeGreaterThan(3)

    // Same reasoning as the advertiser walk above: budget the clock to the number of pages opened.
    test.setTimeout(RAIL_PAINT_TIMEOUT + hrefs.length * RAIL_PATH_BUDGET)

    await walkRail(page, hrefs)
  })
})

/**
 * The influencers portal is withdrawn (INFL-OFF-001).
 *
 * There is nothing to walk: every address in it redirects to the services catalogue. What is audited
 * instead is that the redirect LANDS somewhere real — the failure this replaces would be a 404 or a
 * blank page for everybody holding an old link.
 */
test.describe('the withdrawn influencers portal', () => {
  test.use({ storageState: { cookies: [], origins: [] } })

  test('every retired address lands on a real page that says why', async ({ page }) => {
    for (const path of ['/influencers', '/influencers/roster', '/influencers/nominations', '/influencers/me']) {
      await page.goto(path)

      await expect(page, `${path} did not redirect`).toHaveURL(/\/services\?unavailable=influencers/)
      await expect(page.getByTestId('influencers-unavailable')).toBeVisible()
      expect(await contentLength(page), `${path} landed on an empty page`).toBeGreaterThan(40)
    }
  })

  /**
   * …except its DOOR, which goes where every other old door goes.
   *
   * `/influencers/login` is a sign-in address, and the portal being withdrawn says nothing about the
   * person's account — they still have one, and they still need to sign in. Sending them to a page
   * explaining that a portal they were not asking for is unavailable would answer a question nobody
   * asked; `/login` answers the one they did. This is LOGIN-UNIFIED-001 winning over INFL-OFF-001 for
   * exactly one address, and only because that address was never part of the portal's content.
   */
  test('the retired door goes to the one sign-in page, not to an explanation', async ({ page }) => {
    await page.goto('/influencers/login')

    await expect(page).toHaveURL(/\/login$/)
    await expect(page.getByTestId('login-identify')).toBeVisible()
  })
})

test.describe('the client portal', () => {
  test.use({ storageState: AUTH.client })

  /**
   * The portal its own customer could not open (REVIEW-001c).
   *
   * `client@campaignshub.io` signed in, the server answered `portal: "portal"` and routed them to
   * `/portal` — where every endpoint returned 401, because the portal was gated on the OTP cookie
   * ALONE. The identity resolver was consulted only to narrow a session the cookie had already
   * opened, so the engine that "wins" could never be the one that let you in. Nothing in a status
   * check could show it: each 401 was a correct answer to a request that was correctly authenticated.
   */
  test('a client-portal membership opens the portal without a one-time code', async ({ page }) => {
    await page.goto('/portal')

    // Landed IN the portal, not bounced back to its door.
    await expect(page).toHaveURL(/\/portal(\/clients\/[^/]+)?$/)
    await expect(page).not.toHaveURL(/\/portal\/login/)
    expect(await contentLength(page)).toBeGreaterThan(40)
  })

  test('every rail link opens a page that is not empty', async ({ page }) => {
    await page.goto('/portal')
    await expect(page.getByRole('navigation').first()).toBeVisible()

    const hrefs = await page.getByRole('navigation').first().locator('a[href^="/portal"]')
      .evaluateAll((els) => [...new Set(els.map((e) => e.getAttribute('href')!))])

    expect(hrefs.length, 'the client rail has no links').toBeGreaterThan(3)

    // Same reasoning as the advertiser walk above: budget the clock to the number of pages opened.
    test.setTimeout(RAIL_PAINT_TIMEOUT + hrefs.length * RAIL_PATH_BUDGET)

    await walkRail(page, hrefs)
  })

  /** Direct open, refresh and Back all land in the portal — not at its login. */
  test('a deep link survives a refresh and a Back', async ({ page }) => {
    await page.goto('/portal')
    const space = new URL(page.url()).pathname

    await page.goto(`${space}/invoices`)
    expect(await contentLength(page)).toBeGreaterThan(40)

    await page.reload()
    await expect(page).toHaveURL(new RegExp(`${space}/invoices$`))
    expect(await contentLength(page)).toBeGreaterThan(40)

    await page.goBack()
    await expect(page).not.toHaveURL(/\/portal\/login/)
  })
})

/**
 * The client portal is held to the same standards as the other three (PORTAL-100).
 *
 * Its own language, its own phone layout, and a rail that offers a CLIENT's work — requests, quotes,
 * invoices, files, conversations — and none of the agency's or advertiser's tooling.
 */
test.describe('the client portal, in depth', () => {
  test.use({ storageState: AUTH.client })

  test('no section is left in Arabic when the language is English', async ({ page }) => {
    await page.goto('/portal')
    await expect(page.getByRole('navigation').first()).toBeVisible()

    const hrefs = await page.getByRole('navigation').first().locator('a[href^="/portal"]')
      .evaluateAll((els) => [...new Set(els.map((e) => e.getAttribute('href')!))])

    /*
     * Budget the clock to the number of pages opened — the same rule the four other rail walks in
     * this file already apply, and the only one of the five that was missing it.
     *
     * This walk does strictly MORE per page than its sibling above: the same cold load of every
     * href, plus a language toggle and an `untranslatedChrome` scan of each page. It was
     * nevertheless left on the default 30s, so on the slowest browser here it ran out of clock at
     * 32.5s while its lighter sibling finished the identical set of hrefs in 16.1s.
     *
     * This is arithmetic, not a hang, and the evidence is that chromium and webkit run this exact
     * test against the same pages and the same requests and pass. Raising a budget to cover work
     * that is genuinely being done is not the same act as raising one to wait longer for something
     * that never arrives.
     */
    test.setTimeout(RAIL_PAINT_TIMEOUT + hrefs.length * RAIL_PATH_BUDGET)

    await page.getByRole('button', { name: 'Toggle language' }).first().click()
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')

    const stillArabic: string[] = []
    for (const href of hrefs) {
      await page.goto(href)
      await expect(page.locator('main')).toBeVisible({ timeout: RAIL_PAINT_TIMEOUT })
      await expect.poll(async () => (await page.locator('main').innerText()).trim().length, { timeout: 20000 })
        .toBeGreaterThan(0)

      const leftover = await untranslatedChrome(page)
      if (leftover.length > 0) stillArabic.push(`${href}: ${leftover.join(' ')}`)
    }

    expect(stillArabic, `these sections are still Arabic under dir=ltr:\n${stillArabic.join('\n')}`).toEqual([])
  })

  /**
   * A client sees their OWN work and nothing that belongs to the people serving them.
   *
   * The agency's client roster, the advertiser's campaign tooling and the platform console must not
   * be reachable from this rail — a portal that offered them would be showing a customer the inside
   * of the business they bought from.
   */
  test('the rail offers the client’s work and no operator tooling', async ({ page }) => {
    await page.goto('/portal')
    const rail = page.getByRole('navigation').first()

    for (const foreign of ['/agency', '/app', '/admin', '/influencers']) {
      await expect(rail.locator(`a[href^="${foreign}"]`), `${foreign} is offered in the client rail`).toHaveCount(0)
    }
  })

  test('it holds together on a phone', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 })
    await page.goto('/portal')
    await expect(page.locator('main')).toBeVisible()

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    )
    expect(overflow, 'the client portal scrolls sideways on a phone').toBe(false)
  })
})
