import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { RequestSteps, requestStep } from './RequestSteps'

/** PORTAL-REQUEST-STEPS-001 — جديد / قيد التنفيذ / تم. */
describe('request steps', () => {
  it.each([
    ['new', 0], ['under_review', 1], ['waiting_client', 1], ['in_progress', 1], ['approved', 1],
    ['delivered', 2], ['completed', 2],
  ] as const)('%s is step %s', (status, step) => {
    expect(requestStep(status)).toBe(step)
  })

  it.each(['cancelled', 'rejected', 'archived'])('%s is off the path and draws no bar', (status) => {
    expect(requestStep(status)).toBeNull()
    const { container } = render(<RequestSteps status={status} ar />)
    expect(container).toBeEmptyDOMElement()
  })

  it('marks the current step with the three Arabic labels', () => {
    render(<RequestSteps status="in_progress" ar />)
    const bar = screen.getByTestId('request-steps')
    expect(bar).toHaveTextContent('جديد')
    expect(bar).toHaveTextContent('قيد التنفيذ')
    expect(bar).toHaveTextContent('تم')
    expect(screen.getByText('قيد التنفيذ').closest('li')).toHaveAttribute('aria-current', 'step')
  })
})
