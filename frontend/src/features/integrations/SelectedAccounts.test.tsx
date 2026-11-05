import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { SelectedAccounts } from './SelectedAccounts'
import { renderWithProviders } from '@/test/utils'
import type { AccountRow, AccountsPage } from './api'

vi.mock('./api', () => ({
  listAccounts: vi.fn(),
  getAccountLogs: vi.fn(),
  backfillAccount: vi.fn(),
  syncAccountNow: vi.fn(),
}))

import { getAccountLogs, listAccounts, syncAccountNow } from './api'

function account(overrides: Partial<AccountRow> = {}): AccountRow {
  return {
    id: 'a1',
    provider: 'snapchat',
    provider_label: 'Snapchat',
    account_type: 'ad_account',
    account_type_label: 'ad account',
    name: 'متجر العطور',
    reference: 'snap-1',
    named_by_provider: true,
    parent_name: null,
    parent_external_id: null,
    currency: 'SAR',
    timezone: 'Asia/Riyadh',
    connection_id: 'c1',
    connection_name: 'Snapchat — main',
    is_linked: false,
    assigned_project_id: null,
    assigned_project_name: null,
    health: null,
    last_synced_at: null,
    last_sync_attempt_at: null,
    last_sync_error_category: null,
    next_sync_at: null,
    access_lost_at: null,
    counts_toward_ad_account_quota: true,
    ...overrides,
  }
}

function page(accounts: AccountRow[], summary?: Partial<AccountsPage['summary']>): AccountsPage {
  return {
    accounts,
    summary: { linked: 0, unlinked: accounts.length, total: accounts.length, ...summary },
    meta: { total: accounts.length, per_page: 50, current_page: 1, last_page: 1 },
  }
}

/**
 * INTEGRATIONS-CENTRE-001 — the list of SELECTED accounts.
 *
 * The backend tests hold the rules; these hold the screen. The contract here is a negative one: this
 * list can only ever ask the server for accounts that carry a binding, and it is paged.
 */
describe('SelectedAccounts', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  /** **The fence.** Every request carries the selection filter and a scope; none can ask for the inventory. */
  it('always asks for linked accounts of one connection or project, a page at a time', async () => {
    vi.mocked(listAccounts).mockResolvedValue(page([account({ is_linked: true, assigned_project_id: 'p-1' })]))

    renderWithProviders(<SelectedAccounts connection="c1" />)

    await screen.findByText('متجر العطور')
    expect(listAccounts).toHaveBeenCalledWith({ link: 'linked', connection: 'c1', page: 1, per_page: 10 })
    for (const [query] of vi.mocked(listAccounts).mock.calls) {
      expect(query).toMatchObject({ link: 'linked' })
    }
  })

  it('scopes to a project when given one', async () => {
    vi.mocked(listAccounts).mockResolvedValue(page([account({ is_linked: true, assigned_project_id: 'p-1' })]))

    renderWithProviders(<SelectedAccounts project="p-1" />)

    await screen.findByText('متجر العطور')
    expect(listAccounts).toHaveBeenCalledWith({ link: 'linked', project: 'p-1', page: 1, per_page: 10 })
  })

  /** No search box, no link chips, no «show all»: widening the list is the wizard's job. */
  it('has no search, no all/unlinked filter and no discovery wording', async () => {
    vi.mocked(listAccounts).mockResolvedValue(page([account({ is_linked: true, assigned_project_id: 'p-1' })]))

    renderWithProviders(<SelectedAccounts connection="c1" />)

    await screen.findByText('متجر العطور')
    expect(screen.queryByRole('searchbox')).toBeNull()
    expect(screen.queryByRole('textbox')).toBeNull()
    expect(screen.queryByText(/غير مرتبطة|Not linked/)).toBeNull()
    expect(document.body.textContent).not.toMatch(/جميع الحسابات المكتشفة|Every discovered account|الحسابات المكتشَفة|Discovered accounts/)
  })

  /** State, last sync and next sync are on every row; a null next sync reads as paused, not blank. */
  it('shows state, last sync and next sync per account', async () => {
    vi.mocked(listAccounts).mockResolvedValue(page([
      account({
        is_linked: true, assigned_project_id: 'p-1', assigned_project_name: 'حملة الصيف', health: 'healthy',
        last_synced_at: '2026-09-30T10:00:00Z', next_sync_at: null,
      }),
    ]))

    renderWithProviders(<SelectedAccounts connection="c1" />, { locale: 'en' })

    expect(await screen.findByTestId('selected-account-state')).toHaveTextContent('Healthy')
    expect(screen.getByTestId('selected-account-last-sync')).not.toHaveTextContent('None')
    expect(screen.getByTestId('selected-account-next-sync')).toHaveTextContent('Paused')
    expect(screen.getByText(/حملة الصيف/)).toBeInTheDocument()
  })

  /** Latin digits in the Arabic interface: the product-wide numeral rule. */
  it('prints the reference and the page counter with Latin digits', async () => {
    vi.mocked(listAccounts).mockResolvedValue({
      ...page([account({ is_linked: true, assigned_project_id: 'p-1', reference: 'snap-12345' })]),
      meta: { total: 25, per_page: 10, current_page: 1, last_page: 3 },
    })

    renderWithProviders(<SelectedAccounts connection="c1" />, { locale: 'ar' })

    await screen.findByText('snap-12345')
    expect(screen.getByTestId('selected-accounts-pager')).toHaveTextContent('1 / 3')
    expect(document.body.textContent).not.toMatch(/[\u0660-\u0669]/)
  })

  /** A hundred selected accounts are never loaded at once: the pager asks the server for the next page. */
  it('pages on the server', async () => {
    vi.mocked(listAccounts).mockResolvedValue({
      ...page([account({ is_linked: true, assigned_project_id: 'p-1' })]),
      meta: { total: 25, per_page: 10, current_page: 1, last_page: 3 },
    })

    renderWithProviders(<SelectedAccounts connection="c1" />, { locale: 'en' })

    await screen.findByText('متجر العطور')
    expect(screen.getByTestId('selected-accounts-prev')).toBeDisabled()
    fireEvent.click(screen.getByTestId('selected-accounts-next'))

    await waitFor(() => expect(listAccounts).toHaveBeenCalledWith({ link: 'linked', connection: 'c1', page: 2, per_page: 10 }))
  })

  it('says plainly when nothing has been selected yet', async () => {
    vi.mocked(listAccounts).mockResolvedValue(page([]))

    renderWithProviders(<SelectedAccounts connection="c1" />, { locale: 'en' })

    expect(await screen.findByTestId('selected-accounts-empty')).toHaveTextContent('No account has been selected yet')
  })

  /** The four account-level acts, each on the selected account's own row. */
  it('offers sync now, pull history, manage link and remove from project', async () => {
    vi.mocked(listAccounts).mockResolvedValue(page([account({ is_linked: true, assigned_project_id: 'p-1', assigned_project_name: 'حملة' })]))
    vi.mocked(syncAccountNow).mockResolvedValue({ queued: true, will_fetch: true })
    const onManage = vi.fn()

    renderWithProviders(<SelectedAccounts connection="c1" onManage={onManage} />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('selected-account-sync'))
    await waitFor(() => expect(syncAccountNow).toHaveBeenCalledWith('p-1', 'a1'))
    expect(await screen.findByRole('status')).toHaveTextContent('queued')

    expect(screen.getByRole('button', { name: /Pull history/ })).toBeInTheDocument()
    expect(screen.getByTestId('unlink-account')).toBeInTheDocument()

    fireEvent.click(screen.getByTestId('selected-account-manage'))
    expect(onManage).toHaveBeenCalledWith(expect.objectContaining({ id: 'a1', assigned_project_id: 'p-1' }))
  })
})

