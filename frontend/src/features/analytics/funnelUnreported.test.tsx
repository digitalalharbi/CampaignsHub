import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { AnalyticsPage } from './AnalyticsPage'
import { ConversionFunnelChart } from './charts'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

/**
 * FUNNEL-NULL-001 — «لم تُرسل» and «صفر» are different sentences, and the funnel must say which.
 *
 * Every stage used to arrive as `COALESCE(SUM(…), 0)`, so a platform that does not count basket adds
 * and a platform that counted zero of them drew the same bar. A client reading «0 add to cart» beside
 * 176 purchases concludes the funnel is broken, or that we are — and what they are actually reading is
 * a sentence about what the platform sends.
 *
 * Both cases are in one fixture on purpose: `add_to_cart` is a genuine measured zero and must survive
 * as zero, while `landing_page_views` and `checkout` were never sent and must not be drawn at all.
 */

function stage(over: Record<string, unknown>) {
  return { reported: true, from_stage: null, step_rate: null, drop_off: null, cost_per: null, ...over }
}

/** A funnel with a real zero and two stages nobody reported. */
const FUNNEL = [
  stage({ stage: 'impressions', label: 'Impressions', count: 83820, cost_per: 0.01 }),
  stage({ stage: 'clicks', label: 'Clicks', count: 2934, from_stage: 'impressions', step_rate: 0.035, drop_off: 0.965, cost_per: 0.34 }),
  // Never sent — null, and the old code would have drawn these as 0.
  stage({ stage: 'landing_page_views', label: 'Landing Page View', reported: false, count: null }),
  // Sent, and genuinely zero.
  stage({ stage: 'add_to_cart', label: 'Add to Cart', count: 0, from_stage: 'clicks', step_rate: 0, drop_off: 1 }),
  stage({ stage: 'checkout', label: 'Checkout', reported: false, count: null }),
  stage({ stage: 'conversions', label: 'Purchase', count: 176, from_stage: 'add_to_cart', cost_per: 5.68 }),
]

function route(funnel: unknown[] = FUNNEL) {
  vi.mocked(getData).mockImplementation((path: string) => {
    if (path.includes('/metrics/funnel')) return Promise.resolve(funnel)
    if (path.includes('disclaimer')) return Promise.resolve(null)
    return Promise.resolve([])
  })
}

