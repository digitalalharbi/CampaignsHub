import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'

import { renderWithProviders } from '@/test/utils'
import { CoverSlide } from './InteractiveReport'
import { coverMeta } from './sharedBranding'

/*
 * REPORT BRANDING — the deck's cover is the first page of the client's PDF and of the snapshot link.
 * It carried the resolved NAME only («Nakheel» in the agency's chip), no mark at all. It now shows the
 * issuing agency's mark and the client's, and a missing mark is its name — never an empty image.
 */
const data = { period: { from: '2026-07-01', to: '2026-07-31' }, currency: 'SAR', objective: null } as never

describe('the report cover', () => {
  it('shows the agency’s mark and the client’s', () => {
    const meta = { reportName: 'تقرير الأداء الشهري', platforms: [], ...coverMeta({ name: 'Nakheel', logoUrl: '/l/client', by: 'Razah Agency', byLogoUrl: '/l/agency' }) }
    renderWithProviders(<CoverSlide data={data} meta={meta} />, { locale: 'ar' })

    expect(screen.getByTestId('report-cover-agency-logo')).toHaveAttribute('src', '/l/agency')
    expect(screen.getByTestId('report-cover-client-logo')).toHaveAttribute('src', '/l/client')
    expect(screen.getByTestId('report-cover-client')).toHaveTextContent('Nakheel')
  })

  it('names both where neither has a mark, and draws no image', () => {
    const meta = { reportName: 'R', platforms: [], ...coverMeta({ name: 'Nakheel', logoUrl: null, by: 'Razah Agency', byLogoUrl: null }) }
    const { container } = renderWithProviders(<CoverSlide data={data} meta={meta} />, { locale: 'ar' })

    expect(screen.getByTestId('report-cover-agency')).toHaveTextContent('Razah Agency')
    expect(screen.getByTestId('report-cover-client')).toHaveTextContent('Nakheel')
    expect(container.querySelector('img')).toBeNull()
  })

  it('treats a report with no client as the agency’s own', () => {
    expect(coverMeta({ name: 'Razah Agency', logoUrl: '/l/agency', by: null, byLogoUrl: null }))
      .toEqual({ agencyName: 'Razah Agency', agencyLogoUrl: '/l/agency', clientName: undefined, clientLogoUrl: null })
  })

  /**
   * The cover's own labels were Arabic-only on a surface sent to clients in both languages.
   *
   * «الفترة», «الهدف», «العملة» were hardcoded, and so was the client-name fallback — so an English
   * client report opened with three Arabic words over its period and currency. The figures were always
   * right; the words around them were not, and on a cover that is the first thing a reader sees.
   */
  it('speaks the reader’s language, labels and fallbacks alike', () => {
    const meta = { reportName: 'Monthly performance', platforms: [], ...coverMeta({ name: null, logoUrl: null, by: 'Razah Agency', byLogoUrl: null }) }
    renderWithProviders(<CoverSlide data={data} meta={meta} />, { locale: 'en' })

    expect(screen.getByText(/Period/)).toBeInTheDocument()
    expect(screen.getByText(/Currency/)).toBeInTheDocument()
    expect(screen.getByTestId('report-cover-client')).toHaveTextContent('Performance report')

    // And none of the Arabic labels leak into the English document.
    expect(screen.queryByText(/الفترة/)).not.toBeInTheDocument()
    expect(screen.queryByText(/العملة/)).not.toBeInTheDocument()
    expect(screen.queryByText(/تقرير الأداء/)).not.toBeInTheDocument()
  })

  it('keeps the Arabic labels in an Arabic document', () => {
    const meta = { reportName: 'تقرير الأداء الشهري', platforms: [], ...coverMeta({ name: 'نخيل', logoUrl: null, by: null, byLogoUrl: null }) }
    renderWithProviders(<CoverSlide data={data} meta={meta} />, { locale: 'ar' })

    expect(screen.getByText(/الفترة/)).toBeInTheDocument()
    expect(screen.getByText(/العملة/)).toBeInTheDocument()
  })

  /**
   * REPORT-SUMMARY-DECISION-001 — the cover says WHICH of the two documents this is.
   *
   * An executive summary and a detailed report have different jobs and the cover was identical for
   * both, so a client sent a five-page summary could not tell whether they had the short version or
   * the whole thing — the first question anybody asks of a forwarded PDF.
   */
  it('names which form of the report it is', () => {
    const meta = { reportName: 'Monthly', platforms: [], ...coverMeta({ name: 'Nakheel', logoUrl: null, by: null, byLogoUrl: null }) }

    renderWithProviders(
      <CoverSlide data={{ ...(data as object), form: 'executive_summary' } as never} meta={meta} />,
      { locale: 'en' },
    )
    expect(screen.getByTestId('report-cover-form')).toHaveTextContent('Executive summary')
  })

  it('says nothing about the form when the report does not state one', () => {
    const meta = { reportName: 'Monthly', platforms: [], ...coverMeta({ name: 'Nakheel', logoUrl: null, by: null, byLogoUrl: null }) }
    renderWithProviders(<CoverSlide data={data} meta={meta} />, { locale: 'en' })

    expect(screen.queryByTestId('report-cover-form')).not.toBeInTheDocument()
  })
})
