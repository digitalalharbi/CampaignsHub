import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import { CreativesPage } from './CreativesPage'
import type { CreativeCard, LibraryPage } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, listCreatives: vi.fn(), compareCreatives: vi.fn(), groupCreatives: vi.fn() }
})

import { listCreatives } from './api'
import { useAuth } from '@/stores/auth'
import type { AuthUser } from '@/lib/api/types'

/**
 * CONTENT-BROWSER-PARITY-001 — the table answers «which account» and «is it running».
 *
 * The table already existed and already carried the objective's own result and efficiency, which is
 * §8's «do not force every metric onto every objective» done correctly. Two columns were missing,
 * and both are facts rather than figures:
 *
 *  - the AD ACCOUNT, because a project reads more than one and two creatives with the same name
 *    under two accounts were indistinguishable here. It is the axis ACCOUNT-SCOPE-ISOLATION-001 is
 *    about: a reader who cannot see the account cannot check the isolation they are promised.
 *  - the DELIVERY state, which the card carries and the table did not — and the table is now
 *    ORDERED by it, so a reader could not see the fact the order rests on.
 *
 * «Fatigue» further right is a different question — whether a creative that IS running has worn out
 * — and reading it as «is this on?» is the confusion these columns remove.
 */
const card = (over: Partial<CreativeCard> = {}): CreativeCard =>
  ({
    id: 'cr-1',
    name: 'Hero image',
    format: 'image',
    provider: 'meta',
    status: 'active',
    campaign_id: 'c1',
    campaign_name: 'National Day Sale',
    ad_account: { id: 'acc-1', name: 'RazzahAvenu Self Service' },
    preview: { state: 'unavailable', kind: 'image', image_url: null, video_url: null, thumbnail_url: null, expires_at: null, note_ar: null, note_en: null },
    aspect_ratio: '1:1',
    duration_seconds: null,
    width: 1080,
    height: 1080,
    file_size: 1,
    grouped: false,
    group_id: null,
    is_demo: false,
    freshness: { last_synced_at: '2026-08-29T10:00:00+00:00', source_updated_at: null, first_seen_at: null, last_active_at: '2026-08-29' },
    objective: 'sales',
    path: 'direct_sales',
    headline_metrics: ['spend'],
    metrics: null,
    ad_delivered: true,
    fatigue: { status: 'insufficient_data', reason_ar: null, reason_en: null },
    ...over,
  }) as unknown as CreativeCard

const page = (creatives: CreativeCard[]): LibraryPage =>
  ({
    creatives,
    total: creatives.length,
    per_page: 24,
    page: 1,
    last_page: 1,
    currency: 'SAR',
    metrics_availability: {},
    sort: { applied: 'auto', metric: 'conversions', objective: 'sales' },
    filters: { providers: [], formats: [], statuses: [], campaigns: [], projects: [], clients: [], kinds: [] },
  }) as unknown as LibraryPage

describe('the content table', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useAuth.setState({
      user: { id: '1', name: 'Op', permissions: ['content.view'], is_platform_admin: false } as unknown as AuthUser,
      status: 'authenticated',
    })
  })

  it('names the ad account the creative ran under', async () => {
    vi.mocked(listCreatives).mockResolvedValue(page([card()]))

    renderWithProviders(<CreativesPage />, { locale: 'en', route: '/app/content?view=list&to=2026-08-30' })

    expect(await screen.findByTestId('content-row-account-cr-1')).toHaveTextContent('RazzahAvenu Self Service')
  })

  /**
   * Two accounts in one project, told apart — the case the column exists for.
   *
   * Same creative name under two accounts is not contrived: an agency duplicates a winning ad
   * across the accounts it runs, and before this the two rows were identical on screen.
   */
  it('tells two accounts apart when the creatives share a name', async () => {
    vi.mocked(listCreatives).mockResolvedValue(page([
      card({ id: 'cr-1', ad_account: { id: 'a1', name: 'Account One' } }),
      card({ id: 'cr-2', ad_account: { id: 'a2', name: 'Account Two' } }),
    ]))

    renderWithProviders(<CreativesPage />, { locale: 'en', route: '/app/content?view=list&to=2026-08-30' })

    expect(await screen.findByTestId('content-row-account-cr-1')).toHaveTextContent('Account One')
    expect(screen.getByTestId('content-row-account-cr-2')).toHaveTextContent('Account Two')
  })

  /** A creative imported before its campaign is linked has no account, and says so with a dash. */
  it('shows a dash rather than inventing an account name', async () => {
    vi.mocked(listCreatives).mockResolvedValue(page([card({ ad_account: null })]))

    renderWithProviders(<CreativesPage />, { locale: 'en', route: '/app/content?view=list&to=2026-08-30' })

    expect(await screen.findByTestId('content-row-account-cr-1')).toHaveTextContent('—')
  })

  /**
   * The delivery state, on the row the order is built from.
   *
   * The default order puts running content first; a table that is sorted by a fact it does not show
   * is an order the reader cannot account for, which is the defect the sort note exists to prevent.
   */
  it('shows the delivery state, and reads it from the same rule the card uses', async () => {
    vi.mocked(listCreatives).mockResolvedValue(page([
      card({ id: 'cr-1', status: 'active', freshness: { last_synced_at: null, source_updated_at: null, first_seen_at: null, last_active_at: '2026-08-29' } }),
      card({ id: 'cr-2', status: 'paused', freshness: { last_synced_at: null, source_updated_at: null, first_seen_at: null, last_active_at: '2026-08-29' } }),
      card({ id: 'cr-3', status: 'active', freshness: { last_synced_at: null, source_updated_at: null, first_seen_at: null, last_active_at: '2026-07-01' } }),
    ]))

    renderWithProviders(<CreativesPage />, { locale: 'en', route: '/app/content?view=list&to=2026-08-30' })

    const rows = await screen.findAllByTestId(/^content-row-cr-/)
    const states = rows.map((r) => within(r).getByTestId('creative-delivery-state').getAttribute('data-state'))

    expect(states).toEqual(['serving', 'stopped', 'idle'])
  })

  /**
   * It is measured against the WINDOW's end, not today — the same rule as the card.
   *
   * A reader looking at an older period is asking whether these ran THEN. Judging an August
   * creative against today would mark a whole quarter stopped.
   */
  it('judges delivery against the period the reader is looking at', async () => {
    vi.mocked(listCreatives).mockResolvedValue(page([card({ status: 'active', freshness: { last_synced_at: null, source_updated_at: null, first_seen_at: null, last_active_at: '2026-08-29' } })]))

    renderWithProviders(<CreativesPage />, { locale: 'en', route: '/app/content?view=list&to=2026-12-30' })

    const row = await screen.findByTestId('content-row-cr-1')

    expect(within(row).getByTestId('creative-delivery-state')).toHaveAttribute('data-state', 'idle')
  })
})
