import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { ContentHub } from './ContentHub'

vi.mock('./CreativesPage', () => ({ CreativesPage: () => <div data-testid="analysis-page" /> }))
vi.mock('./library/CreativesTable', () => ({ CreativesTable: () => <div data-testid="table-stub" /> }))
vi.mock('./library/CreativeInsights', () => ({ CreativeInsights: () => <div data-testid="insights-stub" /> }))
vi.mock('./library/ContentComposer', () => ({ ContentComposer: () => <div data-testid="composer-stub" /> }))
vi.mock('./intake/IntakePanel', () => ({ IntakePanel: () => <div data-testid="intake-stub" /> }))
vi.mock('./intake/FollowUpPanel', () => ({ FollowUpPanel: () => <div data-testid="follow-stub" /> }))
vi.mock('./intake/DriveSyncPanel', () => ({ DriveSyncPanel: () => <div data-testid="drive-stub" /> }))
vi.mock('./intake/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./intake/api')>()
  return { ...actual, listIntake: vi.fn().mockResolvedValue({ items: [], total: 0, withheld: 0, counts: {}, aiState: 'awaiting_credentials', objectives: [], platforms: [], maxUploadKb: 1 }) }
})

describe('ContentHub — CONTENT-LIBRARY-SNAP-001', () => {
  afterEach(() => signOut())

  it('opens on the creatives table under one title and three tabs', () => {
    signInWith(['content.view', 'campaigns.view'])
    renderWithProviders(<ContentHub />)
    expect(screen.getByRole('heading', { name: 'Content library' })).toBeInTheDocument()
    expect(screen.getByTestId('table-stub')).toBeInTheDocument()
    expect(screen.getAllByRole('tab').map((t) => t.textContent)).toEqual(['Creatives', 'Creative insights', 'Media library'])
  })

  it('switches to insights and to the media library', async () => {
    signInWith(['content.view'])
    renderWithProviders(<ContentHub />)
    fireEvent.click(screen.getByRole('tab', { name: 'Creative insights' }))
    await waitFor(() => expect(screen.getByTestId('insights-stub')).toBeInTheDocument())
    fireEvent.click(screen.getByRole('tab', { name: 'Media library' }))
    await waitFor(() => expect(screen.getByTestId('media-library')).toBeInTheDocument())
    fireEvent.click(screen.getByTestId('media-folder-review'))
    await waitFor(() => expect(screen.getByTestId('intake-stub')).toBeInTheDocument())
  })

  it('keeps the old review address working as a media folder', () => {
    signInWith(['content.view'])
    renderWithProviders(<ContentHub />, { route: '/?tab=review' })
    expect(screen.getByTestId('intake-stub')).toBeInTheDocument()
  })

  it('hides the media library and the new-content button from a reader without content.view', () => {
    signInWith(['campaigns.view'])
    renderWithProviders(<ContentHub />)
    expect(screen.queryByRole('tab', { name: 'Media library' })).not.toBeInTheDocument()
    expect(screen.queryByTestId('content-new')).not.toBeInTheDocument()
  })

  it('opens the studio composer from «New content»', async () => {
    signInWith(['content.view'])
    renderWithProviders(<ContentHub />)
    fireEvent.click(screen.getByTestId('content-new'))
    fireEvent.click(screen.getByTestId('content-new-studio'))
    expect(await screen.findByTestId('composer-stub')).toBeInTheDocument()
  })

  it('a drill-down from another page still opens the full analysis view', () => {
    signInWith(['content.view'])
    renderWithProviders(<ContentHub />, { route: '/?campaign_ids[]=abc' })
    expect(screen.getByTestId('analysis-page')).toBeInTheDocument()
  })
})
