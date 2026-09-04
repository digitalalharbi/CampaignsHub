import { describe, expect, it } from 'vitest'
import { SPECS, dashboardMetrics, layoutFor, readMetric } from './metricCatalog'

/**
 * METRIC-NAMES-001 — the metrics on screen belong to the campaign's objective, and are named in
 * words the person paying for the campaign already uses.
 *
 * Two rules, and the second is the one that protects a client.
 *
 * **Plain names.** «الطلبات» rather than «المشتريات», «قيمة الطلبات» rather than «الإيرادات»,
 * «تكلفة الطلب» rather than «تكلفة النتيجة». A dashboard that assumes its reader is a media buyer is
 * a dashboard only media buyers can use.
 *
 * **Nothing borrowed from another objective.** A brand campaign shown baskets, orders or a return is
 * being judged on money it never tried to make — and the figure is not merely irrelevant, it is
 * terrible by construction, which is how a client comes to believe a working campaign has failed.
 */

const KEYS = (objective: string) => [...layoutFor(objective).primary, ...layoutFor(objective).secondary]

describe('metric vocabulary', () => {
  it('names the sales figures the way a merchant does', () => {
    expect(SPECS.purchases.label.ar).toBe('الطلبات')
    expect(SPECS.revenue.label.ar).toBe('قيمة الطلبات')
    expect(SPECS.add_to_cart.label.ar).toBe('الإضافة للسلة')
    expect(SPECS.roas.label.ar).toBe('العائد على الإنفاق')
  })

  it('spells out the acronyms a client would have to look up', () => {
    for (const key of ['cpm', 'cpc', 'ctr', 'cpl', 'cpi', 'cpe']) {
      expect(SPECS[key].label.ar).not.toMatch(/^(CPM|CPC|CTR|CPL|CPI|CPE)$/)
    }
  })

  /**
   * Every objective names the cost of its own result — by a label override where it shares the
   * `cpa` column, and by having its own metric where it does not.
   */
  it('names the cost-per by what it actually costs', () => {
    const labelOf = (objective: string, key: string) =>
      dashboardMetrics(objective, undefined, true).primary.find((m) => m.key === key)?.label

    // Sales shares `cpa` with the generic conversion path, so it needs the override.
    expect(labelOf('sales', 'cpa')).toBe('تكلفة الطلب')
    // These two have their own metrics, and the names live on the metric itself.
    expect(labelOf('leads', 'cpl')).toBe('تكلفة العميل المحتمل')
    expect(labelOf('app_installs', 'cpi')).toBe('تكلفة التحميل')
    // The conversion PATH spans leads and sales, so it must stay generic rather than pick one.
    expect(SPECS.cpa.label.ar).toBe('تكلفة النتيجة')
  })
})

describe('objective-aware layouts', () => {
  it('gives an awareness campaign attention metrics and no sales metrics at all', () => {
    const keys = KEYS('awareness')

    expect(layoutFor('awareness').primary).toEqual(['reach', 'impressions', 'frequency', 'cpm'])
    for (const forbidden of ['purchases', 'add_to_cart', 'revenue', 'roas', 'cpa', 'aov']) {
      expect(keys).not.toContain(forbidden)
    }
  })

  it('gives a traffic campaign the visit metrics and no sales metrics', () => {
    expect(layoutFor('traffic').primary).toEqual(['clicks', 'ctr', 'cpc', 'landing_page_views'])
    for (const forbidden of ['purchases', 'add_to_cart', 'revenue', 'roas']) {
      expect(KEYS('traffic')).not.toContain(forbidden)
    }
  })

  it('gives a leads campaign its own count and cost, never a return', () => {
    expect(layoutFor('leads').primary).toContain('leads')
    expect(layoutFor('leads').primary).toContain('cpl')
    expect(KEYS('leads')).not.toContain('roas')
    expect(KEYS('leads')).not.toContain('revenue')
  })

  it('gives a sales campaign the basket, the order, its value and the return', () => {
    const keys = KEYS('sales')

    for (const expected of ['add_to_cart', 'purchases', 'revenue', 'cpa', 'roas', 'conversion_rate']) {
      expect(keys).toContain(expected)
    }
  })

  it('gives an app campaign installs and their cost', () => {
    expect(layoutFor('app_installs').primary).toContain('installs')
    expect(layoutFor('app_installs').primary).toContain('cpi')
    expect(KEYS('app_installs')).not.toContain('roas')
  })

  /**
   * Several objectives at once: operational figures only.
   *
   * A cost per result across a brand budget and a sales budget divides one objective's money by
   * another objective's events — arithmetic that works and means nothing.
   */
  it('gives a mixed scope no cost-per and no return', () => {
    const keys = KEYS('all')

    for (const forbidden of ['cpa', 'roas', 'revenue', 'purchases']) {
      expect(keys).not.toContain(forbidden)
    }
  })

  /** Every key a layout names must exist, or a card silently disappears. */
  it('names no metric the catalogue does not define', () => {
    for (const objective of ['awareness', 'traffic', 'leads', 'sales', 'app_installs', 'video', 'engagement', 'all']) {
      for (const key of KEYS(objective)) {
        expect(SPECS[key], `${objective} names an unknown metric: ${key}`).toBeDefined()
      }
    }
  })
})

