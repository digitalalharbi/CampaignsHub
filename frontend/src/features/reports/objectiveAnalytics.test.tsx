import { describe, expect, it } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { ObjectiveAnalyticsSection } from './ObjectiveAnalyticsSection'
import { drawableFamilies, formatKpi, type ObjectiveAnalytics, type ObjectiveFamilyBlock, type ObjectiveKpi } from './objectiveAnalytics'

/*
 * REPORT-OBJECTIVE-ANALYTICS-001 — the section draws what the server decided and nothing else.
 *
 * Unavailable reads «—», a reported zero reads «0», an inapplicable figure is not drawn at all, and a
 * ranking the server declined is not drawn as an empty card.
 */

const kpi = (key: string, value: number | null, over: Partial<ObjectiveKpi> = {}): ObjectiveKpi => ({
  key,
  label_ar: key,
  label_en: key.toUpperCase(),
  kind: ['spend', 'cpl', 'cpm', 'cpa'].includes(key) ? 'money' : 'count',
  value,
  state: value === null ? 'unavailable' : 'reported',
  reason: value === null ? 'not_reported' : null,
  not_reported_by: [],
  ...over,
})

const noRanking = { metric: null, best: null, weakest: null, reason: 'only_one_candidate', eligible: 0, candidates: 1 }

const leads: ObjectiveFamilyBlock = {
  family: 'leads',
  label_ar: 'العملاء المحتملون',
  label_en: 'Leads',
  kpis: [kpi('spend', 300), kpi('leads', 0), kpi('cpl', null, { reason: 'zero_denominator' })],
  platforms: [],
  contribution: null,
  platform_ranking: noRanking,
  content_ranking: noRanking,
  trend: null,
}

const awareness: ObjectiveFamilyBlock = {
  family: 'awareness',
  label_ar: 'الوعي',
  label_en: 'Awareness',
  kpis: [kpi('spend', 2500), kpi('reach', null), kpi('impressions', 300_000), kpi('cpm', 8.33)],
  platforms: [],
  contribution: {
    outcome: 'impressions', label_ar: 'مرات الظهور', label_en: 'Impressions', total: 300_000,
    rows: [{ provider: 'meta', value: 200_000, share: 0.6667 }, { provider: 'tiktok', value: 100_000, share: 0.3333 }],
  },
  platform_ranking: {
    metric: 'cpm', reason: null, eligible: 2, candidates: 3,
    best: { provider: 'meta', value: 5, volume: { key: 'impressions', value: 200_000 } },
    weakest: { provider: 'tiktok', value: 15, volume: { key: 'impressions', value: 100_000 } },
  },
  content_ranking: {
    metric: 'cpm', reason: null, eligible: 2, candidates: 2,
    best: { provider: 'meta', name: 'Brand film', format: 'video', value: 4, volume: { key: 'impressions', value: 90_000 } },
    weakest: { provider: 'tiktok', name: 'Teaser', format: 'video', value: 12, volume: { key: 'impressions', value: 20_000 } },
  },
  trend: null,
}

const section = (families: ObjectiveFamilyBlock[]): ObjectiveAnalytics => ({
  version: 1,
  period: { from: '2026-07-01', to: '2026-07-31' },
  families,
  mixed: families.length > 1,
  cross_family_blend: false,
  unclassified_present: false,
})

