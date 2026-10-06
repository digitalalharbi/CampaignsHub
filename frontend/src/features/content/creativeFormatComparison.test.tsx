import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { CreativeFormatComparison } from './CreativeFormatComparison'
import { renderWithProviders } from '@/test/utils'
import type { FormatIntelligencePayload } from './api'

/**
 * CREATIVE-FORMAT-INTELLIGENCE-001 — one module, three depths, one set of numbers.
 *
 * The figures are identical at every depth because they are the same server answer; only how much is
 * drawn changes. That is what stops a dashboard, a campaign page and a client's report quietly
 * disagreeing about one advertiser.
 */
const state = vi.hoisted(() => ({ payload: {} as FormatIntelligencePayload }))

vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  formatIntelligence: () => Promise.resolve(state.payload),
}))

const comparison = (over: Record<string, unknown> = {}) => ({
  metric: 'cpa',
  lower_is_better: true,
  objective: 'sales',
  formats: [
    { format: 'video', value: 10, spend: 900, creatives: 6 },
    { format: 'image', value: 30, spend: 300, creatives: 6 },
  ],
  best: 'video',
  worst: 'image',
  share_of_spend_not_on_the_leading_format: 0.25,
  why_no_spend_share: null,
  too_few_to_speak_for_their_format: [],
  refusal: null,
  ...over,
})

const payload = (over: Partial<FormatIntelligencePayload> = {}): FormatIntelligencePayload => ({
  period: { from: '2026-09-01', to: '2026-09-30' },
  scope: { project_id: 'p1', external_account_id: null, campaign_id: null },
  accounts: [{ id: 'a1', name: 'Meta Account A', provider: 'meta' }],
  coverage: {
    video: { creatives: 34, with_metrics: 27, without_metrics: 7 },
    image: { creatives: 18, with_metrics: 14, without_metrics: 4 },
  },
  spend_mix: {
    formats: [
      { format: 'video', spend: 900, share: 0.75 },
      { format: 'image', spend: 300, share: 0.25 },
    ],
    total: 1200,
    complete: true,
  },
  objectives: [
    { family: 'sales', label: { ar: 'المبيعات', en: 'Sales' }, comparison: comparison() as never, evidence: 'high' },
  ],
  ...over,
})

const show = async (depth: 'compact' | 'medium' | 'full') => {
  renderWithProviders(<CreativeFormatComparison projectId="p1" depth={depth} ar />, { locale: 'ar' })

  return screen.findByTestId('creative-format-comparison')
}

