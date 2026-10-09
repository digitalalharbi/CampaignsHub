import { expect, test, type Page } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * Owner directive 2026-10-09 §5–§7, §54–§55 — the first viewport, measured on every real surface.
 *
 * Two acceptance rules, applied to every major operator surface at the four widths the directive
 * names, in both directions and both themes:
 *
 *   1. No page-level horizontal scroll. The user must not move left or right to understand a
 *      normal analytical screen; a large grid may scroll INSIDE its own box, the document may not.
 *   2. The first viewport carries decision value: the page's heading and at least one of the
 *      blocks that answer the page's question — named per route below, from the rendered DOM, not
 *      assumed. A route whose contract is still `[]` is measured for overflow only until it is
 *      reviewed and its first-screen blocks are named (LIVE_ROUTE_CHECKLIST.md).
 *
 * This is B1's page-overflow guard and first-viewport contract. It is deliberately generic: a new
 * surface joins by one line, and a surface that regresses — a chart hoisted above its answer, a
 * table that widens the page — fails here with the viewport, the locale and the theme named.
 */
const PROJECT = 'متجر تجريبي — Demo'

const WIDTHS = [
  { w: 1366, h: 768 },
  { w: 1440, h: 900 },
  { w: 768, h: 1024 },
  { w: 390, h: 844 },
] as const

/** Route → the testids of blocks that must be at least partly inside the first viewport (desktop). */
const SURFACES: Array<{ path: string; project?: boolean; firstScreen: string[] }> = [
  { path: '/agency/dashboard', firstScreen: ['agency-intro', 'agency-attention'] },
  { path: '/agency/portfolio', firstScreen: ['portfolio-intro-kpis', 'portfolio-trend'] },
  { path: '/agency/campaigns', project: true, firstScreen: [] },
  { path: '/agency/analytics', project: true, firstScreen: [] },
  { path: '/agency/content', project: true, firstScreen: [] },
  { path: '/agency/reports', project: true, firstScreen: [] },
  { path: '/agency/integrations', firstScreen: [] },
  { path: '/agency/spend-limits', project: true, firstScreen: [] },
  { path: '/agency/alerts', firstScreen: [] },
]

async function measure(page: Page, ids: string[]) {
  return page.evaluate((wanted) => {
    const d = document.documentElement
    const vh = window.innerHeight
    const inFirstScreen = (el: Element | null) => {
      if (!el) return false
      const r = el.getBoundingClientRect()
      return r.height > 0 && r.top < vh && r.bottom > 0
    }
    const widest = [...document.querySelectorAll<HTMLElement>('body *')]
      .filter((e) => e.getBoundingClientRect().right > window.innerWidth + 1 && e.getBoundingClientRect().width > 0)
      .slice(0, 5)
      .map((e) => e.dataset.testid || e.className?.toString().slice(0, 60) || e.tagName)
    return {
      overflow: d.scrollWidth - d.clientWidth,
      heading: inFirstScreen(document.querySelector('main h1, h1')),
      missing: wanted.filter((id) => !inFirstScreen(document.querySelector(`[data-testid="${id}"]`))),
      widest,
    }
  }, ids)
}

test.describe('the first viewport, on every operator surface', () => {
  test.use({ storageState: AUTH.owner })

  for (const surface of SURFACES) {
    for (const { w, h } of WIDTHS) {
      for (const locale of ['ar', 'en'] as const) {
        for (const theme of ['dark', 'light'] as const) {
          test(`${surface.path} · ${w}×${h} · ${locale} · ${theme}`, async ({ page, request }) => {
            test.setTimeout(90_000)
            await page.addInitScript(([l, t]) => {
              window.localStorage.setItem('campaign-hub-locale', l)
              window.localStorage.setItem('campaign-hub-theme', t)
            }, [locale, theme] as const)
            await page.setViewportSize({ width: w, height: h })

            if (surface.project) {
              await page.goto('/agency/dashboard')
              await selectProject(page, await seededProject(request, PROJECT))
            }
            await page.goto(surface.path)
            await expect(page.locator('main')).toBeVisible({ timeout: 30_000 })
            await expect(page.locator('html')).toHaveAttribute('dir', locale === 'ar' ? 'rtl' : 'ltr')
            await expect(page.locator('html')).toHaveAttribute('data-theme', theme)
            await page.waitForLoadState('networkidle')

            /* Measure after the data landed: a skeleton has no width problem and no answer either. */
            await expect
              .poll(async () => (await measure(page, surface.firstScreen)).heading, { timeout: 30_000 })
              .toBe(true)
            const m = await measure(page, surface.firstScreen)

            expect(m.overflow, `${surface.path} scrolls sideways at ${w}px (${locale}/${theme}); widest: ${m.widest.join(' | ')}`).toBeLessThanOrEqual(1)
            if (w >= 1366) {
              expect(m.missing, `${surface.path} @${w} (${locale}/${theme}): first-screen blocks not in the first viewport`).toEqual([])
            }
          })
        }
      }
    }
  }
})
