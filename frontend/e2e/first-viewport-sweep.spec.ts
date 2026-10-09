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
const SURFACES: Array<{ path: string; project?: boolean; projectPath?: boolean; firstScreen: string[] }> = [
  { path: '/agency/dashboard', firstScreen: ['agency-intro', 'agency-attention'] },
  { path: '/agency/portfolio', firstScreen: ['portfolio-intro-kpis', 'portfolio-trend'] },
  /* Named from the live review (LIVE_ROUTE_CHECKLIST.md, 2026-10-09): the KPI row or summary that
     answers each page's question, and the filter block that scopes it. */
  { path: '/agency/campaigns', project: true, firstScreen: ['campaigns-intro-kpis', 'campaigns-secondary-strip'] },
  { path: '/agency/analytics', project: true, firstScreen: ['analytics-filters', 'analytics-overview'] },
  { path: '/agency/content', project: true, firstScreen: ['content-summary', 'content-filters'] },
  { path: '/agency/reports', project: true, firstScreen: ['reports-hero-kpis', 'reports-filters'] },
  { path: '/agency/integrations', firstScreen: ['integrations-intro', 'connection-hub'] },
  { path: '/agency/alerts', firstScreen: [] },
  { path: '/agency/clients', firstScreen: ['clients-intro-kpis', 'clients-filters'] },
  { path: '/agency/requests', firstScreen: ['requests-intro-kpis'] },
  { path: '/agency/tasks', firstScreen: ['tasks-intro-kpis', 'tasks-filters'] },
  { path: '/agency/team', firstScreen: ['team-intro-kpis', 'agency-team'] },
  /* `:projectId` is the seeded project's — the only detail route the sweep can address without
     an entity id of its own. */
  { path: '/agency/projects/:projectId/integrations', projectPath: true, firstScreen: ['project-integrations-intro-kpis'] },
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

/**
 * Spend limits live in the ADVERTISER portal (`/app`), not the agency's — the route registry says so,
 * and a sweep that asked `/agency/spend-limits` measured a page that does not exist for thirty
 * seconds per combination. The advertiser's own session opens it.
 */
test.describe('the first viewport, on the advertiser portal', () => {
  test.use({ storageState: AUTH.advertiser })

  for (const { w, h } of WIDTHS) {
    for (const locale of ['ar', 'en'] as const) {
      for (const theme of ['dark', 'light'] as const) {
        test(`/app/spend-limits · ${w}×${h} · ${locale} · ${theme}`, async ({ page }) => {
          test.setTimeout(90_000)
          await page.addInitScript(([l, t]) => {
            window.localStorage.setItem('campaign-hub-locale', l)
            window.localStorage.setItem('campaign-hub-theme', t)
          }, [locale, theme] as const)
          await page.setViewportSize({ width: w, height: h })
          await page.goto('/app/spend-limits')
          await expect(page.locator('main')).toBeVisible({ timeout: 30_000 })
          await page.waitForLoadState('networkidle')
          await expect.poll(async () => (await measure(page, [])).heading, { timeout: 30_000 }).toBe(true)
          const m = await measure(page, [])
          expect(m.overflow, `/app/spend-limits scrolls sideways at ${w}px (${locale}/${theme}); widest: ${m.widest.join(' | ')}`).toBeLessThanOrEqual(1)
        })
      }
    }
  }
})

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

            let path = surface.path
            if (surface.project || surface.projectPath) {
              const projectId = await seededProject(request, PROJECT)
              if (surface.project) {
                await page.goto('/agency/dashboard')
                await selectProject(page, projectId)
              }
              path = path.replace(':projectId', projectId)
            }
            await page.goto(path)
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
