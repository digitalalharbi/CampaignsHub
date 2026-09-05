import { expect, test, type Page } from '@playwright/test'
import { AUTH, untranslatedChrome } from './helpers'

/**
 * `/app` is the advertiser's portal, and it speaks the reader's language (APP-100).
 *
 * The dashboard was **Arabic only**. Choosing English flipped `dir` to `ltr` and left ninety-odd
 * Arabic words on the page — the heading, the objective filter, every KPI label, the demo badge.
 * An interface that changes direction while its content does not reads as broken rather than as
 * unfinished, and it is the flagship page of this portal.
 */
/**
 * Open a section the way a person does — by clicking its rail link.
 *
 * These two walks used `page.goto()` per section, which is a full document load each time: fourteen
 * of them, plus a language toggle, inside ONE default 30s budget. The walk had been finishing at
 * 21–25s on firefox for three consecutive gates — 70–85% of its ceiling, with no headroom — and the
 * fourth tipped it over at exactly 30.0s. Nothing had got slower: the sibling walk in the same run
 * was FASTER than the gate before it (15.1s against 25.0s), and this one passes alone in 19s.
 *
 * Raising the timeout would have been the obvious move and the wrong one — it buys silence until the
 * next few seconds of variance. Clicking navigates through the router instead of reloading the
 * document, which costs a fraction of a `goto`, and it is also what the test claims to be doing:
 * «every rail link opens a page».
 *
 * The URL is asserted after the click, so a link that navigates somewhere else fails here rather
 * than three assertions later against the wrong page.
 */
async function openSection(page: import('@playwright/test').Page, href: string) {
  await page.getByRole('navigation').first().locator(`a[href="${href}"]`).first().click()
  /*
   * A SUB-PATH counts as having arrived — GATE-FF-002.
   *
   * `/app/settings` resolves to its default section, `/app/settings/workspace`. The pattern demanded
   * the URL end at the rail's own href, so it was really asserting «the redirect has not happened
   * yet» — a race, not a requirement. Firefox, being slower on this machine, got there first and
   * failed on the settled destination while chromium and webkit passed on the intermediate one.
   *
   * What the test is for is that the link OPENS ITS SECTION, and it still says exactly that: the URL
   * must be that section or somewhere inside it. A landing page is free to choose its own default.
   */
  await expect(page).toHaveURL(new RegExp(`${href.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(/|\\?|$)`))
}