describe('the creative format comparison', () => {
  beforeEach(() => { state.payload = payload() })

  /** The answer, the cost and the evidence base — the four things a decision needs. */
  it('shows each format’s figure, its evidence base and the verdict', async () => {
    await show('full')

    expect(screen.getByTestId('format-verdict-sales')).toHaveTextContent('الفيديو')

    /*
      The figure, what it cost and the evidence base all live on the product's one labelled figure
      now, so they are asserted on the card rather than on a hint this module used to draw itself.
    */
    const card = screen.getByTestId('format-card-video')

    expect(card).toHaveTextContent('34')
    expect(card).toHaveTextContent('27')
    expect(card).toHaveTextContent('900')
    expect(screen.getByTestId('format-evidence-high')).toBeInTheDocument()
  })

  /**
   * Spend share is never the verdict — both are shown, and they are different statements.
   *
   * Asserted on the donut's own centre figure rather than on a slice: the slices are SVG drawn by
   * the product's chart layer, which has no size in jsdom, and a test that reached into them would
   * be testing Recharts rather than this module. The total is DOM text and it is the number the
   * reader checks the shares against.
   */
  it('shows where the money is, beside what it bought', async () => {
    await show('full')

    const mix = screen.getByTestId('format-spend-mix')

    expect(mix).toBeInTheDocument()
    expect(mix).toHaveTextContent('1200')
    expect(screen.getByText(/توزيع الإنفاق/)).toBeInTheDocument()
  })

  /** A proportion over an incomplete total is not a proportion. */
  it('says the spend mix is unavailable rather than inventing one', async () => {
    state.payload = payload({
      spend_mix: { formats: [{ format: 'video', spend: null, share: null }], total: null, complete: false },
    })

    await show('full')

    expect(screen.getByTestId('format-spend-mix-unavailable')).toBeInTheDocument()
    expect(screen.queryByTestId('format-spend-mix')).toBeNull()
  })

  /** The refusal survives: insufficient evidence names no winner. */
  it('refuses to crown a format on insufficient evidence', async () => {
    state.payload = payload({
      objectives: [{
        family: 'sales',
        label: { ar: 'المبيعات', en: 'Sales' },
        comparison: comparison({ refusal: 'only_one_format_ran_enough_to_compare', best: null, formats: [] }) as never,
        evidence: 'insufficient',
      }],
    })

    await show('full')

    expect(screen.getByTestId('format-verdict-sales')).toHaveTextContent('لا توجد أدلة كافية')
    expect(screen.getByTestId('format-evidence-insufficient')).toBeInTheDocument()
  })

  /** Mixed objectives are drawn separately — never one blended ranking. */
  it('draws one block per objective at full depth', async () => {
    state.payload = payload({
      objectives: [
        { family: 'sales', label: { ar: 'المبيعات', en: 'Sales' }, comparison: comparison() as never, evidence: 'high' },
        { family: 'traffic', label: { ar: 'الزيارات', en: 'Traffic' }, comparison: comparison({ best: 'image', metric: 'ctr' }) as never, evidence: 'moderate' },
      ],
    })

    await show('full')

    expect(screen.getByTestId('format-objective-sales')).toBeInTheDocument()
    expect(screen.getByTestId('format-objective-traffic')).toBeInTheDocument()
  })

  /**
   * Compact draws ONE objective and no table — and the figures it does draw are the same ones.
   *
   * Depth is a presentation decision. A compact surface that recomputed anything would be the second
   * answer this module exists to prevent.
   */
  it('draws one objective and no table when compact', async () => {
    state.payload = payload({
      objectives: [
        { family: 'sales', label: { ar: 'المبيعات', en: 'Sales' }, comparison: comparison() as never, evidence: 'high' },
        { family: 'traffic', label: { ar: 'الزيارات', en: 'Traffic' }, comparison: comparison() as never, evidence: 'high' },
      ],
    })

    await show('compact')

    expect(screen.getByTestId('format-objective-sales')).toBeInTheDocument()
    expect(screen.queryByTestId('format-objective-traffic')).toBeNull()
    expect(screen.queryByTestId('format-table-sales')).toBeNull()
    expect(screen.queryByTestId('format-spend-mix')).toBeNull()
    // Still the same verdict and the same figure as the full depth.
    expect(screen.getByTestId('format-verdict-sales')).toHaveTextContent('الفيديو')
  })

  /** A project rollup names the accounts it blends, so it is not mistaken for one advertiser. */
  it('names the accounts the answer is about', async () => {
    await show('medium')

    expect(screen.getByTestId('format-comparison-accounts')).toHaveTextContent('Meta Account A')
  })

  /** A format held out for being one asset is named rather than silently dropped. */
  it('names a format that was too small to judge', async () => {
    state.payload = payload({
      objectives: [{
        family: 'sales',
        label: { ar: 'المبيعات', en: 'Sales' },
        comparison: comparison({ too_few_to_speak_for_their_format: [{ format: 'carousel', creatives: 1 }] }) as never,
        evidence: 'high',
      }],
    })

    await show('full')

    expect(screen.getByTestId('format-too-few-sales')).toHaveTextContent('الدوارة')
  })

  /** A withheld spend prints «—», never a zero the account never spent. */
  it('prints a withheld spend as unavailable in the exact table', async () => {
    state.payload = payload({
      objectives: [{
        family: 'sales',
        label: { ar: 'المبيعات', en: 'Sales' },
        comparison: comparison({
          formats: [
            { format: 'video', value: 10, spend: null, creatives: 6 },
            { format: 'image', value: 30, spend: 300, creatives: 6 },
          ],
        }) as never,
        evidence: 'high',
      }],
    })

    await show('full')

    expect(screen.getByTestId('format-table-sales')).toHaveTextContent('—')
  })

  /**
   * The comparison is drawn as well as said.
   *
   * The bars carry the ONE metric the verdict was decided on, so the picture and the sentence cannot
   * say different things — the failure mode of every dashboard that charts whatever is handy.
   */
  it('charts the comparison on the metric the verdict used', async () => {
    await show('full')

    expect(screen.getByTestId('format-bars-sales')).toBeInTheDocument()
    expect(screen.getByText(/المقارنة على/)).toBeInTheDocument()
  })

  /** Two bars of one value each is a decoration — a single format is not charted. */
  it('draws no comparison chart when there is nothing to compare', async () => {
    state.payload = payload({
      objectives: [{
        family: 'sales',
        label: { ar: 'المبيعات', en: 'Sales' },
        comparison: comparison({ formats: [{ format: 'video', value: 10, spend: 900, creatives: 6 }] }) as never,
        evidence: 'high',
      }],
    })

    await show('full')

    expect(screen.queryByTestId('format-bars-sales')).toBeNull()
  })

  /**
   * The trend answers whether the advantage is STRENGTHENING, on the same metric.
   *
   * Drawn only at full depth and only with at least two points: one point is a reading, not a
   * direction.
   */
  it('charts the trend on the deciding metric at full depth', async () => {
    state.payload = payload({
      trend: {
        metric: 'cpa',
        lower_is_better: true,
        points: [
          { from: '2026-09-01', to: '2026-09-07', video: 14, image: 31 },
          { from: '2026-09-08', to: '2026-09-14', video: 10, image: 30 },
        ],
      },
    })

    await show('full')

    expect(screen.getByTestId('format-trend')).toBeInTheDocument()
    // Which direction is good is stated, because «lower is better» is not guessable from a line.
    expect(screen.getByText(/الأقل أفضل/)).toBeInTheDocument()
  })

  it('draws no trend from a single point', async () => {
    state.payload = payload({
      trend: { metric: 'cpa', lower_is_better: true, points: [{ from: '2026-09-01', to: '2026-09-07', video: 14 }] },
    })

    await show('full')

    expect(screen.queryByTestId('format-trend')).toBeNull()
  })

  /** A compact surface draws neither chart — it is one answer among many on that page. */
  it('draws no charts when compact', async () => {
    state.payload = payload({
      trend: {
        metric: 'cpa',
        lower_is_better: true,
        points: [
          { from: '2026-09-01', to: '2026-09-07', video: 14, image: 31 },
          { from: '2026-09-08', to: '2026-09-14', video: 10, image: 30 },
        ],
      },
    })

    await show('compact')

    expect(screen.queryByTestId('format-bars-sales')).toBeNull()
    expect(screen.queryByTestId('format-trend')).toBeNull()
    expect(screen.queryByTestId('format-spend-mix')).toBeNull()
    // The answer itself survives: depth removes working, never the verdict.
    expect(screen.getByTestId('format-verdict-sales')).toHaveTextContent('الفيديو')
  })

  it('says nothing ran rather than drawing an empty comparison', async () => {
    state.payload = payload({ objectives: [] })

    renderWithProviders(<CreativeFormatComparison projectId="p1" ar />, { locale: 'ar' })

    expect(await screen.findByTestId('format-comparison-empty')).toBeInTheDocument()
  })
})
