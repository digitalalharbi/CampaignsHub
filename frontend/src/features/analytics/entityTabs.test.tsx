import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { AnalyticsPage } from './AnalyticsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

/**
 * ANALYTICS-DRILLDOWN-001 — the Ad Set and Ads tabs, asserted on the RENDERED page.
 *
 * These tabs did not exist and could not have: there was no table beneath the campaign grain, so
 * the 187 ad squads and 5,706 ads on the live account had nothing to read from. A test on the hook
 * alone would not prove the tab shows anything, and this codebase has shipped a canonical helper
 * with nothing wired to it before.
 */
const ROW = {
  entity_id: 'e1',
  external_id: 'sq-1',
  name: 'Riyadh · 18-34',
  status: 'active',
  campaign_id: 'c1',
  ad_set_id: null,
  active_days: 2,
  last_active_on: '2026-08-02',
  impressions: 90000,
  reach: 45000,
  frequency: 2,
  clicks: 300,
  ctr: 0.00333,
  // Withheld: the account spends USD and no USD→SAR rate exists.
  spend: null,
  spend_original: 412.5,
  spend_withheld_rows: 2,
  money_original_currency: 'USD',
  money_original_currencies: 1,
  // Nobody reported these, so they are null — never 0.
  conversions: null,
  cpa: null,
  cpm: null,
  cpc: null,
}

function route(entities: unknown[]) {
  vi.mocked(getData).mockImplementation(async (url: string) => {
    if (url.includes('/entities/')) {
      return { entities, entity_type: 'ad_set', period: { from: '2026-07-25', to: '2026-08-10' }, currency: 'SAR', attribution_window: null } as never
    }
    // Null, not []: an empty array is truthy and `PerformanceNotice` reads `.sections` off it.
    if (url.includes('disclaimer')) return null as never
    if (url.includes('/summary')) return { current: {}, previous: {}, delta: {}, currency: 'SAR', provenance: { source: 'live', live_rows: 5, demo_rows: 0 } } as never
    return [] as never
  })
}

/**
 * The tab is local state, not a URL parameter, so the test opens it the way a person does — by
 * clicking it. That also proves the tab is actually reachable from the tab bar.
 */
async function openAdSets() {
  renderWithProviders(<AnalyticsPage />, { locale: 'en' })
  fireEvent.click(await screen.findByRole('tab', { name: 'Ad sets' }))
}

describe('the ad set analysis tab', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  it('shows a real ad squad with its name and figures', async () => {
    route([ROW])

    await openAdSets()

    expect(await screen.findByText('Riyadh · 18-34')).toBeInTheDocument()
    expect(screen.getByText('90,000')).toBeInTheDocument()
    // Reach is reported by the provider and never approximated from impressions.
    expect(screen.getByText('45,000')).toBeInTheDocument()
  })

  /**
   * ENTITY-RELEVANCE-ORDERING-001 — «currently-serving ads must not be mixed with stopped historical
   * ads without clear grouping».
   *
   * The table rendered rows exactly as the aggregator returned them: spend-first, which is right for
   * a report and wrong for an operator. A stopped ad set that outspent everything still running led
   * the list, so the first row somebody saw was one they could do nothing about — with nothing on it
   * saying so.
   */
  it('puts what is still running above what has stopped, whatever it spent', async () => {
    route([
      { ...ROW, entity_id: 'dead', external_id: 'sq-dead', name: 'Big spender, paused', status: 'paused', spend: 9000, spend_original: null, spend_withheld_rows: 0, money_original_currency: null },
      { ...ROW, entity_id: 'live', external_id: 'sq-live', name: 'Still running', status: 'active', last_active_on: '2026-08-09', spend: 10, spend_original: null, spend_withheld_rows: 0, money_original_currency: null },
    ])

    await openAdSets()

    const table = await screen.findByTestId('entity-table-ad_set')
    const text = table.textContent ?? ''

    expect(text.indexOf('Still running')).toBeLessThan(text.indexOf('Big spender, paused'))
  })

  /**
   * And the state is ON the row, not only in its position.
   *
   * Ordering alone is not clear grouping: a reader who lands mid-table, or who later sorts by spend,
   * loses it entirely. Serving rows carry no badge — a table where every row is decorated says
   * nothing.
   */
  it('marks a stopped ad set as stopped, and leaves a running one unmarked', async () => {
    route([
      { ...ROW, entity_id: 'dead', external_id: 'sq-dead', name: 'Paused one', status: 'paused' },
      /* One day before the window's end: inside the three-day reporting-lag allowance, so serving. */
      { ...ROW, entity_id: 'live', external_id: 'sq-live', name: 'Running one', status: 'active', last_active_on: '2026-08-09' },
    ])

    await openAdSets()

    expect(await screen.findByTestId('entity-state-dead')).toHaveTextContent('Stopped')
    expect(screen.queryByTestId('entity-state-live')).not.toBeInTheDocument()
  })

  /**
   * A campaign the platform stopped reporting for is IDLE, not stopped.
   *
   * «The platform told us it is paused» and «the platform has said nothing for three weeks» are
   * different facts and lead to different actions — one is a decision somebody made, the other is a
   * question somebody should ask.
   */
  it('separates a silent ad set from a paused one', async () => {
    route([{ ...ROW, entity_id: 'quiet', external_id: 'sq-quiet', name: 'Gone quiet', status: 'active', last_active_on: '2026-07-01' }])

    await openAdSets()

    expect(await screen.findByTestId('entity-state-quiet')).toHaveTextContent('Idle')
  })

  /** The defect this product keeps producing: an unavailable figure rendered as zero. */
  it('prints a dash for a metric nobody reported, never a zero', async () => {
    route([ROW])

    await openAdSets()

    const table = await screen.findByTestId('entity-table-ad_set')

    expect(table).toHaveTextContent('—')
    // A CPA of 0 over real delivery would read as free results.
    expect(table).not.toHaveTextContent('0.00 SAR')
  })

  /** Withheld money states its own currency rather than wearing the project's. */
  it('renders withheld spend as its original amount and currency', async () => {
    route([ROW])

    await openAdSets()

    const table = await screen.findByTestId('entity-table-ad_set')

    expect(table).toHaveTextContent('412.50 USD')
    expect(table).not.toHaveTextContent('0 SAR')
  })

  /** An entity the structure sweep removed keeps its provider id rather than becoming «Unknown». */
  it('falls back to the provider id when the entity has no name', async () => {
    route([{ ...ROW, name: null }])

    await openAdSets()

    expect(await screen.findByTestId('entity-table-ad_set')).toHaveTextContent('sq-1')
  })
})
