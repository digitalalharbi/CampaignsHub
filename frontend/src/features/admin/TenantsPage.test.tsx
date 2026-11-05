import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { TenantsPage } from './TenantsPage'
import type { PlatformTenant } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchTenants: vi.fn(), setTenantStatus: vi.fn() }
})

import { fetchTenants, setTenantStatus } from './api'

const tenant = (over: Partial<PlatformTenant> = {}): PlatformTenant => ({
  id: 't-1', name: 'Acme', slug: 'acme', status: 'suspended', account_type: 'agency', subscription_plan: 'solo',
  onboarding_completed: true, people: 2, client_workspaces: 1, created_at: null, ...over,
})

/** ADMIN-FULL-CONTROL-001 — either direction of the switch decides who can get in, so either direction asks why. */
describe('TenantsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(setTenantStatus).mockResolvedValue({ tenant: { id: 't-1', status: 'active' }, public_intake_affected: false })
  })

  it('does not reactivate on one click: it asks why, then sends the reason', async () => {
    vi.mocked(fetchTenants).mockResolvedValue({ tenants: [tenant()], meta: { total: 1 } })
    renderWithProviders(<TenantsPage />, { route: '/admin/tenants', locale: 'en' })

    fireEvent.click(await screen.findByTestId('tenant-reactivate-acme'))
    expect(setTenantStatus).not.toHaveBeenCalled()
    expect(screen.getByTestId('tenant-status-confirm')).toBeDisabled()

    fireEvent.change(screen.getByTestId('tenant-status-reason'), { target: { value: 'The dispute is settled' } })
    fireEvent.click(screen.getByTestId('tenant-status-confirm'))

    await waitFor(() => expect(setTenantStatus).toHaveBeenCalledWith('t-1', 'active', 'The dispute is settled'))
  })

  it('suspending still warns who loses access', async () => {
    vi.mocked(fetchTenants).mockResolvedValue({ tenants: [tenant({ status: 'active' })], meta: { total: 1 } })
    renderWithProviders(<TenantsPage />, { route: '/admin/tenants', locale: 'en' })

    fireEvent.click(await screen.findByTestId('tenant-suspend-acme'))

    expect(screen.getByRole('dialog')).toHaveTextContent(/lose access/)
  })
})
