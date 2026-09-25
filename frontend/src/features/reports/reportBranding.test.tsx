import { describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'

import { renderWithProviders } from '@/test/utils'
import { headerIdentity, reportPageTitle, type SharedBranding } from './sharedBranding'

vi.mock('./api', () => ({
  fetchSharedReport: vi.fn(),
  sharedBranding: vi.fn(),
  sharedDownloadUrl: () => '#',
}))
vi.mock('./LiveSharedReport', () => ({ LiveSharedReport: () => <div data-testid="live-report" /> }))

import { fetchSharedReport, sharedBranding } from './api'
import { PublicReport } from './PublicReport'

/*
 * REPORT BRANDING (Owner) — a client's report shows the issuing agency's mark and the client's, and
 * its tab reads «<report name> — كامبينز هب» for an Arabic report, «<Report name> — CampaignsHub» for
 * an English one: the report's language, not the viewer's interface.
 */
const both: SharedBranding = {
  name: 'Nakheel', logo_url: '/l/nearest', logo_source: 'client', by: 'Razah Agency',
  agency: { name: 'Razah Agency', logo_url: '/l/agency' },
  client: { name: 'Nakheel', logo_url: '/l/client' },
}

describe('the identity a report header shows', () => {
  it('leads with the client’s mark and names the agency with its own', () => {
    expect(headerIdentity(both, 'ar')).toEqual({ name: 'Nakheel', logoUrl: '/l/client', by: 'Razah Agency', byLogoUrl: '/l/agency' })
  })

  it('never puts the agency’s logo in the client’s place', () => {
    const noClientLogo = { ...both, logo_url: '/l/agency', logo_source: 'tenant', client: { name: 'Nakheel', logo_url: null } }
    expect(headerIdentity(noClientLogo, 'ar')).toEqual({ name: 'Nakheel', logoUrl: null, by: 'Razah Agency', byLogoUrl: '/l/agency' })
  })

  it('shows the agency’s own mark on a report with no client', () => {
    const agencyOnly = { ...both, name: 'Razah Agency', by: null, client: null, logo_url: '/l/agency', logo_source: 'tenant' }
    expect(headerIdentity(agencyOnly, 'ar')).toEqual({ name: 'Razah Agency', logoUrl: '/l/agency', by: null, byLogoUrl: null })
  })
})

describe('a report’s page title', () => {
  it('is the report’s name ending with the product’s, in the report’s language', () => {
    expect(reportPageTitle('تقرير الأداء الشهري', 'ar')).toBe('تقرير الأداء الشهري — كامبينز هب')
    expect(reportPageTitle('Monthly performance', 'en')).toBe('Monthly performance — CampaignsHub')
  })
})

function open(locale: 'ar' | 'en', reportLocale: 'ar' | 'en', branding: SharedBranding) {
  vi.mocked(fetchSharedReport).mockResolvedValue({
    status: 200,
    envelope: { data: {
      name: reportLocale === 'ar' ? 'تقرير الأداء الشهري' : 'Monthly performance', locale: reportLocale,
      currency: 'SAR', is_demo: false, generated_at: null, mode: 'live', form: 'detailed',
      settings: { allow_download: false }, data: [], creatives: { creatives: false },
    } },
  } as never)
  vi.mocked(sharedBranding).mockResolvedValue(branding as never)
  renderWithProviders(<PublicReport />, { locale, route: '/r/tok', path: '/r/:token' })
}

describe('a shared report page', () => {
  it('shows both marks and titles the tab in the report’s language, whatever the interface language', async () => {
    open('en', 'ar', both)

    expect(await screen.findByTestId('shared-report-logo')).toHaveAttribute('src', '/l/client')
    expect(screen.getByTestId('shared-report-agency-logo')).toHaveAttribute('src', '/l/agency')
    await waitFor(() => expect(document.title).toBe('تقرير الأداء الشهري — كامبينز هب'))
  })

  it('draws no image where no mark exists, and still names both', async () => {
    open('ar', 'en', { ...both, logo_url: null, logo_source: 'none', agency: { name: 'Razah Agency', logo_url: null }, client: { name: 'Nakheel', logo_url: null } })

    /*
     * Wait for the CONTENT, not for the element.
     *
     * `findByTestId` resolves as soon as the node exists, and this node exists from the first paint
     * carrying the platform's own fallback name — «كامبينز هب» — until `sharedBranding` resolves and
     * the client's name replaces it. So asserting the text on the element's arrival is asserting
     * that the branding fetch won a race against the first render, which it usually does and under
     * CI load sometimes does not.
     *
     * Observed twice on unrelated pull requests, one of which changed no frontend file at all.
     * Nothing here is weakened: «Nakheel» and «Razah Agency» are still required, and a page that
     * never resolved them still fails.
     */
    await waitFor(() => expect(screen.getByTestId('shared-report-name')).toHaveTextContent('Nakheel'))
    await waitFor(() => expect(screen.getByTestId('shared-report-by')).toHaveTextContent('Razah Agency'))
    expect(screen.queryByRole('img')).not.toBeInTheDocument()
    await waitFor(() => expect(document.title).toBe('Monthly performance — CampaignsHub'))
  })
})
