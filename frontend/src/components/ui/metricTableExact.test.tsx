import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MetricTable } from './MetricTable'

/**
 * NUMBER-PRESENTATION-001 — a compact number is a reading aid, and a lossy one.
 *
 * «1.2M» is any of eleven thousand different figures. Two campaigns both reading «32K» can be a
 * thousand results apart, with nothing on screen to say so, and a media buyer deciding which to
 * scale is choosing between two numbers they have not actually been shown.
 *
 * The product already applies the rule on KPI cards: the exact figure travels WITH the compact one
 * and is one hover or one tap away. This is that rule reaching the TABLES, which is where most of
 * the product's numbers actually live.
 */
describe('the exact figure behind an abbreviation', () => {
  const head = ['Campaign', 'Impressions']
  const rows = [[<span key="n">Eid</span>, <span key="v">90K</span>]]

  it('is reachable from the cell that abbreviated it', () => {
    render(<MetricTable head={head} rows={rows} exact={[[null, '90,000']]} />)

    const cell = screen.getByText('90K').closest('td')
    expect(cell).toHaveAttribute('title', '90,000')
    // And it LOOKS reachable: an affordance nobody can see is not an affordance.
    expect(cell?.className).toContain('decoration-dotted')
  })

  /**
   * A cell with nothing to reveal gets no tooltip.
   *
   * A tooltip that repeats what is already on screen teaches a reader that tooltips are noise, and
   * then they stop hovering over the ones that matter.
   */
  it('is absent where the figure was not abbreviated', () => {
    render(<MetricTable head={head} rows={rows} exact={[[null, null]]} />)

    const cell = screen.getByText('90K').closest('td')
    expect(cell).not.toHaveAttribute('title')
    expect(cell?.className).not.toContain('decoration-dotted')
  })

  /** A table that passes none behaves exactly as it did — this is additive. */
  it('leaves a table with no exact figures untouched', () => {
    render(<MetricTable head={head} rows={rows} />)

    expect(screen.getByText('90K').closest('td')).not.toHaveAttribute('title')
  })
})
