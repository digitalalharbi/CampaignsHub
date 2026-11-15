import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { DateRangePicker } from '@/components/ui/DateRangePicker'
import { useSpendLimits } from '@/features/budget/spendLimitsApi'
import { resetPeriodSession } from './usePeriod'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('@/lib/api/client', async (orig) => ({ ...(await orig<Record<string, unknown>>()), getEnvelope: vi.fn(), getData: vi.fn() }))
import { getEnvelope, getData } from '@/lib/api/client'

/**
 * DATE-RANGE-001 — budgets and spend limits keep THEIR OWN period. Changing the viewing period must not
 * re-ask for them or change what they say, and the page says so beside the control.
 */
function Probe() {
  const limits = useSpendLimits('p1')

  return (
    <>
      <DateRangePicker projectId="p1" budgetHint />
      <div data-testid="limit-count">{limits.data?.limits.length ?? '…'}</div>
    </>
  )
}

describe('the viewing period does not move budgets', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-11-15T06:00:00Z'))
    resetPeriodSession(); window.localStorage.clear()
    signInWith(['campaigns.view'])
    vi.mocked(getData).mockResolvedValue({ ongoing: false, incomplete: false, accounts: [], period: {}, summary: {} })
    vi.mocked(getEnvelope).mockResolvedValue({ data: [{ id: 'l1' }], meta: {} } as never)
  })
  afterEach(() => { vi.useRealTimers(); signOut() })

  it('reads the spend limits once, whatever period is chosen, and says budgets keep their own', async () => {
    renderWithProviders(<Probe />, { locale: 'ar' })
    await waitFor(() => expect(screen.getByTestId('limit-count')).toHaveTextContent('1'))
    expect(screen.getByTestId('date-range-budget-hint')).toHaveTextContent('الميزانية تُحسب على فترتها، لا على فترة العرض')

    fireEvent.click(screen.getByTestId('date-range-button'))
    fireEvent.click(await screen.findByTestId('date-range-preset-last_90'))
    fireEvent.click(screen.getByTestId('date-range-apply'))
    await waitFor(() => expect(screen.getByTestId('date-range-summary')).toHaveTextContent('آخر 90 يوم'))

    const limitCalls = vi.mocked(getEnvelope).mock.calls.filter(([url]) => String(url).includes('/spend-limits'))
    expect(limitCalls).toHaveLength(1)
    expect(String(limitCalls[0][0])).not.toMatch(/from=|to=/)
    expect(screen.getByTestId('limit-count')).toHaveTextContent('1')
  })
})
