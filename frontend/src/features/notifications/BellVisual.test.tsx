import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { BellVisual } from './BellVisual'

/** DIGESTS-CADENCE-BUDGETS-001 — the bell draws an alert in the email's grammar, and draws nothing it was not given. */
describe('BellVisual', () => {
  it('draws before → now and the ordered steps', () => {
    render(<BellVisual ar visual={{ state: 'watch', viz: { type: 'before_now', before: 100, now: 68, before_label: '4.50×', now_label: '3.10×' }, steps: ['افتح الأقل أداءً', 'جدّده أو أوقفه'] }} />)

    expect(screen.getByTestId('bell-visual').getAttribute('data-viz')).toBe('before_now')
    expect(screen.getByText(/4.50×/)).toBeInTheDocument()
    expect(screen.getByTestId('bell-steps').textContent).toContain('جدّده أو أوقفه')
  })

  it('draws the stages of a sync failure with the broken one marked', () => {
    render(<BellVisual ar={false} visual={{ state: 'bad', viz: { type: 'steps', steps: [{ label: 'Connection', state: 'ok' }, { label: 'Sync', state: 'bad' }] }, steps: [] }} />)

    expect(screen.getByText(/Sync/).getAttribute('data-step-state')).toBe('bad')
  })

  it('renders nothing for a notification that is not an alert', () => {
    const { container } = render(<BellVisual ar visual={{ state: 'none', viz: null, steps: [] }} />)

    expect(container.innerHTML).toBe('')
  })
})
