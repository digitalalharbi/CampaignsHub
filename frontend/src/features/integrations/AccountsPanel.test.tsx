import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { AccountsPanel } from './AccountsPanel'
import { renderWithProviders } from '@/test/utils'
import type { AccountRow, AccountsPage } from './api'

vi.mock('./api', () => ({
  listAccounts: vi.fn(),
  getAccountLogs: vi.fn(),
  backfillAccount: vi.fn(),
}))

import { getAccountLogs, listAccounts } from './api'

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
 * INTEG-RUNTIME §3 §5 — what the accounts panel must SHOW.
 *
 * The backend tests hold the rules; these hold the screen. They are separate because every defect
 * this panel exists for was a rendering defect: the data was right and the page said «متصل» to all of
 * it, or printed a UUID where a name belonged, or offered a curation step that did nothing.
 */
describe('AccountsPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  /** **The unanswerable question.** An unnamed account reads as words, and says whose blank it is. */
  it('never prints an identifier where a name belongs', async () => {
    vi.mocked(listAccounts).mockResolvedValue(page([
      account({ name: 'حساب سناب شات بدون اسم من المنصة', reference: '8f3ac1de-90b2-4c77-b0e1-2a4419d7c5aa', named_by_provider: false }),
    ]))

    renderWithProviders(<AccountsPanel />)

    expect(await screen.findByText('حساب سناب شات بدون اسم من المنصة')).toBeInTheDocument()
    expect(screen.getByText(/8f3ac1de-90b2-4c77-b0e1-2a4419d7c5aa/)).toBeInTheDocument()
    expect(screen.getByText(/لم تُرسل المنصة اسمًا|provider sent no name/i)).toBeInTheDocument()
  })

  /** «٤ من ٣٠٩» — a count without its whole cannot tell anybody whether they have finished. */
  it('shows each count against the whole inventory', async () => {
    vi.mocked(listAccounts).mockResolvedValue(
      page([account({ is_linked: true, assigned_project_name: 'حملة الصيف' })], {
        linked: 4, unlinked: 305, total: 309,
      }),
    )

    renderWithProviders(<AccountsPanel />)

    await screen.findByText('متجر العطور')
    expect(screen.getByText(/4 of 309|4 من 309/)).toBeInTheDocument()
  })

  /** An unlinked row says so in words — «no project» is the reason nothing is happening to it. */
  it('says out loud when nothing will be fetched for an account', async () => {
    vi.mocked(listAccounts).mockResolvedValue(page([account()]))

    renderWithProviders(<AccountsPanel />)

    expect(await screen.findByText(/غير مرتبط بمشروع|Not linked to a project/)).toBeInTheDocument()
  })

  /** A linked row names the project. An id would not answer «where do these numbers go?». */
  it('names the project a linked account belongs to', async () => {
    vi.mocked(listAccounts).mockResolvedValue(page([
      account({ is_linked: true, assigned_project_id: 'p-1', assigned_project_name: 'حملة الصيف', health: 'healthy' }),
    ], { linked: 1, unlinked: 0, total: 1 }))

    renderWithProviders(<AccountsPanel />)

    expect(await screen.findByText(/حملة الصيف/)).toBeInTheDocument()
  })

  /** A store row says out loud that it costs nothing against the advertising cap. */
  it('marks a store as not consuming an ad-account slot', async () => {
    vi.mocked(listAccounts).mockResolvedValue(page([
      account({ account_type: 'store', account_type_label: 'store', provider: 'salla', provider_label: 'Salla', counts_toward_ad_account_quota: false }),
    ]))

    renderWithProviders(<AccountsPanel />)

    expect(await screen.findByText(/لا يستهلك حصة|not use an ad-account slot/i)).toBeInTheDocument()
  })

  /**
   * **The step that is gone.** No enable, no exclude, no bulk bar.
   *
   * They named a decision that changed nothing: enabling an account did not sync it, attach it or
   * spend a quota slot. Asserting their ABSENCE is the point — a curation control that quietly
   * returns is a customer being asked to learn a state machine again.
   */
  it('offers no curation step, because none of them ever did anything', async () => {
    vi.mocked(listAccounts).mockResolvedValue(page([account(), account({ id: 'a2', name: 'ثانٍ', reference: 'snap-2' })]))

    renderWithProviders(<AccountsPanel />)

    await screen.findByText('متجر العطور')
    expect(screen.queryByRole('button', { name: /تفعيل|^Enable$/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /استبعاد|^Exclude$/ })).not.toBeInTheDocument()
    expect(screen.queryByTestId('inventory-bulk-bar')).not.toBeInTheDocument()
  })

  /** Backfill is offered only where the history has a project to land in. */
  it('offers history only for an account a project owns', async () => {
    vi.mocked(listAccounts).mockResolvedValue(page([
      account({ id: 'a1', name: 'مرتبط', is_linked: true, assigned_project_name: 'حملة' }),
      account({ id: 'a2', name: 'غير مرتبط', reference: 'snap-2' }),
    ], { linked: 1, unlinked: 1, total: 2 }))

    renderWithProviders(<AccountsPanel />)

    await screen.findByText('مرتبط')
    expect(screen.getAllByRole('button', { name: /سحب بيانات سابقة|Pull history/ })).toHaveLength(1)
  })

  /** An empty inventory says which kind of empty it is — «nothing yet» is not «nothing matched». */
  it('distinguishes an empty inventory from an empty filter', async () => {
    vi.mocked(listAccounts).mockResolvedValue(page([]))

    renderWithProviders(<AccountsPanel />)

    expect(await screen.findByTestId('inventory-empty')).toHaveTextContent(/لم يُكتشف أي حساب|Nothing discovered yet/)
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

    renderWithProviders(<AccountsPanel />)

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

    renderWithProviders(<AccountsPanel />)

    fireEvent.click(await screen.findByTestId('account-logs-a1'))

    await screen.findByTestId('account-log-row')
    expect(screen.queryByTestId('account-log-waited')).toBeNull()
  })
})
