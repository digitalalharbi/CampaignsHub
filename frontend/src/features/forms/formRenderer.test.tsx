import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { FormRenderer } from './FormRenderer'
import { starterDefinition } from './builderState'
import type { FormDefinition } from './types'

const twoStep = (): FormDefinition => {
  const d = starterDefinition()
  d.steps.push({ id: 's2', title: { en: 'Interest' }, fields: [
    { id: 'interest', type: 'select', label: { en: 'Interest' }, required: true, options: [{ value: 'villa', label: 'Villa' }, { value: 'land', label: 'Land' }] },
    { id: 'budget', type: 'number', label: { en: 'Budget' }, show_if: { field: 'interest', op: 'equals', value: 'villa' } },
  ] })

  return d
}

describe('the form a visitor meets', () => {
  it('walks the steps, shows a conditional field only when asked, and sends only what is asked', () => {
    const onSubmit = vi.fn()
    render(<FormRenderer definition={twoStep()} ar={false} onSubmit={onSubmit} />)

    fireEvent.click(screen.getByTestId('form-next'))
    expect(screen.getAllByRole('alert')).toHaveLength(2)

    fireEvent.change(screen.getByTestId('field-full_name'), { target: { value: 'Sara' } })
    fireEvent.change(screen.getByTestId('field-mobile'), { target: { value: '0500000001' } })
    fireEvent.click(screen.getByTestId('form-next'))

    expect(screen.getByTestId('form-progress')).toHaveTextContent('Step 2 of 2')
    expect(screen.queryByTestId('field-budget')).toBeNull()
    fireEvent.change(screen.getByTestId('field-interest'), { target: { value: 'villa' } })
    fireEvent.change(screen.getByTestId('field-budget'), { target: { value: '250000' } })
    fireEvent.change(screen.getByTestId('field-interest'), { target: { value: 'land' } })
    expect(screen.queryByTestId('field-budget')).toBeNull()

    fireEvent.click(screen.getByTestId('form-next'))
    expect(screen.getByTestId('form-consent-error')).toBeInTheDocument()
    expect(onSubmit).not.toHaveBeenCalled()

    fireEvent.click(screen.getByTestId('form-consent'))
    fireEvent.click(screen.getByTestId('form-next'))
    expect(onSubmit).toHaveBeenCalledWith({ full_name: 'Sara', mobile: '0500000001', interest: 'land' }, true)
  })

  it('a preview never sends', () => {
    const onSubmit = vi.fn()
    render(<FormRenderer definition={starterDefinition()} ar preview onSubmit={onSubmit} />)
    fireEvent.change(screen.getByTestId('field-full_name'), { target: { value: 'س' } })
    fireEvent.change(screen.getByTestId('field-mobile'), { target: { value: '0500000001' } })
    fireEvent.click(screen.getByTestId('form-consent'))
    fireEvent.click(screen.getByTestId('form-next'))
    expect(onSubmit).not.toHaveBeenCalled()
  })

  it('goes back without losing what was typed', () => {
    render(<FormRenderer definition={twoStep()} ar={false} onSubmit={vi.fn()} />)
    fireEvent.change(screen.getByTestId('field-full_name'), { target: { value: 'Sara' } })
    fireEvent.change(screen.getByTestId('field-mobile'), { target: { value: '0500000001' } })
    fireEvent.click(screen.getByTestId('form-next'))
    fireEvent.click(screen.getByTestId('form-back'))
    expect((screen.getByTestId('field-full_name') as HTMLInputElement).value).toBe('Sara')
  })
})
