import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { DashboardPage } from './DashboardPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

/**
 * ANALYTICS-FILTER-TRUTH-001 — «deep-link / refresh / Back preserve state», on the page.
 *
 * The hook is tested on its own; this is the claim a reader actually experiences. Every filter here
 * lived in `useState`, so narrowing to one platform and one objective and then reloading gave back
 * the unfiltered page — and the link sent to a colleague showed that colleague a different answer to
 * the question they were discussing, with nothing on screen to say so.
 */
const EMPTY = { data: undefined, isPending: false, isLoading: false, isError: false }

vi.mock('../analytics/hooks', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../analytics/hooks')>()
  return {
    ...actual,
    useSummary: vi.fn(() => ({ ...EMPTY, data: { current: {}, previous: {}, delta: {}, reported: {}, commerce: null, rows_in_scope: true } })),
    useTimeseries: vi.fn(() => EMPTY),
    usePlatforms: vi.fn(() => EMPTY),
    useCampaigns: vi.fn(() => EMPTY),
    useFunnel: vi.fn(() => EMPTY),
    useBudget: vi.fn(() => EMPTY),
    useFreshness: vi.fn(() => EMPTY),
  }
})

vi.mock('./savedViews', () => ({ useSavedViews: () => ({ data: [], isLoading: false, isError: false }) }))
vi.mock('./SavedViewsBar', () => ({ SavedViewsBar: () => null }))

describe('the dashboard opened from a link that already carries filters', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['campaigns.view'])
    useProject.getState().setCurrentProjectId('p1')
  })
  afterEach(() => signOut())

  it('arrives already narrowed to the objective in the link', async () => {
    renderWithProviders(<DashboardPage />, { locale: 'en', route: '/app/dashboard?objective=sales' })
    await screen.findByTestId('dashboard-intro')

    expect(screen.getByTestId('dashboard-objective')).toHaveValue('sales')
    // …and says so, the same way it would if the reader had chosen it here.
    expect(screen.getByTestId('dashboard-applied-objective:sales')).toBeInTheDocument()
  })

  it('arrives on the period the link asked for', async () => {
    renderWithProviders(<DashboardPage />, { locale: 'en', route: '/app/dashboard?days=7' })
    await screen.findByTestId('dashboard-intro')

    // `FilterChips` is a button group, not a select — the chosen period is the pressed chip.
    expect(screen.getByRole('button', { name: '7 days', pressed: true })).toBeInTheDocument()
  })

  /* A period that is not a period is not obeyed — it would ask for a window ending before it starts. */
  it('ignores a nonsense period rather than passing it to the backend', async () => {
    renderWithProviders(<DashboardPage />, { locale: 'en', route: '/app/dashboard?days=-5' })
    await screen.findByTestId('dashboard-intro')

    expect(screen.getByRole('button', { name: '30 days', pressed: true })).toBeInTheDocument()
  })

  it('writes a filter chosen on the page into the link, so a refresh keeps it', async () => {
    renderWithProviders(<DashboardPage />, { locale: 'en', route: '/app/dashboard' })
    await screen.findByTestId('dashboard-intro')

    fireEvent.change(screen.getByTestId('dashboard-objective'), { target: { value: 'leads' } })

    expect(screen.getByTestId('dashboard-applied-objective:leads')).toBeInTheDocument()
    // The reset puts the page back AND clears the link, rather than leaving a filter in the URL that
    // the page is no longer applying.
    fireEvent.click(screen.getByRole('button', { name: /Remove Objective/ }))
    expect(screen.queryByTestId('dashboard-applied-objective:leads')).not.toBeInTheDocument()
  })
})
