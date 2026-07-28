import { useState } from 'react'
import { describe, expect, it } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { TagInput } from './TagInput'

function Harness(props: Partial<React.ComponentProps<typeof TagInput>> = {}) {
  const [value, setValue] = useState<string[]>(props.value ?? [])
  return <TagInput label="Tags" {...props} value={value} onChange={setValue} />
}

describe('TagInput', () => {
  it('commits a tag on Enter and on comma', () => {
    renderWithProviders(<Harness />, { locale: 'en' })
    const input = screen.getByRole('combobox')
    fireEvent.change(input, { target: { value: 'ramadan' } })
    fireEvent.keyDown(input, { key: 'Enter' })
    expect(screen.getByText('ramadan')).toBeInTheDocument()

    fireEvent.change(input, { target: { value: 'vip' } })
    fireEvent.keyDown(input, { key: ',' })
    expect(screen.getByText('vip')).toBeInTheDocument()
  })

  it('removes the last tag on Backspace when the field is empty', () => {
    renderWithProviders(<Harness value={['a', 'b']} />, { locale: 'en' })
    const input = screen.getByRole('combobox')
    fireEvent.keyDown(input, { key: 'Backspace' })
    expect(screen.queryByText('b')).not.toBeInTheDocument()
    expect(screen.getByText('a')).toBeInTheDocument()
  })

  it('enforces the max and rejects duplicates', () => {
    renderWithProviders(<Harness value={['one']} max={1} />, { locale: 'en' })
    const input = screen.getByRole('combobox') as HTMLInputElement
    expect(input).toBeDisabled()
  })

  it('adds a tag from the suggestion list', () => {
    renderWithProviders(<Harness suggestions={['premium', 'basic']} />, { locale: 'en' })
    const input = screen.getByRole('combobox')
    fireEvent.focus(input)
    fireEvent.change(input, { target: { value: 'prem' } })
    fireEvent.mouseDown(screen.getByRole('option', { name: 'premium' }))
    expect(screen.getByText('premium')).toBeInTheDocument()
  })
})
