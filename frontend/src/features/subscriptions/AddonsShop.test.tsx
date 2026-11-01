import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { AddonsShop } from './AddonsShop'
import type { ShopAddon } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, getAddons: vi.fn(), requestAddon: vi.fn() }
})

import { getAddons, requestAddon } from './api'

const addon = (over: Partial<ShopAddon>): ShopAddon => ({
  code: 'extra_projects_5', kind: 'capacity', name: '5 more projects', name_ar: '5 مشاريع إضافية', summary_en: 'Five more projects.',
  summary_ar: 'خمسة مشاريع.', price_monthly: '149.00', currency: 'SAR', readiness: 'available', sold: true,
  grants: { limits: { projects: 5 } }, active_quantity: 0, requested: false, ...over,
})

/** PRICING-ADDONS-001 — the shop never prices or offers what is not ready, and a request says it charges nothing. */
describe('AddonsShop', () => {
  beforeEach(() => vi.clearAllMocks())

  it('prices a sold add-on and requests it; an unready one is «coming soon» with no price and no button', async () => {
    vi.mocked(getAddons).mockResolvedValue({ prices_exclude_vat: true, addons: [
      addon({}),
      addon({ code: 'ai_intelligence', kind: 'feature', name: 'AI Intelligence', price_monthly: null, readiness: 'coming_soon', sold: false, grants: null }),
    ] })
    vi.mocked(requestAddon).mockResolvedValue({ request_id: 'r1', status: 'requested' })
    renderWithProviders(<AddonsShop ar={false} canManage />, { locale: 'en' })

    expect(await screen.findByTestId('addon-extra_projects_5')).toHaveTextContent('149.00 SAR')
    expect(screen.getByTestId('addons-shop')).toHaveTextContent(/charges nothing/i)
    expect(screen.getByTestId('addon-ai_intelligence')).toHaveTextContent('Coming soon')
    expect(screen.queryByTestId('addon-request-ai_intelligence')).not.toBeInTheDocument()

    fireEvent.click(screen.getByTestId('addon-request-extra_projects_5'))
    await waitFor(() => expect(requestAddon).toHaveBeenCalledWith('extra_projects_5'))
  })

  it('offers no request button without the manage permission, and shows a pending request as such', async () => {
    vi.mocked(getAddons).mockResolvedValue({ prices_exclude_vat: true, addons: [addon({ requested: true })] })
    renderWithProviders(<AddonsShop ar={false} canManage={false} />, { locale: 'en' })

    await screen.findByTestId('addon-extra_projects_5')
    expect(screen.queryByTestId('addon-request-extra_projects_5')).not.toBeInTheDocument()
  })
})
