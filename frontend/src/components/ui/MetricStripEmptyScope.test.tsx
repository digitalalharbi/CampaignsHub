import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MetricStrip, type MetricItem } from './MetricStrip'

/**
 * METRICS-EMPTY-SCOPE-001 — a filter that matches nothing speaks about the FILTER.
 *
 * Every card reads its absence from `reported`, which answers «which metric keys are present in
 * this scope». Over an empty scope that answers every key false, so fourteen cards say «لم ترسله
 * المنصة» — a claim about Meta and Snapchat derived from an absence of campaigns.
 *
 * That is what «تغيير الأهداف يجعل كل شيء فارغًا» looked like from the inside: not a broken screen,
 * a screen confidently saying something false.
 */
const NOT_PROVIDED: MetricItem[] = [
  { key: 'impressions', label: 'Impressions', reading: { kind: 'not_provided' } },
  { key: 'clicks', label: 'Clicks', reading: { kind: 'not_provided' } },
]

describe('the metric strip under a filter that matches nothing', () => {
  it('says one true thing about the filter instead of speaking for the platform', () => {
    render(<MetricStrip id="t" ar={false} primary={NOT_PROVIDED} hasRows={false} />)

    expect(screen.getByTestId('t-metrics-empty-scope')).toHaveTextContent('No data matches these filters')
    // The sentence that must NOT appear: it describes a connector, and no connector was asked.
    expect(screen.queryByText('Not provided')).not.toBeInTheDocument()
  })

  it('says it in Arabic too, and names the filter as the thing to change', () => {
    render(<MetricStrip id="t" ar primary={NOT_PROVIDED} hasRows={false} />)

    const panel = screen.getByTestId('t-metrics-empty-scope')
    expect(panel).toHaveTextContent('لا توجد بيانات ضمن هذه الفلاتر')
    expect(panel).toHaveTextContent('هذا ليس نقصًا في بيانات المنصة')
  })

  /**
   * The other half, so the two cannot collapse: a scope that HAS rows keeps every per-metric
   * absence, because there «Not provided» is a real statement about a real answer.
   */
  it('leaves a populated scope alone, absences and all', () => {
    render(<MetricStrip id="t" ar={false} primary={NOT_PROVIDED} hasRows={true} />)

    expect(screen.queryByTestId('t-metrics-empty-scope')).not.toBeInTheDocument()
    expect(screen.getAllByText('Not provided').length).toBeGreaterThan(0)
  })

  /** Callers with no scope to speak of are unchanged — the flag is optional on purpose. */
  it('is inert when the caller passes no scope at all', () => {
    render(<MetricStrip id="t" ar={false} primary={NOT_PROVIDED} />)

    expect(screen.queryByTestId('t-metrics-empty-scope')).not.toBeInTheDocument()
  })
})
