import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { CreativesPage } from './CreativesPage'
import type { CreativeCard, CreativeMetrics, LibraryPage } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()

  return { ...actual, listCreatives: vi.fn(), compareCreatives: vi.fn(), groupCreatives: vi.fn() }
})

import { listCreatives } from './api'
import { useAuth } from '@/stores/auth'
import type { AuthUser } from '@/lib/api/types'

/**
 * OWNER CONTENT P0 — one creative, one period, two surfaces, one answer.
 *
 * The owner's production reading, on the same creative and the same page: the CARD showed Spend with
 * Orders, ROAS and Revenue; the POPUP one click away showed impressions, clicks, CTR, CPC and CPM as
 * well. And the card drew the creative's picture while opening it said the collection had no cover.
 *
 * The shape below is exactly that one: a COLLECTION whose hero image is available, whose product
 * tiles were never fetched, carrying a full set of figures. Missing tiles are a separate statement —
 * they are not a reason to hide a hero the platform did send, and they are not a reason to lose a
 * figure it did report.
 *
 * What is asserted is parity, not a count: the same hero, and the same set of figures that have a
 * value. How many the card draws before the reader expands it is a layout decision and stays one.
 */
const HERO = 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4='

const METRICS = {
  spend: 400,
  revenue: 3000,
  impressions: 90_000,
  clicks: 1_800,
  conversions: 60,
  orders: 60,
  ctr: 0.02,
  cpc: 0.2222,
  cpm: 4.44,
  cpa: 6.67,
  roas: 7.5,
  conversion_rate: 0.0333,
  aov: 50,
  active_days: 12,
  reported: { spend: true, revenue: true, impressions: true, clicks: true, conversions: true, orders: true },
} as unknown as CreativeMetrics

const CARD: CreativeCard = {
  id: 'c-1',
  name: 'Collection with a hero',
  format: 'collection',
  provider: 'snapchat',
  status: 'active',
  campaign_id: 'cmp-1',
  campaign_name: 'Sale',
  preview: {
    state: 'available',
    kind: 'collection',
    image_url: HERO,
    video_url: null,
    thumbnail_url: null,
    expires_at: null,
    note_ar: null,
    note_en: null,
    // The tiles were never fetched: the platform sent no breakdown, and the hero is unaffected.
    cards_reported: false,
    cards_withheld: 0,
    cards: null,
  },
  aspect_ratio: '1:1',
  duration_seconds: null,
  width: 1080,
  height: 1080,
  file_size: null,
  grouped: false,
  is_demo: false,
  freshness: { last_synced_at: '2026-09-16T08:00:00+00:00', source_updated_at: null, first_seen_at: null, last_active_at: '2026-09-16T00:00:00+00:00' },
  objective: 'SALES',
  path: 'conversion',
  /* What the server now sends: the objective's own verdict first, then every universal figure this row answers. */
  headline_metrics: ['spend', 'orders', 'cpa', 'revenue', 'roas', 'conversion_rate', 'aov', 'impressions', 'clicks', 'ctr', 'cpc', 'cpm'],
  ad_delivered: true,
  metrics: METRICS,
  fatigue: { status: 'stable', signals: [], reason_ar: '', reason_en: '' },
} as unknown as CreativeCard

const PAGE: LibraryPage = {
  creatives: [CARD],
  page: 1,
  per_page: 24,
  total: 1,
  period: { from: '2026-08-18', to: '2026-09-16' },
  currency: 'SAR',
  metrics_availability: { snapchat: { status: 'success', rows: 120, error: null, at: null } },
  filters: {
    providers: ['snapchat'], formats: ['collection'], statuses: ['active'], kinds: ['collection'],
    campaigns: [{ id: 'cmp-1', name: 'Sale', objective: 'SALES' }],
    ad_sets: [], ads: [], objectives: ['SALES'], paths: ['conversion'],
    projects: [], clients: [], health: ['stable'],
  },
} as unknown as LibraryPage

/** The labels a container states a VALUE for — a dash is «we cannot say», not a figure. */
const statedLabels = (root: HTMLElement): string[] => {
  const pairs: { label: string; value: string }[] = []

  /* The card is a definition list; the popup is a grid of two-line tiles. Same question, two markups. */
  root.querySelectorAll('dt').forEach((dt) => {
    pairs.push({ label: (dt.textContent ?? '').trim(), value: (dt.nextElementSibling?.textContent ?? '').trim() })
  })

  if (pairs.length === 0) {
    root.querySelectorAll(':scope > div').forEach((tile) => {
      const [label, value] = Array.from(tile.children)

      pairs.push({ label: (label?.textContent ?? '').trim(), value: (value?.textContent ?? '').trim() })
    })
  }

  return pairs
    .filter((p) => p.value !== '' && p.value !== '—')
    .map((p) => p.label)
    .sort()
}

describe('a collection with a hero and no tiles reads the same on the card and in the popup', () => {
  beforeEach(() => {
    useAuth.setState({
      user: { id: '1', name: 'Op', permissions: ['campaigns.view'], is_platform_admin: false } as unknown as AuthUser,
      status: 'authenticated',
    })
    vi.mocked(listCreatives).mockResolvedValue(PAGE)
  })

  it('draws the hero on the card and the same hero in the popup', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'en' })

    await waitFor(() => expect(screen.getByText('Collection with a hero')).toBeInTheDocument())

    const cardPoster = document.querySelector('article img') as HTMLImageElement | null
    expect(cardPoster, 'the card drew no hero for a collection whose hero is available').not.toBeNull()
    expect(cardPoster?.getAttribute('src')).toBe(HERO)

    fireEvent.click(screen.getByRole('button', { name: /^Open preview: Collection with a hero$/ }))

    const dialog = await screen.findByTestId('ad-preview-dialog')
    const dialogPoster = dialog.querySelector('img') as HTMLImageElement | null

    expect(dialogPoster, 'the popup said the collection had no cover while the card drew one').not.toBeNull()
    expect(dialogPoster?.getAttribute('src')).toBe(HERO)
  })

  it('states the same set of figures on the card as in the popup', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'en' })

    await waitFor(() => expect(screen.getByText('Collection with a hero')).toBeInTheDocument())

    /* The card may fold the rest behind «+N» — folded is not lost, so it is opened before comparing. */
    const more = screen.queryByTestId('creative-card-more-metrics')
    if (more) fireEvent.click(more)

    const card = statedLabels(screen.getByTestId('creative-card-metrics'))

    fireEvent.click(screen.getByRole('button', { name: /^Open preview: Collection with a hero$/ }))

    const popup = statedLabels(await screen.findByTestId('ad-preview-dialog-figures'))

    expect(card).toEqual(popup)
    expect(card).toContain('Impressions')
    expect(card).toContain('CPM')
    expect(card).toContain('Orders')
  })
})
