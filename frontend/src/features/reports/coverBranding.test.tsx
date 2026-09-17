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
})
