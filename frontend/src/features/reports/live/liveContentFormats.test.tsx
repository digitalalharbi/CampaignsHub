import { beforeAll, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { FormatComparisonView } from '@/features/content/CreativeFormatComparison'
import type { FormatIntelligencePayload } from '@/features/content/api'

/**
 * CREATIVE-FORMAT-INTELLIGENCE-001 in a client link — «أداء أنواع المحتوى».
 *
 * The client's report carries the answer rather than fetching it: the reader has no session and no
 * project endpoint to call. One component draws both, which is what makes «the same numbers
 * everywhere» true by construction rather than by discipline — so what is tested here is that the
 * CARRIED payload renders, and that a summary draws less of it without drawing anything different.
 */
vi.mock('@tanstack/react-query', async (importOriginal) => await importOriginal())

const payload = (over: Partial<FormatIntelligencePayload> = {}): FormatIntelligencePayload => ({
  period: { from: '2026-09-01', to: '2026-09-30' },
  scope: { project_id: 'p1', external_account_id: null, campaign_id: null },
  // A client is never told which of the agency's ad accounts carried the work.
  accounts: [],
  coverage: { video: { creatives: 6, with_metrics: 6, without_metrics: 0 }, image: { creatives: 6, with_metrics: 6, without_metrics: 0 } },
  spend_mix: {
    formats: [{ format: 'video', spend: 900, share: 0.75 }, { format: 'image', spend: 300, share: 0.25 }],
    total: 1200,
    complete: true,
  },
  objectives: [{
    family: 'sales',
    label: { ar: 'المبيعات', en: 'Sales' },
    comparison: {
      metric: 'cpa', lower_is_better: true, objective: 'sales',
      formats: [
        { format: 'video', value: 10, spend: 900, creatives: 6 },
        { format: 'image', value: 30, spend: 300, creatives: 6 },
      ],
      best: 'video', worst: 'image',
      share_of_spend_not_on_the_leading_format: 0.25,
      why_no_spend_share: null,
      too_few_to_speak_for_their_format: [],
      refusal: null,
    } as never,
    evidence: 'high',
  }],
  ...over,
})

describe('the format comparison a client receives', () => {
  it('draws the carried answer with no request of its own', () => {
    renderWithProviders(<FormatComparisonView payload={payload()} depth="full" ar />, { locale: 'ar' })

    expect(screen.getByTestId('creative-format-comparison')).toBeInTheDocument()
    expect(screen.getByTestId('format-verdict-sales')).toHaveTextContent('الفيديو')
    // The donut's centre figure — the total the shares are read against. Its slices are SVG from the
    // product's chart layer, which has no size in jsdom.
    expect(screen.getByTestId('format-spend-mix')).toHaveTextContent(/1[,.]?2/)
  })

  /** The agency's accounts are its own arrangement — the server sends none, and none is drawn. */
  it('names no ad account to the client', () => {
    renderWithProviders(<FormatComparisonView payload={payload()} depth="full" ar />, { locale: 'ar' })

    expect(screen.queryByTestId('format-comparison-accounts')).toBeNull()
  })

  /**
   * A summary draws LESS, never anything different.
   *
   * The verdict and the figure it rests on are the same ones the detailed report carries; what the
   * summary leaves out is the table and the mix.
   */
  it('draws the compact answer for an executive summary', () => {
    renderWithProviders(<FormatComparisonView payload={payload()} depth="compact" ar />, { locale: 'ar' })

    expect(screen.getByTestId('format-verdict-sales')).toHaveTextContent('الفيديو')
    expect(screen.queryByTestId('format-table-sales')).toBeNull()
    expect(screen.queryByTestId('format-spend-mix')).toBeNull()
  })

  /** A link that hides spend sends no mix, and the block says so rather than drawing an empty bar. */
  it('says the mix is unavailable when the link hides spend', () => {
    renderWithProviders(
      <FormatComparisonView
        payload={payload({ spend_mix: { formats: [], total: null, complete: false } })}
        depth="full"
        ar
      />,
      { locale: 'ar' },
    )

    expect(screen.queryByTestId('format-spend-mix')).toBeNull()
    expect(screen.getByTestId('format-verdict-sales')).toHaveTextContent('الفيديو')
  })
})

/**
 * REPORT-EXPORT-PARITY — the PDF prints the figures the page drew.
 *
 * Both read `content_formats` off the same payload and neither recomputes: the print renderer has
 * its own stylesheet and no Tailwind, so what is shared is the DATA rather than the component, which
 * is the half that has to agree. The first time a page and the file a client keeps disagreed, the
 * client would be holding the evidence.
 */
describe('the printed format section', () => {
  /*
    `document.fonts` does not exist in jsdom and the document awaits `fonts.ready` before it declares
    itself printable — the same stub every other print test here uses.
  */
  beforeAll(() => {
    if (!(document as Document & { fonts?: unknown }).fonts) {
      Object.defineProperty(document, 'fonts', { value: { ready: Promise.resolve() }, configurable: true })
    }
  })

  it('prints the same verdict and the same figures as the page', async () => {
    const { PrintDocument } = await import('../PrintDocument')
    const data = { content_formats: payload(), report_sections: ['content_performance'] } as never

    renderWithProviders(
      <PrintDocument data={data} reportName="R" currency="SAR" />,
      { locale: 'en' },
    )

    expect(screen.getByText('Content format performance')).toBeInTheDocument()
    // The verdict the page shows, in the document's own language.
    expect(screen.getByText(/Video performs better/)).toBeInTheDocument()
    // And the figure behind it, unrounded into the same two decimals.
    expect(screen.getByText('10.00')).toBeInTheDocument()
  })

  /** A withheld amount prints «—», never a zero the account never spent. */
  it('prints a withheld spend as unavailable', async () => {
    const { PrintDocument } = await import('../PrintDocument')
    const withheld = payload()
    withheld.objectives[0].comparison.formats[0].spend = null

    renderWithProviders(
      <PrintDocument data={{ content_formats: withheld, report_sections: ['content_performance'] } as never} reportName="R" currency="SAR" />,
      { locale: 'en' },
    )

    expect(screen.getAllByText('—').length).toBeGreaterThan(0)
  })
})
