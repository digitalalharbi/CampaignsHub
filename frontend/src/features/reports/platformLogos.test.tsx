import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import { ReportAdsSection, type ReportAd } from './ReportAdsSection'
import { ObjectiveAnalyticsSection } from './ObjectiveAnalyticsSection'
import { AdPoster } from '@/features/content/AdPoster'
import { renderWithProviders } from '@/test/utils'
import type { ObjectiveAnalytics, ObjectiveFamilyBlock, ObjectiveKpi } from './objectiveAnalytics'

/**
 * REPORT-PLATFORM-LOGOS-001 — a platform is named with its own logo wherever a report names it.
 *
 * Measured on the real client link, which printed bare platform names in four places the tables and cards had already
 * fixed: the objective leaders («the strongest / weakest platform»), the ad cards' platform line, the «no preview» cover
 * and the executive summary's results split (plus the spend donut's legend). Each assertion below is one of those places
 * carrying a `provider-mark` beside the name — so a new surface that forgets the logo fails here, not on the Owner's screen.
 */
const kpi = (key: string, value: number | null): ObjectiveKpi => ({
  key, label_ar: key, label_en: key.toUpperCase(), kind: ['spend', 'cpm'].includes(key) ? 'money' : 'count', value,
  state: value === null ? 'unavailable' : 'reported', reason: value === null ? 'not_reported' : null, not_reported_by: [],
})

const block = (): ObjectiveFamilyBlock => ({
  family: 'awareness', label_ar: 'الوعي', label_en: 'Awareness',
  kpis: [kpi('spend', 2500), kpi('impressions', 300_000)],
  platforms: [],
  contribution: { outcome: 'impressions', label_ar: 'مرات الظهور', label_en: 'Impressions', total: 300_000, rows: [{ provider: 'meta', value: 200_000, share: 0.6667 }, { provider: 'tiktok', value: 100_000, share: 0.3333 }] },
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
})

const section = (): ObjectiveAnalytics => ({ version: 1, period: { from: '2026-07-01', to: '2026-07-31' }, families: [block()], mixed: true, cross_family_blend: false, unclassified_present: false })

describe('platform logos on the report surfaces', () => {
  it('the strongest and weakest PLATFORM are named with their logos', () => {
    renderWithProviders(<ObjectiveAnalyticsSection section={section()} currency="SAR" ar={false} />)

    const leaders = screen.getByTestId('objective-platform-leaders-awareness')
    for (const end of ['best', 'weakest']) {
      expect(within(within(leaders).getByTestId(`objective-platform-leaders-awareness-${end}`)).getAllByTestId('provider-mark').length, `the ${end} platform has no logo`).toBeGreaterThan(0)
    }
  })

  it('the strongest and weakest CONTENT carry the platform logo on their platform line', () => {
    renderWithProviders(<ObjectiveAnalyticsSection section={section()} currency="SAR" ar={false} />)

    const content = screen.getByTestId('objective-content-leaders-awareness')
    expect(within(content).getAllByTestId('provider-mark')).toHaveLength(2)
  })

  it('an ad card names its platform with the logo', () => {
    const ad = { id: 'a1', name: 'Eid film', provider: 'tiktok', objective: 'sales', preview: { state: 'available', kind: 'image', image_url: 'https://cdn/a.jpg', video_url: null, thumbnail_url: null, expires_at: null, note_ar: null, note_en: null, cards: null }, spend: 100, impressions: 1000, clicks: 10, conversions: 1, ctr: 0.01, cpa: 100, roas: 2, reason: null } as unknown as ReportAd
    renderWithProviders(<ReportAdsSection ads={[ad]} locale="en" currency="SAR" />, { locale: 'en' })

    const card = screen.getByTestId('report-ad-card')
    expect(within(card).getByTestId('provider-mark')).toHaveAttribute('data-provider', 'tiktok')
  })

  it('the «no preview» cover says where the ad ran with the logo as well as the name', () => {
    const preview = { state: 'unavailable', kind: 'image', image_url: null, video_url: null, thumbnail_url: null, expires_at: null, note_ar: null, note_en: null, cards: null } as never
    renderWithProviders(<AdPoster preview={preview} name="Hero" provider="tiktok" forClient testid="p" />, { locale: 'en' })

    expect(within(screen.getByTestId('p-absent')).getByTestId('provider-mark')).toHaveAttribute('data-provider', 'tiktok')
    expect(screen.getByTestId('p-absent-label')).toHaveTextContent('TikTok')
  })
})
