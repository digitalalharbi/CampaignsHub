import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { TenantLimitsPanel } from './TenantLimitsPanel'
import type { TenantLimit } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchTenantLimits: vi.fn(), setTenantLimit: vi.fn() }
})

import { fetchTenantLimits, setTenantLimit } from './api'

const row = (over: Partial<TenantLimit> = {}): TenantLimit => ({
  metric: 'projects', plan_limit: 3, override: null, lapsed: false, effective_limit: 3, usage: 3, ...over,
})

/** SUPERADMIN-LIMITS-001 — the drawer shows used / applies and sets a cap with a reason. */
describe('TenantLimitsPanel', () => {
  beforeEach(() => vi.clearAllMocks())

  it('shows usage against what applies, and raises the cap with a reason', async () => {
    vi.mocked(fetchTenantLimits).mockResolvedValue({ limits: [row()] })
    vi.mocked(setTenantLimit).mockResolvedValue({ limits: [row({ override: { limit: 5, expires_at: null, reason: 'x' }, effective_limit: 5 })] })
    renderWithProviders(<TenantLimitsPanel tenantId="t-1" ar={false} />, { locale: 'en' })

    expect(await screen.findByTestId('limit-projects')).toHaveTextContent('3 / 3')
    expect(screen.getByTestId('limit-projects')).toHaveTextContent('From the plan')
    fireEvent.click(screen.getByTestId('limit-edit-projects'))
    fireEvent.change(screen.getByTestId('limit-value'), { target: { value: '5' } })
    expect(screen.getByTestId('limit-save')).toBeDisabled()
    fireEvent.change(screen.getByTestId('limit-reason'), { target: { value: 'Agreed with the customer' } })
    fireEvent.click(screen.getByTestId('limit-save'))

    await waitFor(() => expect(setTenantLimit).toHaveBeenCalledWith('t-1', 'projects', { mode: 'cap', limit: 5, expires_at: null, reason: 'Agreed with the customer' }))
    expect(await screen.findByTestId('limit-projects')).toHaveTextContent('Override · plan 3')
  })

  it('returns to the plan without a number or a date', async () => {
    vi.mocked(fetchTenantLimits).mockResolvedValue({ limits: [row({ override: { limit: null, expires_at: null, reason: 'x' }, effective_limit: null })] })
    vi.mocked(setTenantLimit).mockResolvedValue({ limits: [row()] })
    renderWithProviders(<TenantLimitsPanel tenantId="t-1" ar={false} />, { locale: 'en' })

    expect(await screen.findByTestId('limit-projects')).toHaveTextContent('3 / Unlimited')
    fireEvent.click(screen.getByTestId('limit-edit-projects'))
    fireEvent.click(screen.getByTestId('limit-mode-inherit'))
    fireEvent.change(screen.getByTestId('limit-reason'), { target: { value: 'Raise is over' } })
    fireEvent.click(screen.getByTestId('limit-save'))

    await waitFor(() => expect(setTenantLimit).toHaveBeenCalledWith('t-1', 'projects', { mode: 'inherit', limit: undefined, expires_at: null, reason: 'Raise is over' }))
  })
})
