import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ConnectionWizard } from './ConnectionWizard'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  fetchConnectionHierarchy: vi.fn(),
  fetchDiscoveredAccounts: vi.fn(),
  fetchPlanUsage: vi.fn(),
  listProjectBindings: vi.fn(),
  applyAccountSelection: vi.fn(),
  confirmAccountSelection: vi.fn(),
}))

import {
  applyAccountSelection, confirmAccountSelection, fetchConnectionHierarchy, fetchDiscoveredAccounts,
  fetchPlanUsage, listProjectBindings,
} from './api'

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §8 — «Manage accounts» is the same wizard, opened on the truth.
 *
 * Two things this pins, and both were reachable only by disconnecting the provider before:
 *
 *   1. the picker opens with what the project ALREADY holds ticked — including accounts on a page
 *      nobody has scrolled to, which is why the bindings are read before the catalogue rather than
 *      inferred from the rows on screen;
 *   2. saving sends the DESIRED SET to the endpoint that diffs it, and never the first-commit
 *      endpoint, which refuses an empty list and would charge the plan again for what is already
 *      connected.
 */
const account = (id: string, name: string) => ({
  id,
  external_id: `ext-${id}`,
  name,
  parent_external_id: null,
  parent_name: null,
  currency: 'SAR',
  timezone: null,
  status: 'active',
  assigned_project_id: null,
  assigned: false,
  last_synced_at: null,
  access_lost_at: null,
  health: 'never_synced',
  last_sync_attempt_at: null,
  last_sync_error_category: null,
  next_sync_at: null,
})

