import { describe, expect, it } from 'vitest'
import { MemoryRouter } from 'react-router-dom'
import { render, screen } from '@testing-library/react'
import { MetricStrip, type MetricItem } from './MetricStrip'

/**
 * METRICS-REQUEST-STATE-001 — a request that failed or has not answered is not «لا توجد بيانات».
 *
 * The strip took only `hasRows`, and every caller derived it from `summary.data`. A summary that had
 * ERRORED and one still in flight both leave `data` undefined, so `hasRows` came through undefined
 * and the row rendered anyway — with no totals to read, every card fell to `no_data` and printed «لا
 * توجد بيانات» / «No data».
 *
 * That is the project's oldest failure wearing a new hat: an absence of EVIDENCE rendered as
 * evidence of absence. A reader whose 403 or dead backend produced fourteen confident «No data»
 * cards has been told something false about their advertising, and there is nothing on screen to
 * suggest otherwise — the empty-scope panel at least names the filter.
 */
const ITEMS: MetricItem[] = [
  { key: 'spend', label: 'Spend', reading: { kind: 'no_data' } },
  { key: 'clicks', label: 'Clicks', reading: { kind: 'no_data' } },
]

const renderStrip = (props: Partial<React.ComponentProps<typeof MetricStrip>>) =>
  render(
    <MemoryRouter>
      <MetricStrip id="t" ar={false} primary={ITEMS} {...props} />
    </MemoryRouter>,
  )

describe('the metric strip while the request is in flight or has failed', () => {
  it('says the figures are still loading rather than that there are none', () => {
    renderStrip({ loading: true })

    expect(screen.getByTestId('t-metrics-loading')).toBeInTheDocument()
    expect(screen.queryByText('No data')).not.toBeInTheDocument()
    expect(screen.queryByTestId('metric-spend')).not.toBeInTheDocument()
  })

  /*
   * The failure arms come from `QueryFailure`, which already distinguishes a refusal from a dead
   * server and offers Retry only where retrying can work. A second failure treatment here would
   * drift away from it.
   */
  it('renders a refusal as a refusal, not as an empty advertising account', () => {
    renderStrip({ error: { response: { status: 403, data: { message: 'Your membership does not cover this client' } } } })

    expect(screen.getByTestId('t-metrics-failure-permission')).toBeInTheDocument()
    expect(screen.queryByText('No data')).not.toBeInTheDocument()
    expect(screen.queryByTestId('metric-spend')).not.toBeInTheDocument()
  })

  it('renders a dead backend as a failure that can be retried', () => {
    renderStrip({ error: { response: { status: 500 } } })

    expect(screen.getByTestId('t-metrics-failure-retryable')).toBeInTheDocument()
    expect(screen.queryByText('No data')).not.toBeInTheDocument()
  })

  /*
   * A failure outranks an empty scope. Both can be true at once in the props — the previous scope was
   * empty and the refetch then failed — and «your filter matched nothing» would be a claim the failed
   * request gave no standing to make.
   */
  it('lets the failure speak over the empty-scope sentence', () => {
    renderStrip({ error: { response: { status: 500 } }, hasRows: false })

    expect(screen.getByTestId('t-metrics-failure-retryable')).toBeInTheDocument()
    expect(screen.queryByTestId('t-metrics-empty-scope')).not.toBeInTheDocument()
  })

  /* And the states that were already right stay right. */
  it('still renders the cards when the request succeeded', () => {
    renderStrip({ loading: false, hasRows: true })

    expect(screen.getByTestId('metric-spend')).toBeInTheDocument()
    expect(screen.queryByTestId('t-metrics-loading')).not.toBeInTheDocument()
  })

  it('still says one thing about the filter when the scope really is empty', () => {
    renderStrip({ hasRows: false })

    expect(screen.getByTestId('t-metrics-empty-scope')).toBeInTheDocument()
  })
})
