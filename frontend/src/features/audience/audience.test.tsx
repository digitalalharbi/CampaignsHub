import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { AudienceReportBlocks, AudienceSlideBody } from './AudienceBlocks'
import { withAudienceSlides } from '@/features/reports/reportSections'
import { AudiencePanel } from './AudiencePanel'
import type { AudienceReading, DeliveryGroup } from './types'
import { useUi } from '@/stores/ui'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

function group(provider: string, rows: DeliveryGroup['rows'], dimension = 'region'): DeliveryGroup {
  return {
    kind: 'paid_delivery', provider, dimension, metric: 'impressions', source_field: `${provider}:insights.breakdowns=${dimension}`,
    denominator: rows.reduce((a, r) => a + (r.impressions ?? 0), 0), reach_note: null,
    coverage: { days_with_rows: 1, days_in_period: 1, ad_sets_with_rows: 1, ad_sets_delivering: 1 }, synced_at: null, rows,
  }
}

const meta = group('meta', [
  { value: 'Najran Province', impressions: 300, share: { numerator: 300, denominator: 400, ratio: 0.75 }, clicks: 6, reach: 250 },
  { value: 'Makkah Province', impressions: 100, share: { numerator: 100, denominator: 400, ratio: 0.25 }, clicks: 2, reach: 90 },
])
const snap = group('snapchat', [{ value: 'Najran', impressions: 1000, share: { numerator: 1000, denominator: 1000, ratio: 1 }, clicks: 20, reach: 800 }])

function reading(over: Partial<AudienceReading> = {}): AudienceReading {
  return {
    period: { from: '2026-11-12', to: '2026-11-12', days: 1 }, providers: ['meta', 'snapchat'], cross_platform: { summed: false, reason: 'x' },
    targeting: { state: 'ready', items: [{ kind: 'targeting', provider: 'meta', level: 'ad_set', ad_set_id: 'a1', ad_set: 'Set A', settings: { cities: ['Najran'] }, not_stored: ['excluded_locations'], synced_at: null }] },
    paid_delivery: { state: 'ready', groups: [meta, snap], unavailable: [] },
    site_events: { state: 'unavailable', reason: 'no_ga4_property', groups: [] },
    organic: { state: 'unavailable', reason: 'not_fetched' },
    capabilities: { meta: { dimensions: { region: { targeting: false, delivery: 'stored' }, city: { targeting: true, delivery: 'unavailable_provider' } }, docs: null } },
    ...over,
  }
}

/** AUDIENCE-001 — each platform on its own denominator, targeting never shown as delivery, missing never zero. */
describe('audience blocks', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['analytics.view']); useUi.setState({ locale: 'en' }) })
  afterEach(() => signOut())

  it('draws one card per platform with the numerator and denominator, and no total across platforms', () => {
    renderWithProviders(<AudienceReportBlocks payload={{ audience_regions: { groups: [meta, snap] } }} locale="en" />)
    expect(screen.getByTestId('audience-delivery-meta-region')).toHaveTextContent('75% (300/400)')
    expect(screen.getByTestId('audience-delivery-snapchat-region')).toHaveTextContent('100% (1,000/1,000)')
    expect(screen.queryByText(/1,400/)).toBeNull()
    expect(screen.queryByText(/Spend/)).toBeNull()
  })

  it('flags a breakdown that covers fewer ad sets than delivered', () => {
    const g = { ...meta, coverage: { ...meta.coverage, ad_sets_with_rows: 1, ad_sets_delivering: 3 } }
    renderWithProviders(<AudienceReportBlocks payload={{ audience_regions: { groups: [g] } }} locale="en" />)
    expect(screen.getByTestId('audience-partial-coverage')).toHaveTextContent('Partial coverage: 1/3')
  })

  it('states a row without a share as not available, and draws nothing for no parts', () => {
    const g = group('meta', [{ value: 'Unknown', impressions: null, share: null, clicks: null, reach: null }])
    const { container, rerender } = renderWithProviders(<AudienceReportBlocks payload={{ audience_regions: { groups: [g] } }} locale="en" />)
    expect(screen.getByTestId('audience-delivery-meta-region')).toHaveTextContent('Not available')
    rerender(<AudienceReportBlocks payload={{}} locale="en" />)
    expect(container.querySelector('[data-testid="live-audience"]')).toBeNull()
  })

  it('labels targeting as a setting and names what is not stored', () => {
    renderWithProviders(<AudienceReportBlocks payload={{ audience_targeting: { items: reading().targeting.items } }} locale="ar" />)
    const block = screen.getByTestId('audience-targeting')
    expect(block).toHaveTextContent('إعداد — ليس تسليمًا')
    expect(block).toHaveTextContent('excluded_locations')
  })

  it('the analytics panel switches kinds, keeps unpaid unavailable and folds the availability matrix', async () => {
    vi.mocked(getData).mockResolvedValue(reading())
    renderWithProviders(<AudiencePanel projectId="p1" range={{ from: '2026-11-12', to: '2026-11-12' }} />)
    expect(await screen.findByTestId('audience-delivery-meta-region')).toBeInTheDocument()
    expect(vi.mocked(getData).mock.calls[0][0]).toBe('/projects/p1/audience?from=2026-11-12&to=2026-11-12')
    fireEvent.click(screen.getByTestId('audience-kind-organic'))
    expect(screen.getByText(/not fetched — unavailable/)).toBeInTheDocument()
    fireEvent.click(screen.getByTestId('audience-kind-site_events'))
    expect(screen.getByText(/No site measurement/)).toBeInTheDocument()
    expect(screen.getByTestId('audience-availability')).toHaveTextContent('Not offered by platform')
  })
})

describe('audience pages of the deck and the PDF', () => {
  type S = { id: string; type: string; order: number; visible: boolean; range?: [number, number] }
  const four = [meta, snap, group('tiktok', [], 'age'), group('tiktok', [], 'gender')]

  it('splits delivery cards three to a page and prints only the parts the server named', () => {
    const slides = withAudienceSlides<S>([], { report_sections: ['audience_regions', 'audience_age'], audience_regions: { groups: [meta, snap] }, audience_age: { groups: four.slice(2) }, audience_targeting: { items: reading().targeting.items } })
    expect(slides.map((s) => [s.type, s.range])).toEqual([['__audience_delivery', [0, 3]], ['__audience_delivery', [3, 4]]])
  })

  it('prints nothing for a payload without the section list or with every part hidden', () => {
    expect(withAudienceSlides<S>([], { audience_regions: { groups: [meta] } })).toEqual([])
    expect(withAudienceSlides<S>([], { report_sections: ['kpis'] })).toEqual([])
  })

  it('a page never draws a part the server did not name', () => {
    renderWithProviders(<AudienceSlideBody type="__audience_delivery" range={[0, 3]} payload={{ audience_regions: { groups: [meta] }, audience_age: { groups: [snap] } }} named={['audience_age']} />)
    expect(screen.queryByTestId('audience-delivery-meta-region')).toBeNull()
    expect(screen.getByTestId('audience-delivery-snapchat-region')).toBeInTheDocument()
  })

  it('a delivery page draws its own slice of cards', () => {
    renderWithProviders(<AudienceSlideBody type="__audience_delivery" range={[1, 2]} payload={{ audience_regions: { groups: [meta, snap] } }} />)
    expect(screen.queryByTestId('audience-delivery-meta-region')).toBeNull()
    expect(screen.getByTestId('audience-delivery-snapchat-region')).toBeInTheDocument()
  })
})

