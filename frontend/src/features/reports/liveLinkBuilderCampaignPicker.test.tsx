import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { LiveLinkBuilder } from './LiveLinkBuilder'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  liveBuilderOptions: vi.fn(),
  createLiveLink: vi.fn(),
  reportSectionRegistry: vi.fn(),
}))

import { createLiveLink, liveBuilderOptions, reportSectionRegistry } from './api'

/**
 * REPORT-SCOPE-SELECTION-001 — choosing campaigns for a report is a choice, not a scroll.
 *
 * «والحملات يجيب تطوير اختيارات داخل اعدادات التقرير لانه تظهر جميع الحملات في الحسابات الاعلانية
 * جميعها وهذا غير منطق ابدا» — the picker offered every campaign in every ad account at once.
 *
 * The platform pills were the first answer and they do not finish it: two Meta ad accounts under one
 * project are ONE pill, so an agency running two accounts for a client gets the flat list back. These
 * tests hold the three properties that make the account filter and the search usable rather than
 * decorative:
 *
 *   1. they NARROW what is shown and never what is selected — a filter that silently unselected work
 *      would be a worse version of the problem it was added to solve;
 *   2. the account is written on every row, because that is the thing the pills could not say;
 *   3. a filter that matches nothing says so and offers the way back, instead of looking like a
 *      project with no campaigns.
 */
const ACCOUNTS = [
  { id: 'a-brand', name: 'Brand — Meta', provider: 'meta' },
  { id: 'a-retail', name: 'Retail — Meta', provider: 'meta' },
]

/** Ten campaigns, so the list is longer than the box and the search is offered. */
const CAMPAIGNS = [
  { id: 'c-1', name: 'Eid brand', accounts: [ACCOUNTS[0]] },
  { id: 'c-2', name: 'Eid retail', accounts: [ACCOUNTS[1]] },
  { id: 'c-3', name: 'Always on', accounts: [ACCOUNTS[0], ACCOUNTS[1]] },
  { id: 'c-4', name: 'Winter brand', accounts: [ACCOUNTS[0]] },
  { id: 'c-5', name: 'Winter retail', accounts: [ACCOUNTS[1]] },
  { id: 'c-6', name: 'Riyadh season', accounts: [ACCOUNTS[0]] },
  { id: 'c-7', name: 'Jeddah season', accounts: [ACCOUNTS[1]] },
  { id: 'c-8', name: 'Back to school', accounts: [ACCOUNTS[0]] },
  { id: 'c-9', name: 'White Friday', accounts: [ACCOUNTS[1]] },
  { id: 'c-10', name: 'Entered by hand', accounts: [] },
].map((c) => ({ ...c, status: 'active', last_active_on: null, platforms: ['meta'] }))

const OPTIONS = {
  campaigns: CAMPAIGNS,
  providers: ['meta'],
  metrics: [{ key: 'spend', ar: 'الإنفاق', en: 'Spend' }],
  ad_accounts: ACCOUNTS,
}

