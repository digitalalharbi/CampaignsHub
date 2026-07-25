import { expect, test } from '@playwright/test'
import { API_HEADERS, AUTH, csrfHeaders, switchToEnglish } from './helpers'

/**
 * Role / RBAC E2E — proves authorization is enforced by the SERVER, not just hidden buttons.
 * Uses `page.request` so API calls carry the authenticated session cookie of the acting role.
 * Reused sessions (created additively for testing; password `password`):
 *   owner   → all campaigns.* + projects.view (Admin)
 *   analyst → campaigns.view + projects.view (read-only)
 *   viewer  → campaigns.view only, NO projects.view (client viewer)
 */

test.describe('Admin (owner)', () => {
  test.use({ storageState: AUTH.owner })

  test('can create — the New campaign action is available', async ({ page }) => {
    await page.goto('/campaigns')
    await switchToEnglish(page)
    await expect(page.getByRole('button', { name: /New campaign|حملة جديدة/ })).toBeVisible()
  })
})

test.describe('Analyst (read-only)', () => {
  test.use({ storageState: AUTH.analyst })

  test('no create button, and a direct create API call is 403', async ({ page }) => {
    await page.goto('/campaigns')
    await switchToEnglish(page)
    await expect(page.getByRole('button', { name: /New campaign|حملة جديدة/ })).toHaveCount(0)

    // Server-side enforcement — a direct POST is rejected regardless of the UI (analyst has
    // projects.view so can list, but lacks campaigns.create → 403).
    const projects = (await (await page.request.get('/api/v1/projects', { headers: API_HEADERS })).json())
      .data as Array<{ id: string }>
    const res = await page.request.post(`/api/v1/projects/${projects[0].id}/campaigns`, {
      headers: await csrfHeaders(page.request),
      data: { name: `Analyst must not create ${Date.now()}` },
      failOnStatusCode: false,
    })
    expect(res.status()).toBe(403)
  })
})

test.describe('Client Viewer (scoped to one project)', () => {
  test.use({ storageState: AUTH.viewer })

  test('sees only the authorized project; cross-project access is denied', async ({ page, playwright }) => {
    // The viewer's own project list is scoped to their membership → exactly one project.
    const viewerProjects = (await (await page.request.get('/api/v1/projects', { headers: API_HEADERS })).json())
      .data as Array<{ id: string }>
    expect(viewerProjects).toHaveLength(1)
    const authorizedId = viewerProjects[0].id

    // Authorized project → 200.
    const ok = await page.request.get(`/api/v1/projects/${authorizedId}/campaigns`, {
      headers: API_HEADERS,
      failOnStatusCode: false,
    })
    expect(ok.status()).toBe(200)

    // A project they are NOT a member of (from the owner's full list) → 403, even by hand-swapping the id.
    const owner = await playwright.request.newContext({
      baseURL: 'http://localhost:5173',
      storageState: AUTH.owner,
      extraHTTPHeaders: API_HEADERS,
    })
    const allProjects = (await (await owner.get('/api/v1/projects')).json()).data as Array<{ id: string }>
    await owner.dispose()
    const forbidden = allProjects.find((p) => p.id !== authorizedId)
    expect(forbidden, 'owner should see more projects than the viewer').toBeTruthy()
    const denied = await page.request.get(`/api/v1/projects/${forbidden!.id}/campaigns`, {
      headers: API_HEADERS,
      failOnStatusCode: false,
    })
    expect(denied.status()).toBe(403)

    // UI: the switcher exposes only the authorized project, and there is no create action.
    await page.goto('/campaigns')
    await switchToEnglish(page)
    await expect(page.getByRole('button', { name: /New campaign|حملة جديدة/ })).toHaveCount(0)
    await expect(page.locator('aside select option')).toHaveCount(1)
  })
})
