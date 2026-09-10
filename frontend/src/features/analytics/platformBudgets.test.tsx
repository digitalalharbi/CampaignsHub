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
    projected_spend: 14_000, pacing_basis: 'comparable', refusal: null,
  },
  {
    provider: 'snapchat', budget: 4_000, budget_currency: 'SAR', spent: null, spent_currency: null,
    spend_withheld: true, remaining: null, consumed_pct: null, pace: null,
    projected_spend: null, pacing_basis: 'mixed_currency', refusal: 'mixed_currency',
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
})
