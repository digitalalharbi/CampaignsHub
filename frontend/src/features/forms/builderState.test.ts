import { describe, expect, it } from 'vitest'
import { addField, addStep, earlierFields, moveField, optionsToText, removeField, retype, starterDefinition, textToOptions, updateField } from './builderState'

describe('the builder’s edits', () => {
  it('starts from a form the server accepts: a mapped name, a reachable contact, consent', () => {
    const d = starterDefinition()
    const maps = d.steps.flatMap((s) => s.fields).map((f) => f.maps_to).filter(Boolean)
    expect(maps).toEqual(['name', 'phone'])
    expect(d.consent.required).toBe(true)
  })

  it('adds fields with unique ids and options where a type needs them', () => {
    let d = addField(starterDefinition(), 0, 'select')
    d = addField(d, 0, 'select')
    const ids = d.steps[0].fields.map((f) => f.id)
    expect(new Set(ids).size).toBe(ids.length)
    expect(d.steps[0].fields[2].options?.length).toBe(1)
  })

  it('keeps a condition pointing backwards: a field never moves above the one it depends on', () => {
    let d = addField(addField(starterDefinition(), 0, 'select'), 0, 'text')
    const [, , a, b] = d.steps[0].fields
    d = updateField(d, 0, 3, { show_if: { field: a.id, op: 'filled', value: null } })
    expect(moveField(d, 0, 3, -1)).toBe(d)
    expect(b.id).toBe(d.steps[0].fields[3].id)
    expect(earlierFields(d, 0, 3).map((f) => f.id)).toContain(a.id)
  })

  it('removing a field drops the conditions that pointed at it', () => {
    let d = addField(addField(starterDefinition(), 0, 'select'), 0, 'text')
    const target = d.steps[0].fields[2].id
    d = updateField(d, 0, 3, { show_if: { field: target, op: 'filled', value: null } })
    d = removeField(d, 0, 2)
    expect(d.steps[0].fields[2].show_if).toBeUndefined()
  })

  it('a retyped field keeps only what its new type can carry', () => {
    let d = addField(starterDefinition(), 0, 'select')
    d = retype(d, 0, 2, 'text')
    expect(d.steps[0].fields[2].options).toBeUndefined()
    // The name field is text mapped to name; as an email it can no longer be the name.
    d = retype(d, 0, 0, 'email')
    expect(d.steps[0].fields[0].maps_to).toBeUndefined()
  })

  it('round-trips options as value|label lines, dropping blanks and repeats', () => {
    const options = textToOptions('villa|Villa\nland\n\nvilla|Again')
    expect(options.map((o) => o.value)).toEqual(['villa', 'land'])
    expect(optionsToText(options)).toBe('villa|Villa\nland')
    expect(addStep(starterDefinition()).steps).toHaveLength(2)
  })
})