describe('objective analytics section', () => {
  it('draws one card per family and never a figure across them', () => {
    renderWithProviders(<ObjectiveAnalyticsSection section={section([awareness, leads])} currency="SAR" ar={false} />)

    // OBJECTIVE-COMPACT-001 — one card, the objectives a switch at its head; one objective drawn at a time.
    expect(screen.getByTestId('objective-family-awareness')).toBeInTheDocument()
    expect(screen.queryByTestId('objective-family-leads')).toBeNull()
    fireEvent.click(screen.getByTestId('objective-tab-leads'))
    expect(screen.getByTestId('objective-family-leads')).toBeInTheDocument()
    // CPM belongs to awareness only; the leads objective has no CPM figure.
    expect(within(screen.getByTestId('objective-family-leads')).queryByText('CPM')).toBeNull()
  })

  it('keeps unavailable, zero and inapplicable apart', () => {
    renderWithProviders(<ObjectiveAnalyticsSection section={section([leads, awareness])} currency="SAR" ar={false} />)

    expect(screen.getByTestId('objective-kpi-leads-leads')).toHaveTextContent('0')
    expect(screen.getByTestId('objective-kpi-leads-leads')).toHaveAttribute('data-state', 'reported')
    // OBJECTIVE-COMPACT-001 — an unavailable figure takes no room: it is neither drawn as 0 nor as an empty card.
    expect(screen.queryByTestId('objective-kpi-leads-cpl')).toBeNull()
    expect(screen.queryByText('Not available')).toBeNull()
    expect(screen.queryByTestId('objective-kpi-leads-roas')).toBeNull()
    fireEvent.click(screen.getByTestId('objective-tab-awareness'))
    expect(screen.queryByTestId('objective-kpi-awareness-reach')).toBeNull()
  })

  it('draws each KPI as the summary does: movement where the server stated one, and the daily line of that very figure', () => {
    const withMove = { ...leads, kpis: leads.kpis.map((k) => (k.key === 'leads' ? { ...k, delta: -0.48 } : k)) }
    renderWithProviders(<ObjectiveAnalyticsSection section={section([withMove])} currency="SAR" ar={false} />)

    expect(screen.getByTestId('objective-kpi-leads-leads')).toHaveTextContent('48%')
    expect(screen.getByTestId('objective-kpi-leads-spend').textContent).not.toMatch(/%/)
  })

  it('opens the family on its outcome, and a card is a switch: the spotlight reads that figure by platform and day (OBJECTIVE-CARDS-CREATIVE-001)', () => {
    const block: ObjectiveFamilyBlock = {
      ...awareness,
      kpis: awareness.kpis.map((k) => (k.key === 'spend' ? { ...k, delta: 0.31 } : k)),
      platforms: [
        { provider: 'meta', spend_share: 0.6, kpis: [kpi('impressions', 200_000), kpi('cpm', 5), kpi('spend', 1000)] },
        { provider: 'tiktok', spend_share: 0.4, kpis: [kpi('impressions', 100_000), kpi('cpm', 15), kpi('spend', 1500)] },
      ],
      trend: {
        metric: 'cpm', outcome: 'impressions',
        points: [
          { date: '2026-07-01', reported: true, spend: 80, outcome: 9000, value: 8.9 },
          { date: '2026-07-02', reported: false, spend: null, outcome: null, value: null },
          { date: '2026-07-03', reported: true, spend: 90, outcome: 11000, value: 8.2 },
        ],
      },
    }
    renderWithProviders(<ObjectiveAnalyticsSection section={section([block])} currency="SAR" ar={false} />)

    const spot = screen.getByTestId('objective-spotlight-awareness')
    expect(spot).toHaveAttribute('data-kpi', 'impressions')
    expect(screen.getByTestId('objective-kpi-awareness-impressions')).toHaveAttribute('aria-selected', 'true')
    // Impressions add up and every platform reported them: each platform carries its share.
    expect(screen.getByTestId('objective-spotlight-platforms-awareness')).toHaveTextContent('67%')
    expect(screen.getByTestId('objective-trend-awareness')).toBeInTheDocument()
    // Spend's movement is neutral — neither good nor bad.
    expect(screen.getByTestId('objective-kpi-awareness-spend')).toHaveTextContent('+31%')

    fireEvent.click(screen.getByTestId('objective-kpi-awareness-cpm'))
    expect(spot).toHaveAttribute('data-kpi', 'cpm')
    const platforms = screen.getByTestId('objective-spotlight-platforms-awareness')
    // A cost reads lowest first, and a rate is never given a share of a whole.
    expect(platforms.textContent?.indexOf('Meta') ?? -1).toBeLessThan(platforms.textContent?.search(/TikTok/) ?? -1)
    expect(platforms.textContent).not.toMatch(/%/)

    // Spend: no daily line of its own sent here beyond the trend's spend, and its platforms read without a «best».
    fireEvent.click(screen.getByTestId('objective-kpi-awareness-spend'))
    expect(screen.getByTestId('objective-spotlight-platforms-awareness').textContent).not.toMatch(/★/)
  })

  it('draws best and weakest only where the server ranked, platform then content', () => {
    renderWithProviders(<ObjectiveAnalyticsSection section={section([awareness, leads])} currency="SAR" ar={false} />)

    expect(screen.getByTestId('objective-platform-leaders-awareness-best')).toHaveTextContent('5 ر.س')
    expect(screen.getByTestId('objective-content-leaders-awareness-best')).toHaveTextContent('Brand film')
    expect(screen.getByTestId('objective-content-leaders-awareness-weakest')).toHaveTextContent('Teaser')
    // One candidate: no ranking card, not an empty one.
    expect(screen.queryByTestId('objective-platform-leaders-leads')).toBeNull()
    // The contribution reads as each platform's share of the outcome, in the spotlight, not as a separate bar.
    expect(screen.queryByTestId('objective-contribution-awareness')).toBeNull()
  })

  it('does not name content on a link that does not publish it', () => {
    renderWithProviders(<ObjectiveAnalyticsSection section={section([awareness])} currency="SAR" ar={false} showContent={false} />)

    expect(screen.queryByTestId('objective-content-leaders-awareness')).toBeNull()
    expect(screen.queryByText('Brand film')).toBeNull()
    expect(screen.getByTestId('objective-platform-leaders-awareness')).toBeInTheDocument()
  })

  it('disappears cleanly when nothing was reported', () => {
    const empty: ObjectiveFamilyBlock = { ...leads, kpis: [kpi('leads', null), kpi('cpl', null)] }

    expect(drawableFamilies(section([empty]))).toEqual([])
    renderWithProviders(<ObjectiveAnalyticsSection section={section([empty])} currency="SAR" ar />)
    expect(screen.queryByTestId('objective-analytics')).toBeNull()
    expect(formatKpi(kpi('leads', 0), 'SAR')).toBe('0')
  })

  it('names a cost per conversion as such on a ranking card', () => {
    const conversions: ObjectiveFamilyBlock = {
      ...awareness,
      family: 'sales',
      platform_ranking: { ...awareness.platform_ranking, metric: 'cost_per_conversion' },
      content_ranking: null,
    }
    renderWithProviders(<ObjectiveAnalyticsSection section={section([conversions])} currency="SAR" ar />)

    expect(screen.getByTestId('objective-platform-leaders-sales')).toHaveTextContent('تكلفة التحويل')
    expect(screen.getByTestId('objective-platform-leaders-sales')).not.toHaveTextContent('المشتريات')
  })
})
