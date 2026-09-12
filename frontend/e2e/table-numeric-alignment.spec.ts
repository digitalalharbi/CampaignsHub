import { expect, test, type Locator, type Page } from '@playwright/test'
import { AUTH, seededProject, selectProject, switchToEnglish } from './helpers'

/**
 * TABLE-NUMERIC-ALIGNMENT-001 §58 — the owner acceptance, measured on screen.
 *
 * ## What the earlier measurement could not see
 *
 * The obvious browser guard compares the header's text with its column's text and requires one
 * edge. It was written, and `CreativesPage` records the result in its own source: «the centre-to-
 * centre sweep read zero throughout, because a `th` and its cells share one column BOX however the
 * text inside them sits». A header and its cells that are BOTH end-aligned measure as perfectly
 * aligned, and they are — against the wrong edge. That is why `metricTableContract` holds the
 * CONVENTION as a source rule.
 *
 * ## What this measures instead, and why it can fail
 *
 * The text against its own COLUMN, not against the other text in it. A centred column puts its
 * header's glyphs and its figures' glyphs near the column's middle; an end-aligned one puts both
 * against one side, and that is a distance this can see. So `text-end` fails here, `text-start`
 * fails here, and the primitive's convention passes — which is the thing no previous measurement
 * of this defect could distinguish.
 *
 * Run in both locales because the whole defect is about which edge `end` resolves to, and at both
 * widths because a phone gives a column barely more room than its digits, where every alignment
 * looks the same and a guard that only ran at 390 would pass anything.
 */

/** Half a column's width is the failure: it means the figures are against a side, not centred. */
const OFF_CENTRE = 0.28

/**
 * The union of the text-node rectangles inside an element — the glyphs, not the box.
 *
 * `selectNodeContents` on the element returns the element's own rectangle, which fills the column
 * whatever the text alignment is: three earlier drafts of the KPI guard passed their injected
 * defect that way. Walking to the text nodes is what makes this about what the owner can see.
 */
async function textRun(locator: Locator): Promise<{ centre: number; width: number } | null> {
  return locator.evaluate((node) => {
    const doc = node.ownerDocument
    const walker = doc.createTreeWalker(node, NodeFilter.SHOW_TEXT)
    let left = Infinity
    let right = -Infinity

    for (let t = walker.nextNode(); t !== null; t = walker.nextNode()) {
      if ((t.textContent ?? '').trim() === '') continue

      const range = doc.createRange()
      range.selectNodeContents(t)
      const r = range.getBoundingClientRect()

      if (r.width === 0 && r.height === 0) continue

      left = Math.min(left, r.left)
      right = Math.max(right, r.right)
    }

    if (right < left) return null

    return { centre: (left + right) / 2, width: right - left }
  })
}

/**
 * Every numeric column of one table, judged against its own column box.
 *
 * A column is numeric when its cells carry the product's own numeral marker — `tabular-nums`, which
 * is what `tnum` compiles to. Guessing from the text would make this a test about number formats.
 */
async function assertNumericColumnsAreCentred(page: Page, table: Locator, where: string) {
  const headers = table.locator('thead th')
  const count = await headers.count()

  expect(count, `${where}: the table drew no header row`).toBeGreaterThan(1)

  let judged = 0

  for (let i = 0; i < count; i++) {
    const cells = table.locator(`tbody tr td:nth-child(${i + 1})`)

    if (await cells.count() === 0) continue

    const first = cells.first()
    const numeric = await first.evaluate((td) => {
      const has = (el: Element) => el.className.toString().includes('tabular-nums')

      return has(td) || Array.from(td.querySelectorAll('*')).some(has)
    })

    if (!numeric) continue

    const box = await headers.nth(i).boundingBox()
    const head = await textRun(headers.nth(i))
    const cell = await textRun(first)

    if (box === null || box.width === 0 || head === null || cell === null) continue

    judged++

    const columnCentre = box.x + box.width / 2

    for (const [what, run] of [['header', head], ['figure', cell]] as const) {
      const drift = Math.abs(run.centre - columnCentre) / box.width

      expect(
        drift,
        `${where}: the ${what} of a numeric column sits ${Math.round(drift * 100)}% of the column's `
        + 'width from its centre — a numeric column is centred, header and digits together '
        + '(MetricTable), because end-aligning puts the figure against the wrong edge under RTL',
      ).toBeLessThan(OFF_CENTRE)
    }
  }

  expect(judged, `${where}: no numeric column was found, so this case proved nothing`).toBeGreaterThan(0)
}

test.describe('a numeric column is centred on screen', () => {
  test.use({ storageState: AUTH.owner })

  for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
    for (const locale of ['ar', 'en'] as const) {
      test(`the content library list at ${viewport.width}px in ${locale}`, async ({ page, request }) => {
        await page.setViewportSize(viewport)
        await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))

        await page.goto('/agency/content?view=list')

        /* After the navigation: the locale switch reads `localStorage`, which `about:blank` refuses. */
        if (locale === 'en') await switchToEnglish(page)

        const table = page.locator('table').first()
        await expect(table).toBeVisible({ timeout: 30000 })
        await expect(page.locator('html')).toHaveAttribute('dir', locale === 'ar' ? 'rtl' : 'ltr')

        await assertNumericColumnsAreCentred(page, table, `content library ${viewport.width} ${locale}`)
      })
    }
  }
})
