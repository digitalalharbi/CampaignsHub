import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import { Route, Routes } from 'react-router-dom'
import { ProjectIntegrationsPage } from './ProjectIntegrationsPage'
import { renderWithProviders, signInWith } from '@/test/utils'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
  getEnvelope: vi.fn(),
  postData: vi.fn(),
}))

import { getData, getEnvelope } from '@/lib/api/client'

/**
 * «المنصات» counts the platforms bound to this project, which is what its label claims.
 *
 * It counted the distinct providers among discovered CAMPAIGNS instead. A project with an account
 * bound and nothing synced yet therefore drew «المنصات 0» directly above a list naming that very
 * platform — on the panel whose own title is «الحسابات المرتبطة بهذا المشروع». Seen on the demo
 * tenant's only bound project: `bound=1`, platforms `0`, with «Sandbox Ad Account — Sandbox»
 * printed underneath.
 *
 * Zero is not a smaller version of one here. It is a different claim, and the false one is the one
 * that looks like data.
 */
const PROJECT = 'p-1'

const binding = {
  id: 'b1', purpose: 'advertising', provider: 'sandbox', is_primary: true, is_active: true,
  created_at: null,
  account: {
    id: 'a1', provider: 'sandbox', external_id: 'sandbox-act-1', name: 'Sandbox Ad Account',
    currency: null, timezone: null, last_synced_at: null, connection_status: 'connected',
  },
}

