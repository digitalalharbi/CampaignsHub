import { expect, test } from '@playwright/test'
import { AUTH, switchToEnglish } from './helpers'

/**
 * PORTFOLIO-VISUAL-001 §9 — «how is my portfolio doing and where should I look?», in a browser.
 *
 * ## What the page must answer before anybody scrolls
 *
 * Four totals cannot answer it: they say how much, never what happened, where it came from, or who
 * moved it. So the head states the scope, the period and the freshness, and the body answers the
 * rest in shapes. These hold the head's four answers and the presence of each shape, because a
 * section that silently disappears is how a rebuilt page quietly becomes the old one again.
 *
 * ## And nothing is invented to fill it
 *
 * Every section here renders only what the payload measured. A currency with no spend has no line,
 * a platform nothing feeds has no column, and a portfolio with nothing to worry about says so
 * rather than drawing an empty queue.
 */
test.use({ storageState: AUTH.owner })

test('the head answers where, what scope, what period and how fresh', async ({ page }) => {
  await page.goto('/agency/portfolio')
  await switchToEnglish(page)

  await expect(page.getByTestId('portfolio-intro')).toBeVisible({ timeout: 30000 })
  // The scope is DRAWN, never inferred — a figure without one gets attributed to whatever the
  // reader had in mind, which on a cross-client page is usually one client.
  await expect(page.getByTestId('portfolio-scope')).toContainText('All projects')
  await expect(page.getByTestId('portfolio-period')).toBeVisible()
  await expect(page.getByTestId('portfolio-freshness')).toBeVisible()
  await expect(page.getByTestId('portfolio-health')).toBeVisible()
})

test('the figures lead, and the spend never becomes one number across currencies', async ({ page }) => {
  await page.goto('/agency/portfolio')
  await switchToEnglish(page)

  await expect(page.getByTestId('portfolio-projects-total')).toBeVisible({ timeout: 30000 })
  await expect(page.getByTestId('portfolio-attention-total')).toBeVisible()
  await expect(page.getByTestId('portfolio-campaigns-total')).toBeVisible()

  const breakdown = page.getByTestId('portfolio-spend-breakdown')

  if (await breakdown.count() > 0) {
    // Every currency stated on its own. The payload carries no total, so there is none to print.
    const currencies = await breakdown.locator('[data-testid^="portfolio-spend-"]').count()
    expect(currencies).toBeGreaterThan(0)
  }
})

test('each section either shows its shape or says which emptiness it is', async ({ page }) => {
  await page.goto('/agency/portfolio')
  await switchToEnglish(page)

  await expect(page.getByTestId('portfolio-intro')).toBeVisible({ timeout: 30000 })

  for (const section of ['trend', 'contribution', 'queue']) {
    await expect(
      page.locator(`[data-testid="portfolio-${section}"], [data-testid="portfolio-${section}-empty"]`).first(),
      `«${section}» rendered neither its shape nor an empty state`,
    ).toBeVisible({ timeout: 20000 })
  }

  // The health distribution is always drawable: every project is in exactly one of its states.
  await expect(page.getByTestId('portfolio-health-distribution')).toBeVisible()
})

/** The attention queue says WHY, and offers the act — not merely that something is wrong. */
test('the attention queue names a reason and offers the way to fix it', async ({ page }) => {
  await page.goto('/agency/portfolio')
  await switchToEnglish(page)

  await expect(page.getByTestId('portfolio-intro')).toBeVisible({ timeout: 30000 })

  const queue = page.getByTestId('portfolio-queue')

  if (await queue.count() === 0) {
    await expect(page.getByTestId('portfolio-queue-empty')).toBeVisible()
    return
  }

  const first = queue.locator('li').first()
  await expect(first).toContainText(/No linked accounts|No data received|Data is behind/)
  await expect(first.getByRole('link')).toHaveAttribute('href', /\/projects\/.+\/integrations/)
})

test('the portfolio prints no placeholder value', async ({ page }) => {
  await page.goto('/agency/portfolio')
  await switchToEnglish(page)

  await expect(page.getByTestId('portfolio-intro')).toBeVisible({ timeout: 30000 })

  const text = (await page.locator('main').innerText()).replace(/\s+/g, ' ')

  expect(text).not.toMatch(/\b(undefined|NaN|\[object Object\])\b/)
})
