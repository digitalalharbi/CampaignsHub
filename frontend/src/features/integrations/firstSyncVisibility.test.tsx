import { afterEach, describe, expect, it, vi } from 'vitest'
import { useQuery } from '@tanstack/react-query'
import { fireEvent, screen } from '@testing-library/react'
import { ConnectionWizard } from './ConnectionWizard'
import { renderWithProviders } from '@/test/utils'

/**
 * INTEGRATION-FIRST-SYNC-VISIBILITY-001 — «it looked like nothing happened, and then I refreshed».
 *
 * ## The owner's report, in full
 *
 * Confirm the selection; the dialog says the sync started; close it; the integrations page looks
 * exactly as it did before. Reload the browser and the sync had in fact finished some time ago —
 * accounts bound, rows imported, last-synced set. Nothing was broken on the server. The product
 * simply never told anybody, and «press F5 to find out whether your integration worked» is not a
 * step in the journey this wizard exists to own.
 *
 * ## Why it happens
 *
 * The confirmation invalidates the surrounding queries — connectors, connection states, plan usage —
 * at the instant the sync is QUEUED. That refetch therefore returns the pre-sync world, truthfully:
 * no rows, no last-synced, an account not yet contributing. The sync then resolves a minute later
 * and NOTHING invalidates those keys a second time, because the only thing watching the run is a
 * panel inside the dialog that renders its own sentence and tells no one else.
 *
 * So the page behind the dialog is not stale by accident. It is stale because the only moment worth
 * refreshing it — the moment the answer arrives — is the one moment the wizard was not refreshing.
 *
 * ## What is pinned here
 *
 * A query the page behind the dialog owns, watched across the settle. It holds one value before the
 * sync resolves and another after, and the test asserts the SECOND value reaches the screen with no
 * remount, no navigation and no reload. That is the whole defect, and it fails on a wizard that
 * invalidates only at confirmation time.
 */

const state = vi.hoisted(() => ({
  runs: [] as never[],
  /** What the surface behind the dialog would say if it asked the server right now. */
  cardValue: 'never synced',
  probeCalls: 0,
}))

vi.mock('@/features/projects/api', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  listProjects: () => Promise.resolve([{ id: 'proj-1', name: 'مشروع قائم' }]),
  listClientWorkspaces: () => Promise.resolve([]),
}))

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()

  const accounts = [
    { id: 'acct-1', external_id: 'act-1', name: 'Riyadh Retail', parent_external_id: 'org-1', parent_name: 'Acme Media', currency: 'SAR', timezone: 'Asia/Riyadh', status: 'active', assigned_project_id: null, assigned: false, last_synced_at: null, access_lost_at: null },
    { id: 'acct-2', external_id: 'act-2', name: 'Jeddah Retail', parent_external_id: 'org-1', parent_name: 'Acme Media', currency: 'SAR', timezone: 'Asia/Riyadh', status: 'active', assigned_project_id: null, assigned: false, last_synced_at: null, access_lost_at: null },
  ]

  return {
    ...actual,
    fetchConnectionHierarchy: () => Promise.resolve({
      connection: { id: 'conn-1', provider: 'snapchat', label: 'Snapchat', label_ar: 'سناب شات', status: 'connected', client_workspace_id: null },
      has_parent: true,
      parent_label: { key: 'organization', label: 'Organization', labelAr: 'المؤسسة' },
      parents: [{ external_id: 'org-1', name: 'Acme Media', account_count: 2 }],
      discovered_count: 2,
      assigned_count: 0,
      wizard: { state: 'needs_selection', discovered: 2, assigned: 0, synced: 0, has_parent: true, resumable: true, next_step: 'accounts' },
    }),
    fetchDiscoveredAccounts: () => Promise.resolve({
      accounts,
      meta: { total: 2, per_page: 25, current_page: 1, last_page: 1 },
    }),
    fetchPlanUsage: () => Promise.resolve({ ad_accounts: { limit: 5, used: 0, remaining: 5 } }),
    confirmAccountSelection: () => Promise.resolve({ connected: 2 }),
    getAccountLogs: () => Promise.resolve({ account: { id: 'acct-1' }, runs: state.runs }),
    fetchFirstSyncStatus: () => Promise.resolve(firstSyncStatusFromRuns()),
  }
})

