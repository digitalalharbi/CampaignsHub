import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { TabContentApprovals } from './TabContentApprovals'
import { renderWithProviders } from '@/test/utils'
import { useAuth } from '@/stores/auth'
import type { ClientDetail } from '../api'

vi.mock('@/lib/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api/client')>()
  return { ...actual, getData: vi.fn(), postData: vi.fn() }
})

import { getData, postData } from '@/lib/api/client'

const d = { id: 'c1', name: 'Razzah', projects: [{ id: 'p1', name: 'Alpha', status: 'active', created_at: null }] } as unknown as ClientDetail

/** CLIENT-CONTENT-APPROVAL-001 — the agency sends content for the client's approval and sees the answer. */
describe('TabContentApprovals', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(getData).mockResolvedValue({ approvals: [
      { id: 'a1', title: 'Story v1', caption: null, status: 'changes_requested', project_id: 'p1', decision_note: 'New logo', decided_by_contact: null, decided_at: null, created_at: null },
    ] })
    vi.mocked(postData).mockResolvedValue({ approval: {} })
  })

  it('sends an ask to the client for the chosen project', async () => {
    useAuth.setState({ hasPermission: () => true })
    renderWithProviders(<TabContentApprovals d={d} />, { locale: 'en' })

    expect(await screen.findByText('New logo')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Story v2' } })
    fireEvent.click(screen.getByRole('button', { name: /Send/ }))
    await waitFor(() => expect(postData).toHaveBeenCalledWith('/app/clients/c1/content-approvals', { project_id: 'p1', title: 'Story v2', caption: undefined }))
  })

  it('shows no form to someone without content.create', async () => {
    useAuth.setState({ hasPermission: (k: string) => k === 'content.view' })
    renderWithProviders(<TabContentApprovals d={d} />, { locale: 'en' })

    expect(await screen.findByText('Story v1')).toBeInTheDocument()
    expect(screen.queryByTestId('content-approval-form')).toBeNull()
  })
})
