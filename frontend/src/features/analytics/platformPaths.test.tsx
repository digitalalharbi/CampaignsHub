import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { AnalyticsPage } from './AnalyticsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

/**
 * PLATFORM-DECISION-ANALYTICS-001 — «which platform is contributing most to THIS objective».
 *
 * The Platforms tab compared platforms with one set of figures over every objective at once. Read as
 * a ranking, that compares a platform buying awareness against one buying sales — a verdict about
 * the work each was given rather than about how either did it.
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
      platforms: [
        /*
          Revenue on an AWARENESS row is not a mistake in the fixture — it is what the aggregator
          reports when a sale gets credited to an impression nobody was asked to buy. The page must
          refuse to price it as a return; that refusal is what this figure is here to exercise.
        */
        { provider: 'meta', spend: 6_000, impressions: 900_000, clicks: 0, landing_page_views: 0, orders: 0, revenue: 90_000, campaigns: 2, spend_share: 0.75 },
        { provider: 'tiktok', spend: 2_000, impressions: 400_000, clicks: 0, landing_page_views: 0, orders: 0, revenue: 0, campaigns: 1, spend_share: 0.25 },
      ],
    },
    {
      path: 'conversion',
      label_ar: 'التحويل',
      label_en: 'Conversion',
      headline_metrics: ['spend', 'orders'],
      spend: 5_000,
      comparable: false,
      comparable_reason: 'only_one_platform_spent',
      platforms: [
        { provider: 'snapchat', spend: 5_000, impressions: 0, clicks: 0, landing_page_views: 0, orders: 90, revenue: 40_000, campaigns: 1, spend_share: 1 },
      ],
    },
  ],
  cross_path_comparison: false,
  cross_path_reason_ar: 'المنصات لا تُقارن عبر المسارات.',
  cross_path_reason_en: 'Platforms are not compared across paths: one buying awareness and one buying sales are not better or worse than each other.',
}

function route() {
  vi.mocked(getData).mockImplementation((url: string) => {
    if (url.includes('platform-objectives')) return PATHS as never
    if (url.includes('disclaimer')) return null as never
    if (url.includes('/summary')) return { current: {}, previous: {}, delta: {}, currency: 'SAR' } as never
    return [] as never
  })
}

async function openPlatforms() {
  renderWithProviders(<AnalyticsPage />, { locale: 'en' })
  fireEvent.click(await screen.findByRole('tab', { name: /Platforms/i }))
}

describe('platform contribution, inside each path', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  it('shows each platform’s share OF THE PATH, not of the account', async () => {
    route()
    await openPlatforms()

    const awareness = await screen.findByTestId('platform-path-awareness')

    expect(awareness).toHaveTextContent('75%')
    expect(awareness).toHaveTextContent('25%')
    // 6,000 of 8,000 on this path — not 6,000 of the 13,000 the account spent.
    expect(awareness).not.toHaveTextContent('46%')
  })

  /**
   * A path one platform ran shows its figures and says there is no comparison.
   *
   * «Snapchat is the best platform for conversion», said of the only platform that ran conversion,
   * is a sentence with no evidence behind it — and it is exactly what a reader writes for themselves
   * when handed a sorted list of one.
   */
  it('says why a one-platform path is not a comparison', async () => {
    route()
    await openPlatforms()

    expect(await screen.findByTestId('platform-path-conversion-not-comparable'))
      .toHaveTextContent('Only one platform spent on this path')
    expect(screen.queryByTestId('platform-path-awareness-not-comparable')).toBeNull()
  })

  /**
   * The cost each path was actually paying, and no column for the ones it was not.
   *
   * A fixed four-column efficiency block breaks this requirement's hard constraint one column at a
   * time: two of them are «—» on every row, and the reader compares the two that are populated.
   * Awareness is priced by the thousand impressions; a conversion path by the result, with the
   * return beside it because that is the only path where returning is what the money was for.
   */
  it('prices each path by what it was buying, and nothing else', async () => {
    route()
    await openPlatforms()

    const awareness = await screen.findByTestId('path-efficiency-awareness-meta')
    expect(awareness).toHaveTextContent(/Cost per 1,000/)
    // 6,000 over 900,000 impressions — 6.67 per thousand.
    expect(awareness).toHaveTextContent(/6\.6[0-9]/)
    // Never a return on a path nobody was buying returns on.
    expect(screen.getByTestId('platform-path-awareness')).not.toHaveTextContent(/[0-9]\.[0-9]{2}[x×]/)

    const conversion = await screen.findByTestId('path-efficiency-conversion-snapchat')
    expect(conversion).toHaveTextContent(/Cost per result/)
    // The glyph belongs to `ratio()` and is held by `formatGlyphs.test.ts`; this is about the figure.
    expect(screen.getByTestId('platform-path-conversion')).toHaveTextContent(/8\.00/)
  })

  /**
   * A platform three days short in this window is not a platform that performed worse.
   *
   * The share above it says nothing about which of the two it is, and sending the reader to another
   * tab to find out means they will read the ranking instead. So the gap is stated here.
   */
  it('marks a platform whose window is incomplete, where the comparison is made', async () => {
    vi.mocked(getData).mockImplementation((url: string) => {
      if (url.includes('platform-objectives')) return PATHS as never
      if (url.includes('freshness')) {
        return [
          { kind: 'ad_platform', provider: 'tiktok', account_id: 'a1', name: 'TikTok', latest_metric_date: '2026-08-27', data_freshness_at: null, days_with_data: 27, missing_days: 3, last_sync_status: 'fresh', last_sync_at: null, last_sync_error: null },
        ] as never
      }
      if (url.includes('disclaimer')) return null as never
      if (url.includes('/summary')) return { current: {}, previous: {}, delta: {}, currency: 'SAR' } as never
      return [] as never
    })
    await openPlatforms()

    expect(await screen.findByTestId('path-gap-awareness-tiktok')).toHaveTextContent('3 days missing')
    expect(screen.queryByTestId('path-gap-awareness-meta')).toBeNull()
  })

  /** And the page says outright that platforms are not compared across paths. */
  it('states the refusal on the page, not only in the payload', async () => {
    route()
    await openPlatforms()

    expect(await screen.findByTestId('platform-paths-cross'))
      .toHaveTextContent('not compared across paths')
  })
})
