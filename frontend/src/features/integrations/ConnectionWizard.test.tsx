import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ConnectionWizard } from './ConnectionWizard'
import { renderWithProviders } from '@/test/utils'

/**
 * PROJECT-CREATE-WORKSPACE-001 / RUNTIME-100 §4 §10 — what the wizard does when it cannot proceed.
 *
 * ## The production failure, reproduced here
 *
 * A customer with a live Snapchat authorisation and no projects pressed «إنشاء مشروع ومتابعة الربط»
 * and was told **«حدث خطأ غير متوقع.»** Nothing crashed and no request was even sent: the wizard read
 * `workspaces.data?.[0]?.id`, found nothing — an advertiser has no CLIENT workspaces — threw a plain
 * `Error`, and `toApiError` replaced its message with the generic one because a locally thrown error
 * has no axios envelope to read a message from.
 *
 * Two separate defects, and the tests below hold them apart: the wizard must be able to create a
 * project without naming a client workspace, and any refusal it decides itself must reach the screen
 * in the words it was written in.
 */

const calls = vi.hoisted(() => ({
  created: [] as Array<{ name: string; client_workspace_id?: string }>,
  confirmed: [] as Array<{ projectId: string; connectionId: string; externalAccountIds: string[] }>,
  refreshed: [] as string[],
}))

const state = vi.hoisted(() => ({
  projects: [] as Array<{ id: string; name: string }>,
  parents: [] as Array<{ external_id: string; name: string | null; account_count: number }>,
  createError: null as unknown,
}))

vi.mock('@/features/projects/api', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  listProjects: () => Promise.resolve(state.projects),
  listClientWorkspaces: () => Promise.resolve([
    { id: 'ws-a', name: 'عميل أ' },
    { id: 'ws-b', name: 'عميل ب' },
  ]),
  createProject: (input: { name: string; client_workspace_id?: string }) => {
    calls.created.push(input)
    if (state.createError) return Promise.reject(state.createError)
    return Promise.resolve({ id: 'proj-new', name: input.name })
  },
}))

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return {
    ...actual,
    fetchConnectionHierarchy: () => Promise.resolve({
      connection: { id: 'conn-1', provider: 'snapchat', label: 'Snapchat', label_ar: 'سناب شات', status: 'connected', client_workspace_id: null },
      has_parent: true,
      parent_label: { key: 'organization', label: 'Organization', labelAr: 'المؤسسة' },
      parents: state.parents,
      discovered_count: 309,
      assigned_count: 0,
      wizard: { state: 'needs_selection', discovered: 309, assigned: 0, synced: 0, has_parent: true, resumable: true, next_step: 'parent' },
    }),
    fetchDiscoveredAccounts: () => Promise.resolve({
      accounts: [
        { id: 'acct-1', external_id: 'act-1', name: 'Riyadh Retail', parent_external_id: 'org-1', parent_name: 'Acme Media', currency: 'SAR', timezone: 'Asia/Riyadh', status: 'active', assigned_project_id: null, assigned: false, last_synced_at: null, access_lost_at: null },
        { id: 'acct-2', external_id: 'act-2', name: 'Jeddah Retail', parent_external_id: 'org-1', parent_name: 'Acme Media', currency: 'SAR', timezone: 'Asia/Riyadh', status: 'active', assigned_project_id: null, assigned: false, last_synced_at: null, access_lost_at: null },
      ],
      meta: { total: 2, per_page: 25, current_page: 1, last_page: 1 },
    }),
    fetchPlanUsage: () => Promise.resolve({ ad_accounts: { limit: 5, used: 0, remaining: 5 } }),
    confirmAccountSelection: (input: { projectId: string; connectionId: string; externalAccountIds: string[] }) => {
      calls.confirmed.push(input)
      return Promise.resolve({ connected: input.externalAccountIds.length })
    },
    refreshDiscoveredAccounts: (id: string) => {
      calls.refreshed.push(id)
      return Promise.resolve({ discovered: 2, created: 0, named: 1, access_lost: 0 })
    },
  }
})

/** Walk from the organisation step to the project step, choosing both accounts. */
async function reachProjectStep() {
  renderWithProviders(<ConnectionWizard connectionId="conn-1" onClose={() => {}} />)

  fireEvent.click(await screen.findByText('Acme Media'))
  const boxes = await screen.findAllByRole('checkbox')
  boxes.forEach((b) => fireEvent.click(b))
  fireEvent.click(screen.getByRole('button', { name: /متابعة|Continue/ }))

  return screen.findByTestId('wizard-step-project')
}

