import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { AddonsPage } from './AddonsPage'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchAddons: vi.fn(), updateAddon: vi.fn() }
})

import { fetchAddons, updateAddon } from './api'

/** PRICING-ADDONS-001 — pricing an add-on and opening it for sale needs a change and a reason. */
describe('AddonsPage', () => {
  beforeEach(() => vi.clearAllMocks())

  it('prices a coming-soon add-on and puts it on sale with the reason given', async () => {
    vi.mocked(fetchAddons).mockResolvedValue({ addons: [{
      id: 'a1', code: 'ai_intelligence', kind: 'feature', name: 'AI Intelligence', name_ar: 'الذكاء الاصطناعي', price_monthly: null,
      currency: 'SAR', readiness: 'coming_soon', is_active: true, sold: false, grants: null, active_workspaces: 0, requests_waiting: 0,
    }] })
    vi.mocked(updateAddon).mockResolvedValue({ addon: {} as never })
    renderWithProviders(<AddonsPage />, { locale: 'en' })

    const save = await screen.findByTestId('admin-addon-save-ai_intelligence')
    expect(save).toBeDisabled()
    fireEvent.change(screen.getByTestId('admin-addon-price-ai_intelligence'), { target: { value: '249' } })
    fireEvent.change(screen.getByTestId('admin-addon-readiness-ai_intelligence'), { target: { value: 'available' } })
    expect(save).toBeDisabled()
    fireEvent.change(screen.getByTestId('admin-addon-reason-ai_intelligence'), { target: { value: 'Priced after cost measurement' } })
    fireEvent.click(save)

    await waitFor(() => expect(updateAddon).toHaveBeenCalledWith('a1', { price_monthly: 249, readiness: 'available', reason: 'Priced after cost measurement' }))
  })
})
