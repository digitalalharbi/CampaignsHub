import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'

import { renderWithProviders } from '@/test/utils'

vi.mock('./api', () => ({
  fetchSharedReport: vi.fn(),
  sharedBranding: vi.fn(),
  sharedDownloadUrl: () => '#',
}))

import { fetchSharedReport, sharedBranding } from './api'
import { PublicReport } from './PublicReport'

/**
 * BRANDING-HIERARCHY-001 — «never a broken image or blank header», on the surface with no session.
 *
 * `headerIdentity` refuses an empty url, but the backend resolved a REAL one — it cannot know the
 * asset was since deleted or that storage will refuse it. The browser then paints its broken-image
 * icon beside the client's name, on a report read by somebody with no way to tell whether the mark
 * or the whole report is broken.
 */
const report = {
  name: 'Q3', currency: 'SAR', is_demo: false, generated_at: null,
  period_start: '2026-07-01', period_end: '2026-07-31',
  settings: { allow_download: false }, data: {}, kind: 'static',
}

function open(logoUrl: string | null) {
  vi.mocked(fetchSharedReport).mockResolvedValue({ status: 'ready', envelope: report } as never)
  vi.mocked(sharedBranding).mockResolvedValue({
    name: 'Nakheel', logo_url: logoUrl, logo_source: logoUrl === null ? 'none' : 'client', by: null,
  } as never)

  // The harness owns the router; mounting a second one throws. `path` is what makes useParams()
  // resolve the token this page reads.
  renderWithProviders(<PublicReport />, { locale: 'en', route: '/r/tok', path: '/r/:token' })
}

describe('a shared report header that cannot show its logo', () => {
  it('hides a logo that fails to load and keeps the client’s name', async () => {
    open('https://cdn.example.test/deleted.png')

    const logo = await screen.findByTestId('shared-report-logo')
    expect(logo).toBeInTheDocument()

    // The asset 404s at render time — the one case the backend cannot rule out.
    fireEvent.error(logo)

    await waitFor(() => expect(logo).toHaveStyle({ display: 'none' }))
    // The header is still the client's, never empty and never the product's name by accident.
    expect(screen.getByTestId('shared-report-name')).toHaveTextContent('Nakheel')
  })

  /** With no logo at all there is no image to break, and the name still stands. */
  it('renders the name alone when no logo resolved', async () => {
    open(null)

    expect(await screen.findByTestId('shared-report-name')).toHaveTextContent('Nakheel')
    expect(screen.queryByTestId('shared-report-logo')).not.toBeInTheDocument()
  })
})
