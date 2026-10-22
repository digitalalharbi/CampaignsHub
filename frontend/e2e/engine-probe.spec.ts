import { expect, test } from '@playwright/test'
import { execSync } from 'node:child_process'
import { AUTH } from './helpers'

/**
 * WEBKIT-ENGINE-FAULT investigation probe — NOT part of the gate, and never merged to main.
 *
 * The same walk as `portal-audit.spec.ts:75` (every link in the advertiser rail, one page, one context), with the two things
 * that test cannot tell us: it does not stop at the first blank page, and it prints the browser's own process memory after
 * every navigation. Run with `--repeat-each`, every repetition reuses the worker's ONE browser process, so a fault that grows
 * with the process's age shows as a pattern across walks and one that is tied to a page shows at the same link every time.
 */
test.use({ storageState: AUTH.advertiser })

function memory(): string {
  try {
    return execSync(
      "ps -eo rss=,args= | awk '/WebKitWebProcess/{w+=$1;nw++} /WebKitNetworkProcess/{n+=$1;nn++} /WebKitGPUProcess/{g+=$1} END{printf \"web x%d=%dMB net x%d=%dMB gpu=%dMB\",nw,w/1024,nn,n/1024,g/1024}'",
    ).toString().trim()
  } catch {
    return 'ps unavailable'
  }
}

test('engine probe: the advertiser rail, with process memory', async ({ page }, info) => {
  test.setTimeout(15 * 60_000)
  const events: string[] = []
  page.on('requestfailed', (r) => events.push(`requestfailed ${r.url().split('/').pop()} ${r.failure()?.errorText ?? '?'}`))
  page.on('console', (m) => { if (m.type() === 'error') events.push(`console ${m.text().slice(0, 120)}`) })

  await page.goto('/app/dashboard')
  await expect(page.getByRole('navigation').first()).toBeVisible()
  const hrefs = await page.getByRole('navigation').first().locator('a[href^="/app"]')
    .evaluateAll((els) => [...new Set(els.map((e) => e.getAttribute('href')!))])

  const blank: string[] = []
  for (const [i, href] of hrefs.entries()) {
    events.length = 0
    const t0 = Date.now()
    await page.goto(href)
    const ok = await page.locator('main').waitFor({ state: 'visible', timeout: 20_000 }).then(() => true).catch(() => false)
    // eslint-disable-next-line no-console
    console.log(`walk=${info.repeatEachIndex} link=${i}/${hrefs.length} ${href} ${ok ? 'OK' : 'BLANK'} ${Date.now() - t0}ms mem[${memory()}]${ok ? '' : ` events=${events.slice(0, 4).join(' | ')}`}`)
    if (!ok) blank.push(href)
  }
  expect(blank, 'pages that stayed blank (see the BLANK lines for the browser-side evidence)').toEqual([])
})
