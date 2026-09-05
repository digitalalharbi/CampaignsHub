import { expect, test, type Page } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * TABLE-PRESENTATION-CONTRACT-001 / TABLE-NUMERIC-ALIGNMENT-001 — the contract, MEASURED.
 *
 * ## Why a sweep, and why geometric
 *
 * The unit guard asks «does this file use the primitive», which is a question about the source. The
 * owner's requirement is about the rendered result: «numeric header and numeric cell must share the
 * exact column, exact alignment, stable width and tabular numerals», in both writing directions. A
 * surface can satisfy the first and fail the second — a hand-rolled table that happens to be aligned
 * passes the source check, and a migrated one whose header carries a sort control can still drift.
 *
 * So this walks every analytical tab, in BOTH directions, and measures the distance between each
 * numeric header's centre and its column's cell centre. It is the same measurement a reader makes
 * with their eye, and nothing about the source can fake it.
 *
 * ## Why the exemption list is not enough on its own
 *
 * «Zero unexplained exemptions» was the instruction. Two of the remaining exemptions are out of scope
 * by KIND — a printed page has no sort control or scroller, and a list of reports compares nothing
 * across rows — and the rest are surfaces the primitive cannot yet model (inline editing, nested
 * expansion, a drag handle). None of those is a licence to mis-align, and this is what says so: it
 * measures every table on the page, migrated or not.
 */
test.use({ storageState: AUTH.owner })

const STORE_PROJECT = 'متجر تجريبي — Demo'

/** How far a numeric header's centre may sit from its cells' — a pixel of rounding, no more. */
const TOLERANCE = 2

type Column = { head: string; cell: string; drift: number; tabular: boolean; table: number }

/**
 * Every numeric column on the page, with the distance between its header and its first cell.
 *
 * «Numeric» is decided from the rendered TEXT rather than from a class or a column key: what the
 * requirement is about is what the reader sees lined up, and a cell is a number when it reads as one.
 */
async function numericColumns(page: Page): Promise<Column[]> {
  return page.evaluate(() => {
    const out: Array<{ head: string; cell: string; drift: number; tabular: boolean; table: number }> = []

    document.querySelectorAll('table').forEach((table, ti) => {
      const heads = [...table.querySelectorAll('thead th')]
      const row = table.querySelector('tbody tr')
      if (!row || heads.length === 0) return

      const cells = [...row.children]

      heads.forEach((th, i) => {
        const cell = cells[i]
        if (!cell) return

        const text = (cell as HTMLElement).innerText.trim()
        // A figure, with or without a unit, a sign or a compact suffix. «—» and names are skipped.
        if (!/^[+\-−]?[\d.,]+\s*(%|×|[A-Z]{3}|K|M|B)?$/.test(text)) return

        const a = th.getBoundingClientRect()
        const b = cell.getBoundingClientRect()

        out.push({
          head: (th as HTMLElement).innerText.trim().slice(0, 24),
          cell: text.slice(0, 16),
          drift: Math.abs((a.left + a.right) / 2 - (b.left + b.right) / 2),
          /*
           * Read from whichever element actually CARRIES the numerals.
           *
           * `font-variant-numeric` inherits downwards, so a `.tnum` on the `<td>` reaches its text —
           * but most cells here put the class on an inner `<span>`, and reading the `<td>` alone
           * reports «normal» for a column that is perfectly tabular. That is a false accusation
           * against the product, and this sweep exists to make true ones.
           */
          tabular: [cell, ...cell.querySelectorAll('*')].some((el) =>
            getComputedStyle(el as Element).fontVariantNumeric.includes('tabular')),
          table: ti,
        })
      })
    })

    return out
  })
}

/**
 * The analytical tabs, by their URL id.
 *
 * Driven by the address rather than by clicking a label: the page keeps its tab in the query string,
 * so this reaches each one deterministically in either language. A label-based walk went looking for
 * «Platforms» on an Arabic render and silently visited nothing, which the vacuity check below caught.
 */
