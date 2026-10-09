import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { DeliveryBadge } from './DeliveryBadge'
import { relevanceOf } from '@/features/campaigns/campaignRelevance'

/**
 * CONTENT-BROWSER-PARITY-001 — the marker the owner asked for, and the rule behind it.
 *
 * «إذا كان هناك علامة لتوضيح هل المحتوى فعال أو واقف في المنصة جدا ممتاز». It is not decoration:
 * the library now puts what is running above what has stopped, and a reader who cannot see why one
 * card sits above another is back to an order they cannot account for.
 */
describe('the delivery marker on a creative', () => {
  it.each([
    ['serving', 'يعمل'],
    ['idle', 'خامل'],
    ['stopped', 'متوقف'],
  ] as const)('says %s as «%s»', (state, word) => {
    render(<DeliveryBadge state={state} ar />)

    expect(screen.getByTestId('creative-delivery-state')).toHaveAttribute('data-state', state)
    expect(screen.getByTestId('creative-delivery-state')).toHaveTextContent(word)
  })

  /**
   * Three states, and the middle one earns its place.
   *
   * «Switched on and producing nothing» is a different thing from «switched off», and it is the one
   * an operator can still act on. Collapsing it into either neighbour would hide exactly the case
   * worth finding.
   */
  it('keeps idle distinct from stopped', () => {
    const { rerender } = render(<DeliveryBadge state="idle" ar />)
    const idle = screen.getByTestId('creative-delivery-state').textContent

    rerender(<DeliveryBadge state="stopped" ar />)

    expect(screen.getByTestId('creative-delivery-state').textContent).not.toBe(idle)
  })
})

/**
 * The rule it reads, at the creative's two facts — the SAME `relevanceOf` the campaigns workspace
 * and the analytics rows use. A fourth reading of `status` is how surfaces come to disagree about
 * which things are live.
 */
describe('what the marker is read from', () => {
  const windowEnd = '2026-08-30'

  it('calls a paused creative stopped however much it spent', () => {
    expect(relevanceOf({ status: 'paused', last_active_on: '2026-08-29' }, windowEnd)).toBe('stopped')
  })

  /**
   * Historical spend never implies «active» — §10, and the reason this badge exists.
   *
   * The two facts are the status and the last ACTIVE date. Nothing here consults a figure, so a
   * creative that spent 5,000 last month and stopped yesterday reads as stopped.
   */
  it('reads the last active date, never whether figures exist', () => {
    expect(relevanceOf({ status: 'active', last_active_on: '2026-08-29' }, windowEnd)).toBe('serving')
    expect(relevanceOf({ status: 'active', last_active_on: '2026-08-01' }, windowEnd)).toBe('idle')
  })

  /**
   * A platform that said nothing is not a platform that said «stopped».
   *
   * Treating missing information as a claim that delivery ended would be inventing the answer, and
   * on this card it would print «متوقف» over a creative nobody has any evidence about.
   */
  it('does not read silence as stopped', () => {
    expect(relevanceOf({ status: null, last_active_on: null }, windowEnd)).toBe('idle')
    expect(relevanceOf({ status: 'unknown', last_active_on: null }, windowEnd)).toBe('idle')
  })

  /**
   * «Still running» is measured against the WINDOW's end, not against today.
   *
   * A reader looking at last quarter is asking whether these ran THEN; judging a September creative
   * against today's date would mark the whole quarter stopped.
   */
  it('measures against the window the reader is looking at', () => {
    expect(relevanceOf({ status: 'active', last_active_on: '2026-08-29' }, '2026-08-30')).toBe('serving')
    expect(relevanceOf({ status: 'active', last_active_on: '2026-08-29' }, '2026-12-30')).toBe('idle')
  })
})