describe('the report builder campaign picker', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['reports.view', 'reports.create'])
    vi.mocked(liveBuilderOptions).mockResolvedValue(OPTIONS as never)
    vi.mocked(reportSectionRegistry).mockResolvedValue({ sections: [], reasons: [] } as never)
    vi.mocked(createLiveLink).mockResolvedValue({
      report_id: 'r-1', share_id: 's-1', url: 'https://campaignshub.io/r/tok', token: 'tok',
    } as never)
  })
  afterEach(() => signOut())

  const open = async () => {
    renderWithProviders(<LiveLinkBuilder projectId="p-1" onClose={() => {}} />, { locale: 'en' })
    await screen.findByTestId('live-builder-campaigns')
  }

  const shown = () =>
    within(screen.getByTestId('live-builder-campaigns'))
      .getAllByRole('checkbox')
      .map((box) => (box.closest('label') as HTMLElement).textContent ?? '')

  /** The account is on the row — the one thing a platform icon could not tell you. */
  it('writes the ad account beside every campaign it came from', async () => {
    await open()

    expect(shown().some((row) => row.includes('Eid brand') && row.includes('Brand — Meta'))).toBe(true)
    expect(shown().some((row) => row.includes('Eid retail') && row.includes('Retail — Meta'))).toBe(true)
  })

  /** A campaign fed by two accounts names both rather than picking a winner. */
  it('names every account behind a campaign that spans two', async () => {
    await open()

    const row = shown().find((r) => r.includes('Always on')) ?? ''

    expect(row).toContain('Brand — Meta')
    expect(row).toContain('Retail — Meta')
  })

  it('narrows the list to one ad account', async () => {
    await open()

    fireEvent.change(screen.getByTestId('live-builder-campaign-account'), { target: { value: 'a-brand' } })

    const rows = shown()

    expect(rows.some((r) => r.includes('Eid brand'))).toBe(true)
    expect(rows.some((r) => r.includes('Eid retail'))).toBe(false)
    // The campaign that spans both accounts belongs to this one too.
    expect(rows.some((r) => r.includes('Always on'))).toBe(true)
  })

  /**
   * The filter narrows what is SHOWN and never what is SELECTED.
   *
   * An operator who ticks a campaign and then looks at another account has not changed their mind
   * about the first one. Unselecting it on their behalf would put a report in front of a client with
   * a campaign missing and nothing to say it had been dropped.
   */
  it('keeps a selected campaign selected when the filter moves away from it', async () => {
    await open()

    const eidRetail = within(screen.getByTestId('live-builder-campaigns'))
      .getByText(/Eid retail/)
      .closest('label') as HTMLElement
    fireEvent.click(within(eidRetail).getByRole('checkbox'))

    expect(screen.getByTestId('live-link-summary').textContent).toContain('1 campaign')

    fireEvent.change(screen.getByTestId('live-builder-campaign-account'), { target: { value: 'a-brand' } })
    expect(screen.getByTestId('live-link-summary').textContent).toContain('1 campaign')

    fireEvent.change(screen.getByTestId('live-builder-campaign-account'), { target: { value: '' } })
    const back = within(screen.getByTestId('live-builder-campaigns'))
      .getByText(/Eid retail/)
      .closest('label') as HTMLElement
    expect(within(back).getByRole<HTMLInputElement>('checkbox').checked).toBe(true)
  })

  it('searches by campaign name', async () => {
    await open()

    fireEvent.change(screen.getByTestId('live-builder-campaign-search'), { target: { value: 'winter' } })

    const rows = shown()

    expect(rows).toHaveLength(2)
    expect(rows.every((r) => r.includes('Winter'))).toBe(true)
  })

  /** Typing the ACCOUNT reaches its campaigns — the operator usually knows which account it was. */
  it('searches by ad account name too', async () => {
    await open()

    fireEvent.change(screen.getByTestId('live-builder-campaign-search'), { target: { value: 'retail —' } })

    const rows = shown()

    expect(rows.some((r) => r.includes('Eid retail'))).toBe(true)
    expect(rows.some((r) => r.includes('Always on'))).toBe(true)
    expect(rows.some((r) => r.includes('Entered by hand'))).toBe(false)
  })

  /**
   * A filter that matches nothing explains itself and offers the way back.
   *
   * An empty box under three controls reads as «this project has no campaigns», which is the one
   * thing it does not mean.
   */
  it('says when a filter matches nothing and can be cleared in one click', async () => {
    await open()

    fireEvent.change(screen.getByTestId('live-builder-campaign-search'), { target: { value: 'zzzz' } })

    const empty = screen.getByTestId('live-builder-campaigns-empty')
    expect(empty.textContent).toContain('No campaign matches this filter')

    fireEvent.click(within(empty).getByRole('button', { name: /Show all campaigns/ }))
    expect(shown()).toHaveLength(CAMPAIGNS.length)
  })
})
