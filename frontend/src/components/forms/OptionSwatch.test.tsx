import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { OptionSwatch } from './internals'

/**
 * The taxonomy stores icons as lucide NAMES. Rendering that string put words like "calendar-check"
 * on top of the Arabic labels in the service picker. A swatch must resolve a name to its icon, and
 * must never emit text that can outgrow its 16px box.
 */
describe('OptionSwatch', () => {
  it('renders a lucide icon when the option carries an icon name', () => {
    const { container } = render(<OptionSwatch option={{ value: 'a', label: 'A', icon: 'rocket' }} />)
    expect(container.querySelector('svg')).not.toBeNull()
    expect(container.textContent).toBe('')
  })

  it('resolves kebab-case names too', () => {
    const { container } = render(<OptionSwatch option={{ value: 'a', label: 'A', icon: 'calendar-check' }} />)
    expect(container.querySelector('svg')).not.toBeNull()
    expect(container.textContent).toBe('')
  })

  it('never prints more than one character for an unknown icon token', () => {
    const { container } = render(<OptionSwatch option={{ value: 'a', label: 'A', icon: 'not-a-real-icon-name' }} />)
    expect(container.querySelector('svg')).toBeNull()
    expect(container.textContent?.length).toBeLessThanOrEqual(1)
  })

  it('prefers a colour dot when the option has a colour', () => {
    const { container } = render(<OptionSwatch option={{ value: 'a', label: 'A', color: '#0f0', icon: 'rocket' }} />)
    expect(container.querySelector('svg')).toBeNull()
    expect(container.firstElementChild).toHaveStyle({ backgroundColor: '#0f0' })
  })

  it('renders nothing when the option carries neither', () => {
    const { container } = render(<OptionSwatch option={{ value: 'a', label: 'A' }} />)
    expect(container.firstChild).toBeNull()
    expect(screen.queryByRole('img')).toBeNull()
  })
})
