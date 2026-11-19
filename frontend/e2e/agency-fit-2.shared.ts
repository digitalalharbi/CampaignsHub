import { expect, test, type Page } from '@playwright/test'
import { seededProject, selectProject } from './helpers'

/**
 * AF2 (NOV-FIT-001, second pass) — the operator pages that share an engine with `/app`, fitted to the screen on
 * `/agency` only. Each page below is measured, in the browser, at the seven widths the Owner named:
 *
 *   · no page scrolls sideways at any width;
 *   · from 1366×768 up the page does not scroll vertically and nothing hangs below the content box;
 *   · below that a page may flow and scroll vertically, never sideways, and is never cut off;
 *   · the console stays clean.
 *
 * A page joins one of the four spec files' `FITTED` lists when it is converted. `views` are the tabs/modes a page has: each is measured too.
 *
 * The pages are split across `agency-fit-2a|b|c|d.spec.ts` (78 tests each): Playwright shards by FILE, so one 312-test file was a
 * 16-minute floor for every PR that touches the frontend. Same assertions, same pages, same widths — only the file boundaries moved.
 */

const WIDTHS = [360, 390, 430, 768, 1024, 1366, 1920] as const
const DESKTOPS = [[1366, 768], [1440, 900], [1920, 1080]] as const

/** `tabs`: a TabStrip's test id and how many tabs it has — each is opened and measured. `flow`: tab indexes that are long documents and deliberately unlock the page (never sideways, never cut off). */
export interface Fitted { path: string; views?: string[]; tabs?: { testid?: string; selector?: string; count: number; flow?: number[] } }

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

async function openTab(page: Page, tabs: Fitted['tabs'], index: number) {
  if (!tabs) return
  await page.locator(tabs.selector ?? `[data-testid="${tabs.testid}"] [role="tab"]`).nth(index).click()
  await page.waitForTimeout(700)
}

/** Declares the fit tests for these pages. Called once per spec file, inside that file. */
export function registerFit(fitted: Fitted[]) {
  for (const { path, views = [''], tabs } of fitted) {
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
              await ready(page, url)
              await openTab(page, tabs, tabIndex)

              const m = await measure(page)
              if (tabs?.flow?.includes(tabIndex)) {
                /* A long document of its own: the page is unlocked and flows — it may scroll, never sideways, never cut off. */
                expect(m.locked, 'a flowing tab must unlock the page').toBe(0)
                expect(m.docH, `document scrolls sideways by ${m.docH}px`).toBeLessThanOrEqual(0)
                expect(m.contentH).toBeLessThanOrEqual(0)
                expect(errors.filter((e) => !/favicon|ResizeObserver/.test(e)), 'console errors').toEqual([])
                return
              }
              expect(m.locked, 'the page did not lock to the screen').toBe(1)
              // SCROLL-RESTORE-001 — the page flows and may scroll vertically; nothing is cut off and nothing goes sideways.
              expect(m.docH, `document scrolls sideways by ${m.docH}px`).toBeLessThanOrEqual(0)
              expect(m.contentV, `content is cut off by ${m.contentV}px; cut: ${m.cut.join(', ')}`).toBeLessThanOrEqual(1)
              expect(m.contentH, `content is wider than its box by ${m.contentH}px`).toBeLessThanOrEqual(0)
              expect(m.cut, `elements hang below the content box (${m.cut.join(', ')})`).toEqual([])
              expect(errors.filter((e) => !/favicon|ResizeObserver/.test(e)), 'console errors').toEqual([])
            })
          }
        }

        for (const w of WIDTHS) {
          test(`never scrolls sideways · ${w}px`, async ({ page, request }) => {
            await page.setViewportSize({ width: w, height: w < 768 ? 800 : 768 })
            await inProject(page, request)
            await ready(page, url)
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
}
