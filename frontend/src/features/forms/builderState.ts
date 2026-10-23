import type { FieldType, FormDefinition, FormField, FormOption, FormStep, LocText, MapsTo } from './types'

/**
 * FORMS-HUB-001 — the builder's edits as pure functions over a definition, so each can be tested without a browser
 * and the screen only wires them to inputs.
 */
export const FIELD_TYPES: FieldType[] = ['text', 'textarea', 'email', 'phone', 'number', 'select', 'radio', 'checkbox', 'date']

/** A new form starts as the smallest one the server accepts: a name, a mobile, and consent. */
export function starterDefinition(): FormDefinition {
  return {
    steps: [{
      id: 's1',
      title: { ar: 'بياناتك', en: 'Your details' },
      fields: [
        { id: 'full_name', type: 'text', label: { ar: 'الاسم', en: 'Name' }, required: true, maps_to: 'name' },
        { id: 'mobile', type: 'phone', label: { ar: 'رقم الجوال', en: 'Mobile' }, required: true, maps_to: 'phone' },
      ],
    }],
    consent: {
      required: true,
      text: { ar: 'أوافق على التواصل معي بخصوص هذا الطلب.', en: 'I agree to be contacted about this enquiry.' },
      // Off by default: a form only asks for it when the team is going to report conversions to a platform.
      measurement: {
        enabled: false,
        text: {
          ar: 'أوافق على إرسال بياناتي مُشفّرة (هاش) إلى منصات القياس والإعلان لقياس نتيجة الحملة.',
          en: 'I agree that my details, hashed, may be sent to measurement and advertising platforms to measure the campaign.',
        },
      },
    },
    thank_you: { message: { ar: 'شكرًا، وصلتنا بياناتك وسنتواصل معك.', en: 'Thank you — we have your details and will be in touch.' }, redirect_url: null },
    style: { accent: null, theme: 'auto' },
  }
}

const uniqueFieldId = (d: FormDefinition, base: string): string => {
  const taken = new Set(d.steps.flatMap((s) => s.fields.map((f) => f.id)))
  let n = 1
  while (taken.has(`${base}_${n}`)) n += 1
  return `${base}_${n}`
}

/** The column a field of this type may be mapped to, if any. */
export const mapFor = (type: FieldType): MapsTo | null => (type === 'email' ? 'email' : type === 'phone' ? 'phone' : type === 'text' ? 'name' : null)

export function addStep(d: FormDefinition): FormDefinition {
  const n = d.steps.length + 1
  const step: FormStep = { id: `s${n}`, title: { ar: `الخطوة ${n}`, en: `Step ${n}` }, fields: [] }
  return { ...d, steps: [...d.steps, step] }
}

export function removeStep(d: FormDefinition, i: number): FormDefinition {
  return { ...d, steps: d.steps.filter((_, k) => k !== i) }
}

export function addField(d: FormDefinition, stepIndex: number, type: FieldType): FormDefinition {
  const field: FormField = {
    id: uniqueFieldId(d, type === 'text' ? 'question' : type),
    type,
    label: { ar: '', en: '' },
    required: false,
    ...(['select', 'radio', 'checkbox'].includes(type) ? { options: [{ value: 'option_1', label: { ar: 'خيار 1', en: 'Option 1' } }] } : {}),
  }
  return { ...d, steps: d.steps.map((s, k) => (k === stepIndex ? { ...s, fields: [...s.fields, field] } : s)) }
}

export function updateField(d: FormDefinition, stepIndex: number, fieldIndex: number, patch: Partial<FormField>): FormDefinition {
  return {
    ...d,
    steps: d.steps.map((s, k) => (k !== stepIndex ? s : { ...s, fields: s.fields.map((f, j) => (j === fieldIndex ? dropEmpty({ ...f, ...patch }) : f)) })),
  }
}

