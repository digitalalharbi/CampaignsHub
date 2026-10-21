import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { BulkCampaignActions, runBulk } from './BulkCampaignActions'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('@/lib/api/client', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, getData: vi.fn() }
})
vi.mock('./providerWrites', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, performWrite: vi.fn() }
})
import { getData } from '@/lib/api/client'
import { performWrite } from './providerWrites'

const entity = (id: string, provider: string, pause: string) => ({ id, level: 'campaign', provider, external_id: `x-${id}`, name: id, actions: { pause, resume: pause } })

/** CAMPAIGNS-TABLE-002 — several campaigns paused or resumed on the platforms through the per-campaign write path. */
describe('bulk pause/resume', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(getData).mockImplementation(async (url: string) => {
      if (url.includes('/campaigns/c1/')) return { entities: [entity('e1', 'meta', 'available')] }
      if (url.includes('/campaigns/c2/')) return { entities: [entity('e2', 'snapchat', 'awaiting_credentials')] }
      return { entities: [] }
    })
    vi.mocked(performWrite).mockResolvedValue({ ok: true })
  })
  afterEach(() => signOut())

  it('sends only what the server offers, reports a refusal and a draft honestly, and never invents a write', async () => {
    const out = await runBulk('p1', [{ id: 'c1', name: 'A' }, { id: 'c2', name: 'B' }, { id: 'c3', name: 'C' }], 'pause')
    expect(performWrite).toHaveBeenCalledTimes(1)
    expect(performWrite).toHaveBeenCalledWith('p1', 'c1', { level: 'campaign', entity_id: 'e1', action: 'pause' })
    expect(out.map((o) => o.result)).toEqual(['done', 'refused', 'nothing'])
    expect(out[1]).toMatchObject({ provider: 'snapchat', message: 'awaiting_credentials' })
  })

  it('reports what the platform refused instead of a success', async () => {
    vi.mocked(performWrite).mockResolvedValue({ ok: false, refusal: 'provider_error', message: 'Rate limited' })
    const out = await runBulk('p1', [{ id: 'c1', name: 'A' }], 'resume')
    expect(out[0]).toMatchObject({ result: 'refused', message: 'Rate limited' })
  })

  it('without the pause permission offers no buttons, only the reason', () => {
    signInWith(['campaigns.view'])
    renderWithProviders(<BulkCampaignActions projectId="p1" selected={[{ id: 'c1', name: 'A' }]} onClear={() => {}} ar />, { locale: 'ar' })
    expect(screen.getByTestId('campaigns-bulk-denied')).toBeInTheDocument()
    expect(screen.queryByTestId('campaigns-bulk-pause')).toBeNull()
  })

  it('confirms before sending, then lists each campaign with the platform’s answer', async () => {
    signInWith(['campaigns.view', 'campaigns.pause'])
    renderWithProviders(<BulkCampaignActions projectId="p1" selected={[{ id: 'c1', name: 'A' }, { id: 'c2', name: 'B' }]} onClear={() => {}} ar />, { locale: 'ar' })
    expect(screen.getByTestId('campaigns-bulk-count')).toHaveTextContent('المحدد: 2')
    fireEvent.click(screen.getByTestId('campaigns-bulk-pause'))
    expect(performWrite).not.toHaveBeenCalled()
    expect(screen.getByTestId('campaigns-bulk-dialog')).toHaveTextContent('لا يُغيَّر شيء محليًا قبل تأكيد المنصة')
    fireEvent.click(screen.getByTestId('campaigns-bulk-confirm'))
    const results = await screen.findByTestId('campaigns-bulk-results')
    await waitFor(() => expect(results).toHaveTextContent('أكّدت المنصة'))
    expect(results).toHaveTextContent('رُفض: بانتظار بيانات اعتماد المنصة')
    expect(performWrite).toHaveBeenCalledTimes(1)
  })
})
