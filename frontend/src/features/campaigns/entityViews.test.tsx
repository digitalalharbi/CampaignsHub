import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { EntityWorkspacePage } from './EntityWorkspacePage'
import { renderWithProviders, signInWith } from '@/test/utils'
import { useProject } from '@/stores/project'

const save = vi.fn()
const modules: string[] = []

vi.mock('@/features/analytics/AnalyticsPage', () => ({ EntityTab: () => <div data-testid="entity-tab" /> }))
vi.mock('./ManagementOverview', () => ({ ManagementOverview: () => null }))
vi.mock('@/features/analytics/api', async (orig) => ({
  ...(await orig<Record<string, unknown>>()),
  useEntities: () => ({ data: { entities: [{ provider: 'meta' }, { provider: 'snapchat' }] }, isLoading: false }),
}))
vi.mock('@/features/dashboard/savedViews', () => ({
  useSavedViews: (_e: boolean, module: string) => { modules.push(module); return { data: [{ id: 'v1', name: 'Snap only', module, filters: { provider: ['snapchat'], status: 'all', search: 'ramadan', hidden: ['cpa'] }, date_range: { days: 7 }, comparison: null, sort_order: 0, is_default: false }] } },
  useSaveView: () => ({ mutate: save, isPending: false }),
  useDeleteView: () => ({ mutate: vi.fn() }),
  useSetDefaultView: () => ({ mutate: vi.fn() }),
}))

/** ADGROUPS-PAGE-001 / ADS-PAGE-001 — saved views on the ad-groups and ads pages. */
describe('saved views on the ad-groups and ads pages', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    modules.length = 0
    localStorage.clear()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })

  it('applies a saved view as one change: platform, search, hidden columns and period', async () => {
    renderWithProviders(<EntityWorkspacePage level="ad_set" />, { locale: 'en' })
    fireEvent.click(await screen.findByTestId('entity-ad_set-view-v1'))
    await waitFor(() => expect((screen.getByTestId('entity-platform-ad_set') as HTMLSelectElement).value).toBe('snapchat'))
    expect((screen.getByTestId('entity-search-ad_set') as HTMLInputElement).value).toBe('ramadan')
    expect(JSON.parse(localStorage.getItem('ch-entity-columns-hidden:ad_set') ?? '[]')).toEqual(['cpa'])
  })

  it('keeps each page’s views apart: ad groups and ads read their own module', async () => {
    renderWithProviders(<EntityWorkspacePage level="ad" />, { locale: 'en' })
    await screen.findByTestId('entity-ad-views')
    expect(modules).toContain('entities-ad')
    expect(modules).not.toContain('entities-ad_set')
  })

  it('saves the current filters under a name', async () => {
    renderWithProviders(<EntityWorkspacePage level="ad" />, { locale: 'en' })
    fireEvent.click(await screen.findByTestId('entity-ad-view-new'))
    fireEvent.change(screen.getByTestId('entity-ad-view-name'), { target: { value: 'My view' } })
    fireEvent.click(screen.getByTestId('entity-ad-view-save'))
    expect(save).toHaveBeenCalledWith(expect.objectContaining({ name: 'My view', date_range: { days: 30 } }), expect.anything())
  })
})
