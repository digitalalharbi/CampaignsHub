import { expect, test } from '@playwright/test'
import { AUTH, csrfHeaders, seededProject, selectProject } from './helpers'

/**
 * BUDGET-CONNECTED-001 — the limit reaches the board where the money is spent.
 *
 * ## Why a browser case and not only the unit ones
 *
 * The matcher and the chip are both tested directly, and neither can tell whether the campaigns page
 * actually ASKS for the limits. That wiring is the whole feature: a spend limit that reaches its own
 * page, the alert evaluator and the daily digest, and not the screen an operator works on, is
 * governance in a drawer — which is the state this closes.
 *
 * The limit is created through the real endpoint rather than seeded, so what is read back has been
 * through `SpendLimitGovernor` exactly as the product computes it.
 */
test.use({ storageState: AUTH.owner })

test('a campaign over its project spend limit says so on the campaigns board', async ({ page, request }) => {
  const projectId = await seededProject(request, 'متجر تجريبي — Demo')

  /*
   * One riyal, over the whole project, for a window that includes today.
   *
   * A limit this small is certain to be breached by a demo account that has spent anything at all —
   * which is what makes the assertion about the WIRING rather than about the seeder's figures.
   */
  const created = await request.post(`/api/v1/projects/${projectId}/spend-limits`, {
    headers: await csrfHeaders(request),
    data: {
      scope: 'project',
      amount: 1,
      currency: 'SAR',
      starts_on: '2026-01-01',
      ends_on: '2026-12-31',
      thresholds: [80],
    },
  })

  expect(created.status(), await created.text()).toBeLessThan(300)
  const limitId = (await created.json()).data?.id

  try {
    await selectProject(page, projectId)
    await page.goto('/agency/campaigns?view=cards')

    await expect(page.getByTestId('campaign-card').first()).toBeVisible({ timeout: 30000 })

    const chip = page.getByTestId('spend-limit-chip').first()
    await expect(chip, 'the campaigns board never asked for the project’s spend limits').toBeVisible({
      timeout: 20000,
    })
    await expect(chip).toHaveAttribute('data-state', 'over')

    /*
     * And it says what a limit DOES. «Over its spend limit» on a campaign that is still serving is
     * exactly where somebody assumes something was paused for them; CampaignsHub watches and warns.
     */
    expect(await chip.getAttribute('title') ?? '').toMatch(/does not stop delivery|ولا يوقف عرض/)
  } finally {
    if (limitId !== undefined) {
      await request.delete(`/api/v1/projects/${projectId}/spend-limits/${limitId}`, {
        headers: await csrfHeaders(request),
      })
    }
  }
})

/** With no limit on the project, the board draws no chip — the feature is silent when it should be. */
test('a project with no spend limit shows no chip at all', async ({ page, request }) => {
  await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
  await page.goto('/agency/campaigns?view=cards')

  await expect(page.getByTestId('campaign-card').first()).toBeVisible({ timeout: 30000 })
  await page.waitForLoadState('networkidle')

  await expect(page.getByTestId('spend-limit-chip')).toHaveCount(0)
})
