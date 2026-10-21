import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { AdvertiserProjectsStrip, AdvertiserSourcesStrip } from './AdvertiserContext'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('@/features/projects/api', () => ({ fetchPortfolioOverview: vi.fn() }))
vi.mock('@/features/commerce/api', () => ({ listStoreProviders: vi.fn() }))
vi.mock('@/features/measurement/api', () => ({ listMeasurementProviders: vi.fn() }))

import { fetchPortfolioOverview } from '@/features/projects/api'
import { listStoreProviders } from '@/features/commerce/api'
import { listMeasurementProviders } from '@/features/measurement/api'

const item = (id: string, over: Record<string, unknown> = {}) => ({
  id, name: `Project ${id}`, status: 'active', client_workspace_id: null, client_name: null,
  accounts: 1, providers: ['meta'], data_last_synced_at: new Date().toISOString(), attention: null, ...over,
})
const overview = (items: unknown[]) => ({
  projects: { total: items.length, by_status: { active: items.length }, items },
  campaigns: { total: 3, by_project: { p1: 2, p2: 1 } },
})
const store = (project_id: string | null, state = 'connected') => [{
  key: 'salla', label: 'Salla', state, connection_error: null, token_expires_at: null, supports_abandoned_carts: true,
  stores: [{ id: 's1', external_id: '1', name: 'متجر رزة', project_id, project_name: null, domain: null, currency: 'SAR', last_synced_at: null, counts: { products: 0, orders: 0, abandoned_carts: 0 }, last_run: null }],
}]
const ga4 = (project_id: string | null) => [{
  key: 'ga4', label: 'GA4', label_ar: 'GA4', state: 'connected', connection_error: null, discovered_count: 1, selected_count: 1,
  properties: [{ id: 'g1', property_id: '1', name: 'Razzah web', analytics_account_id: null, analytics_account_name: null, timezone: null, currency: null, is_selected: true, project_id, last_synced_at: null, discovered_at: null }],
}]

/** ADV-HOME-001 — the advertiser's dashboard names their other projects and where this project's data comes from. */
describe('the advertiser dashboard context', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(fetchPortfolioOverview).mockResolvedValue(overview([item('p1'), item('p2', { attention: 'no_accounts', accounts: 0 })]) as never)
    vi.mocked(listStoreProviders).mockResolvedValue(store('p1') as never)
    vi.mocked(listMeasurementProviders).mockResolvedValue(ga4(null) as never)
  })
  afterEach(() => signOut())

  it('lists every project, marks the current one and switches on a click', async () => {
    signInWith(['projects.view'])
    const onSwitch = vi.fn()
    renderWithProviders(<AdvertiserProjectsStrip currentProjectId="p1" onSwitch={onSwitch} />, { locale: 'ar', route: '/app/dashboard' })

    expect(await screen.findByTestId('adv-project-p1')).toHaveAttribute('aria-current', 'true')
    expect(screen.getByTestId('adv-project-attention-p2')).toHaveTextContent('بلا حسابات مربوطة')
    fireEvent.click(screen.getByTestId('adv-project-p1'))
    expect(onSwitch).not.toHaveBeenCalled()
    fireEvent.click(screen.getByTestId('adv-project-p2'))
    expect(onSwitch).toHaveBeenCalledWith('p2')
  })

  it('draws nothing for an advertiser with a single project', async () => {
    vi.mocked(fetchPortfolioOverview).mockResolvedValue(overview([item('p1')]) as never)
    signInWith(['projects.view'])
    renderWithProviders(<AdvertiserProjectsStrip currentProjectId="p1" onSwitch={vi.fn()} />, { locale: 'en' })

    await vi.waitFor(() => expect(fetchPortfolioOverview).toHaveBeenCalled())
    expect(screen.queryByTestId('adv-projects')).toBeNull()
  })

  it('states each source honestly: a store on this project, a GA4 property chosen but not linked', async () => {
    signInWith(['integrations.view'])
    renderWithProviders(<AdvertiserSourcesStrip projectId="p1" />, { locale: 'en', route: '/app/dashboard' })

    expect(await screen.findByTestId('adv-source-store')).toHaveAttribute('data-state', 'connected')
    expect(screen.getByTestId('adv-source-store')).toHaveTextContent('Salla · متجر رزة')
    expect(await screen.findByTestId('adv-source-ga4')).toHaveAttribute('data-state', 'unassigned')
    expect(await screen.findByTestId('adv-source-ads')).toHaveAttribute('data-state', 'connected')
    expect(screen.getByTestId('adv-source-ga4')).toHaveAttribute('href', '/app/integrations')
  })

  it('says a store on another project is not this project’s, and a broken connection needs reconnecting', async () => {
    signInWith(['integrations.view'])
    const { unmount } = renderWithProviders(<AdvertiserSourcesStrip projectId="p2" />, { locale: 'en' })
    expect(await screen.findByTestId('adv-source-store')).toHaveAttribute('data-state', 'other_project')
    expect(await screen.findByTestId('adv-source-ads')).toHaveAttribute('data-state', 'none')
    unmount()

    vi.mocked(listStoreProviders).mockResolvedValue(store(null, 'error') as never)
    renderWithProviders(<AdvertiserSourcesStrip projectId="p2" />, { locale: 'en' })
    expect(await screen.findByTestId('adv-source-store')).toHaveAttribute('data-state', 'error')
  })

  it('does not draw sources the reader may not inspect', async () => {
    signInWith(['projects.view'])
    renderWithProviders(<AdvertiserSourcesStrip projectId="p1" />, { locale: 'en' })

    expect(screen.queryByTestId('adv-sources')).toBeNull()
    expect(listStoreProviders).not.toHaveBeenCalled()
  })
})