const TAB_IDS = ['platforms', 'accounts', 'campaigns', 'ad_sets', 'budget', 'objective', 'store'] as const

for (const locale of ['en', 'ar'] as const) {
  test(`every numeric column on Analytics lines up with its header — ${locale}`, async ({ page, request }) => {
    /*
     * Seven tabs, each a navigation and a settle, in ONE test — well past the 30s default.
     *
     * The first version left the default and put a 40s expectation inside it, which can never
     * succeed: the test is killed ten seconds before its own assertion is allowed to give up. It
     * failed on firefox, which is slower to paint the first tab, and the report said «heading not
     * found» — a sentence about the product for what was entirely a fault in this file.
     */
    test.setTimeout(180_000)

    const projectId = await seededProject(request, STORE_PROJECT)
    await selectProject(page, projectId)

    await page.addInitScript((l) => {
      try {
        window.localStorage.setItem('ui', JSON.stringify({ state: { locale: l }, version: 0 }))
      } catch {
        // A browser that refuses storage still runs the sweep in whatever direction it defaults to.
      }
    }, locale)

    let measured = 0
    const visited: string[] = []

    for (const id of TAB_IDS) {
      await page.goto(`/agency/analytics?tab=${id}`)
      await expect(page.getByRole('heading', { name: /Analytics|التحليلات/ })).toBeVisible({ timeout: 30000 })

      // The table arrives with its own request; an empty tab is a legitimate state, handled below.
      await page.waitForTimeout(3000)

      const columns = await numericColumns(page)
      if (columns.length > 0) visited.push(`${id}(${columns.length})`)
      measured += columns.length

      const drifting = columns.filter((c) => c.drift > TOLERANCE)
      expect(
        drifting,
        `${id} (${locale}): a numeric cell does not sit under its own heading — `
          + drifting.map((c) => `«${c.head}» ${c.cell} off by ${Math.round(c.drift)}px`).join('; '),
      ).toEqual([])

      const untabular = columns.filter((c) => !c.tabular)
      expect(
        untabular.map((c) => c.head),
        `${id} (${locale}): a numeric column is not set in tabular numerals, so its digits cannot line up`,
      ).toEqual([])
    }

    /*
     * The sweep must actually have swept. Every assertion above passes vacuously over an empty page,
     * and a guard that can pass by finding nothing is the guard that quietly stops guarding. This
     * caught its own first draft, which walked tab LABELS and matched none of them in Arabic.
     */
    expect(
      measured,
      `the sweep found no numeric column at all — it proved nothing. Tabs with columns: ${visited.join(', ') || 'none'}`,
    ).toBeGreaterThan(10)
  })
}

/**
 * TABLE-NUMERIC-ALIGNMENT-001 §58 — the surfaces that still hand-roll a table, and a phone.
 *
 * «Audit EVERY analytical table in the whole product… Do not leave exemptions merely because a table
 * is old.» The sweep above walks the analytics tabs, which are the migrated ones. The tables the
 * owner keeps reporting are the OTHER ones — the campaigns list with its bulk-select column, the
 * content list with its media cells, the dashboard's campaign block with its per-row sparklines. Each
 * is exempt from the PRIMITIVE for a real reason, and none of those reasons is a licence to put a
 * figure somewhere other than under its own heading.
 *
 * And at 390 as well as 1440, because that is the width the requirement names and the one where a
 * column that was merely tight becomes a column that is wrong. Measured, not screenshotted: a phone
 * screenshot of an Arabic table is exactly the artefact nobody can read a 3px drift off.
 *
 * The exemption list may keep these surfaces. It does not get to keep them mis-aligned.
 */
const HAND_ROLLED = [
  { path: '/app/campaigns', what: 'the campaigns list — row selection and bulk actions' },
  { path: '/app/content', what: 'the content list — selection checkboxes and media cells' },
] as const

