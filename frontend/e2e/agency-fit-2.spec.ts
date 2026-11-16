import { expect, test, type Page } from '@playwright/test'
import { API_HEADERS, AUTH, seededProject, selectProject } from './helpers'

/*
 * A 403 in the console is a permission refusal the product already answers on the page (the analytics «quality» tab asks
 * an endpoint the owner's role may not read); it is not a layout or script error, so this spec does not count it.
 */
/**
 * AF2 (NOV-FIT-001, second pass) — the operator pages that share an engine with `/app`, fitted to the screen on
 * `/agency` only. Each page below is measured, in the browser, at the seven widths the Owner named:
 *
 *   · no page scrolls sideways at any width;
 *   · from 1366×768 up the page does not scroll vertically and nothing hangs below the content box;
 *   · below that a page may flow and scroll vertically, never sideways, and is never cut off;
 *   · the console stays clean.
 *
 * A page joins `FITTED` when it is converted. `views` are the tabs/modes a page has: each is measured too.
 */
test.use({ storageState: AUTH.owner })

const WIDTHS = [360, 390, 430, 768, 1024, 1366, 1920] as const
const DESKTOPS = [[1366, 768], [1440, 900], [1920, 1080]] as const

/** `tabs`: a TabStrip's test id and how many tabs it has — each is opened and measured. `flow`: tab indexes that are long documents and deliberately unlock the page (never sideways, never cut off). */
interface Fitted { path: string; /** The whole page is a long document that flows (never sideways, never cut off). */ flow?: boolean; views?: string[]; tabs?: { testid?: string; selector?: string; count: number; flow?: number[] } }
const FITTED: Fitted[] = [
  { path: '/agency/campaigns', views: ['', '?view=table', '?view=cards'] },
  { path: '/agency/ad-groups', tabs: { testid: 'entity-tabs-ad_set', count: 3, flow: [2] } },
  { path: '/agency/ads', tabs: { testid: 'entity-tabs-ad', count: 2 } },
  { path: '/agency/recommendations', tabs: { testid: 'recommendations-tabs', count: 2 } },
  { path: '/agency/campaigns/:project/:campaign', tabs: { testid: 'campaign-overview-tabs', count: 3 } },
  { path: '/agency/campaigns/:project/:campaign', views: ['?tab=performance', '?tab=control', '?tab=budget', '?tab=creatives', '?tab=funnel', '?tab=structure', '?tab=notes', '?tab=settings'] },
  { path: '/agency/forms', tabs: { testid: 'forms-tabs', count: 3, flow: [1, 2] } },
  { path: '/agency/agents', tabs: { selector: '[role="tablist"] [role="tab"]', count: 3, flow: [1, 2] } },
  { path: '/agency/billing', tabs: { testid: 'finance-tabs', count: 3 } },
  { path: '/agency/analytics', views: ['', '?tab=platforms', '?tab=campaigns', '?tab=ad_sets', '?tab=creative', '?tab=funnel', '?tab=budget', '?tab=quality', '?tab=store', '?tab=live'] },
  { path: '/agency/reports' },
  { path: '/agency/content' },
  { path: '/agency/short-links' },
  { path: '/agency/subscriptions' },
  { path: '/agency/settings/workspace', views: ['', '?tab=clients', '?tab=projects', '?tab=security'] },
  { path: '/agency/settings/permissions' },
  { path: '/agency/settings/branding' },
  { path: '/agency/account/profile' },
  { path: '/agency/account/password' },
  { path: '/agency/account/security' },
  { path: '/agency/account/preferences' },
  { path: '/agency/account/notifications' },
  { path: '/agency/portfolio' },
  { path: '/agency/billing/quotes' },
  { path: '/agency/billing/invoices' },
  { path: '/agency/billing/payments' },
  { path: '/agency/delivery' },
  { path: '/agency/messages' },
  { path: '/agency/automation', tabs: { selector: '[data-testid^="automation-tab-"]', count: 3 } },
]

async function ready(page: Page, path: string) {
  await page.goto(path)
  await expect(page.locator('main')).toBeVisible({ timeout: 30_000 })
  await page.waitForLoadState('networkidle').catch(() => undefined)
  await page.waitForTimeout(700)
}

