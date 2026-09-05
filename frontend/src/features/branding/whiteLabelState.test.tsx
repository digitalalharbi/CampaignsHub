import { describe, expect, it, vi, beforeEach } from 'vitest'
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
 * BRANDING-WHITE-LABEL-ENTITLEMENT — the switch says whether it is IN FORCE, not just whether it was
 * asked for.
 *
 * `white_label` is a stored preference and the plan decides whether it does anything. This screen
 * showed the preference alone, so an operator on a plan without the feature ticked a box and watched
 * nothing happen — the same failure as refusing in the UI while the endpoint allows it, pointed the
 * other way.
 *
 * The control stays ENABLED throughout. The preference is real, it is saved, and it takes effect the
 * moment the plan carries the feature; disabling it would lose the operator's intent on a downgrade
 * and make them come back and re-tick after upgrading.
 */
const settings = (over: Record<string, unknown> = {}) => ({
  scope: 'tenant', scope_id: null, colors: null, fonts: null,
  white_label: true, white_label_effective: true, white_label_reason: null,
  ...over,
})

/**
 * The page opens on Assets; the switch lives on «Colors & fonts».
 *
 * Waits for the SWITCH to exist, not merely for the request to have been made. The first version
 * waited on the mock being called and then asserted an absence — which passes before the data has
 * rendered, and did: removing the guard under test changed nothing. An absence is only evidence once
 * the thing that would have shown it is on screen.
 */
const openSettings = async () => {
  renderWithProviders(<BrandingCenterPage />, { locale: 'en' })
  // Role, not text: the tab label sits beside an icon, so the string is split across nodes.
  fireEvent.click(await screen.findByRole('button', { name: /Colors & fonts/ }))
  await screen.findByRole('checkbox', { name: /White-label/ })
}

describe('the white-label switch', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['branding.view', 'branding.manage'])
    vi.mocked(listBrandingAssets).mockResolvedValue({ assets: [] } as never)
  })

  it('says nothing extra when the plan carries the feature', async () => {
    vi.mocked(getBrandingSettings).mockResolvedValue(settings() as never)
    await openSettings()
    expect(screen.queryByTestId('white-label-not-in-force')).not.toBeInTheDocument()
  })

  it('marks it not in force, and says which reason', async () => {
    vi.mocked(getBrandingSettings).mockResolvedValue(
      settings({ white_label_effective: false, white_label_reason: 'plan_does_not_include_white_label' }) as never,
    )
    await openSettings()

    expect(await screen.findByTestId('white-label-not-in-force')).toHaveTextContent('Not in force')
    expect(screen.getByTestId('white-label-reason')).toHaveTextContent(/plan does not include white-labelling/i)
  })

  /** A lapsed subscription is a payment, not a purchase — different reader, different next step. */
  it('distinguishes a lapsed subscription from a plan that never had it', async () => {
    vi.mocked(getBrandingSettings).mockResolvedValue(
      settings({ white_label_effective: false, white_label_reason: 'subscription_not_active' }) as never,
    )
    await openSettings()

    expect(await screen.findByTestId('white-label-reason')).toHaveTextContent(/subscription is not active/i)
  })

  /** The preference was never asked for, so there is nothing to explain. */
  it('says nothing when the operator has not switched it on', async () => {
    vi.mocked(getBrandingSettings).mockResolvedValue(
      settings({ white_label: false, white_label_effective: false, white_label_reason: 'not_requested' }) as never,
    )
    await openSettings()
    expect(screen.queryByTestId('white-label-not-in-force')).not.toBeInTheDocument()
  })

  /** An install answering from before this shipped sends neither field and must read as it did. */
  it('says nothing when the server did not answer the question', async () => {
    const s = settings() as Record<string, unknown>
    delete s.white_label_effective
    delete s.white_label_reason
    vi.mocked(getBrandingSettings).mockResolvedValue(s as never)
    await openSettings()
    expect(screen.queryByTestId('white-label-not-in-force')).not.toBeInTheDocument()
  })
})