/**
 * The server's own answer, shaped from whatever the fixture's runs currently say.
 *
 * Both polling shapes — one account's log, or the whole selection's status — are fed from the SAME
 * `state.runs`, so this test pins the behaviour rather than the endpoint the wizard happens to call.
 */
function firstSyncStatusFromRuns() {
  const runs = state.runs as Array<{ status: string; metrics_imported: number; error: string | null }>
  const settled = runs.length > 0 && runs.every((r) => r.status !== 'running' && r.status !== 'pending')
  const rows = runs.reduce((sum, r) => sum + (r.metrics_imported ?? 0), 0)

  return {
    connection: { id: 'conn-1', provider: 'snapchat' },
    since: new Date(Date.now() - 1000).toISOString(),
    accounts: [
      { id: 'acct-1', external_id: 'act-1', name: 'Riyadh Retail', state: settled ? (rows > 0 ? 'imported' : 'no_data') : 'queued', rows, error: null, last_synced_at: null },
      { id: 'acct-2', external_id: 'act-2', name: 'Jeddah Retail', state: settled ? (rows > 0 ? 'imported' : 'no_data') : 'queued', rows: 0, error: null, last_synced_at: null },
    ],
    summary: {
      total: 2,
      queued: settled ? 0 : 2,
      running: 0,
      imported: settled && rows > 0 ? 2 : 0,
      no_data: settled && rows === 0 ? 2 : 0,
      partial: 0,
      failed: 0,
      awaiting_assignment: 0,
      rows,
      state: settled ? (rows > 0 ? 'imported' : 'no_data') : 'queued',
      settled,
      succeeded: settled ? 2 : 0,
      needs_attention: 0,
    },
  }
}

/** Whatever the integrations page behind the dialog is showing, asked for afresh each time. */
function CardProbe() {
  const card = useQuery({
    queryKey: ['connectors'],
    queryFn: () => {
      state.probeCalls += 1
      return Promise.resolve(state.cardValue)
    },
  })

  return <span data-testid="card-freshness">{card.data ?? '…'}</span>
}

async function confirmTheSelection() {
  renderWithProviders(
    <>
      <CardProbe />
      <ConnectionWizard connectionId="conn-1" onClose={() => {}} />
    </>,
  )

  const boxes = await screen.findAllByRole('checkbox')
  boxes.forEach((b) => fireEvent.click(b))
  fireEvent.click(screen.getByRole('button', { name: /متابعة|Continue/ }))
  fireEvent.click(await screen.findByText('مشروع قائم'))
  fireEvent.click(await screen.findByTestId('wizard-confirm'))
}

describe('the surface behind the wizard learns that the first sync finished', () => {
  afterEach(() => {
    state.runs = [] as never[]
    state.cardValue = 'never synced'
    state.probeCalls = 0
  })

  it('refreshes the integrations surfaces when the run settles, with no browser reload', async () => {
    state.runs = [] as never[]

    await confirmTheSelection()

    // Queued: the page behind is correct and will stay correct until the run answers.
    expect(await screen.findByTestId('card-freshness')).toHaveTextContent('never synced')

    /*
     * The worker finishes. On the server this is now true; the browser has not been told, and the
     * only thing in the product that knows to ask again is the wizard that started it.
     */
    state.runs = [{
      id: 'r1', provider: 'snapchat', status: 'success', trigger: 'automatic',
      window_start: null, window_end: null, provider_rows: null, parsed_rows: null, mapped_rows: null,
      metrics_imported: 936, duration_seconds: 4, attempts: 1,
      started_at: new Date(Date.now() + 5000).toISOString(), finished_at: null, error: null,
      repeats: 1, repeats_since: null,
    }] as never[]
    state.cardValue = 'synced · 936 rows'

    expect(await screen.findByText('synced · 936 rows', undefined, { timeout: 15_000 })).toBeInTheDocument()
  }, 25_000)
})