async function measure(page: Page) {
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
        /* An inbox / chat pane scrolls inside its own box by nature (`data-fit-scroll`); what is beyond it is not cut off, it is below the pane's own scroll. */
        if (el.closest('[data-fit-scroll]')) continue
        if (r.bottom > box.bottom + 1) cut.push(`${el.dataset.testid ?? el.tagName}:${Math.round(r.bottom - box.bottom)}`)
        if (cut.length > 4) break
      }
    }
    const footer = document.querySelector<HTMLElement>('[data-testid="portal-footer"]')

    return {
      locked: document.querySelectorAll('[data-fit="true"]').length,
      docV: d.scrollHeight - d.clientHeight,
      docH: d.scrollWidth - d.clientWidth,
      contentV: content ? content.scrollHeight - content.clientHeight : 0,
      contentH: content ? content.scrollWidth - content.clientWidth : 0,
      footerVisible: footer ? footer.getBoundingClientRect().bottom <= window.innerHeight + 1 : true,
      cut,
    }
  })
}

async function inProject(page: Page, request: Parameters<typeof seededProject>[0]) {
  await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
}

/** `:project` / `:campaign` in a path are the seeded project's id and its first campaign's. */
async function resolve(request: Parameters<typeof seededProject>[0], path: string) {
  if (!path.includes(':project')) return path
  const project = await seededProject(request, 'متجر تجريبي — Demo')
  const res = await request.get(`/api/v1/projects/${project}/campaigns?per_page=1`, { headers: API_HEADERS })
  const campaign = ((await res.json()) as { data: Array<{ id: string }> }).data[0]!.id

  return path.replace(':project', project).replace(':campaign', campaign)
}

async function openTab(page: Page, tabs: Fitted['tabs'], index: number) {
  if (!tabs) return
  await page.locator(tabs.selector ?? `[data-testid="${tabs.testid}"] [role="tab"]`).nth(index).click()
  await page.waitForTimeout(700)
}

for (const { path, views = [''], tabs, flow: pageFlow } of FITTED) {
  for (const [view, tabIndex] of views.flatMap((v) => (tabs ? Array.from({ length: tabs.count }, (_, i) => [v, i] as const) : [[v, 0] as const]))) {
    const url = path + view
    test.describe(`${url}${tabs ? ` · tab ${tabIndex + 1}` : ''}`, () => {
      for (const [w, h] of DESKTOPS) {
        for (const locale of ['ar', 'en'] as const) {
          test(`fits ${w}×${h} · ${locale}`, async ({ page, request }) => {
            const errors: string[] = []
            page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()) })
            page.on('pageerror', (e) => errors.push(String(e)))
            await page.addInitScript((l) => localStorage.setItem('campaign-hub-locale', l), locale)
            await page.setViewportSize({ width: w, height: h })
            await inProject(page, request)
            await ready(page, await resolve(request, url))
            await openTab(page, tabs, tabIndex)

            const m = await measure(page)
            if (pageFlow || tabs?.flow?.includes(tabIndex)) {
              /* A long document of its own: the page is unlocked and flows — it may scroll, never sideways, never cut off. */
              expect(m.locked, 'a flowing tab must unlock the page').toBe(0)
              expect(m.docH, `document scrolls sideways by ${m.docH}px`).toBeLessThanOrEqual(0)
              expect(m.contentH).toBeLessThanOrEqual(0)
              expect(errors.filter((e) => !/favicon|ResizeObserver|status of 403/.test(e)).map((e) => e.slice(0, 200)), 'console errors').toEqual([])
              return
            }
            expect(m.locked, 'the page did not lock to the screen').toBe(1)
            expect(m.docV, `document scrolls vertically by ${m.docV}px`).toBeLessThanOrEqual(0)
            expect(m.docH, `document scrolls sideways by ${m.docH}px`).toBeLessThanOrEqual(0)
            expect(m.contentV, `content is cut off by ${m.contentV}px; cut: ${m.cut.join(', ')}`).toBeLessThanOrEqual(1)
            expect(m.contentH, `content is wider than its box by ${m.contentH}px`).toBeLessThanOrEqual(0)
            expect(m.cut, `elements hang below the content box (${m.cut.join(', ')})`).toEqual([])
            expect(m.footerVisible, 'the footer is below the fold').toBe(true)
            expect(errors.filter((e) => !/favicon|ResizeObserver|status of 403/.test(e)).map((e) => e.slice(0, 200)), 'console errors').toEqual([])
          })
        }
      }

      for (const w of WIDTHS) {
        test(`never scrolls sideways · ${w}px`, async ({ page, request }) => {
          await page.setViewportSize({ width: w, height: w < 768 ? 800 : 768 })
          await inProject(page, request)
          await ready(page, await resolve(request, url))
          await openTab(page, tabs, tabIndex)
          const m = await measure(page)
          expect(m.docH, `scrolls sideways by ${m.docH}px at ${w}px`).toBeLessThanOrEqual(0)
          expect(m.contentH).toBeLessThanOrEqual(0)
          if (w < 1280) expect(m.contentV, `content cut off at ${w}px`).toBeLessThanOrEqual(1)
        })
      }
    })
  }
}