/**
 * INTEG-RUNTIME §9 — a run log whose lines all read the same is not a log.
 *
 * Every line used to say «Automatic», including the first sync after a selection and the one a
 * reconnect kicked off. Those three failing mean three different things and need three different
 * people, so the log says which — and, where it was measured, how long the run sat in the queue
 * before anything began.
 */
describe('the account sync log', () => {
  beforeEach(() => {
    vi.mocked(listAccounts).mockResolvedValue(page([account({ is_linked: true })]))
  })

  it('names what asked for each run and how long it waited', async () => {
    vi.mocked(getAccountLogs).mockResolvedValue({
      account: account({ is_linked: true }),
      runs: [{
        id: 'r1', provider: 'snapchat', status: 'success', trigger: 'automatic',
        source: 'reconnect', queued_at: '2026-09-30T10:00:00Z', waited_seconds: 20,
        window_start: '2026-09-23', window_end: '2026-09-30',
        provider_rows: 12, parsed_rows: 12, mapped_rows: 12, metrics_imported: 12,
        duration_seconds: 4, attempts: 1,
        started_at: '2026-09-30T10:00:20Z', finished_at: '2026-09-30T10:00:24Z',
        error: null, repeats: 1, repeats_since: null,
      }],
    })

    renderWithProviders(<SelectedAccounts connection="c1" />)

    fireEvent.click(await screen.findByTestId('account-logs-a1'))

    const row = await screen.findByTestId('account-log-row')
    expect(row).toHaveTextContent('After reconnecting')
    expect(screen.getByTestId('account-log-waited')).toHaveTextContent('20s')
  })

  /** A run recorded before the column existed waited an unknown time, which is not «0s». */
  it('says nothing about a wait nobody measured', async () => {
    vi.mocked(getAccountLogs).mockResolvedValue({
      account: account({ is_linked: true }),
      runs: [{
        id: 'r2', provider: 'snapchat', status: 'success', trigger: 'automatic',
        window_start: '2026-09-23', window_end: '2026-09-30',
        provider_rows: 1, parsed_rows: 1, mapped_rows: 1, metrics_imported: 1,
        duration_seconds: 2, attempts: 1,
        started_at: '2026-09-30T10:00:20Z', finished_at: '2026-09-30T10:00:22Z',
        error: null, repeats: 1, repeats_since: null,
      }],
    })

    renderWithProviders(<SelectedAccounts connection="c1" />)

    fireEvent.click(await screen.findByTestId('account-logs-a1'))

    await screen.findByTestId('account-log-row')
    expect(screen.queryByTestId('account-log-waited')).toBeNull()
  })
})