test.describe('the advertiser portal', () => {
  test.use({ storageState: AUTH.advertiser })

  const arabicWords = (text: string) => (text.match(/[؀-ۿ]+/g) ?? []).length

  async function toggleLanguage(page: Page) {
    await page.getByRole('button', { name: 'Toggle language' }).first().click()
  }

  test('the dashboard is genuinely bilingual, not just re-directed', async ({ page }) => {
    await page.goto('/app/dashboard')
    const main = page.locator('main')
    await expect(main).toBeVisible()

    // Arabic first — the product default.
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
    await expect.poll(async () => arabicWords(await main.innerText()), { timeout: 20000 }).toBeGreaterThan(20)

    await toggleLanguage(page)
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')

    // …and in English, nothing Arabic is left behind. Not "fewer" — none.
    await expect
      .poll(async () => arabicWords(await main.innerText()), { timeout: 20000 })
      .toBe(0)
  })

  /**
   * The KPI labels are built inside a memo, so the language has to be one of its inputs.
   *
   * Leaving it out froze them in whichever language the page first rendered in — the heading
   * translated and the numbers beside it did not, which is the most confusing half-state of all.
   */
  test('the objective KPIs re-label when the language changes', async ({ page }) => {
    await page.goto('/app/dashboard')
    await expect(page.locator('main')).toBeVisible()

    /*
     * The objective is CHOSEN here rather than assumed (UX-DASH-001).
     *
     * The dashboard used to open pinned to Awareness, so «الوصول» was on screen by default and this
     * test could rely on it. It opens on the mixed operational set now — spend, impressions, clicks,
     * CTR — because pinning a sales account to an awareness layout answers a question nobody asked.
     * Naming the objective keeps the test about what it was always about: the labels re-render in
     * the reader's language rather than freezing in whichever one loaded first.
     */
    /*
     * `sales`, not `awareness` — the objective this account's seeded campaigns actually carry.
     *
     * The awareness scope holds NO rows here, and since METRICS-EMPTY-SCOPE-001 an empty scope
     * renders one sentence about the filter rather than a row of cards each reading «لم ترسله
     * المنصة». A scope with nothing in it has no standing to say what a platform reports.
     *
     * And the assertion is scoped by TESTID rather than by page text, which is the other half of
     * what went wrong: `getByText('الوصول')` also matches the objective SELECT's own «الوصول»
     * option, so the Arabic half used to be satisfied by the dropdown without ever touching a KPI
     * card. Only the English half ever reached the strip — which is why this failed on one language
     * and not the other. Asking the card directly cannot be answered by a control that happens to
     * share a word.
     */
    await page.getByTestId('dashboard-objective').selectOption('sales')

    const roas = page.getByTestId('metric-roas')

    /*
     * FILTER-LOCALE-EMPTY-STATE-OBS — say WHICH arm the strip took when the card is missing.
     *
     * This assertion has failed three times in the gate, always deep into a long run and never in
     * isolation, and «element(s) not found» cannot tell a failed request from one still in flight
     * from a scope with no rows. The strip carries `data-strip-state`; reading it into the failure
     * message means the next sighting arrives with the fact instead of needing a fourth reproduction.
     */
    await expect(async () => {
      const state = await page.getByTestId('dashboard-metrics').getAttribute('data-strip-state')

      expect(await roas.count(), `the ROAS card is absent and the strip is in «${state}»`).toBeGreaterThan(0)
    }).toPass({ timeout: 20000 })

    await expect(roas).toContainText('العائد على الإنفاق', { timeout: 20000 })

    /*
     * FILTER-LOCALE-EMPTY-STATE-OBS — watch EVERY state the strip passes through, not the last one.
     *
     * The observation was: «switching language after selecting Objective=Sales produced ‹No data
     * matches these filters› moments after the same objective rendered its ROAS KPI». The assertions
     * below could never have caught that. They retry for twenty seconds, so a strip that flickered
     * into `empty-scope` and recovered would satisfy them exactly as a strip that never flickered —
     * and the only reason the gate ever saw it was that one run happened to be sampled mid-flicker.
     * «Element not found, retried, passed» is how a real defect is filed as flake.
     *
     * A MutationObserver installed BEFORE the toggle records the whole sequence, so the claim is
     * about the transition rather than about whichever instant the assertion happened to sample.
     * The recorded list is asserted non-empty and asserted to END on `rows`, because an observer
     * that attached to nothing would otherwise report «no empty-scope seen» having seen nothing at
     * all — which is the shape of a guard that closes an observation by looking away from it.
     *
     * If this never fires, the row closes with evidence. If it does, it arrives with the sequence.
     */
    await page.evaluate(() => {
      const strip = document.querySelector('[data-testid="dashboard-metrics"]')
      const w = window as unknown as { __stripStates?: string[] }
      w.__stripStates = strip ? [strip.getAttribute('data-strip-state') ?? 'none'] : []

      new MutationObserver(() => {
        const el = document.querySelector('[data-testid="dashboard-metrics"]')
        const state = el?.getAttribute('data-strip-state') ?? 'none'

        if (w.__stripStates![w.__stripStates!.length - 1] !== state) w.__stripStates!.push(state)
      }).observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['data-strip-state'] })
    })

    await toggleLanguage(page)
    await expect(roas).toContainText('Return on ad spend', { timeout: 20000 })
    await expect(roas).not.toContainText('العائد على الإنفاق')

    const states = await page.evaluate(() => (window as unknown as { __stripStates: string[] }).__stripStates)

    expect(states.length, 'the observer recorded nothing — it attached to the wrong element').toBeGreaterThan(0)
    expect(states.at(-1), `the strip settled on «${states.at(-1)}» rather than on its rows: ${states.join(' → ')}`).toBe('rows')
    expect(
      states,
      `the strip passed through «empty-scope» for an objective that has rows: ${states.join(' → ')}`,
    ).not.toContain('empty-scope')
  })

  /**
   * METRICS-EMPTY-SCOPE-001 — a filter matching nothing speaks about the FILTER, in either language.
   *
   * Narrowing to an objective this account never bought used to produce a row of cards each saying
   * «لم ترسله المنصة» — a claim about Meta and Snapchat derived from an absence of CAMPAIGNS.
   */
  test('an objective with no campaigns says so, and says nothing about the platform', async ({ page }) => {
    await page.goto('/app/dashboard')
    await expect(page.locator('main')).toBeVisible()

    /*
     * The canonical bucket, not the raw objective it replaced — ANALYTICS-OBJECTIVE-SYSTEM-001.
     *
     * Every seeded campaign on this account carries `sales`, so «الوعي والتفاعل» covers four raw
     * objectives and matches none of them. That is exactly the scope this test needs: a filter that
     * really does match nothing, rather than one that happens to be empty today.
     */
    await page.getByTestId('dashboard-objective').selectOption('awareness_engagement')

    const panel = page.getByTestId('dashboard-metrics-empty-scope')

    await expect(panel).toBeVisible({ timeout: 20000 })
    await expect(panel).toContainText('لا توجد بيانات ضمن هذه الفلاتر')

    await toggleLanguage(page)
    await expect(panel).toContainText('No data matches these filters')
  })


  /** Every rail link opens a page with content — the advertiser's own sections, not the agency's. */
  test('every rail link opens a page that is not empty', async ({ page }) => {
    await page.goto('/app/dashboard')
    await expect(page.getByRole('navigation').first()).toBeVisible()

    const hrefs = await page.getByRole('navigation').first().locator('a[href^="/app"]')
      .evaluateAll((els) => [...new Set(els.map((e) => e.getAttribute('href')!))])
    expect(hrefs.length).toBeGreaterThan(3)

    for (const href of hrefs) {
      await openSection(page, href)
      const main = page.locator('main')
      await expect(main).toBeVisible({ timeout: 20000 })
      await expect.poll(async () => (await main.innerText()).trim().length, { timeout: 20000 })
        .toBeGreaterThan(40)
      // The multi-client tooling belongs to /agency and must not appear here.
      await expect(page.getByRole('navigation').first().locator('a[href^="/agency"]')).toHaveCount(0)
    }
  })

  /**
   * Every section of this portal speaks English when English is chosen (APP-100).
   *
   * Written as a WALK rather than a list, so a section added later is measured without anybody
   * remembering to add it here — and asserted as zero rather than "fewer", because a page that is
   * mostly translated is the state that reads as broken.
   *
   * Arabic inside `<code>`/`<pre>`, and the language toggle's own «ع» label, are not content.
   */
  test('no section is left in Arabic when the language is English', async ({ page }) => {
    await page.goto('/app/dashboard')
    await expect(page.getByRole('navigation').first()).toBeVisible()

    const hrefs = await page.getByRole('navigation').first().locator('a[href^="/app"]')
      .evaluateAll((els) => [...new Set(els.map((e) => e.getAttribute('href')!))])

    await toggleLanguage(page)
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')

    const stillArabic: string[] = []
    for (const href of hrefs) {
      await openSection(page, href)
      await expect(page.locator('main')).toBeVisible({ timeout: 20000 })
      // Give the section's own queries a moment to resolve before reading its text.
      await expect.poll(async () => (await page.locator('main').innerText()).trim().length, { timeout: 20000 })
        .toBeGreaterThan(0)

      const leftover = await untranslatedChrome(page)
      if (leftover.length > 0) stillArabic.push(`${href}: ${leftover.join(' ')}`)
    }

    expect(stillArabic, `these sections are still Arabic under dir=ltr:\n${stillArabic.join('\n')}`).toEqual([])
  })

  /**
   * LABEL-LEAK-001 — the mirror of the walk above: no section shows the database's own words.
   *
   * Three screens shipped with label maps written against a guessed subset of their key sets, and
   * every one of them falls back to printing the key: the settings dropdown offered `in_house_team`
   * and `self_serve_company`; the projects filter row read «الكل draft onboarding active paused
   * completed archived»; the subscriptions page — the one somebody reads before paying — showed
   * «clients 1 4 5» and «الدعم community».
   *
   * None of those was a broken query. Each was a map that drifted from a PHP enum or a seeder, and
   * silently. So this walks the rail the same way and asserts that no visible text is a bare
   * lowercase identifier, which is what every one of them looked like.
   *
   * The allow-list is words that are legitimately Latin in an Arabic page — file formats, metric
   * abbreviations, platform names. Anything else that renders as `snake_case` or a lone English
   * word is a label nobody wrote.
   */
  test('no section shows a raw identifier where a label belongs', async ({ page }) => {
    const ALLOWED = new Set([
      'pdf', 'xlsx', 'csv', 'roas', 'ctr', 'cpa', 'cpc', 'cpm', 'aov', 'api', 'url', 'id', 'ai',
      'meta', 'google', 'tiktok', 'snapchat', 'linkedin', 'x', 'salla', 'zid',
      'sar', 'usd', 'aed', 'demo', 'all', 'none', 'beta', 'utc', 'vat', 'ok',
    ])

    await page.goto('/app/dashboard')
    await expect(page.getByRole('navigation').first()).toBeVisible()

    const hrefs = await page.getByRole('navigation').first().locator('a[href^="/app"]')
      .evaluateAll((els) => [...new Set(els.map((e) => e.getAttribute('href')!))])

    const leaks: string[] = []
    for (const href of hrefs) {
      await openSection(page, href)
      await expect(page.locator('main')).toBeVisible({ timeout: 20000 })

      /*
       * Wait for the section to SETTLE, not merely to be non-empty.
       *
       * The first version of this test polled for «any text at all», which a heading satisfies
       * instantly — so it read `main` before the section's queries resolved, found the header and
       * nothing else, and passed. Reintroducing a known leak on purpose did not fail it, which is
       * how that was discovered: a guard that cannot fail is not a guard.
       *
       * Two identical consecutive reads means the content has stopped arriving. That is a fact about
       * this page rather than a sleep long enough to usually work.
       */
      let previous = ''
      await expect.poll(async () => {
        const current = (await page.locator('main').innerText()).trim()
        const settled = current.length > 0 && current === previous
        previous = current
        return settled
      }, { timeout: 20000, intervals: [250] }).toBe(true)

      const found = await page.locator('main').evaluate((root, allowed) => {
        const out = new Set<string>()
        const walk = document.createTreeWalker(root, NodeFilter.SHOW_TEXT)
        let node: Node | null
        while ((node = walk.nextNode())) {
          const text = (node.textContent ?? '').trim()
          if (!text || text.length > 28) continue
          // A lone lowercase word, or snake_case — the shape every leaked key had.
          if (!/^[a-z][a-z0-9]*([_-][a-z0-9]+)*$/.test(text)) continue
          if ((allowed as string[]).includes(text.toLowerCase())) continue
          out.add(text)
        }
        return [...out]
      }, [...ALLOWED])

      if (found.length > 0) leaks.push(`${href}: ${found.join(' ')}`)
    }

    expect(leaks, `these sections render identifiers instead of labels:\n${leaks.join('\n')}`).toEqual([])
  })

  test('the dashboard holds together on a phone', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 })
    await page.goto('/app/dashboard')
    await expect(page.locator('main')).toBeVisible()

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    )
    expect(overflow, 'the advertiser dashboard scrolls sideways on a phone').toBe(false)
  })
})

