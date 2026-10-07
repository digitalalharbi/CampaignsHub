import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { StatusMixBar } from './charts'

/**
 * VIZ-MIX-001 — a composition is a SHAPE, and the shape has to be the data's.
 *
 * Four counts beside each other tell a reader what each band holds. They do not tell them that one
 * band holds most of the estate, which is the thing a portfolio reader is actually looking for and
 * the thing a single divided bar says without being read.
 *
 * The cases below are all about the denominator, because that is the only way a composition lies:
 * the parts must be parts OF something, and when they are not, the bar has to decline rather than
 * normalise its way to a total that nothing measured.
 */
const bands = (over: Partial<Record<'a' | 'b', number>> = {}) => [
  { key: 'a', label: 'Active', count: over.a ?? 6, tone: 'success' as const },
  { key: 'b', label: 'Onboarding', count: over.b ?? 2, tone: 'info' as const },
]

describe('StatusMixBar', () => {
  it('draws one segment per band, sized by its share of the whole', () => {
    render(<StatusMixBar testId="mix" total={10} bands={bands()} ar={false} />)

    expect(screen.getByTestId('mix-segment-a')).toHaveStyle({ width: '60%' })
    expect(screen.getByTestId('mix-segment-b')).toHaveStyle({ width: '20%' })
  })

  it('names every band with its count and its share', () => {
    render(<StatusMixBar testId="mix" total={10} bands={bands()} ar={false} />)

    const legend = screen.getByTestId('mix-legend')
    expect(legend).toHaveTextContent('Active')
    expect(legend).toHaveTextContent('6')
    expect(legend).toHaveTextContent('60%')
  })

  it('carries the un-named remainder as its own segment rather than leaving the bar short', () => {
    render(<StatusMixBar testId="mix" total={10} bands={bands()} ar={false} residualLabel="Other" />)

    expect(screen.getByTestId('mix-segment-residual')).toHaveStyle({ width: '20%' })
    expect(screen.getByTestId('mix-legend')).toHaveTextContent('Other')
  })

  it('draws no remainder when the named bands already account for the whole', () => {
    render(<StatusMixBar testId="mix" total={8} bands={bands()} ar={false} residualLabel="Other" />)

    expect(screen.queryByTestId('mix-segment-residual')).toBeNull()
  })

  it('refuses to draw when the bands add up to MORE than the whole they claim to divide', () => {
    render(<StatusMixBar testId="mix" total={5} bands={bands()} ar={false} />)

    expect(screen.queryByTestId('mix-segment-a')).toBeNull()
    expect(screen.getByTestId('mix-undrawable')).toBeInTheDocument()
  })

  it('refuses to draw a share of nothing', () => {
    render(<StatusMixBar testId="mix" total={0} bands={[{ key: 'a', label: 'Active', count: 0, tone: 'success' }]} ar={false} />)

    expect(screen.queryByTestId('mix-segment-a')).toBeNull()
    expect(screen.getByTestId('mix-empty')).toBeInTheDocument()
  })

  it('states the composition in one sentence for a reader who cannot see the bar', () => {
    render(<StatusMixBar testId="mix" total={10} bands={bands()} ar={false} label="Clients" />)

    expect(screen.getByTestId('mix-bar')).toHaveAccessibleName(/Clients/)
    expect(screen.getByTestId('mix-bar')).toHaveAccessibleName(/Active 6/)
  })
})

describe('StatusMixBar divides money as readily as it divides counts', () => {
  const money = [
    { key: 'current', label: 'Current', count: 7500, tone: 'success' as const },
    { key: 'late', label: '1–30 days', count: 2500, tone: 'warning' as const },
  ]

  it('prints each band through the formatter it was given', () => {
    render(
      <StatusMixBar
        testId="aging"
        ar={false}
        total={10_000}
        bands={money}
        format={(v) => `${v.toLocaleString('en-US')} SAR`}
      />,
    )

    expect(screen.getByTestId('aging-legend')).toHaveTextContent('7,500 SAR')
  })

  it('sizes the bands by their share, whatever the formatter prints', () => {
    render(<StatusMixBar testId="aging" ar={false} total={10_000} bands={money} format={(v) => `${v} SAR`} />)

    expect(screen.getByTestId('aging-segment-current')).toHaveStyle({ width: '75%' })
  })

  it('speaks the formatted figure, not the raw one', () => {
    render(
      <StatusMixBar
        testId="aging"
        ar={false}
        label="Outstanding"
        total={10_000}
        bands={money}
        format={(v) => `${v.toLocaleString('en-US')} SAR`}
      />,
    )

    expect(screen.getByTestId('aging-bar')).toHaveAccessibleName(/7,500 SAR/)
  })

  it('still counts plainly when no formatter is given', () => {
    render(<StatusMixBar testId="mix" ar={false} total={10} bands={bands()} />)

    expect(screen.getByTestId('mix-legend')).toHaveTextContent('6')
  })
})
