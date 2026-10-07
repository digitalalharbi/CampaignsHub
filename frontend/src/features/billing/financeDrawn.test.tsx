import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { FinanceOverviewPage } from './FinanceOverviewPage'
import type { FinanceOverview } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, getFinanceOverview: vi.fn(), listReceivables: vi.fn() }
})

import { getFinanceOverview, listReceivables } from './api'

/**
 * VIZ-FINANCE-001 — the receivable shapes this page already held, drawn once instead of twice.
 *
 * ## The aging bar was right, and that is the point
 *
 * It divided the outstanding money by how late it is, sized the segments by share, named every band
 * and declined when there was nothing outstanding. That is `StatusMixBar`'s whole contract, written
 * a second time — and the second copy had none of the refusals the first one carries: bands that
 * exceed their total silently normalised rather than declining, and the un-named remainder was
 * simply missing track.
 *
 * Moving it to the canonical bar is what makes those refusals apply to money too, which is where
 * they matter most: a share of outstanding money computed over an incomplete denominator is every
 * figure too large, and it looks exactly like a correct one.
 *
 * ## And a rate that is not a rate is not zero
 *
 * `collection_rate` is null when nothing was invoiced — the payload's own comment says so. A ring
 * drawn at 0% would say «none of it was collected» about a month in which nothing was billed.
 */
const overview = (over: Partial<FinanceOverview> = {}): FinanceOverview => ({
  quotes: { by_status: { approved: { count: 2, total: 40_000 } }, count: 2, total: 40_000, approved_total: 40_000 },
  invoices: {
    by_status: { paid: { count: 3, total: 30_000 }, sent: { count: 1, total: 20_000 } },
    count: 4, total: 50_000, collected: 30_000, outstanding: 20_000, overdue_count: 1, collection_rate: 0.6,
  },
  payments: { by_status: { succeeded: { count: 3, total: 30_000 } }, count: 3, succeeded_total: 30_000 },
  aging: { current: 7_500, d1_30: 2_500, d31_60: 0, d61_90: 0, d90_plus: 0 },
  currency: 'SAR',
  ...over,
})

describe('the finance overview draws its receivables through the product’s own bar', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['billing.view'])
    vi.mocked(listReceivables).mockResolvedValue([] as never)
  })
  afterEach(() => signOut())

  it('divides what is outstanding by how late it is', async () => {
    vi.mocked(getFinanceOverview).mockResolvedValue(overview())
    renderWithProviders(<FinanceOverviewPage />, { locale: 'en' })

    // 7,500 of 10,000 outstanding is current.
    expect(await screen.findByTestId('aging-segment-current')).toHaveStyle({ width: '75%' })
  })

  it('states every aged band in the reporting currency, never as a bare number', async () => {
    vi.mocked(getFinanceOverview).mockResolvedValue(overview())
    renderWithProviders(<FinanceOverviewPage />, { locale: 'en' })

    const legend = await screen.findByTestId('aging-legend')
    expect(legend).toHaveTextContent(/SAR/)
    expect(legend).not.toHaveTextContent(/\b7500\b/)
  })

  it('says nothing is outstanding rather than drawing an empty bar', async () => {
    vi.mocked(getFinanceOverview).mockResolvedValue(
      overview({ aging: { current: 0, d1_30: 0, d31_60: 0, d61_90: 0, d90_plus: 0 } }),
    )
    renderWithProviders(<FinanceOverviewPage />, { locale: 'en' })

    expect(await screen.findByTestId('aging-empty')).toBeInTheDocument()
    expect(screen.queryByTestId('aging-segment-current')).toBeNull()
  })

  it('draws the collection rate as a ring over the money it is a rate of', async () => {
    vi.mocked(getFinanceOverview).mockResolvedValue(overview())
    renderWithProviders(<FinanceOverviewPage />, { locale: 'en' })

    expect(await screen.findByTestId('collection-ring')).toHaveTextContent('60%')
  })

  it('draws no ring when nothing was invoiced, because an undefined rate is not a zero rate', async () => {
    vi.mocked(getFinanceOverview).mockResolvedValue(
      overview({ invoices: { ...overview().invoices, collection_rate: null, count: 0, total: 0 } }),
    )
    renderWithProviders(<FinanceOverviewPage />, { locale: 'en' })

    await screen.findByTestId('aging-empty').catch(() => null)
    expect(screen.queryByTestId('collection-ring')).toBeNull()
    expect(screen.getByTestId('collection-rate-absent')).toBeInTheDocument()
  })

  it('divides the invoiced money by the state each invoice is in', async () => {
    vi.mocked(getFinanceOverview).mockResolvedValue(overview())
    renderWithProviders(<FinanceOverviewPage />, { locale: 'en' })

    // 30,000 paid of 50,000 invoiced.
    expect(await screen.findByTestId('invoice-states-segment-paid')).toHaveStyle({ width: '60%' })
  })

  it('shows the un-named remainder of the invoiced money rather than a bar that stops short', async () => {
    vi.mocked(getFinanceOverview).mockResolvedValue(
      overview({ invoices: { ...overview().invoices, by_status: { paid: { count: 3, total: 30_000 } } } }),
    )
    renderWithProviders(<FinanceOverviewPage />, { locale: 'en' })

    expect(await screen.findByTestId('invoice-states-segment-residual')).toBeInTheDocument()
  })

  it('refuses the invoice mix when the states hold more than was invoiced', async () => {
    // A denominator the parts exceed is a contradiction; normalising it produces shares that look right.
    vi.mocked(getFinanceOverview).mockResolvedValue(
      overview({ invoices: { ...overview().invoices, total: 10_000 } }),
    )
    renderWithProviders(<FinanceOverviewPage />, { locale: 'en' })

    expect(await screen.findByTestId('invoice-states-undrawable')).toBeInTheDocument()
  })
})
