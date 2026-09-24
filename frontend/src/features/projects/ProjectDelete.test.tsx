import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ProjectsPage } from './ProjectsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

/**
 * PROJECT-DELETE-001 — «حذف المشروع», and the dialog that has to earn the press.
 *
 * ## What the product offered instead
 *
 * Archive. Which is a different promise — reversible, keeps everything, project comes back — and it
 * was the only thing on the card, so an agency that had finished with a client was told to file them
 * away for ever. `projects.delete` had been in the permission catalogue the whole time with nothing
 * reading it.
 *
 * ## Why the dialog is most of this unit
 *
 * The deletion itself is three updates and a soft delete. The danger is the press: a project sits
 * under a SHARED authorisation, so the question a person actually needs answered before agreeing is
 * «what does this NOT touch», and no amount of «Are you sure?» answers it. The dialog therefore
 * states the counts the server measured and the two guarantees the server keeps, and it will not
 * arm its button until the project has been named.
 *
 * The tests are written from the ways that goes wrong: a button that is live before the name is
 * typed, a name that is matched loosely, a delete offered to somebody who may not delete, and a list
 * that still shows the project afterwards until the browser is reloaded.
 */

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    listProjects: vi.fn(),
    listClientWorkspaces: vi.fn(),
    createProject: vi.fn(),
    updateProject: vi.fn(),
    projectAction: vi.fn(),
    archiveProject: vi.fn(),
    fetchProjectDeletionImpact: vi.fn(),
    deleteProject: vi.fn(),
  }
})

import { deleteProject, fetchProjectDeletionImpact, listClientWorkspaces, listProjects } from './api'

const PROJECT = { id: 'p1', name: 'رزة أفينيو', status: 'active', client_workspace_id: 'w1', setup_completion: 40 }

const IMPACT = {
  project: { id: 'p1', name: 'رزة أفينيو', status: 'active', client: 'Acme WS' },
  counts: {
    integration_bindings: 2,
    campaigns: 14,
    ad_sets: 31,
    ads: 88,
    creatives: 61,
    reports: 5,
    report_exports: 3,
    tasks: 7,
    team_members: 4,
    metric_rows: 4212,
    report_schedules: 1,
    active_shares: 2,
  },
  revokes_provider_authorisation: false,
  deletes_advertising_accounts: false,
}

/** Open the danger dialog for the one project on the page. */
async function openTheDialog() {
  renderWithProviders(<ProjectsPage />, { locale: 'ar' })

  fireEvent.click(await screen.findByTestId('project-delete-p1'))

  return screen.findByTestId('project-delete-dialog')
}

describe('deleting a project', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['projects.view', 'projects.update', 'projects.delete'])
    vi.mocked(listProjects).mockResolvedValue([PROJECT as never])
    vi.mocked(listClientWorkspaces).mockResolvedValue([{ id: 'w1', name: 'Acme WS' } as never])
    vi.mocked(fetchProjectDeletionImpact).mockResolvedValue(IMPACT as never)
    vi.mocked(deleteProject).mockResolvedValue(IMPACT as never)
  })
  afterEach(() => signOut())

  /** The counts are the point of the dialog: this is what will happen, not «are you sure». */
  it('states what the deletion will reach, measured by the server', async () => {
    const dialog = await openTheDialog()

    await waitFor(() => expect(fetchProjectDeletionImpact).toHaveBeenCalledWith('p1'))

    expect(dialog).toHaveTextContent('5')
    expect(dialog).toHaveTextContent('4,212')
    expect(dialog.textContent).toContain('الحسابات الإعلانية')
  })

  /**
   * And what it will NOT reach. A person about to delete one client's project is entitled to know,
   * in the dialog, that the authorisation the OTHER client advertises through survives it.
   */
  it('promises that the advertising account and the authorisation survive', async () => {
    await openTheDialog()
    await waitFor(() => expect(fetchProjectDeletionImpact).toHaveBeenCalled())

    const keeps = screen.getByTestId('project-delete-keeps')
    expect(keeps).toBeInTheDocument()
    expect(keeps.textContent).toContain('لن')
  })

  /** The button is not armed until the project has been named — exactly. */
  it('refuses to arm until the name is typed exactly', async () => {
    await openTheDialog()
    await waitFor(() => expect(fetchProjectDeletionImpact).toHaveBeenCalled())

    const confirm = screen.getByTestId('project-delete-confirm')
    expect(confirm).toBeDisabled()

    // A prefix is not the name. «Close enough» is the property this field must not have.
    fireEvent.change(screen.getByTestId('project-delete-name'), { target: { value: 'رزة' } })
    expect(screen.getByTestId('project-delete-confirm')).toBeDisabled()

    fireEvent.change(screen.getByTestId('project-delete-name'), { target: { value: 'رزة أفينيو' } })
    expect(screen.getByTestId('project-delete-confirm')).toBeEnabled()

    expect(deleteProject).not.toHaveBeenCalled()
  })

  /**
   * **The defect the product had.** Deleting says so, and the list changes — with no reload.
   */
  it('deletes, says so, and the project leaves the list without a browser reload', async () => {
    await openTheDialog()
    await waitFor(() => expect(fetchProjectDeletionImpact).toHaveBeenCalled())

    fireEvent.change(screen.getByTestId('project-delete-name'), { target: { value: 'رزة أفينيو' } })

    // The server's next answer: the project is gone.
    vi.mocked(listProjects).mockResolvedValue([])

    fireEvent.click(screen.getByTestId('project-delete-confirm'))

    await waitFor(() => expect(deleteProject).toHaveBeenCalledWith('p1', 'رزة أفينيو'))

    expect(await screen.findByTestId('project-action-notice')).toHaveTextContent('تم حذف المشروع')
    await waitFor(() => expect(screen.queryByText('رزة أفينيو')).not.toBeInTheDocument())
  })

  /** A refusal reaches the reader in the server's words rather than closing the dialog silently. */
  it('shows the server’s refusal instead of pretending it worked', async () => {
    vi.mocked(deleteProject).mockRejectedValue({
      response: { status: 422, data: { message: 'Type the project name exactly to confirm deletion.' } },
    })

    await openTheDialog()
    await waitFor(() => expect(fetchProjectDeletionImpact).toHaveBeenCalled())
    fireEvent.change(screen.getByTestId('project-delete-name'), { target: { value: 'رزة أفينيو' } })
    fireEvent.click(screen.getByTestId('project-delete-confirm'))

    expect(await screen.findByTestId('project-delete-error')).toHaveTextContent('Type the project name exactly')
    // Still open, still showing the project: nothing was claimed.
    expect(screen.getByTestId('project-delete-dialog')).toBeInTheDocument()
  })
})

describe('who is offered the delete', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listProjects).mockResolvedValue([PROJECT as never])
    vi.mocked(listClientWorkspaces).mockResolvedValue([{ id: 'w1', name: 'Acme WS' } as never])
  })
  afterEach(() => signOut())

  /**
   * Hiding is not security — the server refuses this reader either way — but offering somebody a
   * destructive button they will always be refused is its own defect.
   */
  it('is not offered to a reader who may rename but not delete', async () => {
    signInWith(['projects.view', 'projects.update'])

    renderWithProviders(<ProjectsPage />, { locale: 'ar' })

    await screen.findByText('رزة أفينيو')
    expect(screen.queryByTestId('project-delete-p1')).not.toBeInTheDocument()
  })
})