/** A field whose type changed keeps only what its new type can carry. */
export function retype(d: FormDefinition, stepIndex: number, fieldIndex: number, type: FieldType): FormDefinition {
  const current = d.steps[stepIndex].fields[fieldIndex]
  const options = ['select', 'radio', 'checkbox'].includes(type) ? (current.options?.length ? current.options : [{ value: 'option_1', label: { ar: 'خيار 1', en: 'Option 1' } }]) : undefined
  const maps = current.maps_to && mapFor(type) === current.maps_to ? current.maps_to : undefined
  return updateField(d, stepIndex, fieldIndex, { type, options, validation: undefined, maps_to: maps })
}

export function removeField(d: FormDefinition, stepIndex: number, fieldIndex: number): FormDefinition {
  const gone = d.steps[stepIndex].fields[fieldIndex]?.id
  return {
    ...d,
    steps: d.steps.map((s, k) => {
      const fields = (k === stepIndex ? s.fields.filter((_, j) => j !== fieldIndex) : s.fields)
      // A condition that pointed at the removed field would refuse the whole form; drop it with its target.
      return { ...s, fields: fields.map((f) => (f.show_if?.field === gone ? { ...f, show_if: undefined } : f)) }
    }),
  }
}

/** Move a field up or down inside its step. A conditional field never moves above the field it depends on. */
export function moveField(d: FormDefinition, stepIndex: number, fieldIndex: number, by: -1 | 1): FormDefinition {
  const fields = [...d.steps[stepIndex].fields]
  const to = fieldIndex + by
  if (to < 0 || to >= fields.length) return d
  const [moved] = fields.splice(fieldIndex, 1)
  fields.splice(to, 0, moved)
  const next = { ...d, steps: d.steps.map((s, k) => (k === stepIndex ? { ...s, fields } : s)) }

  const order = next.steps.flatMap((s) => s.fields.map((f) => f.id))
  const broken = order.some((id, i) => {
    const dep = next.steps.flatMap((s) => s.fields).find((f) => f.id === id)?.show_if?.field
    return dep !== undefined && order.indexOf(dep) > i
  })

  return broken ? d : next
}

/** The fields a condition may point at: everything strictly before this one. */
export function earlierFields(d: FormDefinition, stepIndex: number, fieldIndex: number): FormField[] {
  const out: FormField[] = []
  d.steps.forEach((s, k) => s.fields.forEach((f, j) => { if (k < stepIndex || (k === stepIndex && j < fieldIndex)) out.push(f) }))
  return out
}

/** «value|label» per line ⇄ options. A line without a bar uses the text as both. */
export function optionsToText(options: FormOption[] | undefined): string {
  return (options ?? []).map((o) => {
    const label = typeof o.label === 'string' ? o.label : (o.label.en ?? o.label.ar ?? '')
    return label && label !== o.value ? `${o.value}|${label}` : o.value
  }).join('\n')
}

export function textToOptions(text: string): FormOption[] {
  const seen = new Set<string>()
  const out: FormOption[] = []
  for (const line of text.split('\n')) {
    const [rawValue, ...rest] = line.split('|')
    const value = rawValue.trim()
    if (value === '' || seen.has(value)) continue
    seen.add(value)
    const label = rest.join('|').trim()
    out.push({ value, label: label === '' ? value : { en: label, ar: label } })
  }
  return out
}

export const setLoc = (current: LocText | undefined, lang: 'ar' | 'en', value: string): LocText => {
  const base = typeof current === 'string' ? { ar: current, en: current } : { ...(current ?? {}) }
  return { ...base, [lang]: value }
}

/** Empty strings and empty objects are removed so the saved definition says only what was chosen. */
function dropEmpty(f: FormField): FormField {
  const out = { ...f }
  if (out.validation && Object.values(out.validation).every((v) => v === undefined || Number.isNaN(v))) delete out.validation
  if (!out.maps_to) delete out.maps_to
  if (!out.show_if) delete out.show_if
  if (!out.options) delete out.options
  return out
}