/**
 * HEADLINE-SCOPE-001 — a single-objective scope is headlined by that objective.
 *
 * The families are the backend's `ObjectiveFamily` cases, listed here in full so that a family added
 * to the enum without a layout fails HERE rather than silently falling through to the operational
 * row on somebody's dashboard.
 */
describe('the headline follows what is in scope, not what the filter says', () => {
  const FAMILIES = ['awareness', 'traffic', 'engagement', 'video', 'leads', 'sales', 'app', 'unknown']

  it('headlines a scope holding only sales campaigns with return and cost per order', () => {
    const keys = layoutFor('all', ['sales']).primary

    expect(keys).toContain('roas')
    expect(keys).toContain('cpa')
  })

  it('keeps the operational row when the scope really does mix objectives', () => {
    const keys = layoutFor('all', ['sales', 'awareness']).primary

    for (const forbidden of ['cpa', 'roas', 'revenue']) {
      expect(keys).not.toContain(forbidden)
    }
  })

  it('gives every classified family its own headline, including the ones named differently', () => {
    for (const family of FAMILIES.filter((f) => f !== 'unknown')) {
      const keys = layoutFor('all', [family]).primary

      expect(keys, `${family} fell through to the operational row`).not.toEqual(layoutFor('all').primary)
    }
  })

  it('leaves an unclassified scope on the operational row, which is the honest answer for it', () => {
    expect(layoutFor('all', ['unknown']).primary).toEqual(layoutFor('all').primary)
  })

  it('ignores the scope entirely once the reader has chosen an objective', () => {
    expect(layoutFor('awareness', ['sales']).primary).toEqual(layoutFor('awareness').primary)
  })
})

/**
 * NUMBER-PRESENTATION-001 — the compact figure is the display; the exact one stays reachable.
 *
 * A card shows «4.85M SAR» because a card has room for six characters and not for eleven. That is a
 * presentation decision, and a presentation decision must not destroy the figure: two campaigns
 * reading «4.85M» can be forty thousand riyals apart, and the reader comparing them has nowhere to
 * look. The reading now carries the full number, and the strip hangs it on the value as a title.
 *
 * It is attached where the READING is built rather than at each card, because there are a dozen
 * surfaces rendering these and only one place where a value meets its formatter.
 */
describe('the exact figure travels with the compact one', () => {
  const read = (key: string, value: number) =>
    readMetric(key, SPECS[key]!, { [key]: value }, undefined, 'SAR')

  /** The same reading, narrowed to the measured case — the only one with text to assert. */
  const textOf = (key: string, value: number): string => {
    const r = read(key, value)

    expect(r.kind, `${key} did not read as a measured figure`).toBe('value')

    return (r as { kind: 'value'; text: string }).text
  }

  it('carries the full number when the display abbreviated it', () => {
    expect(read('spend', 4_850_321)).toEqual({ kind: 'value', text: '4.85M SAR', exact: '4,850,321 SAR' })
  })

  it('says nothing extra when the display already showed every digit', () => {
    /* Under a thousand there is nothing to reveal, so no title is attached at all. */
    expect(read('spend', 940)).toEqual({ kind: 'value', text: '940 SAR' })
  })

  /**
   * NUMBER-PRESENTATION-001 §58 — a large COUNT abbreviates too, and keeps its digits.
   *
   * «1,282,024» in a card sixty pixels wide is eleven characters fighting for room with the label
   * above it, and the owner's correction names this case exactly: 4,127,676 → 4.13M. Only the
   * DISPLAY compacts; the full number rides along and the strip hangs it on the value.
   */
  it('abbreviates a large count and carries its full figure', () => {
    expect(read('impressions', 1_282_024)).toEqual({ kind: 'value', text: '1.28M', exact: '1,282,024' })
    expect(read('clicks', 29_210)).toEqual({ kind: 'value', text: '29.2K', exact: '29,210' })
  })

  /** Below a thousand there is nothing to abbreviate, so no tooltip repeats what is on screen. */
  it('attaches nothing to a count that was never abbreviated', () => {
    expect(read('conversions', 581)).toEqual({ kind: 'value', text: '581' })
  })

  /**
   * «Do NOT compact figures where the exact figure itself is the decision-critical value, such as:
   * CPC / CPL / CPA / ROAS / percentages where precision matters.»
   *
   * The owner's rule, and the defect that prompted it: a cost per click of 1.50 printed «2 SAR» on
   * Analytics — `money()` keeps three significant digits, which is generous for a total and
   * destructive for a figure whose whole value is in the decimals. Every cost-per metric is read in
   * full, and a percentage keeps its two places.
   */
  it('never abbreviates a cost per result, a return or a rate', () => {
    // The decimals that carry the decision, kept.
    expect(textOf('cpc', 1.5)).toBe('1.50 SAR')
    expect(textOf('cpl', 88.72)).toBe('88.72 SAR')
    expect(textOf('cpm', 3.03)).toBe('3.03 SAR')
    expect(textOf('roas', 12.0836)).toBe('12.08×')
    expect(textOf('ctr', 0.0226)).toBe('2.26%')

    /*
     * And no K/M/B on any of them, whatever the magnitude. That is the rule the owner stated —
     * «do not compact» — and it is distinct from rounding: a cost per result above a thousand still
     * writes its digits out, where `money()` would have abbreviated it to «1.23K SAR».
     */
    for (const key of ['cpa', 'cpc', 'cpl', 'cpi', 'cpe', 'cpm']) {
      expect(textOf(key, 1_234.56), `${key} was abbreviated`).not.toMatch(/[KMB]/)
      expect(textOf(key, 1_284_000), `${key} was abbreviated`).not.toMatch(/[KMB]/)
    }
  })
})

