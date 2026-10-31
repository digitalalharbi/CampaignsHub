import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { CreateWorkspaceDialog } from './CreateWorkspaceDialog'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, createWorkspace: vi.fn(), fetchPlans: vi.fn() }
})

import { createWorkspace, fetchPlans } from './api'

/** SUPERADMIN-WORKSPACE-001 — the form sends what the operator chose, and the one-time link is shown once, ready to copy. */
describe('CreateWorkspaceDialog', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(fetchPlans).mockResolvedValue({ plans: [] })
  })
  afterEach(() => signOut())

  it('creates the workspace and shows the owner link when mail was not confirmed', async () => {
    vi.mocked(createWorkspace).mockResolvedValue({
      tenant: { id: 't1', name: 'Acme', slug: 'acme', plan: 'trial', modules: ['paid_media'] },
      created: true, owner_has_access: false,
      invitation: { delivery_status: 'failed', accept_link: 'https://campaignshub.io/invite/accept?token=abc', expires_in_hours: 72 },
    })
    renderWithProviders(<CreateWorkspaceDialog open onClose={() => {}} ar={false} />, { locale: 'en' })

    fireEvent.change(screen.getByTestId('cw-company'), { target: { value: 'Acme' } })
    fireEvent.change(screen.getByTestId('cw-email'), { target: { value: 'owner@acme.test' } })
    fireEvent.click(screen.getByTestId('cw-module-influencer_marketing'))
    fireEvent.click(screen.getByTestId('cw-submit'))

    await waitFor(() => expect(createWorkspace).toHaveBeenCalledWith({
      company_name: 'Acme', workspace_name: undefined, owner_email: 'owner@acme.test', plan: undefined, modules: ['paid_media'],
    }))
    expect(await screen.findByTestId('owner-invitation-link')).toHaveTextContent('/invite/accept?token=abc')
    expect(screen.getByTestId('owner-invitation')).toHaveTextContent(/hand it to the owner yourself/i)
  })

  it('says when the workspace already existed and nothing was duplicated', async () => {
    vi.mocked(createWorkspace).mockResolvedValue({
      tenant: { id: 't1', name: 'Acme', slug: 'acme', plan: 'trial', modules: ['paid_media'] },
      created: false, owner_has_access: true, invitation: null,
    })
    renderWithProviders(<CreateWorkspaceDialog open onClose={() => {}} ar={false} />, { locale: 'en' })

    fireEvent.change(screen.getByTestId('cw-company'), { target: { value: 'Acme' } })
    fireEvent.change(screen.getByTestId('cw-email'), { target: { value: 'owner@acme.test' } })
    fireEvent.click(screen.getByTestId('cw-submit'))

    expect(await screen.findByTestId('create-workspace-result')).toHaveTextContent(/already existed; nothing was duplicated/i)
  })
})
