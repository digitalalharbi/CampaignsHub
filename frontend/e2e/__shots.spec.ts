import { test } from '@playwright/test'
import { AUTH, selectProject, switchToEnglish } from './helpers'

const OUT = '/private/tmp/claude-501/-Users-mohammedalharbimacbook-Desktop/937d5a2b-2c49-4d1d-863a-9ffcdd882907/scratchpad/shots'

const ROUTES = [
  ['integrations', '/app/integrations'],
  ['campaigns', '/app/campaigns'],
  ['analytics', '/app/analytics'],
  ['content', '/app/content'],
  ['reports', '/app/reports'],
  ['recommendations', '/app/recommendations'],
  ['alerts', '/app/alerts'],
  ['spend-limits', '/app/spend-limits'],
  ['tasks', '/app/tasks'],
  ['files', '/app/files'],
  ['short-links', '/app/short-links'],
  ['subscriptions', '/app/subscriptions'],
  ['settings', '/app/settings'],
] as const

test.use({ storageState: AUTH.advertiser })

test('arabic dark', async ({ page }) => {
  test.setTimeout(ROUTES.length * 20_000 + 60_000)
  await page.setViewportSize({ width: 1440, height: 1000 })
  await page.goto('/app/dashboard')
  const projects = (await (await page.request.get('/api/v1/projects', {
    headers: { Accept: 'application/json', Origin: process.env.E2E_ORIGIN ?? 'http://localhost:5273' },
  })).json()).data as Array<{ id: string }> | null
  if (projects?.length) await selectProject(page, projects[0]!.id)

  for (const [name, route] of ROUTES) {
    await page.goto(route)
    await page.waitForTimeout(2500)
    await page.screenshot({ path: `${OUT}/ar-${name}.png` })
  }
})

test('english LTR integrations', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 })
  await page.goto('/app/integrations')
  await switchToEnglish(page)
  await page.goto('/app/integrations')
  await page.waitForTimeout(2500)
  await page.screenshot({ path: `${OUT}/en-integrations.png` })
})

test('phone integrations', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/app/integrations')
  await page.waitForTimeout(2500)
  await page.screenshot({ path: `${OUT}/ar-integrations-390.png` })
})
