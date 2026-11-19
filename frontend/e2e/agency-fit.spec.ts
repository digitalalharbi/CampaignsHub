import { expect, test, type Page } from '@playwright/test'
import { API_HEADERS, AUTH, seededProject, selectProject } from './helpers'
import { removeLimits, seedBudgets, seedLimits, voidBudgets, type SeededBudget } from './agencyFitSeed'

/**
 * AGENCY-FIT-001 — the pages that were left scrolling now fit the screen.
 *
 * Desktop (1366×768 and larger): no vertical and no horizontal scroll, and nothing cut off by the lock.
 * Phone and tablet: a vertical scroll is allowed, a horizontal one never is. No console errors either way.
 * Measured in the browser, in both languages at the desktop sizes and in Arabic at the seven widths.
 */
test.use({ storageState: AUTH.owner })

const WIDTHS = [360, 390, 430, 768, 1024, 1366, 1920] as const
const DESKTOP = [[1366, 768], [1440, 900], [1920, 1080]] as const

/**
 * `project` — the page is the SELECTED project's space, so it is opened with the seeded project chosen (without one it is
 * a chooser, which has its own row). The estate is made fuller than the demo seed on purpose: a page that has to PAGE
 * proves nothing against a seed that fits on any screen.
 */
const PAGES: Array<{ name: string; path: string; project?: boolean }> = [
  { name: 'home', path: '/agency/home' },
  { name: 'budgets', path: '/agency/budgets' },
  { name: 'spend limits · chooser', path: '/agency/spend-limits' },
  { name: 'spend limits · limits', path: '/agency/spend-limits', project: true },
  { name: 'spend limits · readings', path: '/agency/spend-limits?tab=readings', project: true },
  { name: 'integrations · all clients', path: '/agency/integrations?view=all' },
  { name: 'integrations · chooser', path: '/agency/integrations' },
  { name: 'integrations · sources', path: '/agency/integrations', project: true },
  { name: 'integrations · platforms', path: '/agency/integrations?section=platforms', project: true },
  { name: 'integrations · audit', path: '/agency/integrations?section=audit', project: true },
  { name: 'client', path: '/agency/clients/:client' },
]

let seeded: SeededBudget[] = []
let limitIds: string[] = []
let projectId = ''

test.beforeAll(async ({ request }) => {
  projectId = await seededProject(request, 'متجر تجريبي — Demo')
  seeded = await seedBudgets(request, 12)
  limitIds = await seedLimits(request, projectId, 8)
})

test.afterAll(async ({ request }) => {
  await removeLimits(request, projectId, limitIds)
  await voidBudgets(request, seeded)
})

async function overflow(page: Page) {
  return page.evaluate(() => {
    const d = document.documentElement
    const content = document.querySelector<HTMLElement>('.portal-content')
    const cut: string[] = []
    if (content) {
      const box = content.getBoundingClientRect()
      for (const el of content.querySelectorAll<HTMLElement>('*')) {
        const r = el.getBoundingClientRect()
        const style = getComputedStyle(el)
        if (r.height === 0 || style.position === 'fixed' || style.position === 'absolute' || style.display === 'none') continue
        if (el.closest('[data-fit-scroll]')) continue
        if (r.bottom > box.bottom + 1) cut.push(`${el.dataset.testid ?? el.tagName}:${Math.round(r.bottom - box.bottom)}`)
        if (cut.length > 4) break
      }
    }

    // A region that is allowed to scroll (an expanded row's panel) must not need to at rest.
    const restless = [...document.querySelectorAll<HTMLElement>('[data-fit-scroll]')].filter((e) => e.scrollHeight > e.clientHeight + 1).map((e) => e.dataset.testid ?? 'region')

    return {
      restless,
      docV: d.scrollHeight - d.clientHeight,
      docH: d.scrollWidth - d.clientWidth,
      contentV: content ? content.scrollHeight - content.clientHeight : 0,
      contentH: content ? content.scrollWidth - content.clientWidth : 0,
      cut,
    }
  })
}

async function open(page: Page, path: string, project = false) {
  if (project) await selectProject(page, projectId)
  let target = path
  if (path.includes(':client')) {
    const list = await page.request.get('/api/v1/app/clients', { headers: API_HEADERS })
    target = path.replace(':client', (await list.json()).data[0].id as string)
  }
  await page.goto(target)
  await expect(page.locator('main')).toBeVisible({ timeout: 30_000 })
  await page.waitForLoadState('networkidle').catch(() => undefined)
  await page.waitForTimeout(700)
}

function watchConsole(page: Page) {
  const errors: string[] = []
  page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`))
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource|favicon|net::ERR/i.test(m.text())) errors.push(m.text()) })

  return errors
}

test.describe('desktop: nothing cut off, never sideways (SCROLL-RESTORE-001)', () => {
  for (const p of PAGES) {
    for (const [w, h] of DESKTOP) {
      for (const locale of ['ar', 'en'] as const) {
        test(`${p.name} · ${w}×${h} · ${locale}`, async ({ page }) => {
          const errors = watchConsole(page)
          await page.addInitScript((l) => localStorage.setItem('campaign-hub-locale', l), locale)
          await page.setViewportSize({ width: w, height: h })
          await open(page, p.path, p.project)

          const m = await overflow(page)
          // SCROLL-RESTORE-001 — a page may scroll vertically (nothing is clipped to fit); sideways and cut-off never.
          expect(m.docH, `document scrolls sideways by ${m.docH}px`).toBeLessThanOrEqual(0)
          expect(m.contentV, `content is cut off by ${m.contentV}px; cut: ${m.cut.join(', ')}`).toBeLessThanOrEqual(1)
          expect(m.contentH, `content is wider than its box by ${m.contentH}px`).toBeLessThanOrEqual(0)
          expect(m.cut, 'elements hang below the content box').toEqual([])
          expect(m.restless, 'a scroll region that scrolls at rest').toEqual([])
          expect(errors, 'console errors').toEqual([])
        })
      }
    }
  }
})

test.describe('every width: never sideways, and on a phone only vertically', () => {
  for (const p of PAGES) {
    for (const w of WIDTHS) {
      test(`${p.name} · ${w}px`, async ({ page }) => {
        const errors = watchConsole(page)
        await page.setViewportSize({ width: w, height: w < 768 ? 800 : 768 })
        await open(page, p.path, p.project)

        const m = await overflow(page)
        expect(m.docH, `scrolls sideways by ${m.docH}px at ${w}px`).toBeLessThanOrEqual(0)
        expect(m.contentH, `content wider than its box by ${m.contentH}px`).toBeLessThanOrEqual(0)
        expect(errors, 'console errors').toEqual([])
      })
    }
  }
})
