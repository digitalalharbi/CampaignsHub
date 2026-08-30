import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { CreativeGroupsPage } from './CreativeGroupsPage'
import type { CreativeCard, CreativeGroupDetail, CreativeGroupSummary, CreativeMetrics } from './api'
import { renderWithProviders } from '@/test/utils'
import { useAuth } from '@/stores/auth'
import type { AuthUser } from '@/lib/api/types'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return {
    ...actual,
    listCreativeGroups: vi.fn(),
    getCreativeGroup: vi.fn(),
    ungroupCreative: vi.fn(),
  }
})

import { getCreativeGroup, listCreativeGroups, ungroupCreative } from './api'

/**
 * §15.8, §15.13 — the acceptance claims for reading one asset across platforms.
 *
 * The claims worth a test are the ones a reviewer would check by hand:
 *
 *   - a group of an awareness cut and a sales cut is NOT handed a blended ROAS, and says why;
 *   - a group that does share an objective gets that objective's own headline metrics;
 *   - the split control is absent without `campaigns.link`, not present-and-refusing;
 *   - an open group is in the ADDRESS, so a refresh and a pasted link both reopen it;
 *   - splitting the second-to-last member closes the panel, because the group no longer exists.
 */

const metrics = (over: Partial<CreativeMetrics> = {}): CreativeMetrics => ({
  spend: 500,
  impressions: 65000,
  clicks: 1100,
  conversions: 14,
  revenue: 4200,
  video_views: null,
  video_p25: null,
  video_p50: null,
  video_p75: null,
  video_p100: null,
  frequency: 1.3,
  ctr: 0.017,
  cpc: 0.45,
  cpm: 7.7,
  cpa: 35.7,
  roas: 8.4,
  conversion_rate: 0.013,
  view_rate: null,
  completion_rate: null,
  active_days: 12,
  reported: {
    spend: true, impressions: true, clicks: true, conversions: true, revenue: true,
    video_views: false, video_p100: false,
  },
  ...over,
})

const summary = (over: Partial<CreativeGroupSummary> = {}): CreativeGroupSummary => ({
  id: 'grp-1',
  name: 'The film',
  method: 'manual',
  confirmed: true,
  confirmed_at: '2026-08-01T10:00:00+03:00',
  project_id: 'proj-1',
  creative_count: 2,
  providers: ['meta', 'tiktok'],
  objectives: ['sales'],
  paths: ['conversion'],
  objective: 'sales',
  mixed_objectives: false,
  headline_metrics: ['spend', 'conversions', 'roas'],
  metrics: metrics(),
  mixed_reason_ar: null,
  mixed_reason_en: null,
  ...over,
})

const member = (over: Partial<CreativeCard> = {}): CreativeCard =>
  ({
    id: 'cr-1',
    name: 'The film · Meta',
    format: 'video',
    provider: 'meta',
    status: 'active',
    campaign_id: 'cmp-1',
    campaign_name: 'Sale',
    ad_set_id: null,
    preview: {
      state: 'available',
      kind: 'video',
      image_url: null,
      video_url: null,
      thumbnail_url: null,
      expires_at: null,
      note_ar: null,
      note_en: null,
    },
    aspect_ratio: '1:1',
    duration_seconds: 20,
    width: 600,
    height: 600,
    file_size: null,
    grouped: true,
    group_id: 'grp-1',
    is_demo: false,
    freshness: { last_synced_at: null, source_updated_at: null, first_seen_at: null, last_active_at: null },
    objective: 'sales',
    path: 'conversion',
    headline_metrics: ['spend', 'conversions', 'roas'],
    metrics: metrics(),
    fatigue: { status: 'stable', signals: [], reason_ar: '', reason_en: '' },
    ...over,
  }) as CreativeCard

