import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { ConnectionHub, HubSummary } from './ConnectionHub'
import type { ConnectionHub as Hub, HubConnection } from './api'
import { renderWithProviders } from '@/test/utils'

/**
 * INTEG-PROJECT-SPACE-001 — a source is ONE compact row: the platform by its short name and mark, its
 * numbers, one state chip and at most one action. Expanding it lists the linked accounts, one per line,
 * each with a light-confirm «فصل». Money is read through the money contract: withheld stays withheld.
 */

const hub = vi.hoisted(() => ({ data: { connections: [], connectable: [] } as Hub }))
const calls = vi.hoisted(() => ({ unlinked: [] as Array<[string, string]> }))
const metrics = vi.hoisted(() => ({ summary: undefined as unknown, accounts: [] as unknown[] }))

vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  fetchConnectionHub: () => Promise.resolve(hub.data),
  fetchConnectionHubWithRemoved: () => Promise.resolve(hub.data),
  unlinkAccount: (projectId: string, accountId: string) => { calls.unlinked.push([projectId, accountId]); return Promise.resolve({}) },
}))

vi.mock('@/features/analytics/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/analytics/api')>()),
  useSummary: (projectId: string | null) => ({ data: projectId === null ? undefined : metrics.summary }),
  useAccounts: (projectId: string | null) => ({ data: projectId === null ? undefined : metrics.accounts }),
}))

function connection(over: Partial<HubConnection> = {}): HubConnection {
  return {
    id: 'c-1', provider: 'snapchat', label: 'Snapchat Marketing API', label_ar: 'واجهة سناب شات الإعلانية', connection_name: 'Snapchat',
    authorised_by: { name: 'Owner', email: 'o@a.test' }, provider_account: null, removed_at: null, abandoned: false,
    authorised_at: null, selected_accounts: 2, discovered_accounts: 2, connection_state: 'CONNECTED', sync_state: 'SUCCEEDED',
    needs_attention: false, last_success_at: null, next_sync_at: null, projects: [{ id: 'p-1', name: 'Razzah' }], has_parent: true,
    discovery_blocked_reason: null, token_expires_at: null, project_id: 'p-1', status: 'connected', campaigns: 7,
    accounts: [
      { id: 'a-1', name: 'Riyadh', organisation: 'Razzah Org', external_id: '1234567890123456', currency: 'USD', campaigns: 5 },
      { id: 'a-2', name: 'Jeddah', organisation: null, external_id: 'act_22', currency: 'SAR', campaigns: 2 },
    ],
    ...over,
  }
}

const noop = () => undefined
const renderHub = (over: Partial<Parameters<typeof ConnectionHub>[0]> = {}) =>
  renderWithProviders(<ConnectionHub projectId="p-1" onConnect={noop} onOpen={noop} onAction={noop} onRemove={noop} onReauthorise={noop} onRelink={noop} {...over} />, { locale: 'ar' })

beforeEach(() => {
  hub.data = { connections: [connection()], connectable: [] }
  calls.unlinked = []
  metrics.summary = { current: { spend: 1500 }, currency: 'USD' }
  metrics.accounts = [
    { account_id: 'a-1', provider: 'snapchat', account_name: 'Riyadh', spend: 1200 },
    { account_id: 'a-2', provider: 'snapchat', account_name: 'Jeddah', spend: 300 },
  ]
})

