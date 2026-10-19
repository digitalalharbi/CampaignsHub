import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { ProviderMark, ProviderName } from './ProviderMark'

/**
 * Owner, 2026-10-19: platform names in reports become the platform's logo, or the logo with its name.
 * The logo is the official mark the dashboard filter already ships; the name is never lost.
 */
describe('the platform logo in reports', () => {
  it.each(['snapchat', 'tiktok', 'meta', 'google', 'x', 'linkedin', 'openai_ads'])('draws %s as its own logo, not as initials', (p) => {
    const { container } = render(<ProviderMark provider={p} />)

    expect(screen.getByTestId('provider-mark')).toHaveAttribute('data-logo', 'true')
    expect(container.querySelector('svg path')).not.toBeNull()
    expect(screen.getByTestId('provider-mark').textContent).toBe('')
  })

  it('falls back to initials for a platform it has no logo for, never a blank', () => {
    render(<ProviderMark provider="some_new_network" />)

    expect(screen.getByTestId('provider-mark')).toHaveAttribute('data-logo', 'false')
    expect(screen.getByTestId('provider-mark').textContent).toBe('··')
  })

  it('is decorative on its own: hidden from assistive technology', () => {
    render(<ProviderMark provider="meta" />)

    expect(screen.getByTestId('provider-mark')).toHaveAttribute('aria-hidden', 'true')
  })

  it('shows the logo with the name, in the reader’s language', () => {
    render(<ProviderName provider="snapchat" locale="ar" />)

    expect(screen.getByTestId('provider-name')).toHaveTextContent('سناب شات')
    expect(screen.getByTestId('provider-mark')).toBeInTheDocument()
  })

  it('shows the logo alone where the room is tight, keeping the name for screen readers and on hover', () => {
    render(<ProviderName provider="tiktok" locale="en" logoOnly />)

    const el = screen.getByTestId('provider-name')
    expect(el).toHaveAttribute('title', 'TikTok')
    expect(el.querySelector('.sr-only')).toHaveTextContent('TikTok')
  })
})
