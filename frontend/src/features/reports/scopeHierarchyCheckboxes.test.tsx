import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useState } from 'react'
import { act, fireEvent, screen, waitFor, within } from '@testing-library/react'
import { ReportScopePicker } from './ReportScopePicker'
import type { ReportScopeShape, ScopeOptions } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, scopeOptions: vi.fn(), listScopeTemplates: vi.fn(), explainScope: vi.fn(), searchScopeAxis: vi.fn() }
})

import { explainScope, listScopeTemplates, scopeOptions, searchScopeAxis } from './api'

/**
 * REPORT-SCOPE-HIERARCHY-001 — the Owner's flow, on the real picker: tick ad accounts, see only their
 * campaigns, tick campaigns, see only their ads. Every level is a list of VISIBLE checkboxes with a
 * count, search, select all and clear; «nothing ticked» means everything inside the choice above.
 *
 * The mock answers like the server: campaigns narrowed by the accounts asked about.
 */
const ACCOUNTS = [
  { id: 'a-razzah', name: 'Razzah Avenue', provider: 'meta' },
  { id: 'a-hanka', name: 'hanka', provider: 'meta' },
  { id: 'a-ssf', name: 'Saudi Surfing Federation', provider: 'tiktok' },
]
const CAMPAIGN_ACCOUNT: Record<string, string> = { 'c-r1': 'a-razzah', 'c-r2': 'a-razzah', 'c-h1': 'a-hanka', 'c-s1': 'a-ssf' }
const CAMPAIGNS = [
  { id: 'c-r1', name: 'Razzah Eid', status: 'active', objective: 'sales' },
  { id: 'c-r2', name: 'Razzah Winter', status: 'active', objective: 'sales' },
  { id: 'c-h1', name: 'Hanka Launch', status: 'active', objective: 'sales' },
  { id: 'c-s1', name: 'Surf Season', status: 'active', objective: 'sales' },
]
const ADS = [
  { id: 'ad-1', name: 'Razzah hero', provider: 'meta', campaign_id: 'c-r1' },
  { id: 'ad-2', name: 'Razzah carousel', provider: 'meta', campaign_id: 'c-r2' },
  { id: 'ad-3', name: 'Hanka reel', provider: 'meta', campaign_id: 'c-h1' },
]

const optionsFor = (accountIds: string[] = []): ScopeOptions => ({
  campaigns: CAMPAIGNS.filter((c) => accountIds.length === 0 || accountIds.includes(CAMPAIGN_ACCOUNT[c.id])),
  providers: ['meta'],
  accounts: ACCOUNTS,
  ad_sets: [],
  ads: ADS,
  creatives: [],
  objectives: [],
  paths: [],
  metrics: [],
  grain: { figures: [], resolved_to_campaign: [], creatives_only: [] },
} as unknown as ScopeOptions)

function Harness() {
  const [value, setValue] = useState<ReportScopeShape>({})
  return (
    <>
      <ReportScopePicker projectId="p1" value={value} onChange={setValue} audience="client" />
      <output data-testid="value">{JSON.stringify(value)}</output>
    </>
  )
}

const stored = () => JSON.parse(screen.getByTestId('value').textContent ?? '{}') as ReportScopeShape
const labels = (kind: string) =>
  within(screen.getByTestId(`scope-select-${{ accounts: 'Ad accounts', campaigns: 'Campaigns', ads: 'Ads' }[kind]}`))
    .getAllByRole('checkbox').map((c) => (c.closest('label') as HTMLElement).textContent ?? '')

