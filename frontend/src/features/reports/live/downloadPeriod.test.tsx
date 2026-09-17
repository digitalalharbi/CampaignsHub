import { describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'

import { renderWithProviders } from '@/test/utils'

vi.mock('../api', () => ({
  fetchSharedReport: vi.fn(),
  sharedBranding: vi.fn().mockResolvedValue({ name: 'Nakheel', logo_url: null, logo_source: 'none', by: null }),
  sharedDownloadUrl: () => '#',
}))
vi.mock('../LiveSharedReport', () => ({ LiveSharedReport: () => <div data-testid="live-report" /> }))

import { fetchSharedReport } from '../api'
import { PublicReport } from '../PublicReport'

/*
 * A live link's figures follow the window the client picks; its download buttons deliver the saved
 * report, which covers the report's own period. Measured locally: at the report's period the page
 * and the CSV state one spend, and at any other window they differ with nothing on the page saying
 * why. The download controls now name the period the file covers.
 */
describe('the downloads on a live link', () => {
  it('say which period the file covers', async () => {
    vi.mocked(fetchSharedReport).mockResolvedValue({
      status: 200,
      envelope: { data: {
        name: 'Q3', currency: 'SAR', is_demo: false, generated_at: null, mode: 'live', form: 'detailed',
        period: { from: '2026-08-18', to: '2026-09-16' },
        settings: { allow_download: true }, data: [], creatives: { creatives: false },
      } },
    } as never)

    renderWithProviders(<PublicReport />, { locale: 'en', route: '/r/tok', path: '/r/:token' })

    const pdf = await screen.findByRole('link', { name: /Download PDF/ })
    expect(pdf).toHaveAccessibleName(/2026-08-18 → 2026-09-16/)
    expect(screen.getByTestId('shared-report-download-period')).toHaveTextContent('2026-08-18 → 2026-09-16')
  })
})