/**
 * Language and theme are REMEMBERED (APP-100).
 *
 * They were not. The sidebar's collapsed state was persisted while the two choices a customer
 * actually notices were not, so choosing English or dark mode lasted until the next full page load
 * and then silently reverted — every bookmark, refresh and new tab put them back into Arabic and
 * light with no explanation.
 *
 * It survived clicking around inside the SPA, which is the path a manual check takes, and only broke
 * on the full navigations an automated walk performs.
 */
test.describe('remembered preferences', () => {
  test.use({ storageState: AUTH.advertiser })

  test('the chosen language survives a full page load', async ({ page }) => {
    await page.goto('/app/dashboard')
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')

    await page.getByRole('button', { name: 'Toggle language' }).first().click()
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')

    // A RELOAD, not a client-side navigation — the case that was broken.
    await page.reload()
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')

    // …and a hard navigation to another section keeps it too.
    await page.goto('/app/reports')
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
  })

  test('the chosen theme survives a full page load', async ({ page }) => {
    await page.goto('/app/dashboard')
    const before = await page.locator('html').getAttribute('data-theme')

    await page.getByRole('button', { name: 'Toggle theme' }).first().click()
    const after = await page.locator('html').getAttribute('data-theme')
    expect(after).not.toBe(before)

    await page.reload()
    await expect(page.locator('html')).toHaveAttribute('data-theme', after!)
  })
})
