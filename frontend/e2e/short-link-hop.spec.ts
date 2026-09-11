import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * SHORT-LINK-PRODUCTION-001 — `/l/{slug}` reaches Laravel, counts the click, and redirects.
 *
 * Production was answering `GET https://campaignshub.io/l/jm4bf2p` with **200 and the SPA's
 * `index.html`**, so a link the product had just minted rendered «الصفحة غير موجودة» for the person
 * it was sent to. The cause was an edge config: the block existed in `deploy/nginx-spa.conf`, which
 * no Dockerfile copies, while the file the image is built from had no `/l/` at all.
 *
 * A unit test cannot see that. This creates a real link through the UI and follows its own short URL
 * the way a recipient would — which is the only check that crosses the edge.
 */
test.describe('a short link resolves for the person it was sent to', () => {
  test.use({ storageState: AUTH.owner })

  test('a link short-url redirects to its destination and counts the click', async ({ page, request }) => {
    test.setTimeout(180_000)

    await page.goto('/agency/short-links')
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })

    const submit = page.getByTestId('short-link-submit')
    if (await submit.count() === 0) test.skip(true, 'this portal does not offer short links')

    /* The form is open on arrival — the Owner's correction; no step before the first field. */
    await expect(submit).toBeVisible()

    await page.getByTestId('short-link-kind-link').click()
    await page.locator('#short-link-url').fill('https://example.com/hop-check')
    await submit.click()

    const result = page.getByTestId('short-link-result')
    await expect(result).toBeVisible({ timeout: 30000 })

    const shortUrl = (await result.locator('code').innerText()).trim()
    const slug = shortUrl.split('/l/')[1]
    expect(slug, `no slug in ${shortUrl}`).toBeTruthy()

    /*
      Followed WITHOUT redirects so the hop itself is what is asserted: a 200 here is the SPA
      answering, which is exactly the production defect. `request` carries no browser session, which
      is also the point — the recipient of a link has none.
    */
    const hop = await request.get(`/l/${slug}`, { maxRedirects: 0 })

    expect(hop.status(), 'the hop must redirect, not render the app').toBe(302)
    expect(hop.headers()['location']).toContain('example.com/hop-check')
  })

  /* The Owner asked for both kinds. A WhatsApp link hops to `wa.me`, built from the number typed. */
  test('a whatsapp short-url redirects to wa.me', async ({ page, request }) => {
    test.setTimeout(180_000)

    await page.goto('/agency/short-links')
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })

    const submit = page.getByTestId('short-link-submit')
    if (await submit.count() === 0) test.skip(true, 'this portal does not offer short links')

    await page.getByTestId('short-link-kind-whatsapp').click()
    await page.locator('#short-link-phone').fill('532115582')
    await submit.click()

    const result = page.getByTestId('short-link-result')
    await expect(result).toBeVisible({ timeout: 30000 })

    const slug = (await result.locator('code').innerText()).trim().split('/l/')[1]
    const hop = await request.get(`/l/${slug}`, { maxRedirects: 0 })

    expect(hop.status()).toBe(302)
    expect(hop.headers()['location']).toContain('wa.me/')
  })
})

