import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { ProjectsPage } from './ProjectsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

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
  }
})

import { createProject, listClientWorkspaces, listProjects } from './api'

describe('ProjectsPage — create-form ErrorSummary', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['projects.view', 'projects.create'])
    vi.mocked(listProjects).mockResolvedValue([])
    vi.mocked(listClientWorkspaces).mockResolvedValue([{ id: 'w1', name: 'Acme WS' } as never])
  })
  afterEach(() => signOut())

  it('shows an ErrorSummary from a 422 and focuses the offending field', async () => {
    vi.mocked(createProject).mockRejectedValue({
      response: { status: 422, data: { message: 'Validation failed', errors: { name: ['The name has already been taken.'] } } },
    })
    renderWithProviders(<ProjectsPage />, { locale: 'en' })

    fireEvent.click(screen.getByRole('button', { name: /New project/i }))
    // Wait for the async workspace option before selecting it (native selects ignore unknown values).
    await screen.findByRole('option', { name: 'Acme WS' })
    fireEvent.change(screen.getByLabelText(/Client workspace/i), { target: { value: 'w1' } })
    fireEvent.change(screen.getByLabelText(/^Name/i), { target: { value: 'Dup' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    const summary = await screen.findByTestId('error-summary')
    expect(summary).toHaveTextContent('The name has already been taken.')
    fireEvent.click(screen.getByRole('button', { name: 'The name has already been taken.' }))
    expect(screen.getByLabelText(/^Name/i)).toHaveFocus()
  })
})

/**
 * The two links out of a project card must stay inside the portal the reader is in (ADR 0002 §2).
 *
 * They were written as `/projects/{id}/team` and `/projects/{id}/integrations`, and there is no
 * `/projects` route in ANY portal — so both were dead in `/app` and `/agency` alike. Nothing caught
 * it, because a client-side router answers a route it does not have with a blank page and an HTTP
 * 200: it took pressing them during a live review.
 */
describe('ProjectsPage — the links out of a card', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['projects.view'])
    vi.mocked(listProjects).mockResolvedValue([
      { id: 'p1', name: 'Retail launch', status: 'active', client_workspace_id: 'w1' } as never,
    ])
    vi.mocked(listClientWorkspaces).mockResolvedValue([{ id: 'w1', name: 'Acme WS' } as never])
  })
  afterEach(() => signOut())

  it.each([
    ['/app/projects', '/app'],
    ['/agency/projects', '/agency'],
  ])('resolves against the portal in the URL (%s)', async (route, base) => {
    renderWithProviders(<ProjectsPage />, { locale: 'en', route })

    const integrations = await screen.findByRole('link', { name: /Integrations/i })
    expect(integrations).toHaveAttribute('href', `${base}/projects/p1/integrations`)
    expect(screen.getByRole('link', { name: /Team/i }))
      .toHaveAttribute('href', `${base}/projects/p1/team`)
  })
})

/** GA4-HEALTH-001 — the card says whether the store measurement is live, when it was read, and the platforms' gap. */
describe('ProjectsPage — the GA4 health chip', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['projects.view'])
    vi.mocked(listClientWorkspaces).mockResolvedValue([{ id: 'w1', name: 'Acme WS' } as never])
  })
  afterEach(() => signOut())

  it('shows state, last read and the gap for a project with a property, and nothing for one without', async () => {
    vi.mocked(listProjects).mockResolvedValue([
      { id: 'p1', name: 'With store', status: 'active', client_workspace_id: 'w1', summary: { accounts: 1, providers: ['meta'], data_last_synced_at: null, team_members: 1, campaigns: 2, attention: null, ga4: { state: 'stale', last_read_at: '2026-10-01T10:00:00Z', gap_share: 0.39 } } } as never,
      { id: 'p2', name: 'No store', status: 'active', client_workspace_id: 'w1', summary: { accounts: 1, providers: ['meta'], data_last_synced_at: null, team_members: 1, campaigns: 2, attention: null, ga4: null } } as never,
    ])
    renderWithProviders(<ProjectsPage />, { locale: 'en' })

    const chip = await screen.findByTestId('project-ga4-p1')
    expect(chip).toHaveAttribute('data-state', 'stale')
    expect(chip).toHaveTextContent('GA4')
    expect(chip).toHaveTextContent('Gap +39%')
    expect(screen.queryByTestId('project-ga4-p2')).toBeNull()
  })
})