describe('the project integrations counters', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['projects.view'])
    /* `listProjectTasks` reads the envelope, not the data — the tasks card is fed here. */
    vi.mocked(getEnvelope).mockResolvedValue({
      data: [{
        id: 't1', title: 'Prepare tracking — Demo', status: 'in_progress',
        priority: 'high', due_date: null, is_overdue: false,
      }],
      meta: { total: 1 },
    } as never)
    vi.mocked(getData).mockImplementation((path: string) => {
      /* `listProjectBindings` asks for `/projects/{id}/integrations` exactly. */
      if (path === `/projects/${PROJECT}/integrations`) return Promise.resolve([binding] as never)
      if (path === '/projects') return Promise.resolve([{ id: PROJECT, name: 'Demo' }] as never)

      /* The platform panel reads an OBJECT; handing it an array throws inside the panel. */
      if (path.endsWith('/integrations/platforms')) {
        return Promise.resolve({
          platforms: [],
          summary: { total: 0, with_credentials: 0, with_accounts: 0, discovered_campaigns: 0 },
        } as never)
      }

      /* The sync log reads an OBJECT too — an array has no `runs`, and the panel would throw. */
      if (path.endsWith('/sync-runs')) {
        return Promise.resolve({ runs: [], summary: {}, runs_total: 0, runs_withheld: 0 } as never)
      }

      /* Everything else — campaigns, tasks — is empty, which is the state that exposed the defect. */
      return Promise.resolve([] as never)
    })
  })

  it('counts the platforms bound to the project, not the ones that happen to have campaigns', async () => {
    renderWithProviders(
      <Routes>
        <Route path="/projects/:projectId/integrations" element={<ProjectIntegrationsPage />} />
      </Routes>,
      { locale: 'en', route: `/projects/${PROJECT}/integrations` },
    )

    /*
     * Wait for the BINDING, not for the label. The counter labels render on the first pass and the
     * values arrive with the query, so asserting on a label reads the zeros that were there before
     * the answer came back — which is how this test first passed against the defect it exists for.
     */
    /*
     * Wait for the BINDING, not for the label. The counter labels render on the first pass and the
     * values arrive with the query, so asserting on a label reads the zeros that were there before
     * the answer came back — which is how this test first passed against the defect it exists for.
     */
    /*
     * Wait for the BINDING, not for the label. The counter labels render on the first pass and the
     * values arrive with the query, so asserting on a label reads the zeros that were there before
     * the answer came back — which is how this test first passed against the defect it exists for.
     */
    await screen.findByText(/Sandbox Ad Account/)

    const platforms = (await screen.findByText('Platforms')).closest('div') as HTMLElement
    expect(within(platforms).getByText('1')).toBeInTheDocument()
  })

  /**
   * A revoked connection says so on the row.
   *
   * `connection_status` arrives on every account and appeared nowhere in the frontend outside the
   * type declaration. So an account whose authorisation had been revoked looked exactly like a
   * working one — same name, same type, same id — and «آخر تحديث» simply stopped moving. This is
   * the page an operator opens to ask why the numbers stopped.
   */
  it('says when a bound account’s connection is not healthy', async () => {
    vi.mocked(getData).mockImplementation((path: string) => {
      if (path === `/projects/${PROJECT}/integrations`) {
        return Promise.resolve([
          { ...binding, account: { ...binding.account, connection_status: 'revoked' } },
        ] as never)
      }
      if (path === '/projects') return Promise.resolve([{ id: PROJECT, name: 'Demo' }] as never)
      if (path.endsWith('/integrations/platforms')) {
        return Promise.resolve({
          platforms: [],
          summary: { total: 0, with_credentials: 0, with_accounts: 0, discovered_campaigns: 0 },
        } as never)
      }

      return Promise.resolve([] as never)
    })

    renderWithProviders(
      <Routes>
        <Route path="/projects/:projectId/integrations" element={<ProjectIntegrationsPage />} />
      </Routes>,
      { locale: 'en', route: `/projects/${PROJECT}/integrations` },
    )

    await screen.findByText(/Sandbox Ad Account/)
    expect(screen.getByText('Access revoked')).toBeInTheDocument()
  })

  /**
   * The install's CREDENTIAL state is not restated to a project reader — §12.
   *
   * `awaiting_credentials` says what keys this install holds. It is the platform operator's number,
   * nothing on a project page can change it, and on a customer's own project it reads as «none of
   * your platforms work» — which is exactly why the platform panel above stopped saying it and why
   * `integrations.spec.ts` asserts those words never reach this page. The badge I added for
   * connection health had the state in its map, so a bound account behind a credential-less install
   * would have put the sentence back on the page the rule was written for, and failed that spec.
   *
   * Silence rather than the raw key: the fallback shows an unknown state as itself, so leaving it
   * out of the map would have printed `awaiting_credentials`, which is worse than both.
   */
  it('does not restate the install’s credential state on a project row', async () => {
    vi.mocked(getData).mockImplementation((path: string) => {
      if (path === `/projects/${PROJECT}/integrations`) {
        return Promise.resolve([
          { ...binding, account: { ...binding.account, connection_status: 'awaiting_credentials' } },
        ] as never)
      }
      if (path === '/projects') return Promise.resolve([{ id: PROJECT, name: 'Demo' }] as never)
      if (path.endsWith('/integrations/platforms')) {
        return Promise.resolve({
          platforms: [],
          summary: { total: 0, with_credentials: 0, with_accounts: 0, discovered_campaigns: 0 },
        } as never)
      }

      return Promise.resolve([] as never)
    })

    renderWithProviders(
      <Routes>
        <Route path="/projects/:projectId/integrations" element={<ProjectIntegrationsPage />} />
      </Routes>,
      { locale: 'en', route: `/projects/${PROJECT}/integrations` },
    )

    await screen.findByText(/Sandbox Ad Account/)
    expect(screen.queryByText(/Awaiting credentials/i)).not.toBeInTheDocument()
    expect(screen.queryByText('awaiting_credentials')).not.toBeInTheDocument()
  })

  /** A healthy connection stays quiet — a list where every row carries a tick teaches nobody to read it. */
  it('says nothing when the connection is healthy', async () => {
    renderWithProviders(
      <Routes>
        <Route path="/projects/:projectId/integrations" element={<ProjectIntegrationsPage />} />
      </Routes>,
      { locale: 'en', route: `/projects/${PROJECT}/integrations` },
    )

    await screen.findByText(/Sandbox Ad Account/)
    expect(screen.queryByText('Access revoked')).toBeNull()
    expect(screen.queryByText('connected')).toBeNull()
  })

  /**
   * A task's state and urgency are NAMED, not printed from the column.
   *
   * The card rendered `task.priority` and `task.status` verbatim, so «مهام هذا المشروع» listed
   * «in_progress» and «high» in the middle of an Arabic page. `TasksPage` has named both all along
   * — the map was simply private to that file — and the two surfaces must not end up with two
   * different Arabic sentences for `waiting_client`, which is why the map moved rather than being
   * copied.
   */
  it('names a task’s state and urgency instead of printing the column', async () => {
    renderWithProviders(
      <Routes><Route path="/projects/:projectId/integrations" element={<ProjectIntegrationsPage />} /></Routes>,
      { route: `/projects/${PROJECT}/integrations`, locale: 'ar' },
    )

    expect(await screen.findByText('Prepare tracking — Demo')).toBeInTheDocument()
    expect(screen.getByText('قيد التنفيذ')).toBeInTheDocument()
    expect(screen.getByText(/عالية/)).toBeInTheDocument()
    expect(screen.queryByText('in_progress')).not.toBeInTheDocument()
    expect(screen.queryByText('high')).not.toBeInTheDocument()
  })
})