describe('a source row in the project space', () => {
  it('is titled by the short platform name with its mark, and carries accounts, campaigns and 30-day spend', async () => {
    renderHub()

    const row = await screen.findByTestId('hub-row-c-1')
    expect(within(row).getByTestId('hub-open-c-1')).toHaveTextContent('سناب شات')
    expect(row).not.toHaveTextContent('واجهة سناب شات الإعلانية')
    expect(within(row).getByTestId('hub-accounts-c-1')).toHaveTextContent('حسابان مربوطان')
    expect(within(row).getByTestId('hub-campaigns-c-1')).toHaveTextContent('7')
    expect(within(row).getByTestId('hub-spend-c-1')).toHaveTextContent('1.5K USD')
    // A healthy own row has a state chip and no button beside it.
    expect(within(row).getByTestId('hub-state-c-1')).toBeInTheDocument()
    expect(within(row).queryByTestId('hub-relink-c-1')).toBeNull()
  })

  it('lists only its linked accounts under it, one per line, once expanded', async () => {
    renderHub()

    const panel = await screen.findByTestId('hub-detail-c-1')
    expect(panel).not.toBeVisible()
    fireEvent.click(screen.getByTestId('hub-expand-c-1'))
    expect(panel).toBeVisible()

    const lines = within(screen.getByTestId('hub-account-list-c-1')).getAllByRole('listitem')
    expect(lines.map((li) => li.getAttribute('data-testid'))).toEqual(['hub-account-a-1', 'hub-account-a-2'])
    expect(lines[0]).toHaveTextContent('Razzah Org · Riyadh')
    // The long platform number is shown by its end; the whole of it is behind the copy icon.
    expect(lines[0]).toHaveTextContent('…90123456')
    expect(within(lines[0]!).getByTestId('hub-account-id-a-1')).toHaveAttribute('title', '1234567890123456')
    expect(within(lines[0]!).getByTestId('hub-account-spend-a-1')).toHaveTextContent('1.2K USD')
  })

  it('unlinks an account through the existing endpoint only after a two-button confirmation with one short line', async () => {
    renderHub()

    fireEvent.click(await screen.findByTestId('hub-expand-c-1'))
    fireEvent.click(screen.getByTestId('hub-unlink-a-1'))

    const dialog = screen.getByTestId('unlink-account-dialog')
    expect(dialog).toHaveTextContent('فصل Riyadh؟')
    expect(dialog).toHaveTextContent('يبقى السجل، ويمكن ربطه من جديد.')
    expect(within(dialog).getAllByRole('button')).toHaveLength(2)
    expect(calls.unlinked).toEqual([])

    fireEvent.click(within(dialog).getByTestId('unlink-account-keep'))
    expect(screen.queryByTestId('unlink-account-dialog')).toBeNull()
    expect(calls.unlinked).toEqual([])

    fireEvent.click(screen.getByTestId('hub-unlink-a-1'))
    fireEvent.click(screen.getByTestId('unlink-account-confirm'))
    await waitFor(() => expect(calls.unlinked).toEqual([['p-1', 'a-1']]))
  })

  it('shows withheld money as withheld, in its own currency, and never as a zero', async () => {
    metrics.accounts = [{ account_id: 'a-1', provider: 'snapchat', account_name: 'Riyadh', spend: 0, spend_withheld_rows: 3, spend_original: 4500, money_original_currency: 'SAR', money_original_currencies: 1 }]
    hub.data = { connections: [connection({ selected_accounts: 1, accounts: [connection().accounts![0]!] })], connectable: [] }
    renderHub()

    const chip = await screen.findByTestId('hub-spend-c-1')
    expect(chip).toHaveTextContent('4,500.00 SAR')
    expect(chip).not.toHaveTextContent(/^0/)
  })

  it('says nothing about spend for a source that reported none', async () => {
    metrics.accounts = []
    renderHub()

    await screen.findByTestId('hub-row-c-1')
    expect(screen.queryByTestId('hub-spend-c-1')).toBeNull()
  })

  /** The card counts this account, so the space lists it — said to be disconnected, with the way back as its one action. */
  it('lists a disconnected source that still holds a link, with «إعادة الربط» as its one action', async () => {
    const relinked: string[] = []
    hub.data = { connections: [connection({ status: 'disconnected', connection_state: 'REVOKED' })], connectable: [] }
    renderHub({ onRelink: (c) => relinked.push(c.id) })

    const row = await screen.findByTestId('hub-row-c-1')
    expect(within(row).getByTestId('hub-state-c-1')).toHaveTextContent('مفصول')
    expect(within(row).queryByTestId('hub-removed-c-1')).toBeNull()
    expect(within(row).queryByTestId('hub-reauth-c-1')).toBeNull()
    fireEvent.click(within(row).getByTestId('hub-relink-c-1'))
    expect(relinked).toEqual(['c-1'])
  })
})

describe('the four figures of the project space', () => {
  it('counts a disconnected link among the accounts and the sources that need an action, and states the 30-day spend', () => {
    renderWithProviders(<HubSummary projectId="p-1" campaigns={7} connections={[connection({ status: 'disconnected', connection_state: 'REVOKED' })]} />, { locale: 'ar' })

    expect(screen.getByTestId('hub-kpi-accounts')).toHaveTextContent('2')
    expect(screen.getByTestId('hub-kpi-campaigns')).toHaveTextContent('7')
    expect(screen.getByTestId('hub-kpi-spend')).toHaveTextContent('1.5K USD')
    expect(screen.getByTestId('hub-kpi-attention')).toHaveTextContent('1')
  })

  it('states withheld spend in its own currency rather than a zero', () => {
    metrics.summary = { current: { spend: 0, spend_withheld_rows: 2, spend_original: 900, money_original_currency: 'SAR', money_original_currencies: 1 }, currency: 'USD' }
    renderWithProviders(<HubSummary projectId="p-1" connections={[connection()]} />, { locale: 'ar' })

    expect(screen.getByTestId('hub-kpi-spend')).toHaveTextContent('900.00 SAR')
  })
})
