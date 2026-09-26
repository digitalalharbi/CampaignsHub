import { expect, request as playwrightRequest, test } from '@playwright/test'
import { AUTH, csrfHeaders } from './helpers'

/**
 * BRANDING-RENDER-EVIDENCE-001 — a configured mark reaches the surface a CLIENT opens.
 *
 * The row's bar is «the configured logo actually renders, proven per surface», and its own warning
 * is that «code containing `logo_url` is not completion». Both halves of this were already tested
 * and neither half is the claim: the backend resolves a url (`SharedReportBrandingTest`), and the
 * header renders whatever url it is handed (`PublicReportBranding.test.tsx`). A mark that is
 * uploaded in the Branding Center and never reaches the shared link would pass both.
 *
 * So this walks it end to end, in a browser, across the boundary that matters: the mark is
 * configured through the authenticated Branding Center, and then the link is opened with NO session
 * at all — a real client, holding only the address they were sent.
 *
 * `naturalWidth` rather than visibility, and that is the whole point. An `<img>` with a src that
 * 404s is still «visible» to a locator; the browser draws its broken-image icon, which on a client's
 * report reads as «this report failed» rather than «this agency has no logo». Only a decoded
 * intrinsic width says the bytes arrived and the browser drew them.
 *
 * The asset is removed afterwards. It is tenant-scoped rather than project-scoped, so leaving it
 * behind would change what every other spec's reports are branded with.
 */

/** The smallest real PNG — one opaque pixel, which is all `naturalWidth` needs to be non-zero. */
const PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64',
)

const TOKEN = 'demo-live-report-token'

test.use({ storageState: { cookies: [], origins: [] } })

test('a mark configured in the Branding Center reaches the link a client opens', async ({ page, context, baseURL }) => {
  test.setTimeout(120_000)

  /*
   * The agency side, in its OWN context — the page below must stay signed out.
   *
   * `AUTH.owner` is the agency account. The `request` fixture cannot be used here: this file sets an
   * empty `storageState` so the reader is a stranger, and that governs the fixture too.
   */
  const agency = await playwrightRequest.newContext({ baseURL, storageState: AUTH.owner })
  let assetId: string | null = null

  try {
    const upload = await agency.post('/api/v1/branding/assets', {
      headers: await csrfHeaders(agency),
      multipart: {
        scope: 'tenant',
        kind: 'primary_horizontal',
        theme: 'any',
        file: { name: 'mark.png', mimeType: 'image/png', buffer: PNG },
      },
    })

    expect(upload.status(), `the Branding Center refused the upload: ${await upload.text()}`).toBe(201)
    assetId = ((await upload.json())?.data?.id ?? null) as string | null
    expect(assetId, 'the upload returned no asset id').not.toBeNull()

    /*
     * What the SERVER resolved, before asking what the page drew.
     *
     * The two are different claims and only one of them can be wrong at a time: a null `logo_url`
     * here is the resolver not finding the mark, and a non-null one with no `<img>` below is the
     * header not rendering what it was handed. Asserting only the second leaves a failure that
     * cannot say which.
     */
    const resolved = await agency.get(`/api/v1/reports/shared/${TOKEN}/branding`)
    expect(resolved.status(), await resolved.text()).toBe(200)
    const identity = (await resolved.json())?.data ?? {}
    expect(
      identity.logo_url,
      `the resolver found no mark for this share — it answered ${JSON.stringify(identity)}`,
    ).toBeTruthy()

    await page.goto(`/r/${TOKEN}`)
    // `storageState` only governs what the context STARTS with; anything set on arrival goes too.
    await context.clearCookies()
    await page.reload()

    /*
     * EITHER SLOT, because the hierarchy decides which one and this spec must not decide for it.
     *
     * `headerIdentity` puts the CLIENT's own mark in the leading slot and the AGENCY's beside
     * «بواسطة», on purpose: the nearest-logo fallback would otherwise put an agency's mark where the
     * client's belongs, which is a different claim about whose report this is. A tenant-scope mark on
     * a client's report therefore lands in the provenance slot, and a first cut of this spec asserted
     * only the leading one and read that as the mark never arriving.
     *
     * What the requirement asks is that a CONFIGURED mark reaches the surface a client opens. Which
     * slot the hierarchy gives it is the product's decision, not this test's.
     */
    const logo = page.locator('[data-testid="shared-report-logo"], [data-testid="shared-report-agency-logo"]').first()
    await expect(logo, 'the configured mark reached neither the leading slot nor the provenance slot').toBeVisible({ timeout: 20000 })

    // Drawn, not merely present. A 404 src is «visible» and shows a broken-image icon, which on a
    // client's report reads as «this report failed» rather than «this agency has no logo».
    await expect
      .poll(async () => logo.evaluate((img: HTMLImageElement) => img.naturalWidth), { timeout: 15000 })
      .toBeGreaterThan(0)

    // And the identity beside it is still the report's, never emptied by the mark arriving.
    await expect(page.getByTestId('shared-report-name')).not.toBeEmpty()
  } finally {
    if (assetId !== null) {
      await agency.delete(`/api/v1/branding/assets/${assetId}`, { headers: await csrfHeaders(agency) })
    }
    await agency.dispose()
  }
})