describe('the ad account → campaign → ad checkbox flow', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(scopeOptions).mockImplementation(async (_p, _period, filters) => optionsFor((filters?.account_ids as string[] | undefined) ?? []))
    vi.mocked(listScopeTemplates).mockResolvedValue([] as never)
    vi.mocked(explainScope).mockResolvedValue({ scope: {}, bound_axes: [], explain: [] } as never)
    vi.mocked(searchScopeAxis).mockResolvedValue({} as never)
  })

  it('shows every ad account as a visible checkbox, and no campaign until one is ticked', async () => {
    renderWithProviders(<Harness />, { locale: 'en' })

    await screen.findByTestId('scope-select-Ad accounts')
    expect(labels('accounts')).toEqual(['Razzah Avenue · meta', 'hanka · meta', 'Saudi Surfing Federation · tiktok'])
    expect(screen.queryByTestId('scope-select-Campaigns')).not.toBeInTheDocument()
    expect(screen.getByTestId('scope-accounts-required')).toBeInTheDocument()
    expect(screen.getByTestId('scope-count-accounts')).toHaveTextContent('0 of 3 selected')
  })

  it('selects one or many accounts explicitly, and the campaigns follow only those accounts', async () => {
    renderWithProviders(<Harness />, { locale: 'en' })
    fireEvent.click(await screen.findByTestId('scope-accounts-a-razzah'))

    // The server narrows; the previous list stays until its answer lands, so wait for the narrowed one.
    await waitFor(() => expect(labels('campaigns').join('|')).not.toContain('Hanka'))
    expect(labels('campaigns')).toEqual(['Razzah Eid', 'Razzah Winter'])
    expect(stored().account_ids).toEqual(['a-razzah'])
    expect(screen.getByTestId('scope-count-accounts')).toHaveTextContent('1 of 3 selected')

    fireEvent.click(screen.getByTestId('scope-accounts-a-ssf'))
    await waitFor(() => expect(labels('campaigns').join('|')).toContain('Surf Season'))
    expect(stored().account_ids).toEqual(['a-razzah', 'a-ssf'])
    expect(labels('campaigns').join('|')).not.toContain('Hanka')
  })

  it('says «all» only inside the chosen scope, and ads follow the ticked campaigns', async () => {
    renderWithProviders(<Harness />, { locale: 'en' })
    fireEvent.click(await screen.findByTestId('scope-accounts-a-razzah'))
    await screen.findByTestId('scope-select-Campaigns')

    expect(screen.getByTestId('scope-count-campaigns')).toHaveTextContent('All campaigns of the selected accounts')
    // With none ticked, ads are those of the account's campaigns — never the other client's.
    await waitFor(() => expect(labels('ads')).toEqual(['Razzah hero · Razzah Eid', 'Razzah carousel · Razzah Winter']))
    expect(screen.getByTestId('scope-count-ads')).toHaveTextContent('All ads of the selected campaigns')

    fireEvent.click(screen.getByTestId('scope-campaigns-c-r1'))
    await waitFor(() => expect(labels('ads')).toEqual(['Razzah hero · Razzah Eid']))
    expect(stored().campaign_ids).toEqual(['c-r1'])

    fireEvent.click(screen.getByTestId('scope-ads-ad-1'))
    expect(stored().ad_ids).toEqual(['ad-1'])
    expect(screen.getByTestId('scope-count-ads')).toHaveTextContent('1 of 1 selected')
  })

  it('drops what no longer sits inside the choice above when an account is unticked', async () => {
    renderWithProviders(<Harness />, { locale: 'en' })
    fireEvent.click(await screen.findByTestId('scope-accounts-a-razzah'))
    fireEvent.click(await screen.findByTestId('scope-campaigns-c-r1'))
    fireEvent.click(await screen.findByTestId('scope-ads-ad-1'))
    expect(stored()).toMatchObject({ account_ids: ['a-razzah'], campaign_ids: ['c-r1'], ad_ids: ['ad-1'] })

    fireEvent.click(screen.getByTestId('scope-accounts-a-razzah'))

    await waitFor(() => expect(stored().campaign_ids).toBeUndefined())
    expect(stored().ad_ids).toBeUndefined()
    expect(stored().account_ids).toBeUndefined()
    expect(screen.queryByTestId('scope-select-Campaigns')).not.toBeInTheDocument()
  })

  it('has select all, clear and search at the account level', async () => {
    renderWithProviders(<Harness />, { locale: 'en' })
    await screen.findByTestId('scope-select-Ad accounts')

    fireEvent.click(screen.getByTestId('scope-all-accounts'))
    expect(stored().account_ids).toEqual(['a-razzah', 'a-hanka', 'a-ssf'])
    expect(screen.getByTestId('scope-count-accounts')).toHaveTextContent('3 of 3 selected')

    fireEvent.click(screen.getByTestId('scope-clear-accounts'))
    expect(stored().account_ids).toBeUndefined()
  })

  /**
   * Ticks that land before React re-renders must all count. A fast operator, or a test driving a slow
   * browser, ticks the next account before the first tick has been rendered; each handler used to read
   * the selection its own render saw, so the last tick overwrote the others and accounts went missing
   * with no message — which is how a report came to cover fewer accounts than were ticked.
   */
  it('keeps every tick when several land in one batch', async () => {
    renderWithProviders(<Harness />, { locale: 'en' })
    await screen.findByTestId('scope-select-Ad accounts')

    act(() => {
      fireEvent.click(screen.getByTestId('scope-accounts-a-razzah'))
      fireEvent.click(screen.getByTestId('scope-accounts-a-hanka'))
      fireEvent.click(screen.getByTestId('scope-accounts-a-ssf'))
    })

    expect(stored().account_ids).toEqual(['a-razzah', 'a-hanka', 'a-ssf'])
  })
})
