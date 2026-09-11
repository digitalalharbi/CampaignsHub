import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
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
 * BUDGET-GOVERNANCE-001 — the platform rung, which the customer could see and the operator could not.
 *
 * `budgetPacingByProvider()` fed the daily digest, the generated report and the client's live link;
 * the product had no route to it, so «which platform is overspending» was answerable from a client's
 * report and not from the account that runs it.
 *
 * The columns are deliberately the campaign table's, in the same order: moving down the hierarchy
 * should read the same figures at a coarser grain, not teach a second layout.
 */
const PLATFORMS = [
  {
    provider: 'meta', budget: 10_000, budget_currency: 'SAR', spent: 7_500, spent_currency: 'SAR',
    spend_withheld: false, remaining: 2_500, consumed_pct: 0.75, pace: 1.4,
    projected_spend: 14_000, expected_to_date: 5_000, daily_average: 500, over_under: 4_000,
    pacing_basis: 'comparable', refusal: null,
  },
  {
    provider: 'snapchat', budget: 4_000, budget_currency: 'SAR', spent: null, spent_currency: null,
    spend_withheld: true, remaining: null, consumed_pct: null, pace: null,
    projected_spend: null, expected_to_date: null, daily_average: null, over_under: null,
    pacing_basis: 'mixed_currency', refusal: 'mixed_currency',
  },
]

function route() {
  const body = (url: string) => {
    if (url.includes('budget-platforms')) return PLATFORMS
    /* The reading panel is a sibling, not the subject — given null it renders nothing. */
    if (url.includes('budget-explanation')) return null
    if (url.includes('/summary')) return { current: {}, previous: {}, delta: {}, currency: 'SAR' }
    if (url.includes('disclaimer')) return null
    return []
  }
  vi.mocked(getData).mockImplementation((url: string) => body(url) as never)
  vi.mocked(getEnvelope).mockImplementation(
    (url: string) => ({ data: body(url), meta: null, message: null, success: true }) as never,
  )
}

describe('the budget hierarchy reaches the platform rung', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    route()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view', 'budget.view'])
  })
  afterEach(() => signOut())

  it('lists each platform against its budget', async () => {
    renderWithProviders(<AnalyticsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByRole('tab', { name: /Budget/i }))

    expect(await screen.findByText(/Budget by platform/i)).toBeVisible()

    /* Scoped to the rung's own table — «Meta» is a word this page uses in several places. */
    const table = (await screen.findAllByRole('table'))[0]!
    expect(table).toHaveTextContent('Meta')
    expect(table).toHaveTextContent(/Projected|Pace/i)
  })

  /**
   * A platform whose spend cannot be compared with its budget shows «—», never a ratio.
   *
   * Two currencies produce a number that looks like pacing and is not, which is the failure the
   * money contract exists to prevent — carried down to this rung rather than re-decided here.
   */
  it('refuses a pace it cannot compute rather than inventing one', async () => {
    renderWithProviders(<AnalyticsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByRole('tab', { name: /Budget/i }))

    await screen.findByText(/Budget by platform/i)
    const table = (await screen.findAllByRole('table'))[0]!

    // Meta paces at 1.4×; snapchat cannot be paced at all.
    expect(table).toHaveTextContent(/1\.4/)
    expect(table).toHaveTextContent('—')
  })

  /**
   * BUDGET-GOVERNANCE-001 — the three columns the owner's spreadsheet has.
   *
   * «Expected to date» is the figure `pace` already divides BY, and the product knew it without ever
   * showing it: a reader told «1.4×» could not check it, or see which of the two figures had moved.
   * «Over / under» is the overrun in money — projected 14,000 against a 10,000 plan makes the reader
   * do the subtraction, and that difference is the decision.
   */
  it('states what should have been spent by today, and by how much the forecast misses', async () => {
    renderWithProviders(<AnalyticsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByRole('tab', { name: /Budget/i }))

    const table = (await screen.findAllByRole('table'))[0]!

    expect(table).toHaveTextContent(/Expected to date/i)
    expect(table).toHaveTextContent(/Daily average/i)
    expect(table).toHaveTextContent(/Over \/ under/i)

    const meta = within(table).getByText('Meta').closest('tr')!
    /*
      Asserted CELL BY CELL, not against the row's text.
      Matching `/5K SAR/` across the whole row passes on the spend cell's «7.5K SAR» — so the
      assertion survived blanking the column it was meant to be about. `money()` compacts, and the
      compacted forms of these figures overlap; only the position distinguishes them.
    */
    /* The platform name is the row's header cell, so the data cells start at Budget. */
    const cells = within(meta).getAllByRole('cell').map((c) => c.textContent?.trim() ?? '')

    expect(cells[4]).toBe('5K SAR')
    expect(cells[5]).toBe('500 SAR')
    /* Signed, so one column carries both directions and an overrun reads as one. */
    expect(cells[8]).toBe('+4K SAR')
  })

  /** A row the contract refuses withholds these three with everything else on it. */
  it('withholds all three where the row itself is withheld', async () => {
    renderWithProviders(<AnalyticsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByRole('tab', { name: /Budget/i }))

    const table = (await screen.findAllByRole('table'))[0]!
    const snap = within(table).getByText('Snapchat').closest('tr')!

    /* Its BUDGET is known — 4K — and the eight figures derived from a spend it cannot state are not. */
    expect(snap.textContent).toContain('4K SAR')
    expect(snap.textContent).not.toMatch(/\+/)
    expect((snap.textContent ?? '').match(/—/g) ?? []).toHaveLength(8)
  })
})
