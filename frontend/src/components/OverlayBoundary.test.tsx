import { describe, expect, it, vi, afterEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { OverlayBoundary } from './OverlayBoundary'

/**
 * The overlay is allowed to fail. The application is not allowed to fail with it.
 *
 * `UpgradeRequiredDialog` is mounted above the router, where this application has no error handling
 * at all — `errorElement` covers route elements only. The first live run of that dialog threw and
 * took the entire interface with it, and an overlay that only appears when something has already
 * gone wrong is the worst possible place to learn that.
 */
describe('OverlayBoundary', () => {
  afterEach(() => vi.restoreAllMocks())

  function Boom(): never {
    throw new Error('overlay exploded')
  }

  it('renders its child when the child is fine', () => {
    render(<OverlayBoundary><p>the prompt</p></OverlayBoundary>)

    expect(screen.getByText('the prompt')).toBeInTheDocument()
  })

  it('renders nothing — not a second error — when the child throws', () => {
    // React logs the caught error itself; silenced so the suite output stays readable.
    vi.spyOn(console, 'error').mockImplementation(() => {})

    const { container } = render(
      <OverlayBoundary><Boom /></OverlayBoundary>,
    )

    expect(container).toBeEmptyDOMElement()
  })

  /** Swallowed silently would be worse than the crash: it is how a broken overlay stays broken. */
  it('reports the failure to the console', () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {})

    render(<OverlayBoundary><Boom /></OverlayBoundary>)

    expect(spy.mock.calls.some((args) => String(args[0]).includes('[overlay]'))).toBe(true)
  })

  /** The siblings of a failed overlay keep rendering — that is the whole point. */
  it('does not take the rest of the tree down with it', () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})

    render(
      <div>
        <p>the application</p>
        <OverlayBoundary><Boom /></OverlayBoundary>
      </div>,
    )

    expect(screen.getByText('the application')).toBeInTheDocument()
  })
})
