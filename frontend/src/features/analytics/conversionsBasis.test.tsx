import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { AnalyticsPage } from './AnalyticsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

/**
 * REPORT-OBJECTIVE-005 — the sentence that has to travel with the «Results» figure.
 *
 * `SUM(conversions)` across platforms is the sum of each platform's own claim, and those claims
 * overlap: one sale clicked from two platforms is reported in full by both. Live, the dashboard
 * printed 1,169 with nothing beside it — a number every reader takes for an order count.
 *
 * The figure is not removed. It is the only conversion number available before a store is connected.
 * What is asserted here is that it is never shown bare when more than one platform contributed, and
 * never carries a caveat about an overlap that cannot happen.
 */

const TOTALS = {
  impressions: 100000, clicks: 2280, conversions: 1169, spend: 96000, revenue: 805000,
  roas: 8.37, cpa: 82, ctr: 0.0228, cpc: 42, cpm: 960,
  reach: 0, video_views: 0, video_completions: 0, landing_page_views: 0, leads: 0,
  qualified_leads: 0, purchases: 0, installs: 0, registrations: 0, in_app_events: 0,
  engagements: 0, frequency: null, cpl: null, cpi: null, cpe: null, aov: null,
  conversion_rate: null, engagement_rate: null, video_completion_rate: null,
}

function summary(providers: string[]) {
  return {
    current: TOTALS,
    previous: TOTALS,
    delta: {},
    commerce: null,
    conversions_basis: {
      source: 'platform_reported' as const,
      label_ar: 'ما أبلغت به المنصات',
      label_en: 'Platform-Reported',
      providers,
      may_double_count: providers.length > 1,
      is_unique_order_count: false as const,
      note_ar: providers.length > 1
        ? `مجموع ما أبلغت به ${providers.length} منصات، وليس عدد طلبات فريدة — البيعة الواحدة قد تُبلَّغ من أكثر من منصة.`
        : 'ما أبلغت به المنصة عن التحويلات التي تعتقد أن إعلانها تسبب بها.',
      note_en: providers.length > 1
        ? `The sum of what ${providers.length} platforms each reported — not a count of unique orders, because one sale can be reported by more than one platform.`
        : 'What the platform reported for the conversions it believes its ads caused.',
    },
  }
}

function route(providers: string[]) {
  vi.mocked(getData).mockImplementation((path: string) => {
    if (path.includes('/metrics/summary')) return Promise.resolve(summary(providers))
    if (path.includes('/metrics/timeseries')) return Promise.resolve([])
    // The page also mounts the performance disclaimer. `null` is what «no disclaimer configured»
    // looks like from the API; an empty ARRAY is not, and feeding one in would have this fixture
    // assert against a shape the server never sends.
    if (path.includes('disclaimer')) return Promise.resolve(null)
    return Promise.resolve([])
  })
}

describe('the Results figure states what it is', () => {
  beforeEach(() => {
    signInWith(['campaigns.view'])
    // Every metric query is `enabled: Boolean(projectId)` — with no active project the page renders
    // its empty state and the assertion below would pass for the wrong reason.
    useProject.getState().setCurrentProjectId('p1')
  })

  afterEach(() => {
    signOut()
    useProject.getState().setCurrentProjectId(null)
    vi.clearAllMocks()
  })

  it('says the figure is a sum of platform claims when more than one contributed', async () => {
    route(['snapchat', 'tiktok', 'meta', 'google'])

    renderWithProviders(<AnalyticsPage />, { locale: 'en', route: '/app/analytics' })

    const basis = await screen.findByTestId('conversions-basis')
    expect(basis).toHaveTextContent(/not a count of unique orders/)
    expect(basis).toHaveTextContent(/4 platforms/)
  })

  /** One platform cannot overlap with itself — a warning about an impossible risk teaches readers to skip warnings. */
  it('says nothing when a single platform produced the figure', async () => {
    route(['meta'])

    renderWithProviders(<AnalyticsPage />, { locale: 'en', route: '/app/analytics' })

    await screen.findByText('Results')
    expect(screen.queryByTestId('conversions-basis')).not.toBeInTheDocument()
  })

  it('reads in Arabic without falling back to the English copy', async () => {
    route(['snapchat', 'meta'])

    renderWithProviders(<AnalyticsPage />, { locale: 'ar', route: '/app/analytics' })

    const basis = await screen.findByTestId('conversions-basis')
    expect(basis).toHaveTextContent(/وليس عدد طلبات فريدة/)
    expect(basis).not.toHaveTextContent(/unique orders/)
  })
})
