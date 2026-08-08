import { describe, expect, it } from 'vitest'
import { SPECS, dashboardMetrics, layoutFor } from './metricCatalog'

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

const KEYS = (objective: string, path = 'all') => [
  ...layoutFor(objective, path).primary,
  ...layoutFor(objective, path).secondary,
]

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
      dashboardMetrics(objective, 'all', undefined, true).primary.find((m) => m.key === key)?.label

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

    expect(layoutFor('awareness', 'all').primary).toEqual(['reach', 'impressions', 'frequency', 'cpm'])
    for (const forbidden of ['purchases', 'add_to_cart', 'revenue', 'roas', 'cpa', 'aov']) {
      expect(keys).not.toContain(forbidden)
    }
  })

  it('gives a traffic campaign the visit metrics and no sales metrics', () => {
    expect(layoutFor('traffic', 'all').primary).toEqual(['clicks', 'ctr', 'cpc', 'landing_page_views'])
    for (const forbidden of ['purchases', 'add_to_cart', 'revenue', 'roas']) {
      expect(KEYS('traffic')).not.toContain(forbidden)
    }
  })

  it('gives a leads campaign its own count and cost, never a return', () => {
    expect(layoutFor('leads', 'all').primary).toContain('leads')
    expect(layoutFor('leads', 'all').primary).toContain('cpl')
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
    expect(layoutFor('app_installs', 'all').primary).toContain('installs')
    expect(layoutFor('app_installs', 'all').primary).toContain('cpi')
    expect(KEYS('app_installs')).not.toContain('roas')
  })

  /**
   * Several objectives at once: operational figures only.
   *
   * A cost per result across a brand budget and a sales budget divides one objective's money by
   * another objective's events — arithmetic that works and means nothing.
   */
  it('gives a mixed scope no cost-per and no return', () => {
    const keys = KEYS('all', 'all')

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
