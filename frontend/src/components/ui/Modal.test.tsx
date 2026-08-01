import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { Modal } from './Modal'

/**
 * Where an open modal puts the keyboard, and — the part that mattered — when it stops moving it.
 *
 * The defect these cover was a form that emptied itself while you typed. `onClose` is an inline
 * arrow at every call site, so it is a new function on every render of the parent; it was in the
 * focus effect's dependency list, so the effect re-ran on every re-render and re-focused the panel.
 * Any query settling behind the modal — the taxonomy options, the user list — therefore pulled the
 * caret out of the field mid-sentence, and whatever was typed in that instant was lost.
 */
describe('Modal focus', () => {
  /**
   * `[data-autofocus]` wins over an earlier button.
   *
   * A single comma-separated selector returns the first match in DOCUMENT order, so the close
   * button in the title row always won — every modal opened focused on "close" no matter which
   * field it was asking for, and the attribute was decorative.
   */
  it('focuses the field the modal asked for, not the close button', () => {
    render(
      <Modal open onClose={() => {}} title="New campaign">
        <input aria-label="name" data-autofocus />
      </Modal>,
    )

    expect(document.activeElement).toBe(screen.getByLabelText('name'))
  })

  /** With nothing designated, the first focusable element is still a reasonable place to land. */
  it('falls back to the first focusable element', () => {
    render(
      <Modal open onClose={() => {}}>
        <button type="button">First</button>
        <input aria-label="second" />
      </Modal>,
    )

    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'First' }))
  })

  /**
   * The regression itself: a re-render of the PARENT must not move the caret.
   *
   * Rendered through a parent that passes a fresh inline `onClose` each time, because that is what
   * every real call site does and what made the old dependency list re-fire.
   */
  it('does not steal focus back when the parent re-renders', () => {
    function Host() {
      const [, setTick] = useState(0)

      return (
        <>
          <button type="button" onClick={() => setTick((t) => t + 1)}>
            re-render
          </button>
          <Modal open onClose={() => setTick((t) => t)} title="New campaign">
            <input aria-label="name" data-autofocus />
            <input aria-label="notes" />
          </Modal>
        </>
      )
    }

    render(<Host />)

    // The person moves on to the second field and types.
    const notes = screen.getByLabelText('notes')
    notes.focus()
    fireEvent.change(notes, { target: { value: 'half a sentence' } })

    // …and something behind the modal settles, re-rendering the parent.
    fireEvent.click(screen.getByRole('button', { name: 're-render' }))

    expect(document.activeElement).toBe(notes)
    expect((notes as HTMLInputElement).value).toBe('half a sentence')
  })

  /** Escape still closes — the handler is read through a ref, so it must not go stale. */
  it('closes on Escape with the latest handler', () => {
    const seen: string[] = []

    function Host() {
      const [label, setLabel] = useState('first')

      return (
        <>
          <button type="button" onClick={() => setLabel('second')}>
            change handler
          </button>
          <Modal open onClose={() => seen.push(label)}>
            <input aria-label="name" data-autofocus />
          </Modal>
        </>
      )
    }

    render(<Host />)
    fireEvent.click(screen.getByRole('button', { name: 'change handler' }))
    fireEvent.keyDown(document, { key: 'Escape' })

    expect(seen).toEqual(['second'])
  })
})
