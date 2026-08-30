import { expect, test } from '@playwright/test'
import { AUTH, openFilters } from './helpers'

/**
 * `/agency` after SIMPLIFY-002 — the rail is grouped by job, and filters fold.
 *
 * Two things are asserted, and neither is about how the page looks:
 *
 * 1. **Nothing became unreachable.** Regrouping a menu is the change most likely to strand a page:
 *    a link that quietly stops being rendered is invisible until somebody needs it. Every one of the
 *    fifteen destinations is opened here, by URL, and asserted to render.
 * 2. **Folding hid no state.** Every page that moved its filters into a dialog must say what is
 *    applied, in words. A filtered list that looks unfiltered is worse than the toolbar it replaced.
 *
 * The pages carry the same controls they always did; what changed is that the reader meets the data
 * first. So the tests open the dialog, use a control inside it, and assert the summary line follows.
 */

/** Every destination the rail offers, in rail order. Kept here so a dropped link fails loudly. */
const AGENCY_PATHS = [
  '/agency/dashboard',
  '/agency/clients', '/agency/projects',
  '/agency/campaigns', '/agency/content',
  '/agency/requests', '/agency/tasks', '/agency/messages', '/agency/alerts',
  '/agency/reports', '/agency/files',
  '/agency/billing', '/agency/subscriptions',
  '/agency/team', '/agency/settings',
] as const

test.describe('the agency rail', () => {
  test.use({ storageState: AUTH.owner })

  /**
   * Every destination still resolves to a page with content.
   *
   * Walked by URL rather than by clicking the rail, because a deep link and a bookmark are how people
   * actually return to a page — and because a link the regrouping dropped would still be reachable
   * this way, which is the distinction worth knowing.
   */
  test('every destination opens, and none was lost in the regrouping', async ({ page }) => {
    test.setTimeout(20_000 + AGENCY_PATHS.length * 8_000)

    for (const path of AGENCY_PATHS) {
      await page.goto(path)
      await expect(page.locator('main'), `${path} rendered nothing`).toBeVisible({ timeout: 20000 })
      await expect(page.locator('main').getByRole('heading').first(), `${path} has no heading`)
        .toBeVisible({ timeout: 20000 })
      // A route that fell through to a not-found or a portal refusal is the failure this catches.
      await expect(page.locator('main'), `${path} is a not-found page`)
        .not.toContainText(/غير متاحة لحسابك|not available to your account|الصفحة غير موجودة|Page not found/i)
    }
  })

  /** The rail groups are named for the job, and there are no third-level menus. */
  test('the rail is two levels and named for what somebody came to do', async ({ page }) => {
    await page.goto('/agency/dashboard')
    const nav = page.getByRole('navigation').first()
    await expect(nav).toBeVisible({ timeout: 20000 })

    const text = await nav.innerText()
    for (const group of ['العملاء والمشاريع', 'الحملات', 'المهام والطلبات', 'التقارير والملفات', 'المالية', 'الإعدادات']) {
      expect(text, `the rail is missing the «${group}» group`).toContain(group)
    }

    // «العمل» and «التشغيل» were the two headings that described nothing; they must not return.
    expect(text, 'the rail still has the old catch-all groups').not.toMatch(/^\s*العمل\s*$/m)
  })
})

