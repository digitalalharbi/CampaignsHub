import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { PlatformFeaturesPage } from './PlatformFeaturesPage'
import { renderWithProviders, signOut } from '@/test/utils'
import { DialogHost } from '@/components/ui/DialogHost'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchPlatformCapabilities: vi.fn(), setPlatformCapability: vi.fn(), fetchPlanCapabilities: vi.fn(), setPlanCapability: vi.fn() }
})

import { fetchPlanCapabilities, fetchPlatformCapabilities, setPlanCapability, setPlatformCapability } from './api'

const planData = {
  modules: [{ module: 'smart_links', default_entitled: true }, { module: 'influencer_intelligence', default_entitled: false }],
  plans: [{ id: 'p1', code: 'growth', name: 'Growth', name_ar: null, is_active: true, workspaces: 3, modules: { influencer_intelligence: false } }],
}

/** SUPERADMIN-FEATURES-001 — a capability turned off for everyone, with a reason; an environment kill switch is not offered. */
describe('PlatformFeaturesPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(fetchPlatformCapabilities).mockResolvedValue({ capabilities: [
      { module: 'smart_links', activation: 'default_on', platform_disabled: false, disabled_by_environment: false },
      { module: 'link_in_bio', activation: 'opt_in', platform_disabled: true, disabled_by_environment: true },
    ] })
    vi.mocked(setPlatformCapability).mockResolvedValue({ capabilities: [] })
    vi.mocked(fetchPlanCapabilities).mockResolvedValue(planData)
    vi.mocked(setPlanCapability).mockResolvedValue(planData)
  })
  afterEach(() => signOut())

  it('turns a capability off for everyone with the reason given', async () => {
    renderWithProviders(<><PlatformFeaturesPage /><DialogHost /></>, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('feature-toggle-smart_links'))
    fireEvent.change(await screen.findByTestId('dialog-input'), { target: { value: 'Incident on the redirect service' } })
    fireEvent.click(screen.getByTestId('dialog-confirm'))

    await waitFor(() => expect(setPlatformCapability).toHaveBeenCalledWith('smart_links', true, 'Incident on the redirect service'))
  })

  it('does not offer to undo an environment kill switch', async () => {
    renderWithProviders(<PlatformFeaturesPage />, { locale: 'en' })

    expect(await screen.findByTestId('feature-toggle-link_in_bio')).toBeDisabled()
    expect(screen.getByTestId('feature-link_in_bio')).toHaveTextContent(/Switched off by the environment/)
  })

  it('includes a module in a plan after saying how many workspaces it reaches, and never offers a switch that changes nothing', async () => {
    renderWithProviders(<><PlatformFeaturesPage /><DialogHost /></>, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('plan-module-growth-influencer_intelligence'))

    expect(await screen.findByTestId('dialog-body')).toHaveTextContent(/Affects 3 workspaces on Growth/)
    fireEvent.change(screen.getByTestId('dialog-input'), { target: { value: 'Growth now includes influencers' } })
    fireEvent.click(screen.getByTestId('dialog-confirm'))
    await waitFor(() => expect(setPlanCapability).toHaveBeenCalledWith('p1', 'influencer_intelligence', true, 'Growth now includes influencers'))
    expect(screen.queryByTestId('plan-module-growth-smart_links')).not.toBeInTheDocument()
    expect(screen.getByTestId('plan-modules')).toHaveTextContent('Everyone has it')
  })
})
