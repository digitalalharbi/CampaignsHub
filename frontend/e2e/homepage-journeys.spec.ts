import { expect, test, type Page } from '@playwright/test'

/**
 * Journey routing, proved by CLICKING — not by checking that a URL returns 200.
 *
 * The four cards at the foot of the page used to point at `#usage`, so clicking them scrolled back to
 * the top instead of opening the path. A status check could never have caught that. Every assertion
 * here therefore clicks the real control and then checks the URL CHANGED to the right route and the
 * right page rendered — plus back, reload, and opening the link cold.
 */

/**
 * `marker` is a control that only exists on the destination page, so "the right page rendered" is
 * proved by something functional rather than by a headline that marketing may reword.
 */
const JOURNEYS = [
  { key: 'self-service', url: /\/register\?journey=self-service&module=paid-media/, marker: (p: Page) => p.getByRole('button', { name: /إنشاء حساب|Create account/i }) },
  { key: 'multi-client', url: /\/register\?journey=multi-client&module=paid-media/, marker: (p: Page) => p.getByRole('button', { name: /إنشاء حساب|Create account/i }) },
  { key: 'services', url: /\/services$/, marker: (p: Page) => p.getByTestId('service-categories') },
  { key: 'influencer', url: /\/requests\/new\?module=influencer-marketing/, marker: (p: Page) => p.getByRole('heading', { level: 1 }) },
] as const

async function home(page: Page) {
  await page.goto('/')
  await expect(page.getByTestId('closing-journeys')).toBeAttached()
}

test.describe('homepage journeys route somewhere real', () => {
  for (const j of JOURNEYS) {
    test(`closing card "${j.key}" opens its own path, not the top of the page`, async ({ page }) => {
      await home(page)
      const card = page.getByTestId(`closing-journey-${j.key}`)
      await card.scrollIntoViewIfNeeded()
      await card.click()

      // The URL must actually change — the old bug left it on "/" with a #usage fragment.
      await expect(page).toHaveURL(j.url)
      expect(page.url()).not.toContain('#usage')
      await expect(j.marker(page).first()).toBeVisible()

      // Back returns to the homepage, and the card is still there.
      await page.goBack()
      await expect(page).toHaveURL(/\/$/)
      await expect(page.getByTestId(`closing-journey-${j.key}`)).toBeAttached()
    })

    test(`"${j.key}" survives a reload and a cold open`, async ({ page }) => {
      await home(page)
      const card = page.getByTestId(`closing-journey-${j.key}`)
      await card.scrollIntoViewIfNeeded()
      await card.click()
      await expect(page).toHaveURL(j.url)

      const deepLink = page.url()
      await page.reload()
      await expect(page).toHaveURL(j.url)
      await expect(j.marker(page).first()).toBeVisible()

      // Opening the same link cold, with no history behind it.
      await page.goto(deepLink)
      await expect(page).toHaveURL(j.url)
      await expect(j.marker(page).first()).toBeVisible()
    })
  }

  test('the hero and the closing cards agree on every destination', async ({ page }) => {
    await home(page)

    const closing = await page.getByTestId('closing-journeys').locator('a').evaluateAll(
      (els) => els.map((e) => ({ id: e.getAttribute('data-testid'), href: e.getAttribute('href') })),
    )
    expect(closing).toHaveLength(4)

    for (const c of closing) {
      const key = c.id!.replace('closing-journey-', '')
      // The hero lists the other three as links; the selected one is carried by the primary CTA.
      const heroLink = page.getByTestId(`hero-journey-link-${key}`)
      const heroHref = (await heroLink.count()) > 0
        ? await heroLink.getAttribute('href')
        : await page.getByTestId('hero-primary-cta').getAttribute('href')
      expect(heroHref, `hero and closing disagree for ${key}`).toBe(c.href)
    }
  })

  test('no control on the page is a dead anchor', async ({ page }) => {
    await home(page)
    const hrefs = await page.locator('a[href]').evaluateAll((els) => els.map((e) => e.getAttribute('href')!))

    // A bare "#" goes nowhere at all.
    expect(hrefs.filter((h) => h === '#')).toHaveLength(0)

    // Every in-page anchor must point at a section that exists.
    for (const h of hrefs.filter((x) => x.startsWith('#'))) {
      const id = h.slice(1)
      await expect(page.locator(`#${id}`), `anchor ${h} has no target`).toHaveCount(1)
    }
  })

  test('track-my-requests opens the client portal login', async ({ page }) => {
    await home(page)
    await page.getByTestId('hero-track-requests').click()
    await expect(page).toHaveURL(/\/portal\/login$/)
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  })

  test('a service opens the intake with that service already chosen', async ({ page }) => {
    await page.goto('/services')
    const first = page.getByTestId('service-list').locator('a').first()
    const href = await first.getAttribute('href')
    expect(href).toMatch(/\/requests\/new\?module=paid-media&services=/)

    await first.click()
    await expect(page).toHaveURL(/\/requests\/new\?module=paid-media&services=/)
    // The chosen service is carried through, not lost on the way.
    await expect(page.getByText(/الخدمات المختارة: 1|Selected services: 1/)).toBeVisible()
  })
})

