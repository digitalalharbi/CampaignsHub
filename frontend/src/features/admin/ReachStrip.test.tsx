import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { ReachStrip } from './ReachStrip'

/**
 * NOTIFICATION-ENROLMENT-001 — «is it effective?» answered on the platform email page, in chips and numbers.
 */
const never = { daily: null, weekly: null, monthly: null }

describe('the reach readout', () => {
  it('states the four numbers and, when people are not enrolled, how many get nothing', () => {
    render(<ReachStrip ar={false} reach={{ members: 10, verified: 10, enrolled: 1, digest_on: 0, last_digest: never }} />)

    expect(screen.getByTestId('reach-members')).toHaveTextContent('10')
    expect(screen.getByTestId('reach-verified')).toHaveTextContent('10')
    expect(screen.getByTestId('reach-enrolled')).toHaveTextContent('1')
    expect(screen.getByTestId('reach-digest-on')).toHaveTextContent('0')
    expect(screen.getByTestId('reach-gap')).toHaveTextContent('9 get nothing')
    expect(screen.getByTestId('reach-enrolled').querySelector('div')).toHaveClass('text-warning')
  })

  it('says «never sent» for a rhythm that has not run, and the date for one that has', () => {
    render(<ReachStrip ar reach={{ members: 3, verified: 3, enrolled: 3, digest_on: 3, last_digest: { daily: '2026-11-06T05:00:00+00:00', weekly: null, monthly: null } }} />)

    expect(screen.getByTestId('reach-last-daily')).toHaveTextContent(/2026/)
    expect(screen.getByTestId('reach-last-daily')).toHaveTextContent(/11/)
    expect(screen.getByTestId('reach-last-weekly')).toHaveTextContent('لم يُرسل')
    expect(screen.getByTestId('reach-last-monthly')).toHaveTextContent('لم يُرسل')
    expect(screen.queryByTestId('reach-gap')).toBeNull()
    expect(screen.getByTestId('reach-enrolled').querySelector('div')).toHaveClass('text-success')
  })
})
