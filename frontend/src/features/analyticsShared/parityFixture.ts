/**
 * ANALYTICS-PARITY-001 — one set of figures, in the two shapes the two surfaces are served.
 *
 * The /r/ report is served a `LivePayload` (`totals`, `currency`, `reported`) and the internal board a metrics
 * `Summary` (`current`, `currency`, `reported`). They are the same measurement, so the same fixture is handed to
 * each, and `parity.test.tsx` fails on any difference between what the two draw.
 */
export type Totals = Record<string, number | null>

export const PARITY_KEYS = [
  'spend', 'impressions', 'clicks', 'ctr', 'conversions', 'purchases', 'revenue', 'roas', 'cpa',
  'reach', 'frequency', 'cpm', 'cpc', 'leads', 'cpl', 'installs', 'cpi', 'engagements', 'cpe',
  'landing_page_views', 'cost_per_lpv', 'conversion_rate',
] as const

export const FIXTURES: Record<string, { currency: string; totals: Totals }> = {
  everyday: {
    currency: 'SAR',
    totals: {
      spend: 12345.67, impressions: 4127676, clicks: 42738, ctr: 0.010354, conversions: 581, purchases: 581, revenue: 91234.5, roas: 7.39,
      cpa: 21.25, reach: 1500000, frequency: 2.75, cpm: 2.99, cpc: 0.29, leads: 100, cpl: 123.4567, installs: 50, cpi: 246.9,
      engagements: 900, cpe: 13.7, landing_page_views: 30000, cost_per_lpv: 0.41, conversion_rate: 0.0136,
    },
  },
  // Zero spend is data, not a gap: a quiet account reads zeros, and both surfaces must read the same zeros.
  quiet: {
    currency: 'USD',
    totals: {
      spend: 0, impressions: 0, clicks: 0, ctr: null, conversions: 0, purchases: 0, revenue: 0, roas: null, cpa: null,
      reach: null, frequency: null, cpm: null, cpc: null, leads: 0, cpl: null, installs: 0, cpi: null, engagements: 0, cpe: null,
      landing_page_views: 0, cost_per_lpv: null, conversion_rate: null,
    },
  },
  small: {
    currency: 'USD',
    totals: {
      spend: 18.05, impressions: 999, clicks: 7, ctr: 0.007007, conversions: 3, purchases: 3, revenue: 54.15, roas: 3,
      cpa: 6.0167, reach: 800, frequency: 1.25, cpm: 18.07, cpc: 2.5786, leads: 3, cpl: 6.0167, installs: 1, cpi: 18.05, engagements: 40, cpe: 0.45,
      landing_page_views: 5, cost_per_lpv: 3.61, conversion_rate: 0.4286,
    },
  },
}
