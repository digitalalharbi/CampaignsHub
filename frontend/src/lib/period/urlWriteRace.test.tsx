import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, screen } from '@testing-library/react'
import { useLocation } from 'react-router-dom'
import { useUrlState } from '@/features/analytics/filterUrlState'
import { resetPeriodSession, usePeriod, type PeriodState } from './usePeriod'
import { resetPendingSearchParams } from '@/lib/url/latestSearchParams'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

/**
 * URL-WRITE-RACE-001 — two address writes in one tick both survive.
 *
 * React Router hands a functional `setSearchParams` the params of the render that made the setter, so the period
 * (`usePeriod`) and a tab (`useUrlState`) written before React re-renders each started from the same stale address and
 * the second write dropped the first — the analytics tab click that «stayed unselected» on Firefox (CI PR #1071).
 */
let period: PeriodState
let setTab: (v: string) => void

function Probe({ writeUrl }: { writeUrl?: boolean }) {
  period = usePeriod(writeUrl === false ? { writeUrl } : {})
  const [tab, set] = useUrlState('tab', 'overview')
  setTab = set
  const loc = useLocation()

  return <><div data-testid="search">{loc.search}</div><div data-testid="tab">{tab}</div></>
}

const params = () => new URLSearchParams(screen.getByTestId('search').textContent ?? '')

describe('address writes in the same tick', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-11-15T06:00:00Z'))
    resetPeriodSession(); resetPendingSearchParams(); window.localStorage.clear()
    signInWith(['analytics.view'])
  })
  afterEach(() => { vi.useRealTimers(); signOut() })

  it('a tab click and a period write before the next render keep both', () => {
    renderWithProviders(<Probe />, { route: '/agency/analytics' })
    // The mount wrote the period.
    expect(params().get('from')).toBe('2026-11-08')

    act(() => { setTab('quality'); period.setPreset('last_30') })
    expect(params().get('tab')).toBe('quality')
    expect(params().get('from')).toBe('2026-10-16')
    expect(screen.getByTestId('tab')).toHaveTextContent('quality')
  })

  it('the period written first is not undone by a tab written from the same stale render', () => {
    renderWithProviders(<Probe />, { route: '/agency/analytics' })

    act(() => { period.setPreset('last_30'); setTab('quality') })
    expect(params().get('tab')).toBe('quality')
    expect(params().get('from')).toBe('2026-10-16')
  })

  it('a reader of the period that does not own the address leaves it alone', () => {
    renderWithProviders(<Probe writeUrl={false} />, { route: '/app/reports?status=done' })
    expect(params().toString()).toBe('status=done')
    expect(period.range).toEqual({ from: '2026-11-08', to: '2026-11-14' })
  })
})
