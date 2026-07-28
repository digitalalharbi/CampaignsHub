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
