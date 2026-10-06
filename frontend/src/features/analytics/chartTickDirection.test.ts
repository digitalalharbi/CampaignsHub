import { describe, expect, it } from 'vitest'

/**
 * CHART-TICK-DIRECTION-001 — an Arabic category label rendered as one glyph.
 *
 * Recharts draws a tick as `<text text-anchor="end" x="120">`, right-aligning the label against the
 * axis — exactly what a horizontal bar chart's category column needs. `text-anchor` resolves in the
 * WRITING direction, and on an Arabic page the chart inherits `direction: rtl`, so «end» becomes the
 * left-hand end: every label is laid out from its anchor OUTWARD INTO the plot area and clipped.
 *
 * Measured on `/app/leads` in Arabic: «جديد» occupied 23px starting at the axis line and running
 * under the bars, one glyph visible. It had already cost the product a chart — `PlatformResultsBars`
 * in the live report is hand-rolled markup whose docblock says «under `dir="rtl"` that chart dropped
 * its category labels», a conclusion that blamed recharts for one inherited CSS property.
 *
 * ## Why this is a source guard
 *
 * There is nothing to render against. `ResponsiveContainer` measures its parent, jsdom reports every
 * element as 0×0, and recharts then draws no axis at all — so a render test would assert on a tick
 * that does not exist. Vitest does not process CSS either, so a stylesheet rule could not be read
 * back as a string. What CAN be checked here is the rule the browser then applies: every chart in
 * this layer that draws a cartesian axis carries it.
 */
const TREE: Record<string, string> = import.meta.glob('/src/features/analytics/charts.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
})

const SOURCE = TREE['/src/features/analytics/charts.tsx'] ?? ''

/** Each exported chart component, as its own slice of the file. */
function components(): Array<{ name: string; body: string }> {
  const parts = SOURCE.split(/\nexport function /).slice(1)

  return parts.map((part) => ({ name: part.slice(0, part.indexOf('(')), body: part }))
}

describe('every cartesian chart anchors its ticks the way recharts lays them out', () => {
  it('finds the charts it is guarding, so a rename cannot empty this test', () => {
    const names = components().map((c) => c.name)

    expect(names).toContain('RankingBarChart')
    expect(names).toContain('MetricLineChart')
    expect(names).toContain('SpendEfficiencyScatter')
  })

  it('a chart that draws an axis also pins its tick text', () => {
    const offenders = components()
      .filter((c) => /<XAxis|<YAxis/.test(c.body))
      .filter((c) => !c.body.includes('TICK_LTR'))
      .map((c) => c.name)

    expect(offenders).toEqual([])
  })

  it('pins the TICK, never the surface or the wrapper', () => {
    // A blanket rule would fix the same labels and silently take the legend with it — and the legend
    // is HTML whose reading order should keep following the page.
    expect(SOURCE).toMatch(/TICK_LTR = '\[&_\.recharts-cartesian-axis-tick_text\]/)
    expect(SOURCE).not.toMatch(/recharts-(surface|wrapper)\][^']*\[direction/)
  })

  it('applies in both languages, because the anchor is the same in both', () => {
    const declaration = SOURCE.match(/const TICK_LTR = '[^']*'/)![0]

    // Behind `rtl:` the LTR page would depend on an inherited default that nothing states, and the
    // two languages would then be laid out by different mechanisms.
    expect(declaration).not.toMatch(/rtl:|ltr:/)
  })
})