/**
 * UX-KPI-PRESENTATION-001 — «المؤشر والرقم والشارت لكل مؤشر».
 *
 * Every indicator card is to carry its label, its figure, its movement and the shape of the metric
 * over the period. The first three were already there; the sparkline is the one the owner asked for,
 * and the interesting rules are all about when NOT to draw it.
 *
 * The series is the page's own — the array it already fetched for the chart under the cards — so a
 * card's trend cannot disagree with the drawing beneath it.
 */
describe('the sparkline on an indicator card', () => {
  const summary = (over: Record<string, unknown> = {}) => ({
    current: { spend: 900, clicks: 300, conversions: 12, impressions: 90_000, revenue: 0 },
    previous: { spend: 800, clicks: 250, conversions: 10, impressions: 80_000, revenue: 0 },
    delta: { spend: 0.125 },
    reported: { spend: true, clicks: true, conversions: true, impressions: true, revenue: true },
    rows_in_scope: true,
    currency: 'SAR',
    ...over,
  }) as never

  const series = (spend: Array<number | null>) =>
    spend.map((v, i) => ({ date: `2026-08-0${i + 1}`, spend: v, clicks: 10 + i })) as never

  const spendCard = (summaryOver: Record<string, unknown>, points: unknown) =>
    dashboardMetrics('sales', summary(summaryOver), true, points as never)
      .primary.concat(dashboardMetrics('sales', summary(summaryOver), true, points as never).secondary)
      .find((m) => m.key === 'spend')

  it('carries the shape of the metric across the window', () => {
    expect(spendCard({}, series([100, 300, 200, 400]))?.spark).toEqual([100, 300, 200, 400])
  })

  /** No series to hand — a card, not a card built from a second source. */
  it('draws nothing when the page has no series', () => {
    expect(spendCard({}, undefined)?.spark).toBeUndefined()
  })

  /**
   * A metric no platform sent has no shape — UX-METRICS-001.
   *
   * The summary's sums coalesce to zero, so an unreported metric and a measured zero are the same
   * number in the payload. A flat line at the floor under «لم ترسله المنصة» would be a drawing of
   * an absence, which is the claim that whole requirement exists to prevent.
   */
  it('draws nothing for a metric the platform never reported', () => {
    const card = spendCard({ reported: { spend: false } }, series([100, 300, 200, 400]))

    expect(card?.spark).toBeUndefined()
  })

  /**
   * A hole is not a zero — FX-001.
   *
   * A withheld money figure comes through as null. Plotting it at the floor would draw a collapse
   * that did not happen, on exactly the days the product refused to state a number.
   */
  it('refuses the line when too much of the window is missing', () => {
    expect(spendCard({}, series([100, null, null, 400]))?.spark).toBeUndefined()
  })

  /** …and tolerates a hole it can still describe a shape around. */
  it('draws the shape it does have when one day is missing', () => {
    expect(spendCard({}, series([100, 300, null, 400]))?.spark).toEqual([100, 300, 400])
  })

  /** A flat line is not a trend, and drawing one invites a reader to see movement in it. */
  it('draws nothing when every day is the same figure', () => {
    expect(spendCard({}, series([0, 0, 0, 0]))?.spark).toBeUndefined()
    expect(spendCard({}, series([250, 250, 250]))?.spark).toBeUndefined()
  })

  /** One point is a dot, not a line. */
  it('draws nothing for a single day', () => {
    expect(spendCard({}, series([100]))?.spark).toBeUndefined()
  })
})
