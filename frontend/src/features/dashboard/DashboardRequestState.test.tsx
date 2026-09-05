import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { AnalyticsPage } from '@/features/analytics/AnalyticsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

/**
 * METRICS-REQUEST-STATE-001, on the page that showed it.
 *
 * The strip's states are only as true as the props the page hands it, and the page derived
 * everything from `summary.data`. A refused request and a dead backend both leave `data` undefined —
 * so the dashboard rendered its KPI row with nothing to read, and every card printed «لا توجد
 * بيانات»: a confident statement about this account's advertising, made by a request that never came
 * back.
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
    useSummary: vi.fn(),
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

import { useSummary } from '../analytics/api'

const failing = (status: number, message?: string) => ({
  data: undefined,
  isPending: false,
  isLoading: false,
  isError: true,
  error: { response: { status, data: message ? { message } : {} } },
  refetch: vi.fn(),
})

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
describe('the dashboard when the summary request does not answer', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['campaigns.view'])
    useProject.getState().setCurrentProjectId('p1')
  })
  afterEach(() => signOut())

  it('says a refusal was a refusal, not that this account has no data', async () => {
    vi.mocked(useSummary).mockReturnValue(failing(403, 'Your membership does not cover this client') as never)

    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'en' })
    await screen.findByTestId('dashboard-intro')

    expect(screen.getByTestId('dashboard-metrics-failure-permission')).toBeInTheDocument()
    // The sentence the reader used to get instead, fourteen times over.
    expect(screen.queryByText('No data')).not.toBeInTheDocument()
    expect(screen.queryByTestId('metric-spend')).not.toBeInTheDocument()
  })

  it('offers a retry for a dead backend, which is the one case retrying helps', async () => {
    vi.mocked(useSummary).mockReturnValue(failing(500) as never)

    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'en' })
    await screen.findByTestId('dashboard-intro')

    expect(screen.getByTestId('dashboard-metrics-failure-retryable')).toBeInTheDocument()
    expect(screen.queryByText('No data')).not.toBeInTheDocument()
  })

  it('does not answer for the figures while the request is still in flight', async () => {
    vi.mocked(useSummary).mockReturnValue({ ...EMPTY, isPending: true, isLoading: true } as never)

    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'en' })
    await screen.findByTestId('dashboard-intro')

    expect(screen.getByTestId('dashboard-metrics-loading')).toBeInTheDocument()
    expect(screen.queryByText('No data')).not.toBeInTheDocument()
  })
})
