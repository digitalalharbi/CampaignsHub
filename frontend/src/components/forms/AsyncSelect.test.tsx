import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { AsyncSelect } from './AsyncSelect'
import type { Option } from './types'

const ALL: Option[] = [
  { value: 'riyadh', label_en: 'Riyadh', label_ar: 'الرياض' },
  { value: 'jeddah', label_en: 'Jeddah', label_ar: 'جدة' },
  { value: 'dammam', label_en: 'Dammam', label_ar: 'الدمام' },
]

function Harness(props: Partial<React.ComponentProps<typeof AsyncSelect>> = {}) {
  const [value, setValue] = useState<string | null>(null)
  const loadOptions = props.loadOptions ?? (async (q: string) =>
    ALL.filter((o) => (o.label_en ?? '').toLowerCase().includes(q.toLowerCase())))
  return <AsyncSelect label="City" {...props} loadOptions={loadOptions} value={value} onChange={setValue} />
}

describe('AsyncSelect', () => {
  it('loads options on open and after a debounced search, then selects one', async () => {
    const loadOptions = vi.fn(async (q: string) =>
      ALL.filter((o) => (o.label_en ?? '').toLowerCase().includes(q.toLowerCase())))
    const onChange = vi.fn()

    function Controlled() {
      const [value, setValue] = useState<string | null>(null)
      return (
        <AsyncSelect
          label="City"
          value={value}
          onChange={(v) => {
            setValue(v)
            onChange(v)
          }}
          loadOptions={loadOptions}
          debounceMs={10}
        />
      )
    }

    renderWithProviders(<Controlled />, { locale: 'en' })
    fireEvent.click(screen.getByRole('combobox', { name: 'City' }))

    // Initial load with an empty query resolves all cities.
    await waitFor(() => expect(loadOptions).toHaveBeenCalledWith(''))
    await screen.findByRole('option', { name: 'Riyadh' })

    fireEvent.change(screen.getByPlaceholderText('Search…'), { target: { value: 'jed' } })
    await waitFor(() => expect(loadOptions).toHaveBeenCalledWith('jed'))
    const jeddah = await screen.findByRole('option', { name: 'Jeddah' })
    fireEvent.mouseDown(jeddah)
    expect(onChange).toHaveBeenCalledWith('jeddah')
  })

  it('surfaces a load error in the panel', async () => {
    renderWithProviders(
      <Harness loadOptions={async () => { throw new Error('boom') }} debounceMs={5} />,
      { locale: 'en' },
    )
    fireEvent.click(screen.getByRole('combobox', { name: 'City' }))
    expect(await screen.findByText('boom')).toBeInTheDocument()
  })
})
