import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { Panel } from './components'
import { renderWithProviders } from '@/test/utils'

/**
 * A panel's action is a control, not a paragraph.
 *
 * A flex item's minimum width is its own content, so a two-word Arabic title with nowhere to wrap
 * squeezed the button beside it: «حد جديد» broke across two lines on a phone, which reads as two
 * stacked controls — and it is the first thing a reader sees on the spend-limits page.
 *
 * Both halves are asserted because either alone leaves the defect: the title must be allowed to
 * shrink (`min-w-0`), and the action must refuse to (`shrink-0`, and no wrap inside it).
 */
describe('a panel keeps its action on one line', () => {
  it('lets the title shrink and refuses to shrink the action', () => {
    renderWithProviders(
      <Panel title="حدود الإنفاق الداخلية" description="حدود تضعها مساحة العمل" action={<button type="button" data-testid="act">حد جديد</button>}>
        <p>body</p>
      </Panel>,
      { locale: 'ar' },
    )

    const action = screen.getByTestId('act').parentElement as HTMLElement
    expect(action.className).toContain('shrink-0')
    expect(action.className).toContain('whitespace-nowrap')

    const heading = screen.getByText('حدود الإنفاق الداخلية').parentElement as HTMLElement
    expect(heading.className).toContain('min-w-0')
  })
})