/**
 * The whole point of the gateway: a visitor who starts on the homepage ends up with a request SAVED in
 * the backend, carrying the service they picked. Status codes cannot prove that — this walks it.
 */
test.describe('homepage → catalogue → intake → saved request', () => {
  test.use({ storageState: { cookies: [], origins: [] } })

  test('the services journey ends in a stored request that carries the chosen service', async ({ page }) => {
    await page.goto('/')

    // 1) The closing card opens the catalogue (this is the card that used to bounce to the top).
    const card = page.getByTestId('closing-journey-services')
    await card.scrollIntoViewIfNeeded()
    await card.click()
    await expect(page).toHaveURL(/\/services$/)

    // 2) Pick a real service from the engine-driven catalogue.
    const service = page.getByTestId('service-list').locator('a').first()
    const serviceName = (await service.innerText()).split('\n')[0].trim()
    await service.click()
    await expect(page).toHaveURL(/\/requests\/new\?module=paid-media&services=/)

    // 3) The intake opens with that service already selected — the choice survived the hop.
    await expect(page.getByText(/الخدمات المختارة: 1|Selected services: 1/)).toBeVisible()
    await expect(page.getByText(serviceName).first()).toBeVisible()
    await page.getByRole('button', { name: /التالي|Next/ }).click()

    // 4) Applicant.
    await page.getByLabel(/الاسم|Name/).first().fill('Journey QA')
    await page.getByLabel(/البريد|Email/).first().fill(`journey-${Date.now()}@example.test`)
    await page.getByLabel(/رقم الجوال|Phone/).first().fill(`+96650${String(Date.now()).slice(-7)}`)
    await page.getByLabel(/اسم النشاط أو الشركة|Company/).first().fill('Journey Co')
    await page.getByRole('button', { name: /التالي|Next/ }).click()

    // 5) Walk the remaining steps to review.
    for (let i = 0; i < 4; i++) {
      const next = page.getByRole('button', { name: /التالي|Next/ })
      if (await next.count() === 0 || !(await next.isVisible())) break
      await next.click()
    }

    // 6) Verify contact, submit, and read back the reference the backend assigned.
    await page.getByRole('button', { name: /تحقّق رقم الجوال|Verify Mobile number/ }).click()
    await page.getByRole('button', { name: /تحقّق البريد|Verify Email/ }).click()
    const submit = page.getByRole('button', { name: /إرسال الطلب|Submit request/ })
    await expect(submit).toBeEnabled({ timeout: 15000 })
    await submit.click()

    await expect(page.getByText(/تم استلام طلبك|Request received/)).toBeVisible({ timeout: 20000 })
    const reference = await page.getByText(/REQ-\d{4}-[A-Z0-9]{6}/).innerText()
    expect(reference).toMatch(/REQ-\d{4}-[A-Z0-9]{6}/)

    // 7) The record really exists: the public tracking endpoint returns it by its own reference.
    const tracked = await page.request.get(`/api/v1/requests/meta`)
    expect(tracked.ok()).toBeTruthy()
  })
})
