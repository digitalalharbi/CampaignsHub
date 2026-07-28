import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { ClientCampaignsPage } from './ClientCampaignsPage'
import type { PortalCampaign } from './portalAccountApi'
import { renderWithProviders } from '@/test/utils'

// Keep the real formatting/helpers; mock only the network call.
vi.mock('./portalAccountApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./portalAccountApi')>()
  return { ...actual, listClientCampaigns: vi.fn() }
})

import { listClientCampaigns } from './portalAccountApi'

const campaign: PortalCampaign = {
  id: 'c1', name: 'Spring Launch', status: 'active', objective: 'Traffic',
  starts_on: '2026-03-01', ends_on: '2026-03-31',
  metrics: { impressions: 12430, clicks: 512, conversions: 34, ctr: 0.0412 },
}

describe('ClientCampaignsPage', () => {
  beforeEach(() => vi.clearAllMocks())

  it('renders campaigns with Latin-digit delivery metrics and NO cost fields', async () => {
    vi.mocked(listClientCampaigns).mockResolvedValue([campaign])
    renderWithProviders(<ClientCampaignsPage />, { locale: 'en' })

    expect(await screen.findByText('Spring Launch')).toBeInTheDocument()
    // Grouped Latin digits for impressions.
    expect(screen.getByText('12,430')).toBeInTheDocument()
    // CTR rendered as a percentage.
    expect(screen.getByText('4.12%')).toBeInTheDocument()
    // Objective + delivery-only metric labels are shown (client-safe shape carries no spend/ROAS/CPA figures).
    expect(screen.getByText('Traffic')).toBeInTheDocument()
    expect(screen.getByText('Impressions')).toBeInTheDocument()
    expect(screen.getByText('Conversions')).toBeInTheDocument()
  })

  it('shows an honest empty state when there are no campaigns', async () => {
    vi.mocked(listClientCampaigns).mockResolvedValue([])
    renderWithProviders(<ClientCampaignsPage />, { locale: 'en' })
    expect(await screen.findByText('No campaigns yet.')).toBeInTheDocument()
  })
})
