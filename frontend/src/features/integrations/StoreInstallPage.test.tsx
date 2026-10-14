import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { StoreInstallPage } from './StoreInstallPage'

vi.mock('@/lib/api/client', async (orig) => ({
  ...(await orig<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
  postData: vi.fn(),
  ensureCsrfCookie: vi.fn(async () => undefined),
}))

import { getData, postData } from '@/lib/api/client'

/** SALLA-INSTALL-001 — a store installed from Salla's side is named, then claimed into this workspace. */
describe('store install claim', () => {
  afterEach(() => vi.clearAllMocks())

  it('names the store and claims it with the code it was given', async () => {
    vi.mocked(getData).mockResolvedValue({ provider: 'salla', store_name: 'متجر سلة التجريبي', store_domain: 'https://demostore.salla.sa', currency: 'SAR', expires_at: '2026-10-21T00:00:00Z' } as never)
    vi.mocked(postData).mockResolvedValue({ connection_id: 'c1', stores: 1 } as never)
    renderWithProviders(<StoreInstallPage />, { route: '/app/store-install?provider=salla&claim=abc123', locale: 'en' })

    expect(await screen.findByTestId('store-install-name')).toHaveTextContent('متجر سلة التجريبي')
    expect(getData).toHaveBeenCalledWith('/commerce/store-installs/abc123')
    fireEvent.click(screen.getByTestId('store-install-claim'))
    await waitFor(() => expect(postData).toHaveBeenCalledWith('/commerce/store-installs/abc123/claim', {}))
  })

  it('says an expired link is expired', async () => {
    vi.mocked(getData).mockRejectedValue(new Error('404'))
    renderWithProviders(<StoreInstallPage />, { route: '/app/store-install?claim=old', locale: 'en' })

    expect(await screen.findByTestId('store-install-expired')).toHaveTextContent('expired')
  })
})
