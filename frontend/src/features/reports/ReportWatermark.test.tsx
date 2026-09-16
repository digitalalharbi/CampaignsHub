import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { ReportWatermark } from './ReportWatermark'
import { renderWithProviders } from '@/test/utils'

/**
 * The watermark the product PROMISED a client and did not deliver.
 *
 * `report_shares.watermark` was drawn on the share page and announced in the client's own portal as
 * «يحمل علامة مائية / Watermarked», while the PDF — the copy that is downloaded, kept and forwarded,
 * and the one artefact a watermark is for — carried none.
 */
describe('the report watermark', () => {
  it('says the product name in the reader’s language', () => {
    renderWithProviders(<ReportWatermark locale="ar" />, { locale: 'ar' })
    expect(screen.getByText('كامبينز هب')).toBeInTheDocument()

    renderWithProviders(<ReportWatermark locale="en" />, { locale: 'en' })
    expect(screen.getByText('CampaignsHub')).toBeInTheDocument()
  })

  /*
   * A paged deck and a continuous document need different anchors, and getting this wrong is silent:
   * an absolute overlay on a document Chromium paginates itself marks sheet one and nothing after it,
   * which looks exactly like a watermark that works.
   */
  it('anchors to its own page in a deck, and repeats across a paginated document', () => {
    const { container: deck } = renderWithProviders(<ReportWatermark locale="ar" />, { locale: 'ar' })
    expect(deck.firstElementChild).toHaveClass('absolute')

    const { container: doc } = renderWithProviders(<ReportWatermark locale="ar" mode="flow" />, { locale: 'ar' })
    expect(doc.firstElementChild).toHaveClass('fixed')
  })

  /** It is decoration over somebody's figures, so it is hidden from assistive technology and inert. */
  it('is not read out and cannot be clicked through to', () => {
    const { container } = renderWithProviders(<ReportWatermark locale="ar" />, { locale: 'ar' })
    expect(container.firstElementChild).toHaveAttribute('aria-hidden', 'true')
    expect(container.firstElementChild).toHaveClass('pointer-events-none')
  })
})
