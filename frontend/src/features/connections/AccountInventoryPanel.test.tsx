import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { AccountInventoryPanel } from './AccountInventoryPanel'
import { renderWithProviders } from '@/test/utils'
import type { InventoryAccount, InventoryPage } from './api'

vi.mock('./api', () => ({
  ACCOUNT_LIFECYCLES: ['discovered', 'enabled', 'excluded', 'assigned'],
  listInventory: vi.fn(),
  setAccountStateBulk: vi.fn(),
  getAccountLogs: vi.fn(),
  backfillAccount: vi.fn(),
}))

import { listInventory, setAccountStateBulk } from './api'

function account(overrides: Partial<InventoryAccount> = {}): InventoryAccount {
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
    lifecycle: 'discovered',
    lifecycle_label: 'Discovered',
    lifecycle_hint: 'The provider returned it.',
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

function page(accounts: InventoryAccount[], summary?: Partial<InventoryPage['summary']>): InventoryPage {
  return {
    accounts,
    summary: { discovered: 0, enabled: 0, excluded: 0, assigned: 0, total: accounts.length, ...summary },
    meta: { total: accounts.length, per_page: 50, current_page: 1, last_page: 1 },
  }
}

/**
 * COMMAND-CENTER §§7–20 — what the inventory must SHOW, as opposed to what it must store.
 *
 * The backend tests hold the rules; these hold the screen. They are separate because every defect
 * this panel exists for was a rendering defect: the data was right and the page said «متصل» to all
 * of it, or printed a UUID where a name belonged.
 */
describe('AccountInventoryPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  /** **The unanswerable question.** An unnamed account reads as words, and says whose blank it is. */
  it('never prints an identifier where a name belongs', async () => {
    vi.mocked(listInventory).mockResolvedValue(page([
      account({ name: 'حساب سناب شات بدون اسم من المنصة', reference: '8f3ac1de-90b2-4c77-b0e1-2a4419d7c5aa', named_by_provider: false }),
    ]))

    renderWithProviders(<AccountInventoryPanel />)

    // The name is words.
    expect(await screen.findByText('حساب سناب شات بدون اسم من المنصة')).toBeInTheDocument()
    // The identifier is present as a labelled reference, not as the heading.
    expect(screen.getByText(/8f3ac1de-90b2-4c77-b0e1-2a4419d7c5aa/)).toBeInTheDocument()
    // And the reason for the blank is attributed to the provider rather than left as a mystery.
    expect(screen.getByText(/لم تُرسل المنصة اسمًا|provider sent no name/i)).toBeInTheDocument()
  })

  /** «٤ من ٣٠٩» — a count without its whole cannot tell anybody whether they have finished. */
  it('shows each state count against the whole inventory', async () => {
    vi.mocked(listInventory).mockResolvedValue(
      page([account({ lifecycle: 'enabled', lifecycle_label: 'Enabled' })], {
        discovered: 305, enabled: 4, excluded: 0, assigned: 0, total: 309,
      }),
    )

    renderWithProviders(<AccountInventoryPanel />)

    await screen.findByText('متجر العطور')
    expect(screen.getByText(/4 of 309|4 من 309/)).toBeInTheDocument()
  })

  /** An assigned row names the project. An id would not answer «where do these numbers go?». */
  it('names the project an assigned account belongs to', async () => {
    vi.mocked(listInventory).mockResolvedValue(page([
      account({
        lifecycle: 'assigned', lifecycle_label: 'Assigned to a project',
        assigned_project_id: 'p-1', assigned_project_name: 'حملة الصيف', health: 'healthy',
      }),
    ]))

    renderWithProviders(<AccountInventoryPanel />)

    expect(await screen.findByText(/حملة الصيف/)).toBeInTheDocument()
  })

  /** A store row says out loud that it costs nothing against the advertising cap. */
  it('marks a store as not consuming an ad-account slot', async () => {
    vi.mocked(listInventory).mockResolvedValue(page([
      account({ account_type: 'store', account_type_label: 'store', provider: 'salla', provider_label: 'Salla', counts_toward_ad_account_quota: false }),
    ]))

    renderWithProviders(<AccountInventoryPanel />)

    expect(await screen.findByText(/لا يستهلك حصة|not use an ad-account slot/i)).toBeInTheDocument()
  })

  /**
   * Excluding is refused in the interface while an assigned account is selected.
   *
   * The server is the rule and refuses it too; this only spares the customer a round trip that ends
   * in a refusal they could have been shown before pressing.
   */
  it('will not offer to exclude a selection containing an assigned account', async () => {
    vi.mocked(listInventory).mockResolvedValue(page([
      account({ id: 'a1', name: 'حر' }),
      account({ id: 'a2', name: 'مرتبط', reference: 'snap-2', lifecycle: 'assigned', lifecycle_label: 'Assigned' }),
    ]))

    renderWithProviders(<AccountInventoryPanel />)

    fireEvent.click(await screen.findByRole('button', { name: 'مرتبط' }))

    const bar = await screen.findByTestId('inventory-bulk-bar')
    const exclude = within(bar).getByRole('button', { name: /استبعاد|Exclude/ })
    expect(exclude).toBeDisabled()
  })

  /** A clean bulk selection sends ONE request naming every account — not one request each. */
  it('applies a bulk decision as a single call', async () => {
    vi.mocked(listInventory).mockResolvedValue(page([
      account({ id: 'a1', name: 'أول' }),
      account({ id: 'a2', name: 'ثانٍ', reference: 'snap-2' }),
    ]))
    vi.mocked(setAccountStateBulk).mockResolvedValue({ updated: 2, state: 'enabled' })

    renderWithProviders(<AccountInventoryPanel />)

    fireEvent.click(await screen.findByRole('button', { name: 'أول' }))
    fireEvent.click(screen.getByRole('button', { name: 'ثانٍ' }))

    const bar = await screen.findByTestId('inventory-bulk-bar')
    fireEvent.click(within(bar).getByRole('button', { name: /تفعيل|Enable/ }))

    await waitFor(() => expect(setAccountStateBulk).toHaveBeenCalledTimes(1))
    expect(setAccountStateBulk).toHaveBeenCalledWith(['a1', 'a2'], 'enabled')
  })

  /** An empty inventory says which kind of empty it is — «nothing yet» is not «nothing matched». */
  it('distinguishes an empty inventory from an empty filter', async () => {
    vi.mocked(listInventory).mockResolvedValue(page([]))

    renderWithProviders(<AccountInventoryPanel />)

    expect(await screen.findByTestId('inventory-empty')).toHaveTextContent(/لم يُكتشف أي حساب|Nothing discovered yet/)
  })
})
