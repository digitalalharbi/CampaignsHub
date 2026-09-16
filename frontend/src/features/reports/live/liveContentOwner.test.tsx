import { describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'

import { renderWithProviders } from '@/test/utils'

vi.mock('../api', () => ({
  fetchSharedReport: vi.fn(),
  sharedBranding: vi.fn().mockResolvedValue({ name: 'Nakheel', logo_url: null, logo_source: 'none', by: null }),
  sharedDownloadUrl: () => '#',
}))
vi.mock('../LiveSharedReport', () => ({ LiveSharedReport: () => <div data-testid="live-report" /> }))
vi.mock('../InteractiveReport', () => ({ InteractiveReport: () => <div data-testid="snapshot-report" /> }))
vi.mock('../SharedCreativeSection', () => ({ SharedCreativeSection: () => <div data-testid="shared-creative-section" /> }))

import { fetchSharedReport } from '../api'
import { PublicReport } from '../PublicReport'

/**
 * A live link's content has one home: its Content mode, which opens platform → content and drills
 * into one piece. The snapshot's creative library stacked beneath it turned every mode — the short
 * summary included — into «the mode, then a second content library», so the modes stopped being
 * different products at the bottom of the page. A snapshot keeps its library: it has no Content mode.
 */
function open(mode: 'live' | 'snapshot') {
  vi.mocked(fetchSharedReport).mockResolvedValue({
    status: 200,
    envelope: { data: {
      name: 'Q3', currency: 'SAR', is_demo: false, generated_at: null, mode, form: 'detailed',
      period_start: '2026-07-01', period_end: '2026-07-31',
      settings: { allow_download: false }, data: {}, kind: 'static',
      creatives: { creatives: true },
    } },
  } as never)

  renderWithProviders(<PublicReport />, { locale: 'en', route: '/r/tok', path: '/r/:token' })
}

describe('where a shared link shows its content', () => {
  it('leaves content to the live link’s own Content mode', async () => {
    open('live')

    expect(await screen.findByTestId('live-report')).toBeInTheDocument()
    expect(screen.queryByTestId('shared-creative-section')).not.toBeInTheDocument()
  })

  it('keeps the creative library on a snapshot, which has no Content mode', async () => {
    open('snapshot')

    expect(await screen.findByTestId('shared-creative-section')).toBeInTheDocument()
  })
})
