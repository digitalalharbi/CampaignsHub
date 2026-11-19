import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { ContentHub } from './ContentHub'

vi.mock('./CreativesPage', () => ({ CreativesPage: () => <div data-testid="library-page" /> }))
vi.mock('./intake/IntakePanel', () => ({ IntakePanel: () => <div data-testid="intake-stub" /> }))
vi.mock('./intake/FollowUpPanel', () => ({ FollowUpPanel: () => <div data-testid="follow-stub" /> }))
vi.mock('./intake/DriveSyncPanel', () => ({ DriveSyncPanel: () => <div data-testid="drive-stub" /> }))
vi.mock('./intake/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./intake/api')>()
  return { ...actual, listIntake: vi.fn().mockResolvedValue({ items: [], total: 0, withheld: 0, counts: { in_review: 3 }, aiState: 'awaiting_credentials', objectives: [], platforms: [], maxUploadKb: 1 }) }
})

describe('ContentHub', () => {
  afterEach(() => signOut())

  it('keeps the library as the page when the reader cannot see intake — no tabs at all', () => {
    signInWith(['campaigns.view'])
    renderWithProviders(<ContentHub />)
    expect(screen.getByTestId('library-page')).toBeInTheDocument()
    expect(screen.queryByTestId('content-hub-tabs')).not.toBeInTheDocument()
  })

  it('an unknown tab falls back to the library; the review tab carries the waiting count', async () => {
    signInWith(['content.view'])
    renderWithProviders(<ContentHub />, { route: '/?tab=nonsense' })
    expect(screen.getByTestId('library-page')).toBeInTheDocument()
    expect(await screen.findByRole('tab', { name: /Intake and review \(3\)/ })).toBeInTheDocument()
  })

  it('switches between the four views', async () => {
    signInWith(['content.view'])
    renderWithProviders(<ContentHub />)
    fireEvent.click(await screen.findByRole('tab', { name: /Intake and review/ }))
    await waitFor(() => expect(screen.getByTestId('intake-stub')).toBeInTheDocument())
    fireEvent.click(screen.getByRole('tab', { name: 'Follow-up' }))
    await waitFor(() => expect(screen.getByTestId('follow-stub')).toBeInTheDocument())
    fireEvent.click(screen.getByRole('tab', { name: 'Google Drive' }))
    await waitFor(() => expect(screen.getByTestId('drive-stub')).toBeInTheDocument())
    fireEvent.click(screen.getByRole('tab', { name: 'Library' }))
    await waitFor(() => expect(screen.getByTestId('library-page')).toBeInTheDocument())
  })

  it('opens a tab from the address the notification links to', () => {
    signInWith(['content.view'])
    renderWithProviders(<ContentHub />, { route: '/?tab=review' })
    expect(screen.getByTestId('intake-stub')).toBeInTheDocument()
  })
})
