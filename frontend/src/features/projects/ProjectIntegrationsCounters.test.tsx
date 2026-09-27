import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import { Route, Routes } from 'react-router-dom'
import { ProjectIntegrationsPage } from './ProjectIntegrationsPage'
import { renderWithProviders, signInWith } from '@/test/utils'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
  postData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

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
})
