import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { TenantAddonsPanel } from './TenantAddonsPanel'
import { TenantLimitsPanel } from './TenantLimitsPanel'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchWorkspaceAddons: vi.fn(), fetchAddons: vi.fn(), grantWorkspaceAddon: vi.fn(), decideWorkspaceAddon: vi.fn(), fetchTenantLimits: vi.fn() }
})

import { fetchAddons, fetchTenantLimits, fetchWorkspaceAddons, grantWorkspaceAddon } from './api'

/** PRICING-ADDONS-001 — granting an add-on refreshes the caps shown in the same drawer, so the operator never reads a stale limit. */
describe('TenantAddonsPanel', () => {
  beforeEach(() => vi.clearAllMocks())

  it('refreshes the limits after a grant', async () => {
    vi.mocked(fetchWorkspaceAddons).mockResolvedValue({ addons: [] })
    vi.mocked(fetchAddons).mockResolvedValue({ addons: [{ id: 'a1', code: 'extra_projects_5', kind: 'capacity', name: '5 more projects', name_ar: 'x',
      price_monthly: '149.00', currency: 'SAR', readiness: 'available', is_active: true, active_workspaces: 0, requests_waiting: 0 } as never] })
    vi.mocked(fetchTenantLimits).mockResolvedValue({ limits: [{ metric: 'projects', plan_limit: 5, override: null, lapsed: false, effective_limit: 3, usage: 0 }] } as never)
    vi.mocked(grantWorkspaceAddon).mockResolvedValue({ addons: [] })
    renderWithProviders(<><TenantAddonsPanel tenantId="t1" ar={false} /><TenantLimitsPanel tenantId="t1" ar={false} /></>, { locale: 'en' })

    await waitFor(() => expect(fetchTenantLimits).toHaveBeenCalledTimes(1))
    fireEvent.change(await screen.findByTestId('tenant-addon-grant-code'), { target: { value: 'extra_projects_5' } })
    fireEvent.change(screen.getByTestId('tenant-addon-reason'), { target: { value: 'Agreed with the customer' } })
    fireEvent.click(screen.getByTestId('tenant-addon-grant'))

    await waitFor(() => expect(grantWorkspaceAddon).toHaveBeenCalledWith('t1', 'extra_projects_5', 1, 'Agreed with the customer'))
    await waitFor(() => expect(fetchTenantLimits).toHaveBeenCalledTimes(2))
  })
})
