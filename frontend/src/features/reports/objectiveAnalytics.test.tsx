import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
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

    expect(screen.getByTestId('objective-family-awareness')).toBeInTheDocument()
    expect(screen.getByTestId('objective-family-leads')).toBeInTheDocument()
    // CPM belongs to awareness only; the leads card has no CPM tile.
    expect(within(screen.getByTestId('objective-family-leads')).queryByText('CPM')).toBeNull()
  })

  it('keeps unavailable, zero and inapplicable apart', () => {
    renderWithProviders(<ObjectiveAnalyticsSection section={section([leads, awareness])} currency="SAR" ar={false} />)

    expect(screen.getByTestId('objective-kpi-leads-leads')).toHaveTextContent('0')
    expect(screen.getByTestId('objective-kpi-leads-leads')).toHaveAttribute('data-state', 'reported')
    expect(screen.getByTestId('objective-kpi-leads-cpl')).toHaveTextContent('—')
    expect(screen.getByTestId('objective-kpi-leads-cpl')).toHaveAttribute('data-state', 'unavailable')
    expect(screen.getByTestId('objective-kpi-awareness-reach')).toHaveTextContent('—')
    expect(screen.queryByTestId('objective-kpi-leads-roas')).toBeNull()
  })

  it('draws best and weakest only where the server ranked, platform then content', () => {
    renderWithProviders(<ObjectiveAnalyticsSection section={section([awareness, leads])} currency="SAR" ar={false} />)

    expect(screen.getByTestId('objective-platform-leaders-awareness-best')).toHaveTextContent('5 SAR')
    expect(screen.getByTestId('objective-content-leaders-awareness-best')).toHaveTextContent('Brand film')
    expect(screen.getByTestId('objective-content-leaders-awareness-weakest')).toHaveTextContent('Teaser')
    // One candidate: no ranking card, not an empty one.
    expect(screen.queryByTestId('objective-platform-leaders-leads')).toBeNull()
    expect(screen.getByTestId('objective-contribution-awareness')).toHaveTextContent('66.7%')
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
})