const detail = (over: Partial<CreativeGroupDetail> = {}): CreativeGroupDetail => ({
  ...summary(),
  currency: 'SAR',
  members: [member(), member({ id: 'cr-2', name: 'The film · TikTok', provider: 'tiktok' })],
  by_platform: [
    { provider: 'meta', creative_count: 1, creative_ids: ['cr-1'], metrics: metrics({ spend: 400 }) },
    { provider: 'tiktok', creative_count: 1, creative_ids: ['cr-2'], metrics: metrics({ spend: 100 }) },
  ],
  period: { from: '2026-07-08', to: '2026-08-06' },
  audit: [
    {
      id: 'a1',
      action: 'creative.group.created',
      at: '2026-08-01T10:00:00+03:00',
      actor: 'Mohammed',
      creative_ids: ['cr-1', 'cr-2'],
      group_dissolved: false,
    },
  ],
  ...over,
})

const signIn = (permissions: string[]) =>
  useAuth.setState({
    user: { id: '1', name: 'Op', permissions, is_platform_admin: false } as unknown as AuthUser,
    status: 'authenticated',
  })

describe('CreativeGroupsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signIn(['campaigns.view', 'campaigns.link'])
    vi.mocked(listCreativeGroups).mockResolvedValue({
      groups: [summary()],
      page: 1,
      per_page: 24,
      total: 1,
      period: { from: '2026-07-08', to: '2026-08-06' }, currency: 'SAR',
    })
    vi.mocked(getCreativeGroup).mockResolvedValue(detail())
  })

  it('lists a group with its platforms and its rolled-up total', async () => {
    renderWithProviders(<CreativeGroupsPage portal="app" />, { route: '/app/content/groups' })

    expect(await screen.findByText('The film')).toBeInTheDocument()
    expect(screen.getByText(/Meta.*TikTok/)).toBeInTheDocument()
    // The total is the group's, not one platform's — 500, not 400.
    expect(screen.getByText('500 SAR')).toBeInTheDocument()
  })

  /**
   * The refusal that matters: no blended figure across objectives.
   *
   * An awareness cut and a sales cut of one film are the same asset and are not the same question.
   * The reason has to be VISIBLE — a reader who only sees an absent ROAS concludes the sync broke.
   */
  it('refuses a headline figure when the members chase different objectives, and says why', async () => {
    vi.mocked(listCreativeGroups).mockResolvedValue({
      groups: [
        summary({
          objective: null,
          objectives: ['sales', 'awareness'],
          paths: ['conversion', 'awareness'],
          mixed_objectives: true,
          headline_metrics: [],
          mixed_reason_en: 'The members of this group do not share one objective.',
        }),
      ],
      page: 1,
      per_page: 24,
      total: 1,
      period: { from: '2026-07-08', to: '2026-08-06' }, currency: 'SAR',
    })

    renderWithProviders(<CreativeGroupsPage portal="app" />, { route: '/app/content/groups' })

    expect(await screen.findByText('Mixed objectives in this group')).toBeInTheDocument()
    expect(screen.getByText('The members of this group do not share one objective.')).toBeInTheDocument()
    // The additive figures still show; what is withheld is the judgement, not the arithmetic.
    expect(screen.getByText('500 SAR')).toBeInTheDocument()
    expect(screen.queryByText('8.40×')).not.toBeInTheDocument()
  })

  it('carries that objectives own headline metrics when the members agree', async () => {
    renderWithProviders(<CreativeGroupsPage portal="app" />, { route: '/app/content/groups' })

    expect(await screen.findByText('ROAS')).toBeInTheDocument()
    expect(screen.getByText('8.40×')).toBeInTheDocument()
  })

  /** A group is a decision somebody points a colleague at, so it has to be an address. */
  it('opens the group named in the address on arrival', async () => {
    renderWithProviders(<CreativeGroupsPage portal="app" />, { route: '/app/content/groups?group=grp-1' })

    expect(await screen.findByTestId('creative-group-detail')).toBeInTheDocument()
    expect(vi.mocked(getCreativeGroup)).toHaveBeenCalledWith('grp-1', expect.anything())
  })

  it('shows the per-platform lines and the members inside the open group', async () => {
    renderWithProviders(<CreativeGroupsPage portal="app" />, { route: '/app/content/groups?group=grp-1' })

    const panel = await screen.findByTestId('creative-group-detail')
    const table = within(panel).getByRole('table')
    expect(within(table).getByRole('rowheader', { name: 'Meta' })).toBeInTheDocument()
    expect(within(table).getByRole('rowheader', { name: 'TikTok' })).toBeInTheDocument()
    expect(within(table).getByText('400 SAR')).toBeInTheDocument()
    expect(within(table).getByText('100 SAR')).toBeInTheDocument()

    // Each member links into its own detail page, carrying the window it was read in.
    const link = within(panel).getByRole('link', { name: 'The film · Meta' })
    expect(link).toHaveAttribute('href', expect.stringContaining('/app/content/cr-1'))
  })

  it('names who merged the group and when', async () => {
    renderWithProviders(<CreativeGroupsPage portal="app" />, { route: '/app/content/groups?group=grp-1' })

    const panel = await screen.findByTestId('creative-group-detail')
    expect(within(panel).getByText('Merged')).toBeInTheDocument()
    expect(within(panel).getByText(/Mohammed/)).toBeInTheDocument()
  })

  /** Without `campaigns.link` the control is ABSENT — a button that always refuses teaches nothing. */
  it('offers no split control without the link permission', async () => {
    signIn(['campaigns.view'])

    renderWithProviders(<CreativeGroupsPage portal="app" />, { route: '/app/content/groups?group=grp-1' })

    await screen.findByTestId('creative-group-detail')
    expect(screen.queryByRole('button', { name: /Split from group/ })).not.toBeInTheDocument()
    expect(screen.getAllByText('You do not have permission to change groups.').length).toBeGreaterThan(0)
  })

  it('splits a member and reports that the group survived', async () => {
    vi.mocked(ungroupCreative).mockResolvedValue({ creative_id: 'cr-2', group_dissolved: false })

    renderWithProviders(<CreativeGroupsPage portal="app" />, { route: '/app/content/groups?group=grp-1' })

    const panel = await screen.findByTestId('creative-group-detail')
    fireEvent.click(within(panel).getByRole('button', { name: 'Split from group: The film · TikTok' }))

    await waitFor(() => expect(vi.mocked(ungroupCreative)).toHaveBeenCalledWith('cr-2'))
    expect(await screen.findByText('The ad was split from its group.')).toBeInTheDocument()
  })

  /** A dissolved group has nothing left to show, so the panel closes rather than 404-ing. */
  it('closes the panel when the split dissolved the group', async () => {
    vi.mocked(ungroupCreative).mockResolvedValue({ creative_id: 'cr-2', group_dissolved: true })

    renderWithProviders(<CreativeGroupsPage portal="app" />, { route: '/app/content/groups?group=grp-1' })

    const panel = await screen.findByTestId('creative-group-detail')
    fireEvent.click(within(panel).getByRole('button', { name: 'Split from group: The film · TikTok' }))

    await waitFor(() => expect(screen.queryByTestId('creative-group-detail')).not.toBeInTheDocument())
  })

  it('says plainly when there are no groups, and how one is made', async () => {
    vi.mocked(listCreativeGroups).mockResolvedValue({
      groups: [],
      page: 1,
      per_page: 24,
      total: 0,
      period: { from: '2026-07-08', to: '2026-08-06' }, currency: 'SAR',
    })

    renderWithProviders(<CreativeGroupsPage portal="app" />, { route: '/app/content/groups' })

    expect(await screen.findByText('There are no groups in this workspace yet.')).toBeInTheDocument()
    expect(
      screen.getByText('Select two or more ads in the library and merge them as one asset.'),
    ).toBeInTheDocument()
  })
})
