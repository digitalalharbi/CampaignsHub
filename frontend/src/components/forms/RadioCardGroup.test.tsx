import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { RadioCardGroup, type RadioCardOption } from './RadioCardGroup'

const OPTIONS: RadioCardOption[] = [
  { value: 'basic', label_en: 'Basic', label_ar: 'أساسي', description: 'Entry tier' },
  { value: 'pro', label_en: 'Pro', label_ar: 'احترافي', description: 'Most popular' },
  { value: 'ent', label_en: 'Enterprise', label_ar: 'مؤسسي', disabled: true },
]

function Harness(props: Partial<React.ComponentProps<typeof RadioCardGroup>> = {}) {
  const [value, setValue] = useState<string | null>(props.value ?? null)
  return <RadioCardGroup label="Plan" options={OPTIONS} {...props} value={value} onChange={setValue} />
}

describe('RadioCardGroup', () => {
  it('selects a card and marks it aria-checked', () => {
    renderWithProviders(<Harness />, { locale: 'en' })
    const pro = screen.getByRole('radio', { name: /Pro/ })
    fireEvent.click(pro)
    expect(pro).toBeChecked()
  })

  it('does not select a disabled card', () => {
    const onChange = vi.fn()
    renderWithProviders(
      <RadioCardGroup label="Plan" options={OPTIONS} value={null} onChange={onChange} />,
      { locale: 'en' },
    )
    const ent = screen.getByRole('radio', { name: /Enterprise/ })
    expect(ent).toBeDisabled()
    fireEvent.click(ent)
    expect(onChange).not.toHaveBeenCalled()
  })

  it('renders a radiogroup with all options as radios', () => {
    renderWithProviders(<Harness />, { locale: 'en' })
    expect(screen.getByRole('radiogroup', { name: 'Plan' })).toBeInTheDocument()
    expect(screen.getAllByRole('radio')).toHaveLength(3)
  })
})
