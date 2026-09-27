import { expect, test, type Page } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * Owner defect #19 (P0) — «No page-level mobile horizontal overflow».
 *
 * The symptom was «page scrolled sideways at 390», and the surfaces it was raised against were
 * «all». That is the kind of claim no unit test can hold: sideways scroll is produced by the SUM of
 * a page — a table wider than its container, a nowrap heading, a chart with a fixed pixel width, a
 * negative margin — and none of those pieces is wrong on its own. Only the rendered document knows.
 *
 * So this measures the document. `scrollWidth > clientWidth` is the browser's own answer to «can
 * this page be scrolled sideways», and it is the same question the owner asked with a thumb.
 *
 * Two things make the measurement mean something:
 *
 * Each portal is driven by ITS OWN account. `AUTH.owner` is an agency account, and using it to open
 * `/app/*` proves nothing about either portal — `helpers.ts` says so where `AUTH.advertiser` is
 * defined, and the guard would otherwise be measuring a redirect.
 *
 * And every page is asserted to have ARRIVED. A login screen and a «page not found» card both fit
 * 390 comfortably, so a guard that only measured width would go green precisely when a route broke.
 * The landing path and a fragment of the page's own text are recorded for each one.
 */

/** What the page is, once it has settled. */
async function reading(page: Page, path: string) {
    await page.goto(path, { waitUntil: 'networkidle' }).catch(() => {})
    /* Charts and virtualised grids lay out after their data lands; width is only stable afterwards. */
    await page.waitForTimeout(1200)

    return page.evaluate(() => ({
        landed: location.pathname,
        scroll: document.documentElement.scrollWidth,
        client: document.documentElement.clientWidth,
        /*
         * The element that sticks out furthest, so a failure names the thing to fix.
         *
         * Measured on BOTH sides. This product's default locale is Arabic, and in RTL a too-wide
         * child overflows to the LEFT: its `right` stays inside the viewport while its `left` goes
         * negative. A «widest element» found by `right` alone reports `html` on every RTL page,
         * which is the one answer that helps nobody.
         */
        widest: Array.from(document.querySelectorAll('*')).reduce(
            (worst: { what: string; over: number; edge: string }, el) => {
                const r = el.getBoundingClientRect()
                if (r.width === 0) return worst

                const past = Math.max(r.right - document.documentElement.clientWidth, -r.left)
                if (past <= worst.over) return worst

                const cls = typeof el.className === 'string' ? el.className.split(' ').slice(0, 3).join('.') : ''

                return {
                    what: `${el.tagName.toLowerCase()}${cls ? `.${cls}` : ''}`,
                    over: Math.round(past),
                    edge: r.right - document.documentElement.clientWidth > -r.left ? 'end' : 'start',
                }
            },
            { what: '', over: 0, edge: '' },
        ),
        text: (document.body.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 80),
    }))
}

function portal(label: string, storageState: string, paths: string[]) {
    test.describe(`${label} at 390`, () => {
        test.use({ storageState, viewport: { width: 390, height: 844 } })

        test(`no ${label} surface scrolls sideways`, async ({ page }) => {
            test.setTimeout(240_000)

            const sideways: string[] = []
            const empty: string[] = []

            for (const path of paths) {
                const r = await reading(page, path)

                if (r.text.length < 20) {
                    empty.push(`${path} landed on ${r.landed} with nothing on it`)
                    continue
                }

                /* One pixel of slack: sub-pixel layout rounds, and a 0.5px seam is not a scrollbar. */
                if (r.scroll > r.client + 1) {
                    sideways.push(
                        `${path} (landed ${r.landed}): scrollWidth ${r.scroll} > clientWidth ${r.client}`
                        + ` — ${r.widest.what} hangs ${r.widest.over}px past the ${r.widest.edge} edge`,
                    )
                }
            }

            expect(empty, `a route under test did not render:\n${empty.join('\n')}`).toEqual([])
            expect(sideways, `these surfaces scroll sideways at 390:\n${sideways.join('\n')}`).toEqual([])
        })
    })
}

portal('advertiser', AUTH.advertiser, [
    '/app/dashboard', '/app/analytics', '/app/content', '/app/reports', '/app/campaigns',
])

portal('agency', AUTH.owner, [
    '/agency/dashboard', '/agency/clients', '/agency/portfolio', '/agency/reports', '/agency/content',
])

portal('client', AUTH.client, [
    '/portal', '/portal/reports', '/portal/invoices',
])