describe('ConnectionWizard — creating the project the accounts will feed', () => {
  afterEach(() => {
    calls.created = []
    calls.confirmed = []
    calls.refreshed = []
    state.projects = []
    state.parents = [{ external_id: 'org-1', name: 'Acme Media', account_count: 2 }]
    state.createError = null
  })

  /**
   * **The defect, pinned.** An advertiser with no client workspace creates a project, and the
   * request carries no `client_workspace_id` at all.
   */
  it('creates a project without naming a client workspace', async () => {
    state.parents = [{ external_id: 'org-1', name: 'Acme Media', account_count: 2 }]
    await reachProjectStep()

    fireEvent.change(screen.getByTestId('wizard-project-name'), { target: { value: 'حملات الربع الثالث' } })
    fireEvent.click(screen.getByTestId('wizard-create-project'))

    await waitFor(() => expect(calls.created).toHaveLength(1))
    expect(calls.created[0]).toEqual({ name: 'حملات الربع الثالث' })
    expect(calls.created[0]).not.toHaveProperty('client_workspace_id')
  })

  /** The name is the customer's, and an empty one is refused before anything is sent. */
  it('refuses an empty project name in words the customer can act on', async () => {
    state.parents = [{ external_id: 'org-1', name: 'Acme Media', account_count: 2 }]
    await reachProjectStep()

    fireEvent.click(screen.getByTestId('wizard-create-project'))

    const error = await screen.findByTestId('wizard-create-project-error')
    // The whole point: NOT «حدث خطأ غير متوقع.»
    expect(error.textContent).toContain('Enter a project name')
    expect(calls.created).toHaveLength(0)
  })

  /** When the server says the client is the customer's to choose, the wizard asks instead of failing. */
  it('reveals the client picker when the server says the choice is theirs', async () => {
    state.parents = [{ external_id: 'org-1', name: 'Acme Media', account_count: 2 }]
    state.createError = {
      response: {
        status: 422,
        data: {
          message: 'اختر مساحة العميل التي ينتمي إليها هذا المشروع.',
          errors: { client_workspace_id: ['اختر مساحة العميل التي ينتمي إليها هذا المشروع.'] },
        },
      },
    }

    await reachProjectStep()
    fireEvent.change(screen.getByTestId('wizard-project-name'), { target: { value: 'حملة' } })
    fireEvent.click(screen.getByTestId('wizard-create-project'))

    const picker = await screen.findByTestId('wizard-project-workspace')
    expect(picker).toBeTruthy()
    expect(screen.getByTestId('wizard-create-project-error').textContent).toContain('اختر مساحة العميل')
  })
})

describe('ConnectionWizard — confirming the selection', () => {
  afterEach(() => {
    calls.confirmed = []
    state.projects = []
    state.parents = [{ external_id: 'org-1', name: 'Acme Media', account_count: 2 }]
  })

  /**
   * RUNTIME-100 §10 — one call carrying the whole selection, not one call per account.
   *
   * The loop this replaces could leave half a selection applied when a cap was reached part-way, and
   * from the server's side nothing had failed.
   */
  it('sends every chosen account in a single confirmation', async () => {
    state.projects = [{ id: 'proj-1', name: 'مشروع قائم' }]
    state.parents = [{ external_id: 'org-1', name: 'Acme Media', account_count: 2 }]

    await reachProjectStep()
    fireEvent.click(screen.getByText('مشروع قائم'))

    fireEvent.click(await screen.findByTestId('wizard-confirm'))

    await waitFor(() => expect(calls.confirmed).toHaveLength(1))
    expect(calls.confirmed[0].externalAccountIds).toEqual(['acct-1', 'acct-2'])
    expect(calls.confirmed[0].projectId).toBe('proj-1')
    expect(calls.confirmed[0].connectionId).toBe('conn-1')
  })
})

describe('ConnectionWizard — an organisation with no name', () => {
  afterEach(() => {
    calls.refreshed = []
    state.parents = []
  })

  /**
   * RUNTIME-100 §5 — the live Snapchat state: ids and no names.
   *
   * An id shown as a name claims the provider called it that. Saying the name is unavailable is true,
   * and it is next to the button that fetches it.
   */
  it('says the name is unavailable rather than showing the id as one', async () => {
    state.parents = [{ external_id: '0f2c8a41-9b7d-4c2e-a1b8-4d5e6f708192', name: null, account_count: 309 }]

    renderWithProviders(<ConnectionWizard connectionId="conn-1" onClose={() => {}} />)

    expect(await screen.findByText('Name unavailable')).toBeTruthy()
    expect(screen.getByTestId('wizard-missing-names').textContent).toContain('1')
  })

  /** And the refresh asks the provider again with the token already held — no second OAuth. */
  it('refreshes the catalogue without re-authorising', async () => {
    state.parents = [{ external_id: 'org-1', name: null, account_count: 309 }]

    renderWithProviders(<ConnectionWizard connectionId="conn-1" onClose={() => {}} />)

    fireEvent.click(await screen.findByTestId('wizard-refresh-accounts'))

    await waitFor(() => expect(calls.refreshed).toEqual(['conn-1']))
  })
})
