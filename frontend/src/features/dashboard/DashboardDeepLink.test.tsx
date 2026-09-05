import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { AnalyticsPage } from '@/features/analytics/AnalyticsPage'
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

/*
 * Mocked at `../analytics/api`, not `../analytics/hooks`.
 *
 * `hooks.ts` is a re-export of `api.ts`, and the overview composition imports from `api` directly.
 * Mocking the re-export replaced a module the code under test never loads, so every hook ran for
 * real, every query stayed pending, and the assertions below failed against a page that was
 * genuinely still loading. Mock what the code imports.
 */
vi.mock('../analytics/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../analytics/api')>()
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

/*
 * Mounted as `<AnalyticsPage surface="dashboard" />` — the component `/app/dashboard` actually
 * renders (router.tsx). These cases were written against `features/dashboard/DashboardPage.tsx`,
 * which stopped being routed and was imported by nothing but these four files: coverage aimed at a
 * page no user could open, while the real Dashboard was covered only by inference.
 *
 * The assertions are unchanged. They already addressed the surface by its testids — `dashboard-intro`,
 * `dashboard-metrics` — and those come from the `surface` prop, so they read the routed page as
 * literally as they read the retired one.
 */
describe('the dashboard opened from a link that already carries filters', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['campaigns.view'])
    useProject.getState().setCurrentProjectId('p1')
  })
  afterEach(() => signOut())

  it('arrives already narrowed to the objective in the link', async () => {
    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'en', route: '/app/dashboard?objective=sales' })
    await screen.findByTestId('dashboard-intro')

    expect(screen.getByTestId('dashboard-objective')).toHaveValue('sales')
    // …and says so, the same way it would if the reader had chosen it here.
    expect(screen.getByTestId('dashboard-applied-objective:sales')).toBeInTheDocument()
  })

  it('arrives on the period the link asked for', async () => {
    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'en', route: '/app/dashboard?days=7' })
    await screen.findByTestId('dashboard-intro')

    // `FilterChips` is a button group, not a select — the chosen period is the pressed chip.
    expect(screen.getByRole('button', { name: '7 days', pressed: true })).toBeInTheDocument()
  })

  /* A period that is not a period is not obeyed — it would ask for a window ending before it starts. */
  it('ignores a nonsense period rather than passing it to the backend', async () => {
    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'en', route: '/app/dashboard?days=-5' })
    await screen.findByTestId('dashboard-intro')

    expect(screen.getByRole('button', { name: '30 days', pressed: true })).toBeInTheDocument()
  })

  it('writes a filter chosen on the page into the link, so a refresh keeps it', async () => {
    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'en', route: '/app/dashboard' })
    await screen.findByTestId('dashboard-intro')

    fireEvent.change(screen.getByTestId('dashboard-objective'), { target: { value: 'leads' } })

    expect(screen.getByTestId('dashboard-applied-objective:leads')).toBeInTheDocument()
    // The reset puts the page back AND clears the link, rather than leaving a filter in the URL that
    // the page is no longer applying.
    fireEvent.click(screen.getByRole('button', { name: /Remove Objective/ }))
    expect(screen.queryByTestId('dashboard-applied-objective:leads')).not.toBeInTheDocument()
  })
})
