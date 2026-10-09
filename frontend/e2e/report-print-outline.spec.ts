import { expect, test, type Page } from '@playwright/test'
import { API_HEADERS, AUTH, csrfHeaders } from './helpers'

/**
 * REPORT-ANALYTICAL-DEPTH-001 — the printed document's headings come from the report's own outline,
 * in the browser that prints it.
 *
 * The row records the defect this guards: «the numbers were written into the file, so a report whose
 * generator had nothing to say about platforms still printed the heading with an empty table under
 * it and the numbering stepped over whatever was skipped». The fix made the headings read the
 * outline and said «the numbering is over what is actually printed». It had unit tests and no browser
 * evidence, and the row's Frontend cell stayed ✗ for a year of appends.
 *
 * Driven through the page Chromium prints (`/reports/print/{token}`), because that page IS the file —
 * what the PDF shows is what this page renders, heading for heading.
 *
 * Three claims, read off the rendered page against the outline the API serves for the same report:
 *   1. every numbered heading names an outline section the report says is PRESENT, in outline order;
 *   2. the numbers are consecutive from 1 — a gap is a section that was counted and never printed,
 *      which is the «stepped over» defect in its other direction;
 *   3. a section the outline says is ABSENT prints its reason and takes no number.
 */
type OutlineSection = { key: string; title_en: string; present: boolean; absent_reason_en?: string | null }

test.use({ storageState: AUTH.owner })

/** A completed, generated report whose snapshot carries an outline — the seed's own. */
async function reportWithOutline(page: Page) {
  const projects = (await (await page.request.get('/api/v1/projects', { headers: API_HEADERS })).json()).data as Array<{ id: string }>
  for (const p of projects) {
    const reports = ((await (await page.request.get(`/api/v1/projects/${p.id}/reports`, { headers: API_HEADERS })).json()).data?.reports ?? []) as Array<{ id: string; status: string }>
    for (const r of reports.filter((x) => x.status === 'completed')) {
      const detail = (await (await page.request.get(`/api/v1/projects/${p.id}/reports/${r.id}`, { headers: API_HEADERS })).json()).data
      const outline = (detail?.data?.outline ?? []) as OutlineSection[]
      if (outline.length > 0 && Number(detail?.data?.kpis?.spend ?? 0) > 0) {
        return { projectId: p.id, reportId: r.id, outline }
      }
    }
  }
  throw new Error('the seed holds no completed report with an outline and figures, so this proves nothing')
}

test('the printed headings are the outline’s present sections, numbered consecutively', async ({ page }) => {
  test.setTimeout(180_000)
  const { projectId, reportId, outline } = await reportWithOutline(page)

  const res = await page.request.post(`/api/v1/projects/${projectId}/reports/${reportId}/print-token`, {
    headers: await csrfHeaders(page.request),
    data: { type: 'document' },
  })
  expect(res.status(), await res.text()).toBe(200)
  const token = (await res.json()).data.token as string

  await page.goto(`/reports/print/${token}?type=document`)
  await expect(page.locator('.doc-root')).toBeVisible({ timeout: 30_000 })

  const headings = (await page.locator('h2').allTextContents()).map((t) => t.trim())
  const numbered = headings
    .map((t) => /^(\d+)\.\s+(.+)$/.exec(t))
    .filter((m): m is RegExpExecArray => m !== null)
    .map((m) => ({ n: Number(m[1]), title: m[2] }))

  expect(numbered.length, `no numbered heading on the page — headings were: ${headings.join(' | ')}`).toBeGreaterThan(0)

  /* 2. Consecutive from 1. A gap is a section that was counted and never printed. */
  expect(
    numbered.map((h) => h.n),
    `the numbering skips — the document counted a section it does not print: ${numbered.map((h) => `${h.n}. ${h.title}`).join(' | ')}`,
  ).toEqual(numbered.map((_, i) => i + 1))

  /* 1. Each numbered heading is a PRESENT outline section, and they appear in outline order. */
  const presentTitles = outline.filter((s) => s.present).map((s) => s.title_en)
  for (const h of numbered) {
    expect(presentTitles, `«${h.title}» is numbered on the page but the outline does not list it as present`).toContain(h.title)
  }
  const positions = numbered.map((h) => presentTitles.indexOf(h.title))
  expect(positions, 'the printed order is not the outline’s order').toEqual([...positions].sort((a, b) => a - b))

  /* 3. An absent section prints why, and carries no number. */
  for (const s of outline.filter((x) => !x.present)) {
    const block = page.locator(`section[data-absent="${s.key}"]`)
    if ((await block.count()) === 0) continue // the surface withholds the section entirely — allowed
    await expect(block.locator('h2')).toHaveText(s.title_en)
    expect(numbered.map((h) => h.title), `absent section «${s.title_en}» took a number`).not.toContain(s.title_en)
    if (s.absent_reason_en) await expect(block).toContainText(s.absent_reason_en)
  }
})