describe('the conversion funnel tells silence from zero', () => {
  beforeEach(() => {
    signInWith(['campaigns.view'])
    // Every metric query is `enabled: Boolean(projectId)`; without one the page renders empty and
    // the assertions below would pass for the wrong reason.
    useProject.getState().setCurrentProjectId('p1')
  })

  afterEach(() => {
    signOut()
    useProject.getState().setCurrentProjectId(null)
    vi.clearAllMocks()
  })

  it('draws no bar for a stage no platform reported, and says why', async () => {
    route()

    renderWithProviders(<AnalyticsPage />, { locale: 'en', route: '/app/analytics' })
    fireEvent.click(screen.getByRole('tab', { name: 'Funnel' }))

    // The two nobody sent: named as unreported, and carrying no figure at all.
    for (const key of ['landing_page_views', 'checkout']) {
      const row = await screen.findByTestId(`ad-funnel-stage-${key}`)
      expect(within(row).getByTestId(`ad-funnel-unreported-${key}`)).toBeInTheDocument()
      expect(row).not.toHaveTextContent(/\b0\b/)
    }

    // Said once in a sentence too, so a reader scanning the bars is told what the gaps are.
    const note = screen.getByTestId('ad-funnel-unreported-note')
    expect(note).toHaveTextContent(/Landing Page View/)
    expect(note).toHaveTextContent(/Checkout/)
    expect(note).toHaveTextContent(/A gap is not a zero/)
  })

  it('keeps a measured zero as a zero', async () => {
    route()

    renderWithProviders(<AnalyticsPage />, { locale: 'en', route: '/app/analytics' })
    fireEvent.click(screen.getByRole('tab', { name: 'Funnel' }))

    const row = await screen.findByTestId('ad-funnel-stage-add_to_cart')
    // The platform said zero. That is a measurement and it must still be shown as one.
    expect(row).toHaveTextContent('0')
    expect(screen.queryByTestId('ad-funnel-unreported-add_to_cart')).not.toBeInTheDocument()
    // And it must not be named among the stages nobody reported.
    expect(screen.getByTestId('ad-funnel-unreported-note')).not.toHaveTextContent(/Add to Cart/)
  })

  it('says nothing about unreported stages when every stage was reported', async () => {
    route(FUNNEL.filter((s) => s.reported))

    renderWithProviders(<AnalyticsPage />, { locale: 'en', route: '/app/analytics' })
    fireEvent.click(screen.getByRole('tab', { name: 'Funnel' }))

    await screen.findByTestId('ad-funnel-stage-impressions')
    expect(screen.queryByTestId('ad-funnel-unreported-note')).not.toBeInTheDocument()
  })

  /**
   * The shared chart, which the campaign command centre, the interactive report, the client's live
   * link and the creative detail page all render through. `null / top` is `0` in JavaScript, so the
   * previous version drew the minimum-width bar with «—» inside it for a stage nobody sent — a bar
   * being, to any reader, a measurement.
   */
  it('the shared chart draws no bar for a null count', () => {
    renderWithProviders(
      <ConversionFunnelChart
        stages={[
          { label: 'Impressions', count: 1000, step_rate: null, cost_per: null },
          { label: 'Add to Cart', count: null, step_rate: null, cost_per: null },
        ]}
      />,
      { locale: 'en' },
    )

    expect(screen.getByText('This stage was never reported')).toBeInTheDocument()
    expect(screen.queryByText('—')).not.toBeInTheDocument()
  })

  /** The same chart, scaled against the largest REPORTED count — an unreported first stage would
   *  otherwise make `top` undefined and collapse every bar below it. */
  it('the shared chart still scales when the top stage is the missing one', () => {
    renderWithProviders(
      <ConversionFunnelChart
        stages={[
          { label: 'Impressions', count: null, step_rate: null, cost_per: null },
          { label: 'Clicks', count: 400, step_rate: null, cost_per: null },
        ]}
        ar
      />,
      { locale: 'ar' },
    )

    expect(screen.getByText('لم ترسل المنصة هذه المرحلة')).toBeInTheDocument()
    expect(screen.getByText('400')).toBeInTheDocument()
  })

  /**
   * UX-COPY-001 — the Arabic funnel names its stages in Arabic.
   *
   * `MetricsAggregator::funnel()` labels its stages in English and only in English, and every
   * surface printed that field straight out. So the most-read chart in the product had «Impressions
   * / Add to Cart / Purchase» down its left edge on an Arabic page, under an Arabic heading, beside
   * Arabic percentages. Found by looking at the page in Arabic — no assertion anywhere covered the
   * left-hand column, so the whole suite passed while it was untranslated.
   */
  it('names the funnel stages in Arabic on an Arabic page', () => {
    renderWithProviders(
      <ConversionFunnelChart
        stages={[
          { stage: 'impressions', label: 'Impressions', count: 1000, step_rate: null, cost_per: null },
          { stage: 'add_to_cart', label: 'Add to Cart', count: 40, step_rate: null, cost_per: null },
        ]}
        ar
      />,
      { locale: 'ar' },
    )

    expect(screen.getByText('الظهور')).toBeInTheDocument()
    expect(screen.getByText('الإضافة إلى السلة')).toBeInTheDocument()
    expect(screen.queryByText('Impressions')).not.toBeInTheDocument()
    expect(screen.queryByText('Add to Cart')).not.toBeInTheDocument()
  })

  /** A stage this map has not met falls back to the server's label rather than to its raw key. */
  it('falls back to the payload label for an unknown stage', () => {
    renderWithProviders(
      <ConversionFunnelChart
        stages={[{ stage: 'subscriptions', label: 'Subscriptions', count: 7, step_rate: null, cost_per: null }]}
        ar
      />,
      { locale: 'ar' },
    )

    expect(screen.getByText('Subscriptions')).toBeInTheDocument()
    expect(screen.queryByText('subscriptions')).not.toBeInTheDocument()
  })
})
