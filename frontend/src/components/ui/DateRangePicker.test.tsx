import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, fireEvent, screen, waitFor, within } from '@testing-library/react'
import { Link, useLocation } from 'react-router-dom'
import { DateRangePicker } from './DateRangePicker'
import { PeriodStatus } from './PeriodStatus'
import { presetPeriod } from '@/lib/period/periodMath'
import { resetPeriodSession } from '@/lib/period/usePeriod'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import type { PeriodCoverage } from '@/lib/period/coverageApi'

vi.mock('@/lib/api/client', async (orig) => ({ ...(await orig<Record<string, unknown>>()), getData: vi.fn() }))
import { getData } from '@/lib/api/client'

/**
 * DATE-RANGE-001 — the picker, on a fixed clock: Sunday 15 November 2026, 09:00 in Riyadh.
 */
const NOW = new Date('2026-11-15T06:00:00Z')

function Where() {
  const loc = useLocation()

  return <div data-testid="where">{loc.pathname}{loc.search}</div>
}

function Pages() {
  return (
    <>
      <Where />
      <Link to="/agency/campaigns" data-testid="go-campaigns">campaigns</Link>
      <Link to="/agency/analytics" data-testid="go-analytics">analytics</Link>
      <DateRangePicker projectId="p1" />
    </>
  )
}

const coverage = (over: Partial<PeriodCoverage> = {}): PeriodCoverage => ({
  period: { from: '2026-11-08', to: '2026-11-14', ongoing: false, timezone: 'Asia/Riyadh' },
  ongoing: false, incomplete: false, accounts: [],
  summary: { accounts_checked: 0, accounts_incomplete: 0, missing_days_total: 0, accounts_other_timezone: 0 },
  ...over,
})

async function pick(preset: string) {
  fireEvent.click(await screen.findByTestId('date-range-button'))
  fireEvent.click(await screen.findByTestId(`date-range-preset-${preset}`))
  fireEvent.click(screen.getByTestId('date-range-apply'))
}

