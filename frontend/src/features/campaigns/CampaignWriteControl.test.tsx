import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { CampaignWriteControl, ProviderWriteGate } from './CampaignWriteControl'
import type { WriteCapabilities, WriteCapability } from './writeControl'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

const PROVIDERS = ['snapchat', 'tiktok', 'meta', 'google', 'x', 'linkedin', 'openai_ads']
const CAPS = ['create_campaign', 'edit_campaign', 'pause_resume', 'budget_change', 'publish']

function cell(provider: string, capability: string, over: Partial<WriteCapability> = {}): WriteCapability {
  return { provider, capability, status: 'not_implemented', permission: 'campaigns.update', evidence: null, permitted: true, allowed: false, ...over }
}

function payload(over: (c: WriteCapability) => WriteCapability = (c) => c): WriteCapabilities {
  return {
    statuses: ['not_implemented', 'implemented_not_verified', 'awaiting_credentials', 'verified'],
    rule_ar: 'لا يُعرض أي إجراء كتابة لمنصة ما لم تكن القدرة نفسها منفَّذة ومقيَّدة بصلاحية.',
    rule_en: 'No write action is shown for a provider unless that exact capability is implemented and permission-gated.',
    providers: PROVIDERS.map((provider) => ({ provider, capabilities: CAPS.map((capability) => over(cell(provider, capability))) })),
  }
}

/**
 * CAMPAIGN-MGMT-DOMAIN-001 — the interface never decides what it may write; it asks, and draws
 * nothing until the registry says yes for this reader, this provider and this exact capability.
 */
describe('ProviderWriteGate', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['campaigns.view', 'campaigns.pause']) })
  afterEach(() => signOut())

  it('draws nothing while every capability is unimplemented, even for a reader who holds the permission', async () => {
    vi.mocked(getData).mockResolvedValue(payload())
    renderWithProviders(
      <ProviderWriteGate projectId="p1" provider="meta" capability="pause_resume" fallback={<span data-testid="no-write" />}>
        <button data-testid="pause-on-meta">pause</button>
      </ProviderWriteGate>,
    )
    await waitFor(() => expect(getData).toHaveBeenCalled())
    expect(screen.queryByTestId('pause-on-meta')).toBeNull()
    expect(await screen.findByTestId('no-write')).toBeTruthy()
  })

  it('draws the write only for the exact provider and capability the registry allows', async () => {
    vi.mocked(getData).mockResolvedValue(payload((c) => (
      c.provider === 'meta' && c.capability === 'pause_resume' ? { ...c, status: 'implemented_not_verified', allowed: true } : c
    )))
    renderWithProviders(
      <>
        <ProviderWriteGate projectId="p1" provider="meta" capability="pause_resume"><button data-testid="pause-on-meta">pause</button></ProviderWriteGate>
        <ProviderWriteGate projectId="p1" provider="snapchat" capability="pause_resume"><button data-testid="pause-on-snap">pause</button></ProviderWriteGate>
        <ProviderWriteGate projectId="p1" provider="meta" capability="budget_change"><button data-testid="budget-on-meta">budget</button></ProviderWriteGate>
      </>,
    )
    expect(await screen.findByTestId('pause-on-meta')).toBeTruthy()
    expect(screen.queryByTestId('pause-on-snap')).toBeNull()
    expect(screen.queryByTestId('budget-on-meta')).toBeNull()
  })

  it('a stale or failed registry read opens nothing', async () => {
    vi.mocked(getData).mockRejectedValue(new Error('offline'))
    renderWithProviders(
      <ProviderWriteGate projectId="p1" provider="meta" capability="pause_resume"><button data-testid="pause-on-meta">pause</button></ProviderWriteGate>,
    )
    await waitFor(() => expect(getData).toHaveBeenCalled())
    expect(screen.queryByTestId('pause-on-meta')).toBeNull()
  })
})

describe('CampaignWriteControl', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['campaigns.view']) })
  afterEach(() => signOut())

  it('states the rule and the honest zero: nothing implemented, nothing verified, nothing available', async () => {
    vi.mocked(getData).mockResolvedValue(payload())
    renderWithProviders(<CampaignWriteControl projectId="p1" />, { locale: 'ar' })

    expect(await screen.findByTestId('campaign-write-control')).toBeTruthy()
    expect(screen.getByTestId('write-control-rule').textContent).toContain('منفَّذة ومقيَّدة بصلاحية')
    expect(screen.getByTestId('write-control-implemented').textContent).toContain('0')
    expect(screen.getByTestId('write-control-verified').textContent).toContain('0')
    expect(screen.getByTestId('write-control-allowed').textContent).toContain('0')
    expect(screen.getByTestId('write-control-none')).toBeTruthy()
    expect(screen.getByTestId('write-cap-status-meta-publish').textContent).toBe('غير منفَّذ')
    expect(screen.getAllByTestId(/^write-control-(snapchat|tiktok|meta|google|x|linkedin|openai_ads)$/)).toHaveLength(7)
    expect(screen.queryByRole('button')).toBeNull()
  })

  it('names each status in English and counts what is implemented, verified and available', async () => {
    vi.mocked(getData).mockResolvedValue(payload((c) => {
      if (c.provider === 'meta' && c.capability === 'pause_resume') return { ...c, status: 'verified', evidence: 'act_1: paused 2026-10-01', allowed: true }
      if (c.provider === 'meta' && c.capability === 'budget_change') return { ...c, status: 'implemented_not_verified', allowed: true }
      if (c.provider === 'snapchat' && c.capability === 'publish') return { ...c, status: 'awaiting_credentials' }
      return c
    }))
    renderWithProviders(<CampaignWriteControl projectId="p1" />, { locale: 'en' })

    await screen.findByTestId('campaign-write-control')
    expect(screen.getByTestId('write-control-implemented').textContent).toContain('3')
    expect(screen.getByTestId('write-control-verified').textContent).toContain('1')
    expect(screen.getByTestId('write-control-allowed').textContent).toContain('2')
    expect(screen.queryByTestId('write-control-none')).toBeNull()
    expect(screen.getByTestId('write-cap-status-meta-pause_resume').textContent).toBe('Verified in Production')
    expect(screen.getByTestId('write-cap-status-meta-budget_change').textContent).toBe('Implemented, not verified')
    expect(screen.getByTestId('write-cap-status-snapchat-publish').textContent).toBe('Awaiting credentials')
    expect(screen.getByTestId('write-cap-status-tiktok-publish').textContent).toBe('Not implemented')
  })
})
