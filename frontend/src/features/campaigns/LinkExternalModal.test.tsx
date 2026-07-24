import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { LinkExternalModal } from './LinkExternalModal'
import type { ExternalCampaign } from './types'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', () => ({
  listExternalCampaigns: vi.fn(),
  listLinkSuggestions: vi.fn(),
  linkExternal: vi.fn(),
}))

import { linkExternal, listExternalCampaigns, listLinkSuggestions } from './api'

function ext(id: string, name: string, opts: Partial<ExternalCampaign> = {}): ExternalCampaign {
  return {
    id, unified_campaign_id: null, external_account_id: 'a1', provider: 'sandbox', external_id: 'sbx-' + id,
    name, status: 'active', objective: null, daily_budget: null, lifetime_budget: null, currency: 'SAR',
    is_linked: false, linked_at: null, last_synced_at: null, ...opts,
  }
}

describe('LinkExternalModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listLinkSuggestions).mockResolvedValue([])
  })
  afterEach(() => signOut())

  it('lists unlinked external campaigns with a Demo badge for sandbox', async () => {
    vi.mocked(listExternalCampaigns).mockResolvedValue([ext('e1', 'Sandbox Awareness')])
    signInWith(['campaigns.update'])
    renderWithProviders(<LinkExternalModal open onClose={() => {}} projectId="p1" campaignId="c1" />)

    expect(await screen.findByText('Sandbox Awareness')).toBeInTheDocument()
    expect(screen.getByText(/Demo/)).toBeInTheDocument() // sandbox surfaced as demo, never production
  })

  it('links an unlinked external campaign (confirm=false path)', async () => {
    vi.mocked(listExternalCampaigns).mockResolvedValue([ext('e1', 'Sandbox Awareness')])
    vi.mocked(linkExternal).mockResolvedValue(ext('e1', 'Sandbox Awareness', { is_linked: true }))
    signInWith(['campaigns.update'])
    renderWithProviders(<LinkExternalModal open onClose={() => {}} projectId="p1" campaignId="c1" />)

    fireEvent.click(await screen.findByText('Link'))
    await waitFor(() => expect(linkExternal).toHaveBeenCalledWith('p1', 'c1', 'e1', false))
  })

  it('on 409 shows the move confirmation and re-links with confirm=true', async () => {
    // The external is linked elsewhere; backend returns 409 on the first (unconfirmed) attempt.
    vi.mocked(listExternalCampaigns).mockResolvedValue([ext('e1', 'Shared Campaign', { unified_campaign_id: 'other-uc' })])
    vi.mocked(linkExternal)
      .mockRejectedValueOnce({
        response: { status: 409, data: { meta: { requires_confirmation: true, current_unified_campaign_id: 'other-uc' } } },
      })
      .mockResolvedValueOnce(ext('e1', 'Shared Campaign', { unified_campaign_id: 'c1', is_linked: true }))
    signInWith(['campaigns.update'])
    renderWithProviders(<LinkExternalModal open onClose={() => {}} projectId="p1" campaignId="c1" />)

    fireEvent.click(await screen.findByText('Link'))
    // Confirmation surfaces (backend is the decision source).
    expect(await screen.findByText('Move link')).toBeInTheDocument()
    expect(screen.getByText(/other-uc/)).toBeInTheDocument()

    fireEvent.click(screen.getByText('Confirm move'))
    await waitFor(() => expect(linkExternal).toHaveBeenLastCalledWith('p1', 'c1', 'e1', true))
  })
})
