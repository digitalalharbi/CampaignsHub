import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
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
 * CONTENT-KPI-EMPTY-STATE-001 — «it ran and nothing can be headlined» is not «it did not run».
 *
 * Availability-aware selection can return an EMPTY headline for a creative that has a metrics object
 * — the platform answered for it, and none of the figures its objective judges on survived the
 * availability test. That branch briefly shared the one for `metrics === null`, which routed it
 * through `metrics_availability`, and with a successful sync that renders «لم يعمل خلال هذه الفترة».
 *
 * That sentence is false about a creative that delivered, and it is false in the direction that
 * costs money: an operator reading it leaves alone a creative that is actually running.
 */
const CARD: CreativeCard = {
  id: 'cr-ran-but-unshowable',
  name: 'Delivered but unshowable',
  format: 'video',
  provider: 'snapchat',
  status: 'active',
  campaign_id: 'cmp-1',
  campaign_name: 'Product Offers',
  preview: {
    state: 'available', kind: 'video', image_url: null,
    video_url: 'https://cf.sc-cdn.net/a.mp4', thumbnail_url: null,
    expires_at: null, note_ar: null, note_en: null,
  },
  aspect_ratio: '9:16', duration_seconds: 8, width: 1080, height: 1920, file_size: 1,
  grouped: false, is_demo: false,
  freshness: {
    last_synced_at: '2026-08-24T08:00:00+00:00', source_updated_at: null,
    first_seen_at: null,
    // It DELIVERED. That is what makes «did not run» a false statement rather than a clumsy one.
    last_active_at: '2026-08-24T00:00:00+00:00',
  },
  objective: 'sales',
  path: 'sales',
  // The row exists and the platform answered — but nothing survived availability.
  headline_metrics: [],
  metrics: { active_days: 3, reported: {} },
  fatigue: { status: 'stable', signals: [], reason_ar: '', reason_en: '' },
} as unknown as CreativeCard

const PAGE: LibraryPage = {
  creatives: [CARD],
  page: 1, per_page: 24, total: 1,
  period: { from: '2026-07-26', to: '2026-08-24' },
  currency: 'SAR',
  // The sync SUCCEEDED. This is exactly the combination that produced the false sentence.
  metrics_availability: { snapchat: { status: 'success', rows: 878, error: null, at: null } },
  filters: {
    providers: ['snapchat'], formats: ['video'], statuses: ['active'], kinds: ['video'],
    campaigns: [], ad_sets: [], ads: [], objectives: [], paths: [],
    projects: [], clients: [], health: [],
  },
} as unknown as LibraryPage

describe('a creative that ran but has no displayable metric', () => {
  beforeEach(() => {
    useAuth.setState({
      user: { id: '1', name: 'Op', permissions: ['campaigns.view'], is_platform_admin: false } as unknown as AuthUser,
      status: 'authenticated',
    })
    vi.mocked(listCreatives).mockResolvedValue(PAGE)
  })

  /** The claim, stated negatively, because the defect was a sentence that should not appear. */
  it('never says it did not run', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'ar' })
    await waitFor(() => expect(screen.getByText('Delivered but unshowable')).toBeInTheDocument())

    expect(screen.queryByTestId('creative-empty-did_not_run')).not.toBeInTheDocument()
    expect(document.body.textContent).not.toContain('لم يعمل خلال هذه الفترة')
  })

  it('says instead that there is nothing displayable for this period, in Arabic', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'ar' })
    await waitFor(() => expect(screen.getByText('Delivered but unshowable')).toBeInTheDocument())

    const panel = screen.getByTestId('creative-empty-no_displayable')
    expect(panel).toHaveTextContent('لا توجد مؤشرات أداء قابلة للعرض لهذه الفترة')
  })

  it('and in English', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'en' })
    await waitFor(() => expect(screen.getByText('Delivered but unshowable')).toBeInTheDocument())

    expect(screen.getByTestId('creative-empty-no_displayable'))
      .toHaveTextContent('No displayable performance metrics for this period')
  })

  /** And still never an empty grid — the outcome the whole branch exists to prevent. */
  it('does not render an empty metric grid', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'ar' })
    await waitFor(() => expect(screen.getByText('Delivered but unshowable')).toBeInTheDocument())

    const grid = document.querySelector('dl')
    expect(grid === null || (grid.textContent ?? '').trim() !== '').toBe(true)
  })

  /**
   * The table says the same thing by saying nothing.
   *
   * Its result and efficiency cells render «—» when there is no key to render, which is neutral.
   * What matters is that it does not borrow the inactivity sentence for a creative that delivered.
   */
  it('does not imply inactivity in the table view either', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'ar' })
    await waitFor(() => expect(screen.getByText('Delivered but unshowable')).toBeInTheDocument())

    const toggles = Array.from(document.querySelectorAll('button')).filter((b) => b.hasAttribute('aria-pressed'))
    fireEvent.click(toggles[toggles.length - 1])

    const row = await screen.findByTestId(`content-row-${CARD.id}`)
    expect(within(row).queryByText('لم يعمل خلال هذه الفترة')).not.toBeInTheDocument()
    expect(row.textContent).toContain('—')
  })
})

/**
 * The other half of the pair, so the two states cannot quietly collapse back into one: a creative
 * with NO metrics object at all still gets the availability sentence, which is correct for it.
 */
describe('a creative with no metrics at all', () => {
  beforeEach(() => {
    useAuth.setState({
      user: { id: '1', name: 'Op', permissions: ['campaigns.view'], is_platform_admin: false } as unknown as AuthUser,
      status: 'authenticated',
    })
    vi.mocked(listCreatives).mockResolvedValue({
      ...PAGE,
      creatives: [{
        ...CARD,
        id: 'cr-silent',
        name: 'Never delivered',
        metrics: null,
        freshness: { ...CARD.freshness, last_active_at: null },
      }],
    } as unknown as LibraryPage)
  })

  it('still says it did not run in this period', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'ar' })
    await waitFor(() => expect(screen.getByText('Never delivered')).toBeInTheDocument())

    expect(screen.getByTestId('creative-empty-did_not_run'))
      .toHaveTextContent('لم يعمل خلال هذه الفترة')
    expect(screen.queryByTestId('creative-empty-no_displayable')).not.toBeInTheDocument()
  })
})
