import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * PERF-BOOTSTRAP-001 — the agency dashboard's first load.
 *
 * It fired thirteen API calls in parallel at a host with five PHP workers, and seven of them are the same on every page: who you are,
 * your memberships, your access, the subscription, the branding, the bell and the financial disclaimer. `GET /bootstrap` answers all
 * seven in one request and the page files each under the key its own hook reads, so none of the seven endpoints is asked on load.
 *
 * The count is taken from the browser's own network events, not from the app's code: a hook that read another key, or a component
 * that asked again on mount, is a request here.
 */
test.use({ storageState: AUTH.owner })

const SEVEN = ['/auth/me', '/auth/memberships', '/me/access', '/subscriptions/current', '/settings/branding', '/notifications/summary', '/funding/disclaimer']

test('the first load sends one bootstrap and none of the seven endpoints it replaces', async ({ page }) => {
  const calls: string[] = []
  page.on('request', (request) => {
    const { pathname } = new URL(request.url())
    if (pathname.startsWith('/api/v1/')) calls.push(pathname.slice('/api/v1'.length))
  })

  await page.goto('/agency/dashboard')
  // The page's own heading: the agency dashboard has drawn, signed in, from the bootstrap alone.
  await expect(page.getByRole('main').getByRole('heading', { level: 1 })).toBeVisible({ timeout: 30000 })
  await page.waitForLoadState('networkidle')

  // Recorded in the report, so the before/after of the dashboard's request count is read off a run and not off a memory.
  console.log(`agency dashboard first load: ${calls.length} API calls — ${calls.join(' ')}`)

  expect(calls.filter((c) => c === '/bootstrap'), 'exactly one bootstrap').toHaveLength(1)
  for (const endpoint of SEVEN) expect(calls, `${endpoint} is in the bootstrap and must not be asked on load`).not.toContain(endpoint)
})
