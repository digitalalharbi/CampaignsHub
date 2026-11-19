import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { AnalyticsPage } from '@/features/analytics/AnalyticsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

const EMPTY = { data: undefined, isPending: false, isLoading: false, isError: false }

vi.mock('@/features/analytics/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/features/analytics/api')>()
  return {
    ...actual,
    useSummary: vi.fn(() => EMPTY),
    useTimeseries: vi.fn(() => EMPTY),
    usePlatforms: vi.fn(() => EMPTY),
    useCampaigns: vi.fn(() => EMPTY),
    useFunnel: vi.fn(() => EMPTY),
    useBudget: vi.fn(() => EMPTY),
    useFreshness: vi.fn(() => EMPTY),
  }
})
vi.mock('@/features/dashboard/savedViews', () => ({ useSavedViews: () => ({ data: [], isLoading: false, isError: false }) }))
vi.mock('@/features/dashboard/SavedViewsBar', () => ({ SavedViewsBar: () => null }))
vi.mock('./api', async (importOriginal) => ({ ...(await importOriginal<typeof import('./api')>()), useWidgetLayout: vi.fn() }))
vi.mock('./WidgetSurface', () => ({ WidgetSurface: ({ surface }: { surface: string }) => <div data-testid={`stub-surface-${surface}`} /> }))

import { useWidgetLayout } from './api'

const layout = (reasons: Array<string | null>) => ({
  data: { surface: 'analytics', sizes: ['third', 'half', 'full'], widgets: reasons.map((reason, i) => ({ key: `w${i}`, visible: reason === null, order: i, size: 'half', kind: 'trend', module: 'm', reason, title: { en: 'W', ar: 'و' } })) },
})

/**
 * WIDGET-REGISTRY-001 — the registry reaches the Dashboard and Analytics, and reaches NOBODY who has no widget module on.
 */
describe('widgets on the Dashboard and Analytics', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['campaigns.view', 'analytics.view'])
    useProject.getState().setCurrentProjectId('p1')
  })
  afterEach(() => signOut())

  it('adds the Insights tab only where a widget module is on, and shows the analytics surface under it', async () => {
    vi.mocked(useWidgetLayout).mockReturnValue(layout([null, 'module_off']) as never)
    renderWithProviders(<AnalyticsPage surface="analytics" />, { locale: 'en' })

    const tab = await screen.findByRole('tab', { name: 'Insights' })
    fireEvent.click(tab)
    expect(await screen.findByTestId('stub-surface-analytics')).toBeTruthy()
  })

  it('leaves the tab bar exactly as it was when every widget module is off', async () => {
    vi.mocked(useWidgetLayout).mockReturnValue(layout(['module_off', 'module_off']) as never)
    renderWithProviders(<AnalyticsPage surface="analytics" />, { locale: 'en' })

    await screen.findAllByRole('tab')
    expect(screen.queryByRole('tab', { name: 'Insights' })).toBeNull()
    expect(screen.queryByTestId('stub-surface-analytics')).toBeNull()
  })

  it('mounts the dashboard surface beneath the dashboard overview, and not on the analytics page', async () => {
    vi.mocked(useWidgetLayout).mockReturnValue(layout([null]) as never)
    const { unmount } = renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'en' })
    expect(await screen.findByTestId('stub-surface-dashboard')).toBeTruthy()
    unmount()

    renderWithProviders(<AnalyticsPage surface="analytics" />, { locale: 'en' })
    await screen.findAllByRole('tab')
    expect(screen.queryByTestId('stub-surface-dashboard')).toBeNull()
  })
})
