import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { SelectField } from './SelectField'
import type { Option } from './types'

const OPTIONS: Option[] = [
  { value: 'sales', label_en: 'Sales', label_ar: 'المبيعات' },
  { value: 'leads', label_en: 'Leads', label_ar: 'العملاء المحتملون' },
  { value: 'awareness', label_en: 'Awareness', label_ar: 'الوعي' },
  { value: 'traffic', label_en: 'Traffic', label_ar: 'الزيارات', disabled: true },
]

function Harness(props: Partial<React.ComponentProps<typeof SelectField>> = {}) {
  const [value, setValue] = useState<string | null>(null)
  return (
    <SelectField
      label="Objective"
      value={value}
      onChange={setValue}
      options={OPTIONS}
      searchable
      {...props}
    />
  )
}

describe('SelectField', () => {
  it('filters options by the search query', () => {
    renderWithProviders(<Harness />, { locale: 'en' })
    fireEvent.click(screen.getByRole('combobox', { name: 'Objective' }))
    const search = screen.getByPlaceholderText('Search…')
    fireEvent.change(search, { target: { value: 'lea' } })

    const list = screen.getByRole('listbox')
    expect(within(list).getByText('Leads')).toBeInTheDocument()
    expect(within(list).queryByText('Sales')).not.toBeInTheDocument()
  })

  it('selects an option with keyboard (ArrowDown + Enter)', () => {
    const onChange = vi.fn()
    renderWithProviders(<Harness onChange={onChange} />, { locale: 'en' })
    fireEvent.click(screen.getByRole('combobox', { name: 'Objective' }))
    const search = screen.getByPlaceholderText('Search…')
    fireEvent.keyDown(search, { key: 'ArrowDown' }) // active 0 -> 1
    fireEvent.keyDown(search, { key: 'Enter' })
    expect(onChange).toHaveBeenCalledWith('leads')
  })

  it('does not select a disabled option', () => {
    const onChange = vi.fn()
    renderWithProviders(<Harness onChange={onChange} />, { locale: 'en' })
    fireEvent.click(screen.getByRole('combobox', { name: 'Objective' }))
    const disabled = screen.getByRole('option', { name: /Traffic/ })
    expect(disabled).toHaveAttribute('aria-disabled', 'true')
    fireEvent.mouseDown(disabled)
    expect(onChange).not.toHaveBeenCalled()
  })

  it('clears the current value', () => {
    const onChange = vi.fn()
    function Controlled() {
      const [value, setValue] = useState<string | null>('sales')
      return (
        <SelectField
          label="Objective"
          value={value}
          onChange={(v) => {
            setValue(v)
            onChange(v)
          }}
          options={OPTIONS}
        />
      )
    }
    renderWithProviders(<Controlled />, { locale: 'en' })
    fireEvent.click(screen.getByLabelText('Clear selection'))
    expect(onChange).toHaveBeenCalledWith(null)
  })

  it('offers a create row and calls onCreate with the query', () => {
    const onCreate = vi.fn()
    renderWithProviders(<Harness canCreate onCreate={onCreate} />, { locale: 'en' })
    fireEvent.click(screen.getByRole('combobox', { name: 'Objective' }))
    fireEvent.change(screen.getByPlaceholderText('Search…'), { target: { value: 'Retention' } })
    fireEvent.mouseDown(screen.getByRole('option', { name: /Retention/ }))
    expect(onCreate).toHaveBeenCalledWith('Retention')
  })

  it('renders Arabic copy + labels under the RTL (Arabic) locale', () => {
    document.documentElement.setAttribute('dir', 'rtl')
    renderWithProviders(<Harness />, { locale: 'ar' })
    const trigger = screen.getByRole('combobox', { name: 'Objective' })
    // Placeholder + options resolve to the Arabic label variants.
    expect(trigger).toHaveTextContent('اختر…')
    fireEvent.click(trigger)
    expect(within(screen.getByRole('listbox')).getByText('المبيعات')).toBeInTheDocument()
  })
})
