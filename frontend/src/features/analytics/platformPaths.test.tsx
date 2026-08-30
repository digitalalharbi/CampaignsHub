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
        { provider: 'meta', spend: 6_000, impressions: 900_000, clicks: 0, landing_page_views: 0, orders: 0, revenue: 0, campaigns: 2, spend_share: 0.75 },
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

  /** And the page says outright that platforms are not compared across paths. */
  it('states the refusal on the page, not only in the payload', async () => {
    route()
    await openPlatforms()

    expect(await screen.findByTestId('platform-paths-cross'))
      .toHaveTextContent('not compared across paths')
  })
})
