import { expect, test, type Page } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * Owner defect #13 (P0) — «Literal "undefined" must never appear as a currency».
 *
 * The observation was «237.90 undefined» on screen. The cause of that family is always the same
 * shape: a value the code was sure of, formatted next to one it was not, and JavaScript printing the
 * absence rather than refusing to. `undefined`, `NaN` and `[object Object]` are what that looks like
 * once it reaches the page — three strings a product's own copy has no reason to contain.
 *
 * They cannot be caught where they are produced, because the place that produces them is a template
 * that is right about everything else. They can only be caught where they are READ. So this reads
 * every surface's rendered text and asserts none of those three appears anywhere in it.
 *
 * Each portal is driven by its own account, for the reason `helpers.ts` gives where AUTH.advertiser
 * is defined, and every page is asserted to have arrived: an empty page contains no bad string
 * either, and would pass for the wrong reason.
 */

/** What the three look like once they are on screen. `null` is deliberately absent — it is a word. */
const NOT_A_VALUE = ['undefined', 'NaN', '[object Object]'] as const

async function textOf(page: Page, path: string) {
    await page.goto(path, { waitUntil: 'networkidle' }).catch(() => {})
    /* Figures arrive with their query; reading before that is reading a skeleton. */
    await page.waitForTimeout(1500)

    return page.evaluate(() => ({
        landed: location.pathname,
        text: (document.body.innerText || '').replace(/\s+/g, ' ').trim(),
    }))
}

function portal(label: string, storageState: string, paths: string[]) {
    test.describe(`${label} surfaces`, () => {
        test.use({ storageState, viewport: { width: 1440, height: 1000 } })

        test(`no ${label} surface prints a non-value`, async ({ page }) => {
            test.setTimeout(300_000)

            const found: string[] = []
            const empty: string[] = []

            for (const path of paths) {
                const { landed, text } = await textOf(page, path)

                if (text.length < 20) {
                    empty.push(`${path} landed on ${landed} with nothing on it`)
                    continue
                }

                for (const bad of NOT_A_VALUE) {
                    const at = text.indexOf(bad)
                    if (at === -1) continue

                    /* The words around it, because «undefined» alone does not say which figure. */
                    found.push(`${path}: «${bad}» in …${text.slice(Math.max(0, at - 60), at + 60)}…`)
                }
            }

            expect(empty, `a route under test did not render:\n${empty.join('\n')}`).toEqual([])
            expect(found, `a non-value reached the screen:\n${found.join('\n')}`).toEqual([])
        })
    })
}

portal('advertiser', AUTH.advertiser, [
    '/app/dashboard', '/app/analytics', '/app/content', '/app/reports', '/app/campaigns', '/app/portfolio',
])

portal('agency', AUTH.owner, [
    '/agency/dashboard', '/agency/clients', '/agency/portfolio', '/agency/reports',
    '/agency/content', '/agency/analytics', '/agency/campaigns',
])

portal('client', AUTH.client, [
    '/portal', '/portal/reports', '/portal/invoices',
])
