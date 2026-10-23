import { describe, expect, it } from 'vitest'
import { cleanAnswers, fieldError, isVisible, stepErrors, visibleFields } from './formLogic'
import type { FormDefinition } from './types'

const definition: FormDefinition = {
  steps: [
    { id: 's1', title: 'You', fields: [
      { id: 'full_name', type: 'text', label: 'Name', required: true, maps_to: 'name' },
      { id: 'mobile', type: 'phone', label: 'Mobile', required: true, maps_to: 'phone' },
    ] },
    { id: 's2', title: 'Interest', fields: [
      { id: 'interest', type: 'select', label: 'Interest', required: true, options: [{ value: 'villa', label: 'Villa' }, { value: 'land', label: 'Land' }] },
      { id: 'budget', type: 'number', label: 'Budget', validation: { min: 100000 }, show_if: { field: 'interest', op: 'equals', value: 'villa' } },
    ] },
  ],
  consent: { required: true, text: 'ok' },
  thank_you: { message: 'Thanks', redirect_url: null },
  style: { accent: null, theme: 'auto' },
}

describe('what a form asks, and what it will accept', () => {
  it('asks a conditional field only while its condition holds, judged across steps', () => {
    expect(visibleFields(definition, { interest: 'land' }).map((f) => f.id)).not.toContain('budget')
    expect(visibleFields(definition, { interest: 'villa' }).map((f) => f.id)).toContain('budget')
    expect(isVisible(definition.steps[1].fields[1], {})).toBe(false)
  })

  it('does not send an answer to a field the logic now hides', () => {
    expect(cleanAnswers(definition, { full_name: 'S', mobile: '0500000001', interest: 'land', budget: 500000 })).toEqual({ full_name: 'S', mobile: '0500000001', interest: 'land' })
    expect(cleanAnswers(definition, { full_name: 'S', mobile: '0500000001', interest: 'villa', budget: '250000' })).toMatchObject({ budget: 250000 })
  })

  it('checks each type by its own rule, in both languages', () => {
    const f = (type: 'email' | 'phone' | 'number', validation?: { min?: number }) => ({ id: 'x', type, label: 'x', required: true, validation })
    expect(fieldError(f('email'), 'nope', false)).toMatch(/valid email/)
    expect(fieldError(f('email'), 'a@b.co', false)).toBeNull()
    expect(fieldError(f('phone'), '12', true)).toMatch(/جوال/)
    expect(fieldError(f('number', { min: 5 }), '3', false)).toMatch(/At least 5/)
    expect(fieldError(f('number'), '', false)).toMatch(/required/)
  })

  it('reports errors only for the step being left and only for fields that are asked', () => {
    expect(Object.keys(stepErrors(definition, 0, {}, false))).toEqual(['full_name', 'mobile'])
    // «budget» is hidden for land, so an empty budget is not an error even though it would be invalid if asked.
    expect(stepErrors(definition, 1, { interest: 'land' }, false)).toEqual({})
    expect(Object.keys(stepErrors(definition, 1, { interest: 'villa', budget: '5' }, false))).toEqual(['budget'])
  })
})
