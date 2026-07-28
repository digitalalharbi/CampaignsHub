import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { ErrorSummary, FormStepper, ReviewList } from './formFlow'

const steps = [
  { id: 'a', label: 'Services' },
  { id: 'b', label: 'Details' },
  { id: 'c', label: 'Review' },
]

describe('FormStepper', () => {
  it('marks the current step with aria-current and shows all labels', () => {
    render(<FormStepper steps={steps} current={1} />)
    expect(screen.getByText('Services')).toBeInTheDocument()
    expect(screen.getByText('Review')).toBeInTheDocument()
    const current = screen.getByText('Details').closest('[aria-current="step"]')
    expect(current).not.toBeNull()
  })

  it('lets completed steps be clicked to jump back, but not upcoming ones', () => {
    const onStepClick = vi.fn()
    render(<FormStepper steps={steps} current={2} onStepClick={onStepClick} />)
    fireEvent.click(screen.getByText('Services'))
    expect(onStepClick).toHaveBeenCalledWith(0)
    // Upcoming step (none here at current=2) — a step beyond current is not a button.
    render(<FormStepper steps={steps} current={0} onStepClick={onStepClick} />)
    // 'Details' is upcoming when current=0 → rendered as plain text, not a button.
    expect(screen.queryByRole('button', { name: 'Details' })).toBeNull()
  })
})

describe('ErrorSummary', () => {
  it('renders nothing when there are no errors', () => {
    const { container } = render(<ErrorSummary errors={[]} title="Fix these" />)
    expect(container).toBeEmptyDOMElement()
  })

  it('announces errors and focuses the offending field on click', () => {
    render(
      <>
        <input id="email" aria-label="email" />
        <ErrorSummary title="Fix these" errors={[{ field: 'email', message: 'Email is required' }]} />
      </>,
    )
    const summary = screen.getByTestId('error-summary')
    expect(summary).toHaveAttribute('role', 'alert')
    fireEvent.click(screen.getByText('Email is required'))
    expect(document.activeElement).toBe(document.getElementById('email'))
  })
})

describe('ReviewList', () => {
  it('renders key/value rows and shows «—» for empty values', () => {
    render(<ReviewList items={[{ label: 'Budget', value: '5000' }, { label: 'Notes', value: '' }]} />)
    expect(screen.getByText('Budget')).toBeInTheDocument()
    expect(screen.getByText('5000')).toBeInTheDocument()
    expect(screen.getByText('—')).toBeInTheDocument()
  })
})
