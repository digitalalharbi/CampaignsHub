import { expect, test, type APIRequestContext, type Page } from '@playwright/test'
import { API_HEADERS, AUTH, seededProject, selectProject } from './helpers'

/**
 * Owner directive 2026-10-09 §5–§7 — the first viewport on the two DETAIL pages the live review
 * changed: the campaign detail (related-entities index moved below the tab panels) and the creative
 * detail (stage sized by its state, objective figures above the identity block).
 *
 * `first-viewport-sweep.spec.ts` measures the list surfaces; a detail page needs an entity id, so it
 * is resolved here from the seeded project's own API before the page is opened. Same two rules: no
 * page-level horizontal scroll at any width, and at ≥1366 the heading and the page's answering
 * block sit in the first viewport.
 */
const PROJECT = 'متجر تجريبي — Demo'

const WIDTHS = [
  { w: 1366, h: 768 },
  { w: 1440, h: 900 },
  { w: 768, h: 1024 },
  { w: 390, h: 844 },
] as const

type Resolve = (request: APIRequestContext, projectId: string) => Promise<string>

const firstCampaign: Resolve = async (request, projectId) => {
  const res = await request.get(`/api/v1/projects/${projectId}/campaigns`, { headers: API_HEADERS })
  const rows = ((await res.json()).data ?? []) as Array<{ id: string }>
  if (!rows.length) throw new Error('seeded project has no campaigns — run the demo seeder')
  return `/agency/campaigns/${projectId}/${rows[0].id}`
}

const firstCreative: Resolve = async (request, projectId) => {
  const res = await request.get(`/api/v1/projects/${projectId}/creatives?per_page=5`, { headers: API_HEADERS })
  const rows = ((await res.json()).data?.creatives ?? []) as Array<{ id: string }>
  if (!rows.length) throw new Error('seeded project has no creatives — run the demo seeder')
  return `/agency/content/${rows[0].id}`
}

/** Route family → how its first instance is found, and the blocks that must share the first screen. */
const DETAILS: Array<{ name: string; resolve: Resolve; firstScreen: string[] }> = [
  { name: '/agency/campaigns/:projectId/:campaignId', resolve: firstCampaign, firstScreen: ['campaign-detail-back'] },
  { name: '/agency/content/:creativeId', resolve: firstCreative, firstScreen: ['creative-media-frame'] },
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

test.describe('the first viewport, on the detail pages', () => {
  test.use({ storageState: AUTH.owner })

  for (const detail of DETAILS) {
    for (const { w, h } of WIDTHS) {
      for (const locale of ['ar', 'en'] as const) {
        for (const theme of ['dark', 'light'] as const) {
          test(`${detail.name} · ${w}×${h} · ${locale} · ${theme}`, async ({ page, request }) => {
            test.setTimeout(90_000)
            await page.addInitScript(([l, t]) => {
              window.localStorage.setItem('campaign-hub-locale', l)
              window.localStorage.setItem('campaign-hub-theme', t)
            }, [locale, theme] as const)
            await page.setViewportSize({ width: w, height: h })

            const projectId = await seededProject(request, PROJECT)
            await page.goto('/agency/dashboard')
            await selectProject(page, projectId)
            await page.goto(await detail.resolve(request, projectId))
            await expect(page.locator('main')).toBeVisible({ timeout: 30_000 })
            await expect(page.locator('html')).toHaveAttribute('dir', locale === 'ar' ? 'rtl' : 'ltr')
            await expect(page.locator('html')).toHaveAttribute('data-theme', theme)
            await page.waitForLoadState('networkidle')

            await expect
              .poll(async () => (await measure(page, detail.firstScreen)).heading, { timeout: 30_000 })
              .toBe(true)
            const m = await measure(page, detail.firstScreen)

            expect(m.overflow, `${detail.name} scrolls sideways at ${w}px (${locale}/${theme}); widest: ${m.widest.join(' | ')}`).toBeLessThanOrEqual(1)
            if (w >= 1366) {
              expect(m.missing, `${detail.name} @${w} (${locale}/${theme}): first-screen blocks not in the first viewport`).toEqual([])
            }
          })
        }
      }
    }
  }
})
