import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { CreatableSelect } from './CreatableSelect'
import type { Option, OptionDraft } from './types'

const OPTIONS: Option[] = [
  { value: 'website', label_en: 'Website', label_ar: 'الموقع' },
  { value: 'referral', label_en: 'Referral', label_ar: 'إحالة' },
]

describe('CreatableSelect', () => {
  it('creates a new option through the drawer and selects it without a refresh', async () => {
    // Injected create fn — resolves with the new Option (as the taxonomy API would).
    const onCreate = vi.fn(async (draft: OptionDraft): Promise<Option> => ({
      value: 'event',
      label_en: draft.label_en,
      label_ar: draft.label_ar,
    }))
    const onChange = vi.fn()

    function Harness() {
      const [value, setValue] = useState<string | null>(null)
      return (
        <CreatableSelect
          label="Source"
          value={value}
          onChange={(v) => {
            setValue(v)
            onChange(v)
          }}
          options={OPTIONS}
          onCreate={onCreate}
          searchable
        />
      )
    }

    renderWithProviders(<Harness />, { locale: 'en' })
    fireEvent.click(screen.getByRole('combobox', { name: 'Source' }))
    fireEvent.change(screen.getByPlaceholderText('Search…'), { target: { value: 'Event' } })
    // Click the "+ add …" row → opens the drawer.
    fireEvent.mouseDown(screen.getByRole('option', { name: /Event/ }))

    const drawer = await screen.findByRole('dialog')
    // English label was pre-seeded from the query.
    const enInput = within(drawer).getByLabelText('Name (English)') as HTMLInputElement
    expect(enInput.value).toBe('Event')
    fireEvent.click(within(drawer).getByText('Save'))

    await waitFor(() => expect(onCreate).toHaveBeenCalledTimes(1))
    // The new option is selected (no full refresh) and shows on the trigger.
    await waitFor(() => expect(onChange).toHaveBeenCalledWith('event'))
    await waitFor(() =>
      expect(screen.getByRole('combobox', { name: 'Source' })).toHaveTextContent('Event'),
    )
  })
})
