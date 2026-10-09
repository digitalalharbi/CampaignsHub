import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { BrandingCenterPage } from './BrandingCenterPage'
import { renderWithProviders, signInWith } from '@/test/utils'

vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  getBrandingSettings: vi.fn(),
  listBrandingAssets: vi.fn(),
}))

import { getBrandingSettings, listBrandingAssets } from './api'

/**
 * BRANDING-HIERARCHY-001 — the font control accepted input and changed nothing.
 *
 * Nothing in the product reads `branding_settings.fonts`: the type scale comes from `--font-body`
 * and `--font-heading` in `tokens.css`, and no surface has ever consulted a tenant's value. An
 * operator typed a brand font, saw «تم الحفظ», and every screen stayed exactly as it was. A control
 * that accepts input and changes nothing spends a customer's trust once, and again every time they
 * look for the change.
 *
 * It is WITHDRAWN rather than implemented, because applying a font needs a source policy this
 * product does not have: a family name alone cannot load, and the only way to make it real is to
 * fetch a font file — which on a CLIENT'S report means a remote resource chosen by whoever typed
 * into the box.
 *
 * And withdrawn rather than deleted: the column, the API field and the validation are untouched, so
 * a tenant who set a value still has it and a future implementation still has it to read.
 */
const settings = (over: Record<string, unknown> = {}) => ({
  scope: 'tenant', scope_id: null, colors: null, fonts: null,
  white_label: false, white_label_effective: false, white_label_reason: null,
  ...over,
})

const openSettings = async () => {
  renderWithProviders(<BrandingCenterPage />, { locale: 'en' })
  fireEvent.click(await screen.findByRole('button', { name: /Colors & fonts/ }))
  /* Wait for the tab's own content, so an absence below is evidence rather than a race. */
  await screen.findByRole('checkbox', { name: /White-label/ })
}

describe('the withdrawn font control', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['branding.view', 'branding.manage'])
    vi.mocked(listBrandingAssets).mockResolvedValue({ assets: [] } as never)
  })

  it('offers no font editor to a tenant that never set one', async () => {
    vi.mocked(getBrandingSettings).mockResolvedValue(settings() as never)

    await openSettings()

    expect(screen.queryByLabelText(/Heading font/)).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/Body font/)).not.toBeInTheDocument()
    expect(screen.queryByTestId('branding-fonts-legacy')).not.toBeInTheDocument()
  })

  /**
   * A stored value is SHOWN and said to be inert — the sentence that was missing all along.
   *
   * Hiding it outright would leave an operator who set one years ago with no way to see what the
   * product is holding for them, and no explanation for why their screens never changed.
   */
  it('shows a stored value read-only, and says it is not applied', async () => {
    vi.mocked(getBrandingSettings).mockResolvedValue(
      settings({ fonts: { heading: 'Nakheel Display', body: '' } }) as never,
    )

    await openSettings()

    const legacy = await screen.findByTestId('branding-fonts-legacy')
    expect(legacy).toHaveTextContent('Nakheel Display')
    expect(screen.getByTestId('branding-fonts-not-applied')).toHaveTextContent(/not applied on any surface/i)

    /* Read-only: there is no input to type a new one into. */
    expect(screen.queryByLabelText(/Heading font/)).not.toBeInTheDocument()
  })

  /** An empty string is not a stored value — it is the absence of one, and shows nothing. */
  it('treats a blank stored value as nothing stored', async () => {
    vi.mocked(getBrandingSettings).mockResolvedValue(
      settings({ fonts: { heading: '   ', body: '' } }) as never,
    )

    await openSettings()

    expect(screen.queryByTestId('branding-fonts-legacy')).not.toBeInTheDocument()
  })

  /**
   * The COLOURS beside it are untouched, because they are not inert.
   *
   * `--brand-*` is read by real surfaces. A sweep that removed «the branding settings» wholesale
   * would have taken a working control with the broken one.
   */
  it('leaves the colour controls alone', async () => {
    vi.mocked(getBrandingSettings).mockResolvedValue(settings() as never)

    await openSettings()

    expect(screen.getByText('Colors')).toBeInTheDocument()
  })
})
