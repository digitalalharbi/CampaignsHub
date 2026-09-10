import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { AnalyticsPage } from './AnalyticsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
  getEnvelope: vi.fn(),
}))

import { getData, getEnvelope } from '@/lib/api/client'

/**
 * ANALYTICS-FILTER-TRUTH-001 — the four objective panels decline the objective axis in silence.
 *
 * `platform-objectives`, `objective-leaders`, `objective-explanations` and `objective-trend` are
 * scoped by platform and campaign only. Narrowing them by objective would make «performance by
 * objective path» a comparison of one, so the refusal is right — and the server has always named
 * the dropped axis in `meta.filter_scope`, which `FilterTruthAuditSurfacesTest` holds.
 *
 * Nothing could read it. `useMetric` returns `getData`, which keeps the payload and throws the
 * envelope away, so the statement existed on the wire and nowhere else: the reader chose one
 * objective, the chip lit, and four panels answered for every objective on the account without a
 * word. That is the same defect `useFreshness` was fixed for, on four more panels.
 */
const PATHS = {
  paths: [
    {
      path: 'awareness',
      label_ar: 'الوعي',
      label_en: 'Awareness',
      headline_metrics: ['spend', 'impressions'],
      spend: 8_000,
      comparable: true,
      comparable_reason: 'two_or_more_platforms_spent',
      platforms: [{ provider: 'meta', spend: 8_000, impressions: 900_000, clicks: 0, landing_page_views: 0, orders: 0, revenue: 0, campaigns: 2, spend_share: 1 }],
    },
  ],
  cross_path_comparison: false,
  cross_path_reason_ar: 'المنصات لا تُقارن عبر المسارات.',
  cross_path_reason_en: 'Platforms are not compared across paths.',
}

const LEADERS = { paths: [], cross_path_comparison: false }
const TREND = { paths: [] }

/** What the endpoint actually replies when the reader has filtered to one objective. */
const DECLINED = { applied: ['provider', 'campaign'], unapplied: ['objective'] }

function route(scope: { applied: string[]; unapplied: string[] } | undefined) {
  const body = (url: string) => {
    if (url.includes('platform-objectives')) return PATHS
    if (url.includes('objective-leaders')) return LEADERS
    if (url.includes('objective-explanations')) return { paths: [] }
    if (url.includes('objective-trend')) return TREND
    if (url.includes('disclaimer')) return null
    if (url.includes('/summary')) return { current: {}, previous: {}, delta: {}, currency: 'SAR' }
    return []
  }

  vi.mocked(getData).mockImplementation((url: string) => body(url) as never)
  vi.mocked(getEnvelope).mockImplementation(
    (url: string) => ({ data: body(url), meta: scope ? { filter_scope: scope } : {}, message: null, success: true }) as never,
  )
}

async function open(tab: RegExp) {
  renderWithProviders(<AnalyticsPage />, { locale: 'en' })
  fireEvent.click(await screen.findByRole('tab', { name: tab }))
}

describe('an objective panel that could not honour the objective chip says so', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  it('names the declined axis on the platform contribution block', async () => {
    route(DECLINED)
    await open(/Platforms/i)

    expect(await screen.findByTestId('platform-objectives-scope')).toHaveTextContent(/objective does not narrow it/i)
  })

  it('names the declined axis on the per-path reading', async () => {
    route(DECLINED)
    await open(/Platforms/i)

    expect(await screen.findByTestId('objective-leaders-scope')).toHaveTextContent(/objective does not narrow it/i)
  })

  it('names the declined axis on the objective trend', async () => {
    route(DECLINED)
    await open(/Objectives/i)

    expect(await screen.findByTestId('objective-trend-scope')).toHaveTextContent(/objective does not narrow it/i)
  })

  /*
   * The note must be ABSENT when nothing was declined, or it is decoration rather than a warning —
   * and a sentence that is always on screen is one nobody reads when it finally matters.
   */
  it('says nothing when every axis the reader set was applied', async () => {
    route({ applied: ['provider', 'campaign'], unapplied: [] })
    await open(/Platforms/i)

    await screen.findByRole('tab', { name: /Platforms/i })
    expect(screen.queryByTestId('platform-objectives-scope')).toBeNull()
    expect(screen.queryByTestId('objective-leaders-scope')).toBeNull()
  })
})
