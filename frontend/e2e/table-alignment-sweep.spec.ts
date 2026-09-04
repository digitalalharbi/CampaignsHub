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
          tabular: getComputedStyle(cell).fontVariantNumeric.includes('tabular'),
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
      await expect(page.getByRole('heading', { name: /Analytics|التحليلات/ })).toBeVisible({ timeout: 40000 })

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