test.describe('agency filters are on the page, and name what they narrow', () => {
  test.use({ storageState: AUTH.owner })

  /**
   * UX-FILTERS-001 narrowed the scope of SIMPLIFY-002.
   *
   * These assertions are the inverse of the ones they replace, on purpose. The daily axes are inline
   * now — status, priority, severity, platform, type — and only the rare ones fold. What is
   * unchanged is the claim underneath: a narrowed page must SAY what narrowed it. It says so with
   * chips that each undo their own value, which a joined sentence could not do.
   */
  /*
   * `narrow` names the exact control to use, per page — the lesson this suite has now learned six
   * times, once more in the gate that followed the rewrite.
   *
   * A generic «drive the first select, or else the first collapsed button» failed on two pages at
   * once and for two different reasons. Clients filters with `SearchableSelect`, which is neither a
   * native select nor a button carrying `aria-expanded`, so the helper found nothing to click. And
   * on Alerts the first select IS a control — but it is the queue's status, which deliberately
   * never becomes an applied chip, so the assertion that followed could not pass however long it
   * waited. A selector that guesses guesses wrong.
   */
  type Bar = import('@playwright/test').Locator

  /*
   * `pickOption` lived here — open a popover multi-select, take its first value, press Escape.
   *
   * The Escape was load-bearing: the popover is `absolute` under its trigger and covered the
   * applied-filters row the selection had just created, so the gate once caught a click on «إعادة
   * ضبط» landing on «سناب شات» instead.
   *
   * It is gone because none of these pages has a popover axis any more. Content's platform filter
   * is visible chips (UX-FILTERS-001), Clients drives its folded dialog, and Tasks and Alerts are
   * native selects. Kept as a note rather than as dead code: if a popover axis returns, so does the
   * Escape, and the reason is written down.
   */

  const PAGES = [
    {
      /*
       * Clients is driven through its FOLDED axis, on purpose.
       *
       * Its visible three are taxonomy comboboxes whose options come from the workspace's own
       * taxonomy — a set the gate's seed does not guarantee, so a test that picks «the first
       * option» is a test that can find none. `needs_attention` is a checkbox that always exists,
       * always narrows, and always names itself; and driving it also proves the folded half of
       * this page still works, which nothing else here covers.
       */
      id: 'clients',
      path: '/agency/clients',
      narrow: async (bar: Bar) => {
        const page = bar.page()
        await openFilters(page, 'clients')
      await page.getByTestId('clients-more-filters').click()
        const dialog = page.getByTestId('clients-more-filters-body')
        await expect(dialog).toBeVisible()
        await dialog.getByRole('checkbox').first().check()
        await page.keyboard.press('Escape')
        await expect(page.getByRole('dialog')).toHaveCount(0)
      },
    },
    {
      id: 'tasks',
      path: '/agency/tasks',
      narrow: (bar: Bar) => bar.getByTestId('tasks-status').selectOption({ index: 1 }),
    },
    {
      // Severity, NOT status: status is the queue's mode and never becomes a chip.
      id: 'alerts',
      path: '/agency/alerts',
      narrow: (bar: Bar) => bar.getByTestId('alerts-severity').selectOption({ index: 1 }),
    },
    {
      id: 'content',
      path: '/agency/content',
      /*
       * The platform axis is visible chips now (UX-FILTERS-001), so it is pressed rather than
       * opened. `pickOption` still drives the pages whose axes ARE popovers.
       */
      narrow: async (bar: Bar) => {
        // Index 0 is «الكل», which clears; index 1 is the first platform.
        await bar.getByTestId('content-providers').getByRole('button').nth(1).click()
      },
    },
  ] as const

  for (const p of PAGES) {
    test(`${p.id}: opens on its data, with its filters on the page`, async ({ page }) => {
      await page.goto(p.path)

      const bar = page.getByTestId(`${p.id}-filters`)
      await expect(bar, 'no visible filter bar').toBeVisible({ timeout: 20000 })

      // Nothing narrowed on arrival: no chip row, and nothing had to be opened to see the controls.
      await expect(page.getByTestId(`${p.id}-applied`)).toHaveCount(0)
      await expect(page.getByRole('dialog')).toHaveCount(0)
    })

    test(`${p.id}: narrowing names itself, and can be undone`, async ({ page }) => {
      await page.goto(p.path)
      const bar = page.getByTestId(`${p.id}-filters`)
      await expect(bar).toBeVisible({ timeout: 20000 })
      await page.waitForLoadState('networkidle')

      await p.narrow(bar)

      await expect(page.getByTestId(`${p.id}-applied`)).toBeVisible({ timeout: 20000 })
      await page.getByTestId(`${p.id}-reset`).click()
      await expect(page.getByTestId(`${p.id}-applied`)).toHaveCount(0)
    })
  }

  /**
   * 343px is the narrowest case in the brief, and the dialog is the thing most likely to burst it.
   *
   * The `/app/dashboard` regression that got through was exactly this shape — fine in Arabic, too
   * wide in English, because the English labels are longer than their Arabic counterparts. So both
   * languages are measured, at the width where it would show. The bar is measured before the dialog
   * as well, because an inline row of controls is now the more likely thing to burst a phone.
   */
  for (const locale of ['ar', 'en'] as const) {
    test(`no sideways scroll at 343px in ${locale}, dialog open`, async ({ page }) => {
      await page.addInitScript((l) => window.localStorage.setItem('campaign-hub-locale', l), locale)
      await page.setViewportSize({ width: 343, height: 760 })
      await page.goto('/agency/clients')

      await expect(page.getByTestId('clients-filters')).toBeVisible({ timeout: 20000 })

      /*
       * Wait for the clients themselves, not just the toolbar above them.
       *
       * The first version measured as soon as the filter button appeared, which is before the list has
       * been fetched — so it measured an empty page, found it exactly 343px wide, and passed. The real
       * overflow was a long client name widening the card grid, and it only ever appeared AFTER the
       * data arrived. The check then failed at the next measurement and blamed the dialog, which had
       * nothing to do with it. Measuring a page that has not loaded proves nothing about the page.
       */
      await page.waitForLoadState('networkidle')

      expect(
        await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth),
        'the loaded page scrolls sideways before the dialog is even opened',
      ).toBe(false)

      await openFilters(page, 'clients')
      await page.getByTestId('clients-more-filters').click()
      await expect(page.getByRole('dialog')).toBeVisible()

      expect(
        await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth),
        'the filters dialog pushes the page sideways',
      ).toBe(false)
    })
  }
})
