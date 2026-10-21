import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'
import { SidebarNav, leafActive, type NavLeaf } from './SidebarNav'
import { agencyNavGroups } from './agencyNav'
import { CampaignsPage } from '@/features/campaigns/CampaignsPage'

/**
 * NAV-TAXONOMY-001 — the rail the Owner approved, and exactly one current entry even where two entries share a page.
 */
const leaf = (to: string): NavLeaf => ({ to, ar: to, en: to, icon: (() => null) as never })

describe('which entry is the current page', () => {
  const all = ['/agency/analytics', '/agency/analytics?tab=ad_sets', '/agency/analytics?tab=ads', '/agency/campaigns', '/agency/campaigns?launch=1'].map(leaf)
  const current = (pathname: string, search: string) => all.filter((l) => leafActive(l, all, pathname, search)).map((l) => l.to)

  it('lights the tab entry, not its parent page, when the address carries the tab', () => {
    expect(current('/agency/analytics', '?tab=ads')).toEqual(['/agency/analytics?tab=ads'])
    expect(current('/agency/analytics', '?tab=ad_sets&days=7')).toEqual(['/agency/analytics?tab=ad_sets'])
  })

  it('lights the parent page on any other tab or none', () => {
    expect(current('/agency/analytics', '')).toEqual(['/agency/analytics'])
    expect(current('/agency/analytics', '?tab=platforms')).toEqual(['/agency/analytics'])
  })

  it('separates the launch builder from the campaigns list, and keeps the list current on a campaign\'s own page', () => {
    expect(current('/agency/campaigns', '?launch=1')).toEqual(['/agency/campaigns?launch=1'])
    expect(current('/agency/campaigns', '')).toEqual(['/agency/campaigns'])
    expect(current('/agency/campaigns/p1/c1', '')).toEqual(['/agency/campaigns'])
  })
})

describe('the agency rail as drawn', () => {
  it('marks exactly one entry current on an ad tab, in Arabic, with the approved order', () => {
    renderWithProviders(<SidebarNav groups={agencyNavGroups} ar label="nav" />, { locale: 'ar', route: '/agency/analytics?tab=ads' })

    const currentLinks = document.querySelectorAll('a[aria-current="page"]')
    expect(currentLinks).toHaveLength(1)
    expect(currentLinks[0]?.textContent).toContain('الإعلانات')

    const names = [...document.querySelectorAll('[data-testid="nav-group-campaigns"] ~ div a')].map((a) => a.textContent)
    expect(names.slice(0, 6)).toEqual(['نظرة عامة', 'المشاريع', 'إطلاق الحملات', 'الحملات', 'المجموعات', 'الإعلانات'])
  })
})

vi.mock('@/features/campaigns/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/campaigns/api')>()),
  listCampaigns: vi.fn(() => Promise.resolve([])),
}))
vi.mock('@/features/analytics/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/features/analytics/api')>()
  const empty = { data: undefined, isPending: false, isLoading: false, isError: false }
  return { ...actual, useSummary: () => empty, useTimeseries: () => empty, usePlatforms: () => empty, useBudget: () => empty, useCampaigns: () => empty }
})
vi.mock('@/features/campaigns/CampaignFormModal', () => ({
  CampaignFormModal: ({ open }: { open: boolean }) => (open ? <div data-testid="campaign-builder" /> : null),
}))

describe('«إطلاق الحملات» opens the existing builder', () => {
  beforeEach(() => useProject.getState().setCurrentProjectId('p1'))
  afterEach(() => signOut())

  it('opens it from the address for someone who may create campaigns', async () => {
    signInWith(['campaigns.view', 'campaigns.create'])
    renderWithProviders(<CampaignsPage />, { locale: 'en', route: '/campaigns?launch=1' })

    expect(await screen.findByTestId('campaign-builder')).toBeTruthy()
  })

  it('tells someone without the permission why, instead of a builder they cannot submit', async () => {
    signInWith(['campaigns.view'])
    renderWithProviders(<CampaignsPage />, { locale: 'en', route: '/campaigns?launch=1' })

    expect(await screen.findByTestId('launch-not-permitted')).toBeTruthy()
    expect(screen.queryByTestId('campaign-builder')).toBeNull()
  })

  it('stays closed on the plain list', async () => {
    signInWith(['campaigns.view', 'campaigns.create'])
    renderWithProviders(<CampaignsPage />, { locale: 'en', route: '/campaigns' })

    await screen.findAllByRole('heading')
    expect(screen.queryByTestId('campaign-builder')).toBeNull()
  })
})