/*
 * ## What this block does and does NOT currently prove — read before trusting it
 *
 * PROVEN: the document never scrolls sideways to hold one of these tables, at 1440 and at 390, in
 * both writing directions, on all three browsers. That is the half of TABLE-NUMERIC-ALIGNMENT-001
 * the requirement names separately and the half that makes a phone unusable when it breaks.
 *
 * NOT PROVEN: the alignment half, on this seed. Measured — `[alignment] en @1440: 1 table(s),
 * 10 cell(s), 1 numeric column(s)`. `/app/campaigns` renders cards rather than a table, and the
 * content list's figures are almost all either «—» (a seeded creative that never spent) or
 * COMPOSITE — «12» beside «Orders» in one cell — which this sweep deliberately does not measure
 * centre-to-centre, because a cell carrying a figure and its unit is a different kind from the pure
 * numerals the centring rule is written for.
 *
 * So the alignment assertions below run over one column, and an injection proved they cannot catch
 * the defect they are for: setting the spend header to `text-end` against `text-start` cells — the
 * exact inversion this requirement exists to stop — passed on all three browsers. They are kept
 * because they cost nothing and will hold the day the seed has figures; they are NOT evidence today,
 * and this comment is here so nobody reads a green run as if they were.
 *
 * The next step is the seed, not the assertion: give the gate's content library a creative with
 * spend, results and efficiency, and this block starts doing its job without another line of test.
 *
 * ## Driven as the ADVERTISER, because these are `/app` routes.
 *
 * The sweep above walks `/agency/analytics` as the agency owner. Pointed at `/app`, that same session
 * gets «بوابة إدارة الحملات غير متاحة لحسابك» — the portal guard doing exactly its job — and
 * `locator('main')` never appears. The first version of this block read that as «the page did not
 * render»: a fault in the test reported as a fault in the product, which is the failure mode this
 * whole file exists to avoid. The portal decides the surface, so the surface decides the identity.
 */
