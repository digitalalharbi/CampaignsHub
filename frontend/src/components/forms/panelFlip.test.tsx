import { describe, expect, it } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { MultiSelectField } from './MultiSelectField'
import { renderWithProviders } from '@/test/utils'

const options = [{ value: 'a', label: 'Alpha' }, { value: 'b', label: 'Bravo' }]

function place(bottom: number) {
  Element.prototype.getBoundingClientRect = function () {
    return { top: bottom - 56, bottom, left: 0, right: 300, width: 300, height: 56, x: 0, y: bottom - 56, toJSON: () => ({}) } as DOMRect
  }
}

/** DROPDOWN-FLIP-001 — a list near the bottom of the screen opens upward; with room below it opens downward. */
describe('select panels choose the side with room', () => {
  it('opens upward at the bottom of the screen', () => {
    place(window.innerHeight - 20)
    renderWithProviders(<MultiSelectField label="Services" options={options as never} value={[]} onChange={() => undefined} />)
    fireEvent.click(screen.getByRole('combobox'))
    expect(screen.getByRole('listbox').closest('div.absolute')?.className).toContain('bottom-full')
  })

  it('opens downward with room below', () => {
    place(120)
    renderWithProviders(<MultiSelectField label="Services" options={options as never} value={[]} onChange={() => undefined} />)
    fireEvent.click(screen.getByRole('combobox'))
    expect(screen.getByRole('listbox').closest('div.absolute')?.className).toContain('top-full')
  })
})
