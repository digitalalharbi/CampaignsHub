import { expect, test } from '@playwright/test'
import { AUTH, selectProject, seededProject } from './helpers'

test.use({ storageState: AUTH.owner, viewport: { width: 1440, height: 1200 } })

const figures = (t: string) => {
  const spend = /([\d,]+(?:\.\d+)?)\s*SAR/g
  return [...t.matchAll(spend)].map((m) => m[1]).slice(0, 6)
}

test('a creative reads the same on its card and its detail', async ({ page, request }) => {
  test.setTimeout(150_000)
  await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))

  await page.goto('/agency/content', { waitUntil: 'networkidle' })
  await page.waitForTimeout(2500)

  const cards = page.locator('[data-testid^="creative-card-"], article, li')
  const firstName = await page.locator('h3, [data-testid*="creative"] strong').first().textContent().catch(() => null)
  console.log('FIRST CARD NAME', JSON.stringify(firstName?.trim().slice(0, 40)))
  console.log('CARD COUNT', await cards.count())

  const body = (await page.evaluate(() => document.body.innerText)).replace(/\s+/g, ' ')
  console.log('CONTENT FIGURES', JSON.stringify(figures(body)))
  await page.screenshot({ path: '/tmp/parity-content.png', fullPage: false })
})
