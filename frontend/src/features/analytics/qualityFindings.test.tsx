import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { AnalyticsPage } from './AnalyticsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
  getEnvelope: vi.fn(),
}))

import { getData, getEnvelope } from '@/lib/api/client'

/**
 * DATA-QUALITY-OPERATOR-UX-001 — asserted on the RENDERED tab, not on the helper alone.
 *
 * The helper being right proves nothing about a page: this codebase has shipped a canonical reader
 * with nothing wired to it more than once, which is the defect half of these units exist to close.
 */
const FRESHNESS = [
  {
    kind: 'ad_platform', provider: 'snapchat', account_id: 'a1', name: 'Snapchat Ads',
    latest_metric_date: '2026-08-01', data_freshness_at: '2026-08-01',
    days_with_data: 3, missing_days: 11,
    last_sync_status: 'failed', last_sync_at: '2026-08-02T02:00:00Z',
    last_sync_error: 'Request failed with status 401',
  },
]

function route(rows: unknown[]) {
  /*
   * Freshness reads the ENVELOPE, not the data alone — ANALYTICS-FILTER-TRUTH-001.
   *
   * The endpoint declines the platform axis on purpose and says so in `meta.filter_scope`, which the
   * panel now passes on to the reader. `meta` is where that answer lives, so the hook has to see the
   * whole envelope, and so does this fixture.
   */
  vi.mocked(getEnvelope).mockImplementation((url: string) =>
    (url.includes('freshness')
      ? { data: rows, meta: { filter_scope: { applied: [], unapplied: ['provider'] } }, message: null, success: true }
      : { data: null, meta: null, message: null, success: true }) as never,
  )
  vi.mocked(getData).mockImplementation((url: string) => {
    if (url.includes('freshness')) return rows as never
    if (url.includes('disclaimer')) return null as never
    /*
     * The complete `Normalization` contract, not a convenient subset.
     *
     * The panel reads eight sections and several of them unguarded, so a partial fixture throws
     * inside its render and takes the whole tab down with it — which makes every assertion in this
     * file fail for a reason that has nothing to do with the findings. Two of those accesses were
     * hardened in this PR; the fixture matching the real shape is what keeps the test honest.
     */
    if (url.includes('normalization')) {
      return {
        project_currency: 'SAR',
        project_currencies: ['SAR'],
        currencies: [],
        timezones: [],
        attribution_windows: [],
        sources: [],
        objectives: { present: [], mixed: false, comparable_metrics: [], objective_specific_metrics: [] },
        catalogue: { available: false, metrics: [] },
        unread_metric_keys: [],
      } as never
    }
    if (url.includes('attribution')) {
      return {
        platform_reported: {
          source: 'platform_reported', label_ar: '', label_en: '', providers: [],
          may_double_count: false, is_unique_order_count: false, note_ar: '', note_en: '',
          platforms: [], total_orders: null, total_revenue: null,
        },
        store_confirmed: null,
        attribution_window: { windows: [], mixed_windows: false, window_known: true },
      } as never
    }
    if (url.includes('/summary')) return { current: {}, previous: {}, delta: {}, currency: 'SAR' } as never
    return [] as never
  })
}

async function openQuality() {
  renderWithProviders(<AnalyticsPage />, { locale: 'en' })
  fireEvent.click(await screen.findByRole('tab', { name: /Data quality/i }))
}

describe('the data quality tab answers the operator’s questions', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  it('says what is wrong, what it affects, what to check and who can end it', async () => {
    route(FRESHNESS)
    await openQuality()

    const finding = await screen.findByTestId('quality-finding-snapchat')

    expect(finding).toHaveTextContent('The last sync from this platform failed')
    expect(finding).toHaveTextContent('lower than the truth, not zero')
    expect(finding).toHaveTextContent('read the error')
    // The answer an operator needs first, and the one six technical columns could not give.
    expect(finding).toHaveTextContent('Needs a look on the platform')
    expect(finding).toHaveTextContent('Coverage 21%')

  })

  /**
   * And a clean window says so.
   *
   * An empty findings list rendered as nothing would read as a page that failed to load — which is
   * the reading this product refuses everywhere else it has an empty state.
   */
  it('states that everything is current rather than showing an empty space', async () => {
    route([{ ...FRESHNESS[0], last_sync_status: 'fresh', missing_days: 0, days_with_data: 14, latest_metric_date: new Date().toISOString().slice(0, 10), data_freshness_at: new Date().toISOString().slice(0, 10) }])
    await openQuality()

    expect(await screen.findByTestId('quality-findings-clear')).toHaveTextContent('nothing here needs your attention')
    expect(screen.queryByTestId('quality-findings')).toBeNull()
  })

  /**
   * ANALYTICS-FILTER-TRUTH-001 — the chips are lit and this panel ignores them, on purpose and out loud.
   *
   * Freshness answers for the whole project deliberately: a sync state narrowed to the platform chips
   * would describe the FILTER rather than the source, and «no data» about a platform the reader
   * filtered out reads as an outage. The endpoint always declined that axis and always said so in its
   * meta — and the hook threw the meta away, so the panel could not pass it on. A reader with one
   * platform's chip lit sat above a table covering four, with nothing on screen to say which it was.
   */
  it('says the panel covers the whole project when an axis was declined', async () => {
    route(FRESHNESS)

    renderWithProviders(<AnalyticsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByRole('tab', { name: /Data quality/i }))

    expect(await screen.findByTestId('freshness-scope')).toHaveTextContent(/whole project/i)
  })
})
