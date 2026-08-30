import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import { PathAnalysis } from './PathAnalysis'
import { renderWithProviders } from '@/test/utils'
import type { PathExplanation, PathLeaders, PathPlatforms } from './api'

/**
 * OBJECTIVE-ANALYTICS-DEPTH-001 · PLATFORM-DECISION-ANALYTICS-001 · FUNNEL-ANALYTICAL-PATTERN-001
 *
 * The acceptance claims are mostly about what this surface REFUSES to say. A ranking across paths, a
 * «best platform» from incomparable objectives, a strongest-of-one wearing a superlative, an action
 * with no evidence behind it — each of those is a sentence the product would be inventing, and each
 * of them reads exactly like a measured one.
 */
const platforms = (over: Partial<PathPlatforms> = {}): PathPlatforms => ({
  path: 'awareness',
  label_ar: 'الوعي',
  label_en: 'Awareness',
  headline_metrics: ['impressions', 'cpm'],
  platforms: [
    {
      provider: 'meta',
      spend: 6_000,
      impressions: 900_000,
      clicks: 4_000,
      landing_page_views: 0,
      orders: 0,
      revenue: 0,
      campaigns: 2,
      spend_share: 0.6,
    },
    {
      provider: 'tiktok',
      spend: 4_000,
      impressions: 700_000,
      clicks: 2_000,
      landing_page_views: 0,
      orders: 0,
      revenue: 0,
      campaigns: 1,
      spend_share: 0.4,
    },
  ],
  spend: 10_000,
  comparable: true,
  comparable_reason: 'two_or_more_platforms_spent',
  ...over,
})

const leaders = (over: Partial<PathLeaders> = {}): PathLeaders => ({
  path: 'awareness',
  label_ar: 'الوعي',
  label_en: 'Awareness',
  metric: 'cpm',
  comparable: true,
  comparable_reason: 'two_or_more_campaigns_spent',
  strongest: { id: 'c1', name: 'Ramadan Reach', objective: 'awareness', metric: 'cpm', value: 12.5 },
  weakest: { id: 'c2', name: 'Always-On Reach', objective: 'awareness', metric: 'cpm', value: 41.2 },
  campaigns: 3,
  ...over,
})

const explanation = (over: Partial<PathExplanation> = {}): PathExplanation => ({
  path: 'awareness',
  label_ar: 'الوعي',
  label_en: 'Awareness',
  signal: {
    metric: 'cpm',
    best: { campaign: 'Ramadan Reach', value: 12.5 },
    worst: { campaign: 'Always-On Reach', value: 41.2 },
  },
  context: { scope: 'awareness', campaigns: 3, from: '2026-08-01', to: '2026-08-30' },
  explanation: {
    ar: 'الحملتان اشتُريتا لنفس الغرض على هذا المسار.',
    en: 'Both campaigns were bought for the same thing on this path.',
  },
  evidence: ['spend', 'cpm'],
  action: {
    ar: 'قارن «Always-On Reach» بـ«Ramadan Reach».',
    en: 'Compare «Always-On Reach» against «Ramadan Reach».',
  },
  silent_reason: null,
  ...over,
})

const render = (
  p: PathPlatforms[] = [platforms()],
  l: PathLeaders[] = [leaders()],
  e: PathExplanation[] = [explanation()],
  locale: 'ar' | 'en' = 'en',
) =>
  renderWithProviders(
    <PathAnalysis locale={locale} currency="SAR" platforms={p} leaders={l} explanations={e} />,
    { locale },
  )

