import { describe, expect, it } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { Explainer } from '@/components/ui/Explainer'

/**
 * VISUAL-FIRST-001 — the disclosure primitive keeps its promise in both directions.
 *
 * «Move deeper explanation to tooltip → expandable details → modal/drawer → evidence section … the
 * user must be able to scan the product in seconds without reading paragraphs.»
 *
 * Two failures are possible here and both are silent. A disclosure that renders its body anyway has
 * changed nothing — the paragraph is still on the page, now with a chevron above it. A disclosure
 * that cannot be opened has DELETED the explanation, and a data limitation a reader cannot reach is
 * worse than one they had to scroll past.
 */
describe('a disclosed explanation is hidden until asked, and reachable when asked', () => {
  const body = 'Reach is not summed across platforms as unique reach — it is shown per platform.'

  it('does not render the explanation until it is opened', () => {
    render(<Explainer label="How reach is counted" testid="x">{body}</Explainer>)

    expect(screen.queryByTestId('x-body')).not.toBeInTheDocument()
    expect(screen.queryByText(body)).not.toBeInTheDocument()
    // The LABEL is what stays on the page — a chevron with no subject is not a disclosure.
    expect(screen.getByTestId('x-toggle')).toHaveTextContent('How reach is counted')
  })

  it('renders the whole explanation once opened', () => {
    render(<Explainer label="How reach is counted" testid="x">{body}</Explainer>)

    fireEvent.click(screen.getByTestId('x-toggle'))

    expect(screen.getByTestId('x-body')).toHaveTextContent(body)
  })

  it('closes again, so the page returns to what it was', () => {
    render(<Explainer label="How reach is counted" testid="x">{body}</Explainer>)

    fireEvent.click(screen.getByTestId('x-toggle'))
    fireEvent.click(screen.getByTestId('x-toggle'))

    expect(screen.queryByTestId('x-body')).not.toBeInTheDocument()
  })

  /** A screen reader is told the state, because a chevron is not an announcement. */
  it('states whether it is open', () => {
    render(<Explainer label="How reach is counted" testid="x">{body}</Explainer>)

    expect(screen.getByTestId('x-toggle')).toHaveAttribute('aria-expanded', 'false')
    fireEvent.click(screen.getByTestId('x-toggle'))
    expect(screen.getByTestId('x-toggle')).toHaveAttribute('aria-expanded', 'true')
  })
})
