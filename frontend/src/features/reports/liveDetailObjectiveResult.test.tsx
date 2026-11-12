import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import { LiveDetailTables } from './LiveDetailTables'
import { renderWithProviders } from '@/test/utils'
import type { LivePayload } from './api'

/**
 * OBJECTIVE-RESULT-001 — the client report's «كل هدف» table states each path's OWN result.
 *
 * Every path read its orders, so a traffic path with 2,881 landing page views and no order showed
 * «0» in the results column of the client's own link.
 */
const path = (over: Record<string, unknown>) => ({
  label_ar: 'الزيارات', label_en: 'Traffic', headline_metrics: [], spend: 1440.5, spend_withheld_rows: 0,
  impressions: 900_000, clicks: 18_464, landing_page_views: 2_881, orders: 0, revenue: 0,
  cpm: null, cpc: null, ctr: null, cpa: null, roas: null, result_metrics_apply: false, campaigns: [],
  ...over,
})

const payload = (paths: unknown[]) => ({
  period: { from: '2026-10-01', to: '2026-10-30' },
  currency: 'SAR',
  totals: { spend: 1440.5 },
  platforms: [], campaigns: [], ad_sets: [], ads: [], ads_groups: [], funnel: [], metrics: [], freshness: [], outline: [],
  objective_performance: { paths },
  store_funnel: null,
  available: { providers: ['snapchat'], campaigns: [], earliest: '2026-10-01', latest: '2026-10-30' },
  applied: {}, is_demo: false, form: 'detailed',
}) as unknown as LivePayload

describe('the objectives row of the client report', () => {
  it('shows a traffic path its visits, named, not «0» orders', async () => {
    renderWithProviders(
      <LiveDetailTables
        payload={payload([path({
          path: 'traffic',
          result_key: 'landing_page_views', result: 2881, result_label: { ar: 'زيارات الصفحة', en: 'Landing page views' },
          cost_per_result: 0.5, cost_per_result_label: { ar: 'تكلفة الزيارة', en: 'Cost per visit' },
        })])}
        currency="SAR"
        locale="ar"
      />,
      { locale: 'ar' },
    )

    const cell = await screen.findByTestId('objective-traffic-result')
    expect(cell.textContent).toContain('2.88K')
    expect(within(cell).getByText('زيارات الصفحة')).toBeTruthy()
    expect(cell.textContent).not.toMatch(/^0/)
  })

  it('names clicks as clicks when the path has no landing page views', async () => {
    renderWithProviders(
      <LiveDetailTables
        payload={payload([path({
          path: 'traffic', landing_page_views: 0,
          result_key: 'clicks', result: 18464, result_label: { ar: 'النقرات', en: 'Clicks' },
          cost_per_result: 0.08, cost_per_result_label: { ar: 'تكلفة النقرة (CPC)', en: 'Cost per click (CPC)' },
        })])}
        currency="SAR"
        locale="ar"
      />,
      { locale: 'ar' },
    )

    const cell = await screen.findByTestId('objective-traffic-result')
    expect(within(cell).getByText('النقرات')).toBeTruthy()
    expect(cell.textContent).not.toContain('زيارات')
  })

  it('keeps orders for a stored document written before the field existed', async () => {
    renderWithProviders(
      <LiveDetailTables
        payload={payload([path({ path: 'conversion', orders: 50, result_metrics_apply: true, cpa: 20 })])}
        currency="SAR"
        locale="en"
      />,
      { locale: 'en' },
    )

    const cell = await screen.findByTestId('objective-conversion-result')
    expect(cell.textContent).toContain('50')
  })

  /** OBJECTIVE-RESULT-001 — a conversion path holding sales and leads has no single result, and says why. */
  it('names no total for a path that mixes kinds of result', async () => {
    renderWithProviders(
      <LiveDetailTables
        payload={payload([path({
          path: 'conversion', orders: 70, result_metrics_apply: true, cpa: 20,
          result_key: null, result: null, result_label: null, result_missing_reason: 'mixed',
          result_missing_label: { ar: 'أهداف مختلفة: كل حملة بنتيجتها', en: 'Different objectives: each campaign has its own result' },
        })])}
        currency="SAR"
        locale="ar"
      />,
      { locale: 'ar' },
    )

    const cell = await screen.findByTestId('objective-conversion-result')
    expect(cell.textContent).toContain('أهداف مختلفة')
    expect(cell.textContent).not.toContain('70')
  })
})
