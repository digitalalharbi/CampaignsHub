import type { LivePayload } from '@/features/reports/api'

/**
 * One report payload, shared by the logic tests, the parity test and the board's tests — a sales objective over three platforms with
 * a funnel, a daily line and ranked content. Figures are invented; nothing here is a client's data.
 */
export const REPORT_FIXTURE = {
  period: { from: '2026-11-01', to: '2026-11-07', days: 7 },
  currency: 'SAR',
  totals: { spend: 1600, impressions: 29_000, clicks: 550, ctr: 0.019, purchases: 40, conversions: 40, revenue: 8000, roas: 5, cpa: 40, cpc: 2.9 },
  deltas: { spend: 0.1, clicks: -0.05, purchases: 0.2 },
  reported: { purchases: true, spend: true, clicks: true, impressions: true, revenue: true },
  results: { configured: false, total: null, cost: null, by_platform: {}, by_path: {}, events: {} },
  timeseries: [
    { date: '2026-11-01', spend: 200, clicks: 70, impressions: 4000, purchases: 5 },
    { date: '2026-11-02', spend: 250, clicks: 90, impressions: 5000, purchases: 7 },
    { date: '2026-11-03', spend: 300, clicks: 100, impressions: 5500, purchases: 9 },
  ],
  platforms: [
    { provider: 'meta', spend: 1000, impressions: 20_000, clicks: 400, ctr: 0.02, purchases: 28, conversions: 28, movement: { purchases: 0.25 } },
    { provider: 'snapchat', spend: 600, impressions: 9000, clicks: 150, ctr: 0.0167, purchases: 12, conversions: 12, movement: { purchases: null } },
    { provider: 'tiktok', spend: 0, impressions: 0, clicks: 0, ctr: null, purchases: 0, conversions: 0 },
  ],
  funnel: [
    { stage: 'impressions', label: 'Impressions', count: 29_000, step_rate: null, cost_per: null, reported: true },
    { stage: 'clicks', label: 'Clicks', count: 550, step_rate: 0.019, cost_per: null, reported: true },
    { stage: 'purchases', label: 'Orders', count: 40, step_rate: 0.0727, cost_per: null, reported: true },
    { stage: 'checkout', label: 'Checkout', count: null, step_rate: null, cost_per: null, reported: false },
  ],
  ads: [
    { content_key: 'a1', name: 'Best one', provider: 'meta', objective: 'sales', spend: 300, clicks: 100, conversions: 12 },
    { content_key: 'a2', name: 'Second', provider: 'snapchat', objective: 'sales', spend: 200, clicks: 60, conversions: 8 },
  ],
  ads_weakest: [
    { content_key: 'a1', name: 'Best one', provider: 'meta', objective: 'sales', spend: 300, clicks: 100, conversions: 12 },
    { content_key: 'w1', name: 'Weak one', provider: 'meta', objective: 'sales', spend: 400, clicks: 20, conversions: 1 },
    { content_key: 'w2', name: 'Weak snap', provider: 'snapchat', objective: 'sales', spend: 100, clicks: 5, conversions: 0 },
  ],
  ads_basis: { metric: 'roas', family: 'sales', family_label_ar: 'المبيعات', family_label_en: 'Sales' },
  available: { providers: ['meta', 'snapchat', 'tiktok'], campaigns: [], earliest: '2026-11-01', latest: '2026-11-07' },
  freshness: [],
  metrics: [],
} as unknown as LivePayload
