import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'

import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

import { AnalyticsPage } from './AnalyticsPage'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

/**
 * HIERARCHY-ENTITY-ANALYTICS-DRILLDOWN — the narrowing has to reach the DATABASE.
 *
 * The endpoint has taken a `parent` since it shipped; the UI called it with `undefined`, so the four
 * rungs were four flat lists. The failure that matters is not a missing feature but a lying one: a
 * breadcrumb reading «ad sets of the summer campaign» over every ad set in the project. So these
 * tests assert the REQUEST, not the heading — a filter applied only on the client would pass a test
 * that checked the visible rows.
 */
const AD_SET = {
  entity_id: 's1', external_id: 'ext-s1', name: 'Riyadh · 18-34', status: 'active',
  campaign_id: 'c1', ad_set_id: null, active_days: 2, last_active_on: '2026-08-02',
  spend: 100, impressions: 9000, reach: 4000, frequency: 2, clicks: 300,
  ctr: 0.0333, conversions: 5, cpa: 20, cpm: 11, cpc: 0.33,
}

const urls: string[] = []

function route(byLevel: { ad_set?: unknown[]; ad?: unknown[] }) {
  vi.mocked(getData).mockImplementation(async (url: string) => {
    urls.push(url)
    if (url.includes('/entities/')) {
      const level = url.includes('/entities/ad_set') ? 'ad_set' : 'ad'

      return { entities: byLevel[level] ?? [], entity_type: level, period: { from: '2026-07-25', to: '2026-08-10' }, currency: 'SAR', attribution_window: null } as never
    }
    if (url.includes('disclaimer')) return null as never
    if (url.includes('/summary')) return { current: {}, previous: {}, delta: {}, currency: 'SAR', rows_in_scope: true, reported: {}, provenance: { source: 'live', live_rows: 5, demo_rows: 0 } } as never
    return [] as never
  })
}

const entityCalls = () => urls.filter((u) => u.includes('/entities/'))
const creativeCalls = () => urls.filter((u) => u.includes('creative'))

describe('drilling from an ad set into its ads', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    urls.length = 0
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  async function openAdSets() {
    renderWithProviders(<AnalyticsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByRole('tab', { name: 'Ad sets' }))
  }

  /** Unnarrowed, the request carries no parent at all — not an empty one. */
  it('asks for every ad set when nothing is pinned', async () => {
    route({ ad_set: [AD_SET], ad: [AD_SET] })
    await openAdSets()

    await screen.findByText('Riyadh · 18-34')

    expect(entityCalls().some((u) => u.includes('/entities/ad_set'))).toBe(true)
    expect(entityCalls().every((u) => !u.includes('parent='))).toBe(true)
  })

  /**
   * The test that would fail if the drill were cosmetic: after clicking, a request for the CHILD
   * level must go out carrying the clicked id as `parent`.
   */
  it('sends the clicked ad set as the parent of the ad request', async () => {
    route({ ad_set: [AD_SET], ad: [AD_SET] })
    await openAdSets()

    fireEvent.click(await screen.findByTestId('drill-into-s1'))

    await waitFor(() => {
      expect(entityCalls().some((u) => u.includes('/entities/ad') && u.includes('parent=s1'))).toBe(true)
    })
  })

  /** And the reader is told where they are, by the name the row carried. */
  it('names the level and the entity in the breadcrumb', async () => {
    route({ ad_set: [AD_SET], ad: [AD_SET] })
    await openAdSets()

    fireEvent.click(await screen.findByTestId('drill-into-s1'))

    const crumb = await screen.findByTestId('drill-crumb-ad_set')
    expect(crumb).toHaveTextContent('Ad set:')
    expect(crumb).toHaveTextContent('Riyadh · 18-34')
  })

  /**
   * Stepping back out drops the narrowing from the REQUEST, not only from the label.
   *
   * Clicking the ad-set crumb returns to the ad-set list, which is the level that crumb names — so
   * the assertion is that the ad-set request goes out unnarrowed, not merely that the crumb vanished.
   */
  it('stops sending a parent once the reader steps back out', async () => {
    route({ ad_set: [AD_SET], ad: [AD_SET] })
    await openAdSets()

    fireEvent.click(await screen.findByTestId('drill-into-s1'))
    await screen.findByTestId('drill-crumb-ad_set')

    urls.length = 0
    fireEvent.click(await screen.findByTestId('drill-crumb-ad_set'))

    await waitFor(() => {
      expect(entityCalls().some((u) => u.includes('/entities/ad_set'))).toBe(true)
    })
    expect(entityCalls().every((u) => !u.includes('parent='))).toBe(true)
  })

  /**
   * The last rung: an ad drills into the creatives that ran under it.
   *
   * The library takes `ad_ids` rather than the metrics API's `parent`, so this asserts the translated
   * request rather than the heading — a filter applied to the rendered rows would pass a test that
   * only looked at the table.
   */
  it('narrows the creative library to the ad the reader drilled into', async () => {
    route({ ad_set: [AD_SET], ad: [{ ...AD_SET, entity_id: 'a1', external_id: 'ext-a1', name: 'Video 9x16' }] })
    await openAdSets()

    fireEvent.click(await screen.findByTestId('drill-into-s1'))
    fireEvent.click(await screen.findByTestId('drill-into-a1'))

    await waitFor(() => {
      expect(creativeCalls().some((u) => u.includes('ad_ids'))).toBe(true)
    })
  })

  /**
   * The lying breadcrumb, which is the whole reason this module keeps the path and the level apart.
   *
   * Drill into ads, then reach the ad-set list by clicking its TAB rather than the crumb. The path
   * still holds an ad set, but the ad-set list is unnarrowed — so the crumb must not caption a
   * project-wide list with one ad set's name. The path is kept, simply not claimed.
   */
  it('never captions an unnarrowed list with a parent it is not filtered by', async () => {
    route({ ad_set: [AD_SET], ad: [AD_SET] })
    await openAdSets()

    fireEvent.click(await screen.findByTestId('drill-into-s1'))
    await screen.findByTestId('drill-crumb-ad_set')

    urls.length = 0
    fireEvent.click(screen.getByRole('tab', { name: 'Ad sets' }))

    await waitFor(() => {
      expect(entityCalls().some((u) => u.includes('/entities/ad_set'))).toBe(true)
    })
    expect(entityCalls().every((u) => !u.includes('parent='))).toBe(true)
    expect(screen.queryByTestId('drill-crumb-ad_set')).not.toBeInTheDocument()
  })

  /**
   * A narrowed list with no rows is not «no data».
   *
   * The unnarrowed empty state says the project reported nothing. Drilled, it must say this parent
   * reported nothing — otherwise a reader concludes the account is dead while looking at one quiet
   * ad set.
   */
  it('says a quiet parent is quiet, not that the project has no data', async () => {
    // The ad set has rows to click; the ads underneath it have none.
    route({ ad_set: [AD_SET], ad: [] })
    await openAdSets()

    fireEvent.click(await screen.findByTestId('drill-into-s1'))

    expect(await screen.findByTestId('entity-empty-under-parent-ad')).toBeInTheDocument()
  })
})

