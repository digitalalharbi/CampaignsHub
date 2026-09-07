import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { mixedResultsNote } from './reportMetrics'
import { LiveDetailTables } from './LiveDetailTables'
import { renderWithProviders } from '@/test/utils'
import type { LivePayload } from './api'

/**
 * CROSS-PLATFORM-ATTRIBUTION-DEPTH-001 / owner ledger row 26 — «never combine Purchases, Leads,
 * Installs, Registrations and Conversations into one Results number».
 *
 * `CampaignObjective::path()` files Leads, App installs, Add to cart, Sales, Conversions and
 * Purchases on the SAME conversion path, so the path's `orders` is their sum and its `cpa` divides
 * the path's whole spend by that sum. A project running a lead programme beside a purchase campaign
 * — 2000 for 400 leads, 1000 for 50 sales — is shown one cost per result of 6.67, when the real
 * price of a sale is 20 and of a lead is 5. The blend is always the flattering direction, because
 * leads are many and cheap, and it is printed on the client's own report.
 *
 * The aggregate stays: callers sum it, and the ratio IS recomputed from the aggregate numerator and
 * denominator rather than averaged, which is the rule for derived ratios. What it may no longer do
 * is travel without saying what it is made of. This is the wording all three surfaces share.
 */
const PARTS = [
  { objective: 'leads', label_ar: 'العملاء المحتملون', label_en: 'Leads', orders: 400 },
  { objective: 'sales', label_ar: 'المبيعات', label_en: 'Sales', orders: 50 },
]

describe('a cost per result that averages two different prices', () => {
  it('names every kind and its own count, in both languages', () => {
    const en = mixedResultsNote(PARTS, false)
    expect(en).not.toBeNull()
    expect(en!.parts).toBe('Leads 400 · Sales 50')
    expect(en!.note).toContain('more than one kind')
    expect(en!.note).toContain('does not price any one of them')

    const ar = mixedResultsNote(PARTS, true)
    expect(ar).not.toBeNull()
    expect(ar!.parts).toBe('العملاء المحتملون 400 · المبيعات 50')
    expect(ar!.note).toContain('أكثر من نوع واحد')
  })

  /**
   * PERMANENT product rule: Latin digits in both languages. An Arabic sentence carrying ٤٠٠ would
   * be the one number on the page a reader cannot compare against the column beside it.
   */
  it('writes its figures in Latin digits in Arabic too', () => {
    expect(mixedResultsNote(PARTS, true)!.parts).toMatch(/400/)
    expect(mixedResultsNote(PARTS, true)!.parts).not.toMatch(/[٠-٩]/)
  })

  /**
   * The vacuity check. One kind of result is the ordinary case: a warning under every honest cost
   * per sale in the product is noise, and a reader who sees it everywhere stops reading it.
   */
  it('says nothing when the results are all one kind', () => {
    expect(mixedResultsNote([PARTS[1]], true)).toBeNull()
    expect(mixedResultsNote([], true)).toBeNull()
    expect(mixedResultsNote(undefined, true)).toBeNull()
  })
})

/** A conversion path holding both kinds, and an awareness path that holds neither. */
const pathPayload = (mixed: boolean) => ({
  period: { from: '2026-08-01', to: '2026-08-30' },
  currency: 'SAR',
  totals: { spend: 3000, conversions: 450, revenue: 10000, impressions: 90000, clicks: 700 },
  platforms: [{ provider: 'snapchat', spend: 3000, conversions: 450 }],
  campaigns: [], ad_sets: [], ads: [], ads_groups: [], funnel: [], metrics: [], freshness: [], outline: [],
  objective_performance: {
    paths: [
      {
        path: 'conversion', label_ar: 'التحويل', label_en: 'Conversion', headline_metrics: [],
        spend: 3000, impressions: 90000, clicks: 700, landing_page_views: 0, orders: mixed ? 450 : 50,
        revenue: 10000, cpm: null, cpc: null, ctr: null, cpa: mixed ? 6.67 : 20, roas: 3.33,
        result_metrics_apply: true, campaigns: [],
        result_composition: mixed ? PARTS : [PARTS[1]],
        results_mixed: mixed,
        cpa_mixes_result_types: mixed,
      },
    ],
  },
  store_funnel: null,
  available: { providers: ['snapchat'], campaigns: [], earliest: '2026-08-01', latest: '2026-08-30' },
  applied: {}, is_demo: false, form: 'detailed',
}) as unknown as LivePayload

describe('the detailed report a client opens', () => {
  /**
   * The surface, not the helper. A note computed and rendered nowhere is the failure mode this
   * product has already met once — `exceeds_previous` was correct in the payload and invisible on
   * screen for as long as nobody looked.
   */
  it('marks the blended cost per result and carries the reason', async () => {
    renderWithProviders(
      <LiveDetailTables payload={pathPayload(true)} currency="SAR" locale="en" />, { locale: 'en' },
    )

    const marker = await screen.findByTestId('objective-conversion-mixed-results')
    expect(marker).toHaveTextContent('mixed')
    expect(marker.getAttribute('title')).toContain('Leads 400 · Sales 50')
  })

  it('leaves an unblended cost per result unmarked', () => {
    renderWithProviders(
      <LiveDetailTables payload={pathPayload(false)} currency="SAR" locale="en" />, { locale: 'en' },
    )

    expect(screen.queryByTestId('objective-conversion-mixed-results')).toBeNull()
  })
})
