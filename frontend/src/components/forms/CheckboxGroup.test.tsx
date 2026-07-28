import { useState } from 'react'
import { describe, expect, it } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { CheckboxGroup } from './CheckboxGroup'
import type { Option } from './types'

const OPTIONS: Option[] = [
  { value: 'image', label_en: 'Image', label_ar: 'صورة' },
  { value: 'video', label_en: 'Video', label_ar: 'فيديو' },
  { value: 'carousel', label_en: 'Carousel', label_ar: 'دوّار', disabled: true },
]

function Harness(props: Partial<React.ComponentProps<typeof CheckboxGroup>> = {}) {
  const [value, setValue] = useState<string[]>(props.value ?? [])
  return <CheckboxGroup label="Creative types" options={OPTIONS} {...props} value={value} onChange={setValue} />
}

describe('CheckboxGroup', () => {
  it('toggles individual checkboxes', () => {
    renderWithProviders(<Harness />, { locale: 'en' })
    const video = screen.getByRole('checkbox', { name: 'Video' })
    fireEvent.click(video)
    expect(video).toBeChecked()
    fireEvent.click(video)
    expect(video).not.toBeChecked()
  })

  it('does not toggle a disabled option', () => {
    renderWithProviders(<Harness />, { locale: 'en' })
    const carousel = screen.getByRole('checkbox', { name: 'Carousel' })
    expect(carousel).toBeDisabled()
  })

  it('select all only picks the enabled options', () => {
    renderWithProviders(<Harness />, { locale: 'en' })
    fireEvent.click(screen.getByRole('checkbox', { name: 'Select all' }))
    expect(screen.getByRole('checkbox', { name: 'Image' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'Video' })).toBeChecked()
    // Disabled option stays unselected.
    expect(screen.getByRole('checkbox', { name: 'Carousel' })).not.toBeChecked()
  })
})
