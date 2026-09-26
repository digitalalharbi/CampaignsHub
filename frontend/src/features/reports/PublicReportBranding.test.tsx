import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'

import { renderWithProviders } from '@/test/utils'

vi.mock('./api', () => ({
  fetchSharedReport: vi.fn(),
  sharedBranding: vi.fn(),
  sharedDownloadUrl: () => '#',
}))

/*
 * The LIVE body is stubbed, deliberately.
 *
 * These cases are about the HEADER, which `PublicReport` renders ABOVE the mode branch — and that
 * placement is the thing worth guarding. Mounting the real live dashboard would pull in its own
 * payload hook and a network it does not need, and a failure there would read as a branding failure.
 */
vi.mock('./LiveSharedReport', () => ({
  LiveSharedReport: () => <div data-testid="live-body" />,
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

function open(
  logoUrl: string | null,
  extra: { mode?: 'live' | 'snapshot'; name?: string; source?: string; by?: string | null } = {},
) {
  /*
   * The SHAPE the page actually reads, which this harness had wrong.
   *
   * `PublicReport` checks `status === 200` — a number — and takes the report from `envelope.data`.
   * The fixture said `status: 'ready'` and put the report at the top level, so the page never left
   * its loading state and every case here was asserting the header of a report that had not loaded.
   * The header is rendered above that gate on purpose (a reader must be told whose report they are
   * being asked to unlock), so nothing failed — which is exactly why it went unnoticed.
   */
  vi.mocked(fetchSharedReport).mockResolvedValue({
    status: 200,
    envelope: { data: { ...report, mode: extra.mode ?? 'snapshot' } },
  } as never)
  vi.mocked(sharedBranding).mockResolvedValue({
    name: extra.name ?? 'Nakheel',
    logo_url: logoUrl,
    logo_source: extra.source ?? (logoUrl === null ? 'none' : 'client'),
    by: extra.by ?? null,
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

/**
 * BRANDING-RENDER-EVIDENCE-001 — «the configured logo ACTUALLY RENDERS, proven per surface».
 *
 * The cases above prove the two ways a header must not fail. They do not prove the thing the row is
 * actually about: that a mark an operator configured reaches the page. That is the row's own warning
 * in miniature — «code containing `logo_url` is not completion» — and a suite holding only the
 * negative cases passes just as happily against a header that renders no image at all.
 *
 * This is the surface with NO SESSION: the client opens the link, and whatever is on it is the whole
 * of what they are told about whose report this is.
 */
describe('the configured mark reaches the shared header', () => {
  it('renders the resolved logo, at the url the backend resolved', async () => {
    open('https://cdn.example.test/nakheel.png')

    const logo = await screen.findByTestId('shared-report-logo')

    expect(logo).toHaveAttribute('src', 'https://cdn.example.test/nakheel.png')
    // Decorative: the name is the text, so a screen reader is not told the mark twice.
    expect(logo).toHaveAttribute('alt', '')
    expect(screen.getByTestId('shared-report-name')).toHaveTextContent('Nakheel')
  })

  /**
   * THE LIVE LINK IS THE SAME HEADER, and this is what keeps it that way.
   *
   * `PublicReport` renders the identity above the `mode` branch, so a live dashboard and a snapshot
   * document carry the same mark by construction. That is a placement, not a guarantee — move the
   * header inside either arm and a live link silently loses its branding while every snapshot test
   * stays green. A client on a live link is the reader least able to tell.
   */
  it('is the same header on a live link as on a snapshot', async () => {
    open('https://cdn.example.test/nakheel.png', { mode: 'live' })

    expect(await screen.findByTestId('live-body')).toBeInTheDocument()
    expect(screen.getByTestId('shared-report-logo')).toHaveAttribute('src', 'https://cdn.example.test/nakheel.png')
    expect(screen.getByTestId('shared-report-name')).toHaveTextContent('Nakheel')
  })

  /**
   * The fallback is the BACKEND's walk, and the header shows whichever layer answered.
   *
   * The agency's own mark, on a report with no client branding of its own, must appear as the
   * agency's — not silently as the client's. The header cannot re-resolve anything (a second
   * branding engine would drift from the Branding Center), so what it must not do is contradict the
   * layer it was handed.
   */
  it('shows the agency layer when that is what resolved', async () => {
    open('https://cdn.example.test/agency.png', { name: 'Al Harbi Digital', source: 'tenant' })

    expect(await screen.findByTestId('shared-report-logo')).toHaveAttribute('src', 'https://cdn.example.test/agency.png')
    expect(screen.getByTestId('shared-report-name')).toHaveTextContent('Al Harbi Digital')
  })

  /**
   * The last link of the chain is a NAME, never an empty image.
   *
   * `<img src="">` draws the broken-image icon, which on a client's report reads as «this report
   * failed» rather than «this agency has no logo» — the distinction BRANDING-HIERARCHY-001 forbids
   * getting wrong.
   */
  it('falls back to a name and draws no image when nothing resolved', async () => {
    open(null, { name: 'CampaignsHub', source: 'none' })

    expect(await screen.findByTestId('shared-report-name')).toHaveTextContent('CampaignsHub')
    expect(screen.queryByTestId('shared-report-logo')).not.toBeInTheDocument()
    expect(document.querySelector('img[src=""]')).toBeNull()
  })
})
