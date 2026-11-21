import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { AccountsWorkspacePage } from './AccountsWorkspacePage'
import { renderWithProviders, signInWith } from '@/test/utils'
import { useProject } from '@/stores/project'

const row = (provider: string, id: string, name: string, spend: number, impressions: number, clicks: number) => ({
  provider, account_id: id, account_name: name, spend, impressions, clicks, spend_reported: true,
})
const pulse = (provider: string, id: string, state: string, active: number, spends: number[]) => ({
  provider, account_id: id, state, active_campaigns: active, paused_campaigns: 0, ended_campaigns: 0,
  daily: spends.map((s, i) => ({ date: `2026-11-0${i + 1}`, spend: s, clicks: 0, impressions: 0 })),
  last_spend_date: '2026-11-20', platform_status: 'active', external_id: `ext-${id}`, account_currency: 'SAR', timezone: 'Asia/Riyadh',
  last_synced_at: '2026-11-21T02:00:00Z', last_sync_attempt_at: null, sync_error: state === 'error' ? 'access_lost' : null,
})

vi.mock('@/features/analytics/api', async (orig) => ({
  ...(await orig<Record<string, unknown>>()),
  useAccounts: () => ({
    data: [
      row('tiktok', 't1', 'TikTok Quiet', 0, 0, 0),
      row('snapchat', 's1', 'Snap Live', 795, 867000, 3310),
      row('meta', 'm1', 'Meta Broken', 50, 103000, 1350),
    ],
    isLoading: false,
    isError: false,
  }),
  useAccountPulse: () => ({
    data: [
      pulse('snapchat', 's1', 'running', 3, [100, 200, 300]),
      pulse('meta', 'm1', 'error', 1, [10, 20, 20]),
      pulse('tiktok', 't1', 'stopped', 0, [0, 0, 0]),
    ],
  }),
  useSummary: () => ({ data: undefined }),
}))

/** ACCOUNTS-PULSE-001 — running or stopped, at a glance, with the platform's logo and a details popup. */
describe('accounts page: state, logos and details', () => {
  beforeEach(() => {
    localStorage.clear()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })

  it('states every account and orders problems first, then running, then stopped', async () => {
    renderWithProviders(<AccountsWorkspacePage />, { locale: 'en' })
    const table = await screen.findByTestId('accounts-table')
    const states = within(table).getAllByTestId('account-state').map((el) => el.getAttribute('data-state'))
    expect(states).toEqual(['error', 'running', 'stopped'])
    expect(within(table).getAllByTestId('provider-mark').length).toBeGreaterThanOrEqual(3)
  })

  it('counts running accounts and flags the ones needing attention', async () => {
    renderWithProviders(<AccountsWorkspacePage />, { locale: 'en' })
    const card = await screen.findByTestId('accounts-running')
    expect(card.textContent).toContain('1')
    expect(card.textContent).toContain('/ 3')
    expect(card.textContent).toContain('1 need attention')
  })

  it('opens the account in a popup with its campaigns and its connection problem', async () => {
    renderWithProviders(<AccountsWorkspacePage />, { locale: 'en' })
    const table = await screen.findByTestId('accounts-table')
    fireEvent.click(within(table).getAllByTestId('account-open')[0])
    const detail = await screen.findByTestId('account-detail')
    expect(detail.textContent).toContain('ext-m1')
    expect(within(detail).getByRole('alert').textContent).toMatch(/reconnect/i)
    expect(within(detail).getByTestId('account-detail-campaigns').textContent).toContain('Active campaigns')
  })
})
