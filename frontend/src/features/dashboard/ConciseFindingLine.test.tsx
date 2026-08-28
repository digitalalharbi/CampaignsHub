import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'

import { renderWithProviders } from '@/test/utils'

import { ConciseFindingLine } from './ConciseFindingLine'

/**
 * The headline is the sentence a reader questions least, so it is the worst place to say something
 * unsupported. This renders nothing in three different silences — request unanswered, scope empty,
 * account healthy — and the test pins each, because they are three separate ways to accidentally
 * publish reassurance.
 */
const FULL = { spend: 1000, impressions: 100000, clicks: 500, landing_page_views: 400, conversions: 10, revenue: 5000 }
const REPORTED = { spend: true, impressions: true, clicks: true, landing_page_views: true, conversions: true, revenue: true }

const render = (props: Partial<React.ComponentProps<typeof ConciseFindingLine>> = {}) =>
  renderWithProviders(
    <ConciseFindingLine objective="sales" totals={FULL} reported={REPORTED} rowsInScope pending={false} ar={false} {...props} />,
  )

describe('the dashboard finding line', () => {
  it('states the earliest weakness with a way through to the detail', () => {
    render({ totals: { ...FULL, impressions: 0, clicks: 0, landing_page_views: 0, conversions: 0, revenue: 0 } })

    expect(screen.getByTestId('dashboard-concise-finding')).toHaveTextContent('not being delivered')
    expect(screen.getByTestId('dashboard-finding-link')).toBeInTheDocument()
  })

  it('keeps an inference labelled as one', () => {
    render({ totals: { ...FULL, clicks: 10, landing_page_views: 8, conversions: 0, revenue: 0 } })

    expect(screen.getByTestId('dashboard-finding-probable')).toBeInTheDocument()
  })

  /**
   * The totals here WOULD produce a finding. That is the point: the suppression has to come from the
   * request state, not from there being nothing to say. The first version of this test passed healthy
   * totals and proved nothing.
   */
  it('says nothing while the request has not answered', () => {
    render({
      pending: true,
      totals: { ...FULL, impressions: 0, clicks: 0, landing_page_views: 0, conversions: 0, revenue: 0 },
    })

    expect(screen.queryByTestId('dashboard-concise-finding')).not.toBeInTheDocument()
  })

  /**
   * Every metric reads unreported over no rows, which diagnoses as «not delivering» — a claim about
   * the platform derived from a filter. Again the totals are ones that WOULD fire.
   */
  it('says nothing about a scope that holds no rows', () => {
    render({
      rowsInScope: false,
      totals: { ...FULL, impressions: 0, clicks: 0, landing_page_views: 0, conversions: 0, revenue: 0 },
    })

    expect(screen.queryByTestId('dashboard-concise-finding')).not.toBeInTheDocument()
  })

  it('says nothing when the account is healthy', () => {
    render()

    expect(screen.queryByTestId('dashboard-concise-finding')).not.toBeInTheDocument()
  })
})
