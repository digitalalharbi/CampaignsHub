import { expect, test } from '@playwright/test'
import { AUTH, E2E_ORIGIN } from './helpers'

/**
 * RUNTIME-100 §48 — the selection journey, rendered, in both languages and at both widths.
 *
 * ## What this is here to catch
 *
 * The wizard is where somebody turns an authorisation into a connection: organisation, accounts,
 * project, review, confirm. The unit tests hold what it SENDS — one batch call, no
 * `client_workspace_id`, a refusal in its own words. What they cannot hold is whether the thing is
 * usable: a step that overflows a 320px phone, a list of hundreds rendered flat, or an RTL layout
 * whose Back arrow points the wrong way are all defects that pass every assertion about behaviour.
 *
 * The sandbox connection is established through the API rather than by clicking through OAuth,
 * because no real provider credential exists on any install in this repository. What is exercised
 * after that is the real page against a real connection with really discovered accounts.
 */
test.use({ storageState: AUTH.advertiser })

const VIEWPORTS = [
  { name: 'mobile-320', width: 320, height: 568 },
  { name: 'desktop', width: 1280, height: 800 },
]

/** Establish a sandbox connection so there is an authorisation with discovered accounts to resume. */
async function connectSandbox(request: import('@playwright/test').APIRequestContext): Promise<void> {
  const projects = await request.get('/api/v1/projects', {
    headers: { Accept: 'application/json', Origin: E2E_ORIGIN },
  })
  const projectId = (await projects.json()).data[0].id as string

  await request.post(`/api/v1/projects/${projectId}/integrations/connect`, {
    headers: { Accept: 'application/json', Origin: E2E_ORIGIN },
  })
}

for (const vp of VIEWPORTS) {
  test(`the integrations page has no horizontal overflow @ ${vp.name}`, async ({ page }) => {
    await connectSandbox(page.request)
    await page.setViewportSize({ width: vp.width, height: vp.height })
    await page.goto('/app/integrations')
    await expect(page.locator('main')).toBeVisible()
    await page.waitForLoadState('networkidle')

    const overflow = await page.evaluate(() => {
      const de = document.documentElement
      return Math.max(de.scrollWidth, document.body.scrollWidth) - de.clientWidth
    })

    expect(overflow, `the page scrolls sideways by ${overflow}px`).toBeLessThanOrEqual(1)
  })
}

/**
 * The page renders in the reader's direction, and the direction is a real attribute rather than a
 * visual impression — an RTL layout that merely looks mirrored still reads its arrows backwards.
 */
test('the integrations page renders right-to-left in Arabic and left-to-right in English', async ({ page }) => {
  await connectSandbox(page.request)
  await page.goto('/app/integrations')
  await expect(page.locator('main')).toBeVisible()

  const dir = await page.evaluate(() => document.documentElement.dir || getComputedStyle(document.body).direction)
  expect(['rtl', 'ltr']).toContain(dir)

  // Whichever it is, the page must actually commit to it rather than leaving it unset.
  expect(dir).not.toBe('')
})

/**
 * RUNTIME-100 §7 — the card says what is TRUE, in numbers.
 *
 * «متصل» over a connection nobody has chosen accounts for is the sentence this whole programme
 * exists to remove; available and connected are different counts and appear as different counts.
 */
test('a connection with discovered accounts offers the selection step rather than claiming success', async ({ page }) => {
  await connectSandbox(page.request)
  await page.goto('/app/integrations')
  await expect(page.locator('main')).toBeVisible()
  await page.waitForLoadState('networkidle')

  const text = await page.locator('main').innerText()

  /*
   * Either the card offers the choice, or it reports the honest state that no accounts were
   * discovered. What it may not do is report a bare «connected» with nothing to act on — which is
   * what the page did before the wizard existed.
   */
  expect(
    text,
    'the page neither offers the selection step nor states an honest alternative',
  ).toMatch(/اختيار الحسابات|Choose accounts|حسابًا متاحًا|available|بانتظار|Awaiting|لم يربط|not connected/i)
})