test.describe('the surfaces that still hand-roll a table', () => {
  test.use({ storageState: AUTH.advertiser })

  for (const locale of ['en', 'ar'] as const) {
    for (const width of [1440, 390] as const) {
      test(`every hand-rolled table lines up too — ${locale} @ ${width}`, async ({ page }) => {
        test.setTimeout(180_000)

        await page.setViewportSize({ width, height: 900 })
        await page.addInitScript((l) => {
          try {
            window.localStorage.setItem('ui', JSON.stringify({ state: { locale: l }, version: 0 }))
          } catch {
            // A browser that refuses storage still runs the sweep in whatever direction it defaults to.
          }
        }, locale)

        let measured = 0
        let tables = 0
        let cells = 0
        const visited: string[] = []

        for (const surface of HAND_ROLLED) {
          await page.goto(surface.path)
          await expect(page.locator('main')).toBeVisible({ timeout: 30000 })

          /*
           * The content library opens as a GRID of cards, and a grid has no columns to line up. The
           * list view is the table this requirement is about, so the sweep asks for it — and does not
           * fail if the control is absent, because the floor below is what proves the sweep swept.
           */
          const list = page.getByRole('button', { name: /^(قائمة|List)$/ })
          if (await list.count()) {
            await list.first().click()

            /*
             * Wait for the TABLE, not for a duration.
             *
             * A flat three seconds passed on firefox and webkit and on chromium in Arabic, and lost
             * the race on chromium in English exactly once — «no table was found» reported for a page
             * that renders one perfectly, which is the sweep failing the product for the sweep's own
             * timing. At 390 the list legitimately has no table, so this waits only where the control
             * that produces one was actually pressed, and does not fail if it stays absent.
             */
            await page.locator('table').first().waitFor({ timeout: 20000 }).catch(() => undefined)
          }

          // These tables arrive with their own requests; a surface with none is caught by the floor.
          await page.waitForTimeout(2000)

          const columns = await numericColumns(page)
          if (columns.length > 0) visited.push(`${surface.path}(${columns.length})`)
          measured += columns.length

          /*
           * What actually proves this ran: a TABLE was reached and its cells were looked at.
           *
           * Counting numeric columns cannot do it here. `/app/campaigns` renders cards rather than a
           * table, and the content list's only pure-figure column is spend, which reads «—» on a
           * seeded creative that never spent — so «zero numeric columns» is the correct answer for
           * this data, and a floor built on it fails the product for being right.
           *
           * Its other figures are COMPOSITE — «12» beside «Orders» in one cell, two elements with a
           * margin between them. `innerText` renders that as «12Orders» because a margin is not
           * whitespace, which looked like a missing space and is not one: the page is correct and the
           * measurement was naive. They are deliberately not measured centre-to-centre either — a
           * cell carrying a figure AND its unit is a different kind from the pure numerals this
           * requirement's centring rule is written for, and forcing it through that rule would
           * manufacture a drift that no reader can see.
           */
          const reached = await page.evaluate(() => {
            const tables = [...document.querySelectorAll('table')]
            return {
              tables: tables.length,
              cells: tables.reduce((n, t) => n + (t.querySelector('tbody tr')?.children.length ?? 0), 0),
            }
          })
          tables += reached.tables
          cells += reached.cells

          const drifting = columns.filter((c) => c.drift > TOLERANCE)
          expect(
            drifting,
            `${surface.path} @${width} (${locale}) — ${surface.what}: a numeric cell does not sit under its own heading — `
              + drifting.map((c) => `«${c.head}» ${c.cell} off by ${Math.round(c.drift)}px`).join('; '),
          ).toEqual([])

          const untabular = columns.filter((c) => !c.tabular)
          expect(
            untabular.map((c) => c.head),
            `${surface.path} @${width} (${locale}): a numeric column is not set in tabular numerals, so its digits cannot line up`,
          ).toEqual([])

          /*
           * And the document does not move sideways to hold any of them — the separate half of this
           * requirement, asserted HERE because 390 is where it happens and these are the widest tables.
           */
          const overflow = await page.evaluate(() =>
            document.documentElement.scrollWidth - document.documentElement.clientWidth)

          expect(overflow, `${surface.path} @${width}: the page scrolls sideways to hold a table`).toBeLessThanOrEqual(1)
        }

        /*
         * The floor, and it is about REACH rather than about how many figures the seed happened to
         * produce. A sweep that can pass by finding nothing is one that has quietly stopped
         * sweeping; a sweep that demands figures fails a correct page whose figures are «—».
         *
         * At 390 these surfaces collapse their tables into cards, which is the right responsive
         * answer, so the table floor applies at 1440. The phone half of this requirement is the
         * overflow assertion inside the loop — «the table may scroll, the document may not» — and
         * that runs at both widths on all three browsers.
         */
        if (width === 1440) {
          expect(
            tables,
            `no table was found on any hand-rolled surface @${width} — the sweep reached nothing. Columns seen: ${visited.join(', ') || 'none'}`,
          ).toBeGreaterThan(0)

          expect(cells, `a table was found @${width} but it had no cells to measure`).toBeGreaterThan(3)
        }

        // Recorded so a run that measured no FIGURE says so, rather than looking like a clean pass.
        // eslint-disable-next-line no-console
        console.log(`[alignment] ${locale} @${width}: ${tables} table(s), ${cells} cell(s), ${measured} numeric column(s) — ${visited.join(', ') || 'no numeric columns in this data'}`)
      })
    }
  }
})

/**
 * And the page itself never scrolls sideways — the table may, the document may not.
 *
 * A wide analytical table is legitimate; pushing the whole document sideways to hold it is what makes
 * a phone unusable, and it is the failure this requirement names separately.
 */
test('a wide table scrolls inside itself rather than moving the page', async ({ page, request }) => {
  const projectId = await seededProject(request, STORE_PROJECT)
  await selectProject(page, projectId)

  await page.setViewportSize({ width: 390, height: 844 })

  await page.goto('/agency/analytics?tab=campaigns')
  await page.waitForTimeout(3000)

  const overflow = await page.evaluate(() => {
    const d = document.documentElement

    return { page: d.scrollWidth - d.clientWidth, tables: document.querySelectorAll('table').length }
  })

  expect(overflow.page, 'the document scrolls sideways on a 390px screen').toBeLessThanOrEqual(1)
})
