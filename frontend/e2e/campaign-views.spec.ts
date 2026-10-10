import { expect, test } from '@playwright/test'
import { AUTH, csrfHeaders, seededProject, selectProject } from './helpers'

/**
 * CAMPAIGN-VIEWS-001 — Drafts · Scheduled · Paused · Change History on the Campaigns surface.
 *
 * A draft created through the API is counted under «مسودات», the chip narrows the table to it,
 * and the change history view names its creation. Non-vacuous: the draft is created here, so the
 * count and the history row cannot be leftovers of the seed.
 */
test.use({ storageState: AUTH.owner })

test('a draft is its own band, the chip narrows the table to it, and the history names its creation', async ({ page, request }) => {
  const project = await seededProject(request, 'Q3 Launch — Demo')
  const name = `Draft ${Date.now()}`
  const created = await request.post(`/api/v1/projects/${project}/campaigns`, {
    headers: await csrfHeaders(request),
    data: { name, objective: 'sales', status: 'draft', total_budget: 5000, budget_currency: 'SAR' },
  })
  expect(created.status(), await created.text()).toBe(201)

  await selectProject(page, project)
  await page.goto('/agency/campaigns')
  await expect(page.getByTestId('campaigns-bands')).toBeVisible({ timeout: 20_000 })
  const drafts = page.getByTestId('campaigns-band-drafts')
  await expect(drafts).toBeVisible()
  await expect.poll(async () => Number(await drafts.getAttribute('data-count'))).toBeGreaterThan(0)

  await drafts.click()
  // The chip opens the list; the list names what narrowed it and offers the way back.
  const active = page.getByTestId('campaigns-band-active')
  await expect(active).toBeVisible({ timeout: 20_000 })
  await expect(active).toContainText('مسودات')
  await expect(page.getByText(name).first()).toBeVisible({ timeout: 20_000 })
  const rows = page.locator('[data-testid="campaign-row"], [data-testid="campaign-card"]')
  await expect.poll(() => rows.count()).toBeGreaterThan(0)

  await page.getByTestId('view-history').click()
  const history = page.getByTestId('project-change-history')
  await expect(history).toBeVisible({ timeout: 20_000 })
  await expect(history.getByText(name).first()).toBeVisible()
  await expect(history.getByText(/أُنشئت الحملة/).first()).toBeVisible()

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow, 'page-level horizontal overflow').toBeLessThanOrEqual(0)
})
