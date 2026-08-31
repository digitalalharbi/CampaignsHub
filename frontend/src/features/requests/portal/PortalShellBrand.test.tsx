import { describe, expect, it, vi, beforeEach } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'

import { PortalShell } from './PortalShell'
import { renderWithProviders } from '@/test/utils'

vi.mock('./useClientBranding', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./useClientBranding')>()
  return { ...actual, useClientBranding: vi.fn() }
})

import { useClientBranding } from './useClientBranding'

/**
 * BRANDING-HIERARCHY-001 — «never a broken image or blank header», in the portal a client logs into.
 *
 * The header renders the agency's mark when there is one and its own fallback when there is not, and
 * the fallback is skipped precisely BECAUSE a mark exists. So a mark that cannot be drawn left the
 * header showing a browser's broken-image glyph and no name at all — which is the outcome the
 * requirement rules out, and the one this header was previously guaranteed to produce, since the URL
 * it was handed answered 401 to every portal session.
 *
 * The URL is fixed on the server. This covers the other half: any other reason a mark fails — a
 * deleted file, an offline CDN, a corrupt upload — must land on the same header a client with no
 * logo sees, never on nothing.
 */
const branding = (logos: Array<{ kind: string; url: string }>) => ({
  space: { name: 'Alpha Retail', slug: 'alpha' },
  colors: {},
  fonts: {},
  white_label_requested: false,
  logos,
})

const shell = () =>
  renderWithProviders(<PortalShell title="Home">body</PortalShell>, { locale: 'en' })

describe('the portal header', () => {
  beforeEach(() => vi.clearAllMocks())

  it('shows the agency mark when it loads', () => {
    vi.mocked(useClientBranding).mockReturnValue(
      branding([{ kind: 'primary_horizontal', url: '/api/v1/client/branding/logo?kind=primary_horizontal' }]) as never,
    )
    shell()

    expect(screen.getByTestId('portal-logo')).toBeInTheDocument()
  })

  it('falls back to the name when the mark cannot be drawn', () => {
    vi.mocked(useClientBranding).mockReturnValue(
      branding([{ kind: 'primary_horizontal', url: '/api/v1/client/branding/logo?kind=primary_horizontal' }]) as never,
    )
    shell()

    fireEvent.error(screen.getByTestId('portal-logo'))

    expect(screen.queryByTestId('portal-logo')).not.toBeInTheDocument()
    /* The header is not merely image-less — it says whose portal this is. */
    expect(screen.getByText('Alpha Retail')).toBeInTheDocument()
  })

  it('shows the name without ever having had a mark', () => {
    vi.mocked(useClientBranding).mockReturnValue(branding([]) as never)
    shell()

    expect(screen.queryByTestId('portal-logo')).not.toBeInTheDocument()
    expect(screen.getByText('Alpha Retail')).toBeInTheDocument()
  })

  /*
   * A replacement mark gets its own chance. Remembering «the logo is broken» rather than «THIS url
   * is broken» would leave an agency that re-uploaded after a bad file staring at the fallback
   * forever, with nothing on screen to explain why.
   */
  it('gives a new mark its own chance after a previous one failed', () => {
    vi.mocked(useClientBranding).mockReturnValue(
      branding([{ kind: 'primary_horizontal', url: '/logo/old' }]) as never,
    )
    const { rerender } = shell()

    fireEvent.error(screen.getByTestId('portal-logo'))
    expect(screen.queryByTestId('portal-logo')).not.toBeInTheDocument()

    vi.mocked(useClientBranding).mockReturnValue(
      branding([{ kind: 'primary_horizontal', url: '/logo/new' }]) as never,
    )
    rerender(<PortalShell title="Home">body</PortalShell>)

    expect(screen.getByTestId('portal-logo')).toHaveAttribute('src', '/logo/new')
  })
})
