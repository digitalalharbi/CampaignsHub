import type { Answers, AnswerValue, FormDefinition, FormField, LocText } from './types'

/**
 * FORMS-HUB-001 — the browser's reading of a definition: which fields show, whether an answer is acceptable.
 *
 * The same rules the server applies in `FormSubmissionValidator`, so a person is told about a problem before
 * they send rather than after. The server still decides; this is a courtesy, never the proof.
 */
export const pick = (text: LocText | undefined, ar: boolean): string => {
  if (text === undefined || text === null) return ''
  if (typeof text === 'string') return text
  return (ar ? text.ar ?? text.en : text.en ?? text.ar) ?? ''
}

const blank = (v: AnswerValue | undefined): boolean => v === undefined || v === '' || (Array.isArray(v) && v.length === 0)

/** Whether a field is asked, given the answers of the fields before it that were themselves asked. */
export function isVisible(field: FormField, answers: Answers): boolean {
  const c = field.show_if
  if (!c) return true
  const have = answers[c.field]
  switch (c.op) {
    case 'filled': return !blank(have)
    case 'equals': return Array.isArray(have) ? have.includes(String(c.value)) : have !== undefined && String(have) === String(c.value)
    case 'not_equals': return Array.isArray(have) ? !have.includes(String(c.value)) : have === undefined || String(have) !== String(c.value)
    case 'in': {
      const want = Array.isArray(c.value) ? c.value : []
      return Array.isArray(have) ? have.some((h) => want.includes(h)) : have !== undefined && want.includes(String(have))
    }
  }
}

/** The fields of a step that are asked, evaluated in order against what has been answered so far. */
export function visibleFields(definition: FormDefinition, answers: Answers): FormField[] {
  const out: FormField[] = []
  const seen: Answers = {}
  for (const step of definition.steps) {
    for (const f of step.fields) {
      if (!isVisible(f, seen)) continue
      out.push(f)
      if (answers[f.id] !== undefined) seen[f.id] = answers[f.id]
    }
  }
  return out
}

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

/** The message for one answer, or null when it is acceptable. English and Arabic. */
export function fieldError(field: FormField, value: AnswerValue | undefined, ar: boolean): string | null {
  if (blank(value)) return field.required ? (ar ? 'هذا السؤال مطلوب.' : 'This question is required.') : null
  const v = field.validation ?? {}
  switch (field.type) {
    case 'email':
      return EMAIL.test(String(value)) ? null : (ar ? 'أدخل بريدًا إلكترونيًا صحيحًا.' : 'Enter a valid email address.')
    case 'phone': {
      const digits = String(value).replace(/[^\d]/g, '')
      return digits.length >= 7 && digits.length <= 15 ? null : (ar ? 'أدخل رقم جوال صحيحًا.' : 'Enter a valid phone number.')
    }
    case 'number': {
      const n = Number(value)
      if (Number.isNaN(n)) return ar ? 'أدخل رقمًا.' : 'Enter a number.'
      if (v.min !== undefined && n < v.min) return ar ? `الحد الأدنى ${v.min}.` : `At least ${v.min}.`
      if (v.max !== undefined && n > v.max) return ar ? `الحد الأقصى ${v.max}.` : `At most ${v.max}.`
      return null
    }
    case 'text':
    case 'textarea': {
      const len = String(value).trim().length
      if (v.min_length !== undefined && len < v.min_length) return ar ? `ما لا يقل عن ${v.min_length} حرفًا.` : `At least ${v.min_length} characters.`
      if (v.max_length !== undefined && len > v.max_length) return ar ? `ما لا يزيد عن ${v.max_length} حرفًا.` : `At most ${v.max_length} characters.`
      return null
    }
    case 'select':
    case 'radio':
      return (field.options ?? []).some((o) => o.value === String(value)) ? null : (ar ? 'اختر أحد الخيارات.' : 'Choose one of the options.')
    case 'checkbox': {
      const allowed = (field.options ?? []).map((o) => o.value)
      return Array.isArray(value) && value.every((x) => allowed.includes(x)) ? null : (ar ? 'اختر من الخيارات.' : 'Choose from the options.')
    }
    case 'date':
      return /^\d{4}-\d{2}-\d{2}$/.test(String(value)) ? null : (ar ? 'أدخل تاريخًا بصيغة YYYY-MM-DD.' : 'Enter a date as YYYY-MM-DD.')
  }
}

/** Errors for the fields of one step that are asked — «asked» judged against the whole form, so a condition on an earlier step holds. */
export function stepErrors(definition: FormDefinition, stepIndex: number, answers: Answers, ar: boolean): Record<string, string> {
  const errors: Record<string, string> = {}
  const asked = new Set(visibleFields(definition, answers).map((f) => f.id))
  for (const f of definition.steps[stepIndex]?.fields ?? []) {
    if (!asked.has(f.id)) continue
    const e = fieldError(f, answers[f.id], ar)
    if (e) errors[f.id] = e
  }
  return errors
}

/** The answers worth sending: only fields that are asked, nothing for a field the logic hides. */
export function cleanAnswers(definition: FormDefinition, answers: Answers): Answers {
  const out: Answers = {}
  for (const f of visibleFields(definition, answers)) {
    const v = answers[f.id]
    if (!blank(v)) out[f.id] = f.type === 'number' ? Number(v) : v
  }
  return out
}