/**
 * HIERARCHY-ENTITY-ANALYTICS-DRILLDOWN — «deep-link + refresh», which is a different test from
 * every one above.
 *
 * Those click through the hierarchy: the path is built by the page, in memory, while it is already
 * mounted. A shared link is the opposite — the page mounts COLD with the path already in the URL and
 * nothing to rebuild it from. `drilldown.test.ts` proves the encoder round-trips, which is not the
 * same claim: an encoder can round-trip perfectly while the page ignores what it decodes on first
 * render and issues an unnarrowed request.
 *
 * That failure is quiet in the worst way. The breadcrumb reads correctly off the URL, so the link
 * looks like it worked, while the table beneath it lists the whole project.
 */
describe('a drill-down link opened cold', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    urls.length = 0
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  it('narrows the very first request to the pinned parent', async () => {
    route({ ad: [{ ...AD_SET, entity_id: 'a1', external_id: 'ad-1', name: 'Riyadh video' }] })

    renderWithProviders(<AnalyticsPage />, {
      locale: 'en',
      route: '/app/analytics?tab=ads&drill=campaign:c1~ad_set:sq-9',
    })

    expect(await screen.findByText('Riyadh video')).toBeInTheDocument()

    const first = entityCalls()[0]
    expect(first).toContain('/entities/ad')
    expect(first).toContain('parent=sq-9')
  })

  it('names the pinned rungs in the breadcrumb without a click', async () => {
    route({ ad: [{ ...AD_SET, entity_id: 'a1', external_id: 'ad-1', name: 'Riyadh video' }] })

    renderWithProviders(<AnalyticsPage />, {
      locale: 'en',
      route: '/app/analytics?tab=ads&drill=campaign:c1~ad_set:sq-9',
    })

    await screen.findByText('Riyadh video')

    /*
     * The crumbs are rebuilt from the URL alone. Names are not in the link — only ids are — so a
     * cold-opened path shows the ad set by its id until the row it came from is on screen, which is
     * honest: inventing a name for an id nobody has resolved would be worse than showing the id.
     */
    const crumbs = screen.getByTestId('drill-crumbs')
    expect(crumbs).toHaveTextContent('sq-9')
  })

  /**
   * A link somebody edited by hand keeps the part that still makes sense.
   *
   * Answering a broken link with the whole project is the failure mode that matters: the reader sees
   * a plausible table and no sign that their link was wrong.
   */
  it('keeps the trustworthy prefix of a hand-edited link', async () => {
    route({ ad_set: [{ ...AD_SET, entity_id: 's1', external_id: 'sq-1', name: 'Riyadh · 18-34' }] })

    renderWithProviders(<AnalyticsPage />, {
      locale: 'en',
      route: '/app/analytics?tab=ad_sets&drill=campaign:c1~nonsense:zz',
    })

    expect(await screen.findByText('Riyadh · 18-34')).toBeInTheDocument()

    const first = entityCalls()[0]
    expect(first).toContain('/entities/ad_set')
    expect(first).toContain('parent=c1')
  })
})