describe('the period picker', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(NOW)
    resetPeriodSession()
    window.localStorage.clear()
    signInWith(['campaigns.view'])
    vi.mocked(getData).mockResolvedValue(coverage())
  })
  afterEach(() => { vi.useRealTimers(); signOut() })

  it('opens a new user on the last 7 complete days, said in words and dates, and writes them in the address', async () => {
    renderWithProviders(<Pages />, { locale: 'ar', route: '/agency/dashboard' })
    expect(screen.getByTestId('date-range-summary')).toHaveTextContent('آخر 7 أيام: 8 نوفمبر – 14 نوفمبر 2026')
    await waitFor(() => expect(screen.getByTestId('where')).toHaveTextContent('from=2026-11-08&to=2026-11-14'))
  })

  it('«آخر 30 يوم» is 16 October → 14 November — it ends yesterday', async () => {
    renderWithProviders(<Pages />, { locale: 'ar' })
    await pick('last_30')
    expect(screen.getByTestId('date-range-summary')).toHaveTextContent('آخر 30 يوم: 16 أكتوبر – 14 نوفمبر 2026')
    expect(screen.getByTestId('where')).toHaveTextContent('from=2026-10-16&to=2026-11-14')
  })

  it('«هذا الشهر» runs 1 → 15 November and draws no «in progress» badge (PERIOD-ONGOING-BADGE-OFF-001)', async () => {
    vi.mocked(getData).mockResolvedValue(coverage({ ongoing: true }))
    renderWithProviders(<Pages />, { locale: 'ar' })
    await pick('this_month')
    expect(screen.getByTestId('date-range-summary')).toHaveTextContent('هذا الشهر: 1 نوفمبر – 15 نوفمبر 2026')
    await new Promise((r) => setTimeout(r, 0))
    expect(screen.queryByTestId('date-range-status-ongoing')).toBeNull()
    expect(document.body.textContent).not.toContain('جاري — غير مكتمل')
  })

  it('«الشهر الماضي» is all of October', async () => {
    renderWithProviders(<Pages />, { locale: 'ar' })
    await pick('last_month')
    expect(screen.getByTestId('where')).toHaveTextContent('from=2026-10-01&to=2026-10-31')
  })

  it('«هذا الأسبوع» starts on Sunday, which 15 November is', async () => {
    renderWithProviders(<Pages />, { locale: 'ar' })
    await pick('this_week')
    expect(screen.getByTestId('where')).toHaveTextContent('from=2026-11-15&to=2026-11-15')
  })

  it('compares «آخر 30 يوم» with the previous 30 days: 16 September → 15 October', async () => {
    renderWithProviders(<Pages />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('date-range-button'))
    fireEvent.click(await screen.findByTestId('date-range-preset-last_30'))
    fireEvent.click(screen.getByTestId('date-range-compare-toggle'))
    expect(screen.getByTestId('date-range-compare-range')).toHaveTextContent('16 سبتمبر – 15 أكتوبر 2026')
    fireEvent.click(screen.getByTestId('date-range-apply'))
    expect(screen.getByTestId('where')).toHaveTextContent('compare=previous')
    expect(screen.getByTestId('date-range-compare-summary')).toHaveTextContent('مقارنة بـ 16 سبتمبر – 15 أكتوبر 2026')
  })

  it('keeps the period when you move to another page, and carries it in that page’s address', async () => {
    renderWithProviders(<Pages />, { locale: 'ar' })
    await pick('last_30')
    fireEvent.click(screen.getByTestId('go-analytics'))
    await waitFor(() => expect(screen.getByTestId('where')).toHaveTextContent('/agency/analytics?'))
    expect(screen.getByTestId('date-range-summary')).toHaveTextContent('آخر 30 يوم: 16 أكتوبر – 14 نوفمبر 2026')
    await waitFor(() => expect(screen.getByTestId('where')).toHaveTextContent('from=2026-10-16&to=2026-11-14'))
  })

  it('survives a reload: a fresh session reads the user’s stored choice', async () => {
    const first = renderWithProviders(<Pages />, { locale: 'ar' })
    await pick('last_14')
    first.unmount()
    resetPeriodSession() // a new tab: nothing in memory, only storage
    renderWithProviders(<Pages />, { locale: 'ar' })
    expect(screen.getByTestId('date-range-summary')).toHaveTextContent('آخر 14 يوم: 1 نوفمبر – 14 نوفمبر 2026')
  })

  it('opens a shared link on the same days', () => {
    renderWithProviders(<Pages />, { locale: 'ar', route: '/agency/reports?from=2026-09-03&to=2026-09-09&compare=custom&cfrom=2026-08-01&cto=2026-08-07' })
    expect(screen.getByTestId('date-range-summary')).toHaveTextContent('3 سبتمبر – 9 سبتمبر 2026')
    expect(screen.getByTestId('date-range-compare-summary')).toHaveTextContent('1 أغسطس – 7 أغسطس 2026')
  })

  it('does not break when storage is blocked', async () => {
    const spy = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('blocked') })
    renderWithProviders(<Pages />, { locale: 'ar' })
    await pick('last_90')
    expect(screen.getByTestId('date-range-summary')).toHaveTextContent('آخر 90 يوم')
    spy.mockRestore()
  })

  it('lists the last three periods under «مستخدمة مؤخرًا»', async () => {
    renderWithProviders(<Pages />, { locale: 'ar' })
    await pick('last_30'); await pick('yesterday'); await pick('last_14'); await pick('last_28')
    fireEvent.click(screen.getByTestId('date-range-button'))
    const recent = await screen.findByTestId('date-range-recent')
    expect(within(recent).getAllByRole('button')).toHaveLength(3)
    expect(recent).toHaveTextContent('مستخدمة مؤخرًا')
    expect(within(recent).getAllByRole('button')[0]).toHaveTextContent('آخر 28 يوم')
  })

  it('takes typed dates, refuses a future day and a malformed one, and says why', async () => {
    renderWithProviders(<Pages />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('date-range-button'))
    const from = await screen.findByTestId('date-range-from')
    const to = screen.getByTestId('date-range-to')
    fireEvent.change(to, { target: { value: '2026-11-16' } }); fireEvent.blur(to)
    expect(screen.getByTestId('date-range-error')).toHaveTextContent('لم يأتِ بعد')
    fireEvent.change(from, { target: { value: '3/9/26' } }); fireEvent.blur(from)
    expect(screen.getByTestId('date-range-error')).toHaveTextContent('2026-11-14')
    fireEvent.change(from, { target: { value: '2026-11-3' } }); fireEvent.change(to, { target: { value: '2026-11-9' } }); fireEvent.blur(to)
    expect(screen.queryByTestId('date-range-error')).not.toBeInTheDocument()
    fireEvent.click(screen.getByTestId('date-range-apply'))
    expect(screen.getByTestId('where')).toHaveTextContent('from=2026-11-03&to=2026-11-09')
  })

  it('picks a range with two clicks, and days after today cannot be clicked', async () => {
    renderWithProviders(<Pages />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('date-range-button'))
    const d3 = await screen.findByTestId('day-2026-11-03')
    fireEvent.click(d3)
    fireEvent.click(screen.getByTestId('day-2026-11-09'))
    expect(screen.getByTestId('day-2026-11-05')).toHaveAttribute('data-state', 'period')
    expect(screen.getByTestId('day-2026-11-16')).toBeDisabled()
    fireEvent.click(screen.getByTestId('date-range-apply'))
    expect(screen.getByTestId('where')).toHaveTextContent('from=2026-11-03&to=2026-11-09')
  })

  it('picks a range by dragging with the mouse', async () => {
    renderWithProviders(<Pages />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('date-range-button'))
    const a = await screen.findByTestId('day-2026-11-02')
    const b = screen.getByTestId('day-2026-11-06')
    fireEvent.pointerDown(a, { pointerType: 'mouse', button: 0 })
    fireEvent.pointerEnter(b, { pointerType: 'mouse' })
    fireEvent.pointerUp(b, { pointerType: 'mouse' })
    expect(screen.getByTestId('day-2026-11-04')).toHaveAttribute('data-state', 'period')
    fireEvent.click(screen.getByTestId('date-range-apply'))
    expect(screen.getByTestId('where')).toHaveTextContent('from=2026-11-02&to=2026-11-06')
  })

  it('shows both ranges in the calendar when comparing', async () => {
    renderWithProviders(<Pages />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('date-range-button'))
    fireEvent.click(await screen.findByTestId('date-range-compare-toggle'))
    // last 7 days = 8..14 Nov; previous = 1..7 Nov
    expect(screen.getByTestId('day-2026-11-10')).toHaveAttribute('data-state', 'period')
    expect(screen.getByTestId('day-2026-11-04')).toHaveAttribute('data-state', 'compare')
  })

  it('«إلغاء» leaves the period alone, and the footer states the clock', async () => {
    renderWithProviders(<Pages />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('date-range-button'))
    fireEvent.click(await screen.findByTestId('date-range-preset-last_90'))
    expect(screen.getByTestId('date-range-zone')).toHaveTextContent('التواريخ بتوقيت الرياض (GMT+3)')
    fireEvent.click(screen.getByTestId('date-range-cancel'))
    expect(screen.getByTestId('date-range-summary')).toHaveTextContent('آخر 7 أيام')
    expect(screen.queryByTestId('date-range-panel')).not.toBeInTheDocument()
  })

  it('closes on Escape', async () => {
    renderWithProviders(<Pages />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('date-range-button'))
    await screen.findByTestId('date-range-panel')
    act(() => { fireEvent.keyDown(document, { key: 'Escape' }) })
    expect(screen.queryByTestId('date-range-panel')).not.toBeInTheDocument()
  })

  it('FILTERS-LIVE-001 — the page follows a preset at once, before «تحديث»; «إلغاء» puts the old period back', async () => {
    renderWithProviders(<Pages />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('date-range-button'))
    fireEvent.click(await screen.findByTestId('date-range-preset-last_30'))
    await waitFor(() => expect(screen.getByTestId('where')).toHaveTextContent('from=2026-10-16&to=2026-11-14'))
    expect(screen.getByTestId('date-range-summary')).toHaveTextContent('آخر 30 يوم')
    expect(screen.getByTestId('date-range-panel')).toBeInTheDocument()
    fireEvent.click(screen.getByTestId('date-range-cancel'))
    await waitFor(() => expect(screen.getByTestId('date-range-summary')).toHaveTextContent('آخر 7 أيام'))
  })

  it('FILTERS-LIVE-001 — a finished two-click range reaches the page at once; half a range does not', async () => {
    renderWithProviders(<Pages />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('date-range-button'))
    fireEvent.click(await screen.findByTestId('day-2026-11-03'))
    expect(screen.getByTestId('date-range-summary')).toHaveTextContent('آخر 7 أيام')
    fireEvent.click(screen.getByTestId('day-2026-11-09'))
    await waitFor(() => expect(screen.getByTestId('where')).toHaveTextContent('from=2026-11-03&to=2026-11-09'))
  })

  it('a press outside the panel keeps the choice and closes', async () => {
    renderWithProviders(<Pages />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('date-range-button'))
    fireEvent.click(await screen.findByTestId('date-range-preset-last_90'))
    act(() => { fireEvent.pointerDown(document.body) })
    expect(screen.queryByTestId('date-range-panel')).not.toBeInTheDocument()
    expect(screen.getByTestId('date-range-summary')).toHaveTextContent('آخر 90 يوم')
  })

  it('speaks English when the page does', () => {
    renderWithProviders(<Pages />, { locale: 'en' })
    expect(screen.getByTestId('date-range-summary')).toHaveTextContent('Last 7 days: Nov 8 – Nov 14, 2026')
  })
})

