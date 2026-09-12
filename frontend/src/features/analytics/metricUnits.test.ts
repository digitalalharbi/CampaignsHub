import { describe, expect, it } from 'vitest'

/**
 * METRIC-UNITS-001 — a cost is money, a return is a multiple, a rate is a percentage.
 *
 * ## What production printed
 *
 * «CPM 65.65%» and «CPC 125.38%» in the panel a reader opens from the Analytics content table. Both
 * are amounts of money — a cost per thousand impressions and a cost per click — run through a reader
 * that multiplies by a hundred and appends a percent sign. A click that cost 1.2538 USD read as
 * «125.38%», beside a table row that said «1.25 USD» about the same click.
 *
 * ## The catalogue was right the whole time
 *
 * `metricCatalog` gives `cpc`, `cpm` and `cpa` the money formatter and `roas` the multiple. Nothing
 * that draws THROUGH the catalogue can get this wrong. The defect was a surface formatting a figure
 * by hand — and the comment above it argued, from a true premise, for the wrong reader.
 *
 * So this does not re-check the catalogue. It looks for the shape that bypasses it: a percentage
 * reader with a money metric's name inside it.
 */
const SOURCES = import.meta.glob('/src/**/*.{ts,tsx}', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

/** Metrics whose unit is money or a multiple — never a percentage. */
const NOT_A_RATE = ['cpc', 'cpm', 'cpa', 'roas', 'aov', 'cost_per_view', 'cost_per_lpv', 'cost_per_result']

/** The readers that end in «%». `pct`, `percent`, `rateOrDash`, and a hand-rolled `* 100`. */
const PERCENT_READER = /\b(percent|pct2?|rateOrDash|asPercent)\s*\(([^()]{0,120})\)/g

describe('a money metric is never printed as a percentage', () => {
  const offenders: string[] = []

  for (const [path, raw] of Object.entries(SOURCES)) {
    if (path.includes('.test.') || path.includes('metricCatalog')) continue

    const code = raw.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '')

    for (const call of code.matchAll(PERCENT_READER)) {
      const argument = call[2]

      for (const key of NOT_A_RATE) {
        /* The metric's own name, as a property or a key — not a substring of a longer word. */
        if (new RegExp(`\\b${key}\\b`).test(argument)) {
          offenders.push(`${path}: ${call[0].slice(0, 70)}`)
          break
        }
      }
    }
  }

  it('read the source it claims to guard', () => {
    expect(Object.keys(SOURCES).length).toBeGreaterThan(100)
  })

  it('has no cost or return read by a percentage formatter', () => {
    expect(
      offenders,
      'A money figure is being formatted as a percentage. Costs go through the money reader\n'
      + '(`rowCostPer`), a return through `rowRoas`, and only true rates through `percent`:\n  '
      + offenders.join('\n  '),
    ).toEqual([])
  })
})
