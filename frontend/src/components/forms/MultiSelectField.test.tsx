import { useState } from 'react'
import { describe, expect, it } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { MultiSelectField } from './MultiSelectField'
import type { Option } from './types'

const OPTIONS: Option[] = [
  { value: 'meta', label_en: 'Meta', label_ar: 'ميتا' },
  { value: 'google', label_en: 'Google', label_ar: 'جوجل' },
  { value: 'tiktok', label_en: 'TikTok', label_ar: 'تيك توك' },
  { value: 'snapchat', label_en: 'Snapchat', label_ar: 'سناب شات' },
]

function Harness({ value: initial, ...props }: Partial<React.ComponentProps<typeof MultiSelectField>> = {}) {
  const [value, setValue] = useState<string[]>(initial ?? [])
  return <MultiSelectField label="Platforms" options={OPTIONS} searchable {...props} value={value} onChange={setValue} />
}

describe('MultiSelectField', () => {
  it('selects multiple options and shows removable chips', () => {
    renderWithProviders(<Harness />, { locale: 'en' })
    fireEvent.click(screen.getByRole('combobox', { name: 'Platforms' }))
    fireEvent.mouseDown(screen.getByRole('option', { name: 'Meta' }))
    fireEvent.mouseDown(screen.getByRole('option', { name: 'Google' }))

    const trigger = screen.getByRole('combobox', { name: 'Platforms' })
    expect(within(trigger).getByText('Meta')).toBeInTheDocument()
    expect(within(trigger).getByText('Google')).toBeInTheDocument()
  })

  it('removes a selection via its chip × button', () => {
    renderWithProviders(<Harness value={['meta', 'google']} />, { locale: 'en' })
    const trigger = screen.getByRole('combobox', { name: 'Platforms' })
    const removeButtons = within(trigger).getAllByLabelText('Remove')
    fireEvent.click(removeButtons[0])
    expect(within(trigger).queryByText('Meta')).not.toBeInTheDocument()
    expect(within(trigger).getByText('Google')).toBeInTheDocument()
  })

  it('enforces maxSelections — extra options become non-selectable', () => {
    renderWithProviders(<Harness value={['meta']} maxSelections={1} />, { locale: 'en' })
    fireEvent.click(screen.getByRole('combobox', { name: 'Platforms' }))
    const google = screen.getByRole('option', { name: 'Google' })
    expect(google).toHaveAttribute('aria-disabled', 'true')
    fireEvent.mouseDown(google)
    const trigger = screen.getByRole('combobox', { name: 'Platforms' })
    expect(within(trigger).queryByText('Google')).not.toBeInTheDocument()
  })

  it('select all then clear all', () => {
    renderWithProviders(<Harness />, { locale: 'en' })
    fireEvent.click(screen.getByRole('combobox', { name: 'Platforms' }))
    fireEvent.mouseDown(screen.getByText('Select all'))
    let trigger = screen.getByRole('combobox', { name: 'Platforms' })
    expect(within(trigger).getByText('Meta')).toBeInTheDocument()
    expect(within(trigger).getByText('Snapchat')).toBeInTheDocument()

    fireEvent.mouseDown(screen.getByText('Clear all'))
    trigger = screen.getByRole('combobox', { name: 'Platforms' })
    expect(within(trigger).queryByText('Meta')).not.toBeInTheDocument()
  })

  it('filters options by search', () => {
    renderWithProviders(<Harness />, { locale: 'en' })
    fireEvent.click(screen.getByRole('combobox', { name: 'Platforms' }))
    fireEvent.change(screen.getByPlaceholderText('Search…'), { target: { value: 'tik' } })
    const list = screen.getByRole('listbox')
    expect(within(list).getByText('TikTok')).toBeInTheDocument()
    expect(within(list).queryByText('Meta')).not.toBeInTheDocument()
  })
})