describe('managing the accounts of a connected source', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['integrations.view', 'integrations.connect'])
    vi.mocked(fetchConnectionHierarchy).mockResolvedValue({
      connection: { id: 'c1', provider: 'linkedin', label: 'LinkedIn', label_ar: 'لينكدإن', status: 'connected', client_workspace_id: null },
      has_parent: false,
      parent_label: null,
      parents: [],
      discovered_count: 2,
      assigned_count: 1,
      wizard: { state: 'active', discovered: 2, assigned: 1, synced: 1, has_parent: false, resumable: true, next_step: 'accounts' },
    } as never)
    vi.mocked(fetchPlanUsage).mockResolvedValue({ ad_accounts: { limit: 5, used: 1, remaining: 4 } } as never)
    vi.mocked(fetchDiscoveredAccounts).mockResolvedValue({
      accounts: [account('a1', 'SFMA'), account('a2', 'Riyadh Store')],
      meta: { total: 2, per_page: 25, current_page: 1, last_page: 1 },
    } as never)
    // Bound HERE: the picker must let it be unticked, which is the whole point of the screen.
    vi.mocked(fetchDiscoveredAccounts).mockResolvedValue({
      accounts: [
        { ...account('a1', 'SFMA'), assigned: true, assigned_project_id: 'p1' },
        account('a2', 'Riyadh Store'),
        { ...account('a3', 'Someone else’s'), assigned: true, assigned_project_id: 'p2' },
      ],
      meta: { total: 3, per_page: 25, current_page: 1, last_page: 1 },
    } as never)
    vi.mocked(listProjectBindings).mockResolvedValue([
      { id: 'b1', provider: 'linkedin', is_active: true, account: { id: 'a1', external_id: 'ext-a1', name: 'SFMA' } },
    ] as never)
    vi.mocked(applyAccountSelection).mockResolvedValue({ added: ['a2'], unchanged: ['a1'], removed: [] } as never)
  })
  afterEach(() => signOut())

  it('opens with the accounts this project already holds ticked', async () => {
    renderWithProviders(<ConnectionWizard connectionId="c1" manageProjectId="p1" onClose={() => {}} />, { locale: 'en' })

    const bound = (await screen.findByText('SFMA')).closest('li')?.querySelector('input[type="checkbox"]')
    expect(bound).toBeChecked()

    const other = screen.getByText('Riyadh Store').closest('li')?.querySelector('input[type="checkbox"]')
    expect(other).not.toBeChecked()
  })

  it('saves the desired set through the endpoint that diffs it', async () => {
    renderWithProviders(<ConnectionWizard connectionId="c1" manageProjectId="p1" onClose={() => {}} />, { locale: 'en' })

    fireEvent.click((await screen.findByText('Riyadh Store')).closest('li')!.querySelector('input[type="checkbox"]')!)
    fireEvent.click(screen.getByTestId('wizard-save-selection'))

    await waitFor(() => expect(vi.mocked(applyAccountSelection)).toHaveBeenCalled())
    const sent = vi.mocked(applyAccountSelection).mock.calls.at(-1)?.[0]
    expect(sent?.projectId).toBe('p1')
    expect([...(sent?.externalAccountIds ?? [])].sort()).toEqual(['a1', 'a2'])

    // Never the first-commit endpoint: it refuses an empty list and re-charges what is already bound.
    expect(vi.mocked(confirmAccountSelection)).not.toHaveBeenCalled()
  })

  it('states what the save changed, in the three groups', async () => {
    renderWithProviders(<ConnectionWizard connectionId="c1" manageProjectId="p1" onClose={() => {}} />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('wizard-save-selection'))

    expect(await screen.findByTestId('wizard-selection-diff')).toHaveTextContent('1 added')
  })

  /** Connecting is the other mode, and it must still ask for a project rather than assuming one. */
  it('asks for a project when it is connecting rather than managing', async () => {
    renderWithProviders(<ConnectionWizard connectionId="c1" onClose={() => {}} />, { locale: 'en' })

    fireEvent.click((await screen.findByText('SFMA')).closest('li')!.querySelector('input[type="checkbox"]')!)

    expect(screen.queryByTestId('wizard-save-selection')).not.toBeInTheDocument()
    expect(screen.getByText('Continue')).toBeInTheDocument()
  })

  /**
   * The rows this project already holds are the ones a reader came to change.
   *
   * The picker disabled every ASSIGNED account, and in manage mode every bound account is assigned
   * by definition — so the only rows that could not be unticked were the ones the screen exists to
   * untick. An account feeding ANOTHER project stays locked, because the server refuses it with a
   * 409 and a control that cannot succeed should not invite the click.
   */
  it('lets this project’s own accounts be unticked, and locks another project’s', async () => {
    renderWithProviders(<ConnectionWizard connectionId="c1" manageProjectId="p1" onClose={() => {}} />, { locale: 'en' })

    const mine = (await screen.findByText('SFMA')).closest('li')!.querySelector('input[type="checkbox"]')!
    expect(mine).toBeEnabled()
    expect(mine).toBeChecked()

    const theirs = screen.getByText('Someone else’s').closest('li')!.querySelector('input[type="checkbox"]')!
    expect(theirs).toBeDisabled()

    fireEvent.click(mine)
    fireEvent.click(screen.getByTestId('wizard-save-selection'))

    await waitFor(() => expect(vi.mocked(applyAccountSelection)).toHaveBeenCalled())
    expect(vi.mocked(applyAccountSelection).mock.calls.at(-1)?.[0].externalAccountIds).not.toContain('a1')
  })

  /** «Select this page» is the page, and it skips what another project holds. */
  it('selects the page without touching a row another project owns', async () => {
    renderWithProviders(<ConnectionWizard connectionId="c1" manageProjectId="p1" onClose={() => {}} />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('wizard-select-page'))
    fireEvent.click(screen.getByTestId('wizard-save-selection'))

    await waitFor(() => expect(vi.mocked(applyAccountSelection)).toHaveBeenCalled())
    const sent = vi.mocked(applyAccountSelection).mock.calls.at(-1)?.[0].externalAccountIds ?? []
    expect([...sent].sort()).toEqual(['a1', 'a2'])
  })
})
