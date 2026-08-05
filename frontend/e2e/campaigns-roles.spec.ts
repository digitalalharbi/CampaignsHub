import { expect, test } from '@playwright/test'
import { API_HEADERS, AUTH, csrfHeaders, E2E_ORIGIN, switchToEnglish } from './helpers'

/**
 * Role / RBAC E2E — proves authorization is enforced by the SERVER, not just hidden buttons.
 * Uses `page.request` so API calls carry the authenticated session cookie of the acting role.
 * Reused sessions (created additively for testing; password `password`):
 *   owner   → all campaigns.* + projects.view (Admin)
 *   analyst → campaigns.view + projects.view (read-only)
 *   viewer  → campaigns.view only, NO projects.view (client viewer)
 */

/**
 * Walk the agency's scope control: client, then project. Written once because every UI assertion in
 * this file needs a project in context, and the sequence IS the agency portal's model.
 */
async function selectFirstClientAndProject(page: import('@playwright/test').Page) {
  /*
   * The client step exists only for operators who hold CLIENT scope. Analyst and viewer hold
   * project scope instead, so their control offers projects directly (AGENCY-006) — the sequence
   * adapts to the grant rather than assuming one shape for everyone.
   */
  // Wait for the control to finish loading before asking what shape it has: `count()` does not
  // wait, so checking it during the loading skeleton reports zero and silently skips the client.
  await expect(page.getByTestId('agency-scope')).toBeVisible({ timeout: 20000 })

  const client = page.getByTestId('agency-scope-client')
  const project = page.getByTestId('agency-scope-project')

  if (await client.count()) {
    /*
     * Try clients until one of them HAS a project.
     *
     * `index: 1` was wrong, and wrong in a way that looked like a product bug: an agency may hold a
     * client that has no projects yet, and the control correctly says so and leaves the project
     * field disabled. Whether the alphabetically-first demo client happens to have a project is not
     * what any test in this file is about — every one of them needs to be IN a project, so the
     * helper's job is to get there rather than to assume the first attempt does.
     */
    const options = await client.locator('option').count()

    for (let i = 1; i < options; i++) {
      await client.selectOption({ index: i })
      await expect(project).toBeAttached()
      if (await project.isEnabled()) break
    }
  }

  await expect(
    project,
    'no authorised client has a project — the fixture cannot put this operator inside one',
  ).toBeEnabled({ timeout: 20000 })
  await project.selectOption({ index: 1 })
}

test.describe('Admin (owner)', () => {
  test.use({ storageState: AUTH.owner })

  test('can create — after choosing a client and a project (AGENCY-006)', async ({ page }) => {
    await page.goto('/agency/campaigns')
    await switchToEnglish(page)

    /*
     * The agency picks a CLIENT first. Before that there is no project, so there is nothing to
     * create a campaign in — and the page says so rather than offering an action that would have
     * to guess whose campaign it is.
     */
    await expect(page.getByRole('button', { name: /New campaign|حملة جديدة/ })).toHaveCount(0)

    await selectFirstClientAndProject(page)
    await expect(page.getByRole('button', { name: /New campaign|حملة جديدة/ })).toBeVisible()
  })
})

test.describe('Analyst (read-only)', () => {
  test.use({ storageState: AUTH.analyst })

  test('no create button, and a direct create API call is 403', async ({ page }) => {
    await page.goto('/agency/campaigns')
    await switchToEnglish(page)
    // In context of a real project, so the absence of the button is about PERMISSION and not about
    // there being nothing selected.
    await selectFirstClientAndProject(page)
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
      baseURL: E2E_ORIGIN,
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

    // UI: the agency scope control offers only what this membership may reach, and there is no
    // create action. Each select carries its options plus one placeholder (AGENCY-006).
    await page.goto('/agency/campaigns')
    await switchToEnglish(page)
    await expect(page.getByRole('button', { name: /New campaign|حملة جديدة/ })).toHaveCount(0)

    /*
     * This viewer holds PROJECT scope and no client scope, so the control offers projects directly.
     * The claim is unchanged and is the one that matters: it exposes exactly the one project their
     * membership allows — plus the placeholder — and nothing from the other twenty.
     */
    const projectSelect = page.getByTestId('agency-scope-project')
    await expect(projectSelect).toBeVisible({ timeout: 20000 })
    await expect(projectSelect.locator('option')).toHaveCount(2)
    await expect(page.getByTestId('agency-scope-client')).toHaveCount(0)
  })
})