describe('the badges on the numbers', () => {
  beforeEach(() => { vi.useFakeTimers({ toFake: ['Date'] }); vi.setSystemTime(NOW); signInWith(['campaigns.view']) })
  afterEach(() => { vi.useRealTimers(); signOut() })

  it('a missing day inside the range gives «بيانات غير مكتملة», naming the account and the day', async () => {
    vi.mocked(getData).mockResolvedValue(coverage({
      incomplete: true,
      accounts: [{
        account_id: 'a1', name: 'حساب سناب', provider: 'snapchat', timezone: 'Asia/Riyadh', timezone_differs: false, never_synced: false,
        missing_count: 1, failed_count: 1, missing_days: ['2026-11-11'], failed_days: ['2026-11-11'], truncated: false,
      }],
    }))
    renderWithProviders(<PeriodStatus projectId="p1" period={presetPeriod('last_7', { today: '2026-11-15' })} />, { locale: 'ar' })
    const chip = await screen.findByTestId('period-status-incomplete')
    expect(chip).toHaveTextContent('بيانات غير مكتملة')
    fireEvent.click(chip)
    const tip = await screen.findByTestId('period-status-incomplete-tip')
    expect(tip).toHaveTextContent('حساب سناب')
    expect(tip).toHaveTextContent('11 نوفمبر')
    expect(tip).toHaveTextContent('فشلت المزامنة')
  })

  it('flags an account on another time zone in the tooltip', async () => {
    vi.mocked(getData).mockResolvedValue(coverage({
      incomplete: true,
      accounts: [{
        account_id: 'a2', name: 'Acct LA', provider: 'meta', timezone: 'America/Los_Angeles', timezone_differs: true, never_synced: false,
        missing_count: 1, failed_count: 0, missing_days: ['2026-11-11'], failed_days: [], truncated: false,
      }],
    }))
    renderWithProviders(<PeriodStatus projectId="p1" period={presetPeriod('last_7', { today: '2026-11-15' })} />, { locale: 'ar' })
    fireEvent.click(await screen.findByTestId('period-status-incomplete'))
    expect(await screen.findByTestId('period-zone-note')).toHaveTextContent('America/Los_Angeles')
  })

  it('shows nothing for a complete period that does not include today', async () => {
    vi.mocked(getData).mockResolvedValue(coverage())
    renderWithProviders(<PeriodStatus projectId="p1" period={presetPeriod('last_7', { today: '2026-11-15' })} />, { locale: 'ar' })
    await waitFor(() => expect(getData).toHaveBeenCalled())
    expect(screen.queryByTestId('period-status')).not.toBeInTheDocument()
  })
})