describe('what a path says about its platforms', () => {
  it('states each platform’s share of THIS path, not of the project', () => {
    render()

    const meta = screen.getByTestId('path-awareness-platform-meta')
    expect(meta).toHaveTextContent('6K SAR')
    expect(meta).toHaveTextContent('60%')
  })

  /** The sentence a «best platform» card would have to contradict, said once and out loud. */
  it('says paths are never compared with each other', () => {
    render()

    expect(screen.getByTestId('path-analysis-never')).toHaveTextContent(
      'Paths are never compared with each other',
    )
  })

  it('says a path nobody ran spent nothing, rather than showing an empty chart', () => {
    render([platforms({ platforms: [], spend: 0, comparable: false, comparable_reason: 'nothing_spent_on_this_path' })])

    expect(screen.getByTestId('path-awareness-empty')).toHaveTextContent('Nothing was spent on this path')
  })
})

describe('the strongest and the weakest, or the reason there is neither', () => {
  it('names both ends with the path’s own metric', () => {
    render()

    expect(within(screen.getByTestId('path-awareness-strongest')).getByText('Ramadan Reach')).toBeInTheDocument()
    expect(screen.getByTestId('path-awareness-strongest')).toHaveTextContent('Cost per 1K impressions')
    expect(within(screen.getByTestId('path-awareness-weakest')).getByText('Always-On Reach')).toBeInTheDocument()
  })

  /**
   * `comparable` is the authority, not the presence of a row.
   *
   * The server decides comparability from what actually SPENT, and it is the only place that can:
   * a payload can carry a strongest campaign and still be marked incomparable — a cached response,
   * a defensive server change, a future scope that keeps the row for its figures. A client that
   * rendered the superlative whenever a row existed would quietly re-introduce «best of one».
   */
  it('honours the comparable flag even when a leader row is present', () => {
    render([platforms()], [leaders({ comparable: false, comparable_reason: 'only_one_campaign_spent' })])

    expect(screen.queryByTestId('path-awareness-strongest')).not.toBeInTheDocument()
    expect(screen.getByTestId('path-awareness-no-comparison')).toBeInTheDocument()
  })

  /**
   * A strongest of one is a figure wearing a superlative.
   *
   * «Your best awareness campaign», said of the only awareness campaign, tells a client nothing they
   * did not know while implying a choice was made between alternatives that did not exist.
   */
  it('refuses to rank one campaign and says why', () => {
    render([platforms()], [leaders({ comparable: false, comparable_reason: 'only_one_campaign_spent', strongest: null, weakest: null })])

    expect(screen.queryByTestId('path-awareness-strongest')).not.toBeInTheDocument()
    expect(screen.getByTestId('path-awareness-no-comparison')).toHaveTextContent(
      'Only one campaign spent on this path',
    )
  })
})

describe('the reading', () => {
  it('gives the signal, the explanation, the evidence and the action', () => {
    render()

    const reading = screen.getByTestId('path-awareness-reading')
    expect(reading).toHaveTextContent('Ramadan Reach')
    expect(reading).toHaveTextContent('Both campaigns were bought for the same thing')
    expect(reading).toHaveTextContent('Based on')
    expect(screen.getByTestId('path-awareness-action')).toHaveTextContent('Compare «Always-On Reach»')
  })

  /**
   * No signal, no action — the reason takes its place.
   *
   * An action offered without evidence is worse than silence: it is the product spending somebody's
   * afternoon on its own guess.
   */
  it('offers no action where there is no signal, and says why instead', () => {
    render(
      [platforms()],
      [leaders()],
      [explanation({ signal: null, context: null, explanation: null, evidence: [], action: null, silent_reason: 'only_one_campaign_spent' })],
    )

    expect(screen.queryByTestId('path-awareness-action')).not.toBeInTheDocument()
    expect(screen.getByTestId('path-awareness-silent')).toHaveTextContent('Only one campaign spent on this path')
  })

  it('reads in Arabic without falling back to the English copy', () => {
    render([platforms()], [leaders()], [explanation()], 'ar')

    expect(screen.getByTestId('path-awareness-action')).toHaveTextContent('قارن')
    expect(screen.getByTestId('path-analysis-never')).toHaveTextContent('المسارات لا تُقارن ببعضها')
  })
})
