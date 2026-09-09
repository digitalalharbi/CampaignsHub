import { expect, test, type Locator, type Page } from '@playwright/test'
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

type Column = { head: string; cell: string; drift: number; tabular: boolean; table: number; headAlign: string; cellAlign: string }

/**
 * Every numeric column on the page, with the distance between its header and its first cell.
 *
 * «Numeric» is decided from the rendered TEXT rather than from a class or a column key: what the
 * requirement is about is what the reader sees lined up, and a cell is a number when it reads as one.
 */
async function numericColumns(page: Page): Promise<Column[]> {
  return page.evaluate(() => {
    const out: Array<{ head: string; cell: string; drift: number; tabular: boolean; table: number; headAlign: string; cellAlign: string }> = []

    /*
     * Where the TEXT sits, resolved to a side a reader can see.
     *
     * `start` and `end` are direction-relative: a header reading `end` and its cells reading `right`
     * are the same edge in LTR and opposite edges in RTL. Comparing the raw strings would accuse the
     * product of a defect in one direction and miss a real one in the other, so both are resolved
     * against the element's own direction before they are compared.
     */
    const side = (el: Element) => {
      const s = getComputedStyle(el)
      const rtl = s.direction === 'rtl'
      const a = s.textAlign
      if (a === 'start') return rtl ? 'right' : 'left'
      if (a === 'end') return rtl ? 'left' : 'right'
      return a
    }

    /* A figure, with or without a unit, a sign or a compact suffix. «—» and names are skipped. */
    const looksNumeric = (text: string) => /^[+\-−]?[\d.,]+\s*(%|×|[A-Z]{3}|K|M|B)?$/.test(text)
    const unmeasured = (text: string) => text === '' || text === '—' || text === '–'

    document.querySelectorAll('table').forEach((table, ti) => {
      const heads = [...table.querySelectorAll('thead th')]
      const rows = [...table.querySelectorAll('tbody tr')]
      const row = rows[0]
      if (!row || heads.length === 0) return

      const cells = [...row.children]

      heads.forEach((th, i) => {
        const cell = cells[i]
        if (!cell) return

        const text = (cell as HTMLElement).innerText.trim()
        if (!looksNumeric(text)) return

        /*
         * A column is numeric when its WHOLE column is, not when its first row happens to be.
         *
         * Reading one row made this misfire on the store tab's «الحملة» column: campaign names here
         * read «20Jan 2026- January offers», the first row sometimes sorted to one that matched the
         * figure pattern, and the sweep then demanded tabular numerals of a column of NAMES. It
         * failed about one run in five, in both locales, and it was a false accusation against the
         * product — the exact thing the comment below is about and this file exists to avoid.
         *
         * Intermittent because the first row is not stable: ordering decides which name lands there.
         * Requiring the whole column removes the coincidence rather than reducing its odds.
         */
        const column = rows
          .map((r) => (r.children[i] as HTMLElement | undefined)?.innerText.trim() ?? '')
          .filter((t) => !unmeasured(t))

        if (!column.every(looksNumeric)) return

        const a = th.getBoundingClientRect()
        const b = cell.getBoundingClientRect()

        out.push({
          head: (th as HTMLElement).innerText.trim().slice(0, 24),
          cell: text.slice(0, 16),
          drift: Math.abs((a.left + a.right) / 2 - (b.left + b.right) / 2),
          /*
           * The centre-to-centre distance above CANNOT see this, and that is why it is here.
           *
           * A `th` and the cells beneath it share one table column, so their BOXES share a centre by
           * construction — the drift figure catches a header row whose columns fall in a different
           * order from its body, and reads a flat zero for the defect this row was opened for:
           * a heading pushed to one edge over figures sitting at the other. The ledger already
           * recorded that exact injection «PASSED on all three browsers» and put it down to there
           * being no qualifying surface; the metric simply could not see it.
           */
          headAlign: side(th),
          cellAlign: side(cell),
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
/**
 * The instrument, checked against a fixture — because the defect it had was intermittent.
 *
 * `numericColumns` decided a column was numeric from the FIRST body row alone. On the store tab the
 * campaign names read «20Jan 2026- January offers», the first row sometimes sorted to one that
 * matched the figure pattern, and the sweep then demanded tabular numerals of a column of NAMES. It
 * failed about one run in five, in both locales, and it was a false accusation against the product.
 *
 * A passing sweep cannot prove that fixed: the fault only appeared when the ordering cooperated. So
 * the rule is held here against a table built to trigger it — a name column whose first row reads
 * «2026» beside a real figure column. Deterministic, and it fails if the one-row rule ever returns.
 */
test('the sweep does not mistake a column of names for a column of figures', async ({ page }) => {
  await page.setContent(`
    <table>
      <thead><tr><th>Campaign</th><th>Spend</th></tr></thead>
      <tbody>
        <tr><td>2026</td><td><span style="font-variant-numeric: tabular-nums">1,200</span></td></tr>
        <tr><td>January offers</td><td><span style="font-variant-numeric: tabular-nums">900</span></td></tr>
        <tr><td>Ramadan</td><td><span style="font-variant-numeric: tabular-nums">750</span></td></tr>
      </tbody>
    </table>
  `)

  const columns = await numericColumns(page)

  expect(
    columns.map((c) => c.head),
    'a column of names was read as a column of figures because its first row looked like one',
  ).toEqual(['Spend'])
})

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

      /*
       * «share one alignment» — the half of the acceptance the drift figure never measured.
       */
      const misaligned = columns.filter((c) => c.headAlign !== c.cellAlign)
      expect(
        misaligned,
        `${id} (${locale}): a numeric header is set to one edge and its figures to another — `
          + misaligned.map((c) => `«${c.head}» head ${c.headAlign} vs cell ${c.cellAlign}`).join('; '),
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

/**
 * Wait until an element's box stops changing, then let the caller act on it.
 *
 * Playwright's own stability check happens immediately before the dispatch, which is too late for a
 * toolbar that rewraps when its filter options arrive: the check passes, the row wraps, and the
 * click lands on whatever moved into that space. This holds until two consecutive readings agree.
 *
 * Bounded, and it FAILS rather than proceeding on a page that never settles — a helper that gave up
 * quietly would put us back to clicking into a moving layout and blaming the product for it.
 */
async function stillFor(locator: Locator, timeout = 15000): Promise<void> {
  const started = Date.now()
  let previous = ''

  while (Date.now() - started < timeout) {
    const box = await locator.boundingBox()
    const now = box === null ? 'none' : `${Math.round(box.x)},${Math.round(box.y)},${Math.round(box.width)},${Math.round(box.height)}`

    if (now !== 'none' && now === previous) return

    previous = now
    await locator.page().waitForTimeout(250)
  }

  throw new Error('the control never stopped moving, so a click on it could not be aimed')
}

/*
 * §58 — «audit EVERY analytical table in the whole product», which includes the ones that count money.
 *
 * The list stopped at two surfaces while six more hand-rolled a table of figures, and most of them
 * are tables somebody reads about their own money. They are added rather than exempted, because an
 * exemption is what §58 refuses: a table is not out of scope for being old, and a money column is
 * the last place a heading should sit over the wrong edge.
 *
 * The `/app` ones are here. The agency's own billing tables live under `/agency` — `billingRoutes`
 * says «Paths are absolute under /app» and the router mounts it beside `/agency/team`, which is how
 * the first draft of this list asked for `/app/billing/invoices` and got a page that never rendered.
 * They need the agency identity, so they are swept in their own block below rather than from here.
 */
const HAND_ROLLED = [
  { path: '/app/campaigns', what: 'the campaigns list — row selection and bulk actions' },
  { path: '/app/content', what: 'the content list — selection checkboxes and media cells' },
  { path: '/app/subscriptions', what: 'the subscription list' },
  { path: '/app/subscriptions/invoices', what: 'CampaignsHub’s own invoices to this customer' },
  { path: '/app/files', what: 'the files library' },
] as const

/*
 * ## What this block proves — and what it used to say it could not
 *
 * PROVEN: the document never scrolls sideways to hold one of these tables, at 1440 and at 390, in
 * both writing directions, on all three browsers. That is the half of TABLE-NUMERIC-ALIGNMENT-001
 * the requirement names separately and the half that makes a phone unusable when it breaks.
 *
 * ALSO PROVEN NOW: the alignment half. This comment used to say the opposite — that an injection
 * «proved they cannot catch the defect they are for», that the assertions were «NOT evidence
 * today», and that «the next step is the seed, not the assertion». The diagnosis was wrong. The
 * assertions could not catch that injection because the only alignment measurement here was
 * centre-to-centre between a header's BOX and its cells' BOX, and a `th` shares one table column
 * with the cells beneath it — those centres coincide by construction, whatever the text inside
 * them does. No amount of seeding would ever have made that number move.
 *
 * Comparing the RESOLVED text edge instead found a real defect on this very surface on its first
 * run: the content list drew «الإنفاق» with its header at one edge of the column and its money at
 * the other, in both locales, because the numeric cell carried `dir="ltr"` for its numerals and
 * that flipped the cell's own `start` edge away from the header's.
 *
 * Left here deliberately, because «a comment describing an intention the code has outgrown» is a
 * defect this ledger has now found twice, and the second time it was in the file written to stop
 * the first.
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
          // Name the surface: «element(s) not found» about an unnamed page sends the reader to the
          // wrong file, and this loop walks seven of them.
          await expect(page.locator('main'), `${surface.path} did not render — ${surface.what}`)
            .toBeVisible({ timeout: 30000 })

          /*
           * The content library opens as a GRID of cards, and a grid has no columns to line up. The
           * list view is the table this requirement is about, so the sweep asks for it — and does not
           * fail if the control is absent, because the floor below is what proves the sweep swept.
           */
          const list = page.getByRole('button', { name: /^(قائمة|List)$/ })
          if (await list.count()) {
            /*
             * Clicked once the control has stopped moving — and this is not a timeout in disguise.
             *
             * MEASURED. The sweep failed here roughly two runs in four at 1440, in EITHER writing
             * direction, and a recorder attached to `document` showed why: the only click the page
             * ever received landed on `DIV|ابحث بالاسم` — the SEARCH BOX. The filter options arrive
             * with the data, the platform filter gains a chip, the toolbar rewraps, and the toggle
             * moves down between Playwright's actionability check and the dispatch. The pointer then
             * hits whatever took its place.
             *
             * So the earlier readings were all wrong about the cause: the table was not slow, the
             * state was not lost, and the locale had nothing to do with it. Nothing was ever asked to
             * switch views, and the 45-second wait below was waiting for a click that never landed on
             * the button.
             *
             * Waiting for the box to hold still targets that exact cause and weakens no assertion —
             * the click still has to work, the table still has to arrive, and the floor below still
             * has to be met.
             */
            await stillFor(list.first())
            await list.first().click()

            /*
             * Wait for the TABLE, not for a duration — and let THAT be the failure.
             *
             * Two versions of this were wrong in opposite ways. A flat three seconds lost the race on
             * chromium in English once. Replacing it with a wait whose timeout was swallowed
             * (`.catch(() => undefined)`) was worse: when firefox-in-Arabic lost the race under CI
             * load, the floor below reported «no table was found on any hand-rolled surface» — a
             * sentence about the PRODUCT for what was entirely this file's timing, which is the exact
             * class of misreport this whole spec was written to avoid.
             *
             * The wait now fails on its own terms, with its own message, and gets a budget matched to
             * a loaded CI runner rather than to a warm laptop. At 390 the list has no such control, so
             * nothing here runs and the width-guarded floor is what applies.
             */
            await expect(
              page.locator('table').first(),
              `${surface.path} @${width} (${locale}): the list view was opened and no table arrived`,
            ).toBeVisible({ timeout: 45000 })
          }

          // The rows arrive with their own request after the table's frame does.
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

          /*
           * «share one alignment» — the half of the acceptance the drift figure never measured.
           */
          const misaligned = columns.filter((c) => c.headAlign !== c.cellAlign)
          expect(
            misaligned,
            `${surface.path} @${width} (${locale}) — ${surface.what}: a numeric header is set to one edge and its figures to another — `
              + misaligned.map((c) => `«${c.head}» head ${c.headAlign} vs cell ${c.cellAlign}`).join('; '),
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

/**
 * The agency's own money tables — the half of §58 the `/app` block could not reach.
 *
 * `billingRoutes` says «Paths are absolute under /app» and the router mounts it beside
 * `/agency/team`. The comment is stale, the router is the truth, and a sweep pointed at
 * `/app/billing/invoices` gets a page that never renders — which is how these tables stayed
 * unswept while the ledger described them as `/app` surfaces.
 *
 * ONE surface, and the reason the others are absent is measured rather than assumed. Probed on the
 * gate's own seed: `/agency/billing/invoices` draws one table of four rows — الرقم, الإجمالي,
 * المدفوع, المتبقي, الاستحقاق — while `/agency/billing/payments`, `/agency/finance` and
 * `/agency/clients` render their `main` and no `<table>` at all on this data. Adding them would
 * sweep nothing and report a green tick for it, which is the exact failure the floor below exists
 * to catch. They belong here the day the seed gives them rows.
 */
test.describe('the agency’s own money tables', () => {
  test.use({ storageState: AUTH.owner })

  for (const locale of ['en', 'ar'] as const) {
    test(`the invoice table lines up — ${locale}`, async ({ page }) => {
      test.setTimeout(120_000)

      await page.setViewportSize({ width: 1440, height: 900 })
      await page.addInitScript((l) => {
        try {
          window.localStorage.setItem('ui', JSON.stringify({ state: { locale: l }, version: 0 }))
        } catch {
          // A browser that refuses storage still runs the sweep in whatever direction it defaults to.
        }
      }, locale)

      await page.goto('/agency/billing/invoices')
      await expect(page.locator('main'), '/agency/billing/invoices did not render').toBeVisible({ timeout: 30000 })
      await expect(page.locator('table').first(), 'the invoice table never arrived').toBeVisible({ timeout: 30000 })

      const columns = await numericColumns(page)

      /*
       * The floor. A money table whose figures the sweep cannot see is a sweep that passes by
       * measuring nothing — and this page is the reason the block exists.
       */
      expect(columns.length, 'the invoice table reported no numeric column to measure').toBeGreaterThan(0)

      const drifting = columns.filter((c) => c.drift > TOLERANCE)
      expect(
        drifting,
        `/agency/billing/invoices (${locale}): a numeric cell does not sit under its own heading — `
          + drifting.map((c) => `«${c.head}» ${c.cell} off by ${Math.round(c.drift)}px`).join('; '),
      ).toEqual([])

      const misaligned = columns.filter((c) => c.headAlign !== c.cellAlign)
      expect(
        misaligned,
        `/agency/billing/invoices (${locale}): a numeric header is set to one edge and its figures to another — `
          + misaligned.map((c) => `«${c.head}» head ${c.headAlign} vs cell ${c.cellAlign}`).join('; '),
      ).toEqual([])

      const untabular = columns.filter((c) => !c.tabular)
      expect(
        untabular.map((c) => c.head),
        `/agency/billing/invoices (${locale}): a numeric column is not set in tabular numerals`,
      ).toEqual([])
    })
  }
})
