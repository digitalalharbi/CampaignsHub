import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { DashboardPage } from './DashboardPage'
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

vi.mock('../analytics/hooks', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../analytics/hooks')>()
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

import { useSummary } from '../analytics/hooks'

const failing = (status: number, message?: string) => ({
  data: undefined,
  isPending: false,
  isLoading: false,
  isError: true,
  error: { response: { status, data: message ? { message } : {} } },
  refetch: vi.fn(),
})

describe('the dashboard when the summary request does not answer', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['campaigns.view'])
    useProject.getState().setCurrentProjectId('p1')
  })
  afterEach(() => signOut())

  it('says a refusal was a refusal, not that this account has no data', async () => {
    vi.mocked(useSummary).mockReturnValue(failing(403, 'Your membership does not cover this client') as never)

    renderWithProviders(<DashboardPage />, { locale: 'en' })
    await screen.findByTestId('dashboard-intro')

    expect(screen.getByTestId('dashboard-metrics-failure-permission')).toBeInTheDocument()
    // The sentence the reader used to get instead, fourteen times over.
    expect(screen.queryByText('No data')).not.toBeInTheDocument()
    expect(screen.queryByTestId('metric-spend')).not.toBeInTheDocument()
  })

  it('offers a retry for a dead backend, which is the one case retrying helps', async () => {
    vi.mocked(useSummary).mockReturnValue(failing(500) as never)

    renderWithProviders(<DashboardPage />, { locale: 'en' })
    await screen.findByTestId('dashboard-intro')

    expect(screen.getByTestId('dashboard-metrics-failure-retryable')).toBeInTheDocument()
    expect(screen.queryByText('No data')).not.toBeInTheDocument()
  })

  it('does not answer for the figures while the request is still in flight', async () => {
    vi.mocked(useSummary).mockReturnValue({ ...EMPTY, isPending: true, isLoading: true } as never)

    renderWithProviders(<DashboardPage />, { locale: 'en' })
    await screen.findByTestId('dashboard-intro')

    expect(screen.getByTestId('dashboard-metrics-loading')).toBeInTheDocument()
    expect(screen.queryByText('No data')).not.toBeInTheDocument()
  })
})
