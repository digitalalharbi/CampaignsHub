import type { PaidFieldDef } from './paidMediaFields'

/**
 * OPS-1 — how complete the brief is, from the fields the client can actually see.
 *
 * Counted: the services chosen (one), the four applicant fields the form requires, and every dynamic
 * brief/tracking/content field the chosen services ask for. Attachments are not counted — a file is
 * a courtesy, not a gap. Nothing is invented: the denominator is the visible form, so a brief with one
 * service and no extra questions is judged on those alone.
 */
export interface BriefApplicant {
  contact_name: string
  contact_email: string
  contact_phone: string
  company_name: string
}

export interface BriefCompletion {
  done: number
  total: number
  /** Whole percent, 0–100. */
  percent: number
}

export function isFilled(value: unknown): boolean {
  if (value === null || value === undefined) return false
  if (typeof value === 'string') return value.trim().length > 0
  if (Array.isArray(value)) return value.some(isFilled)
  if (typeof value === 'number') return Number.isFinite(value)
  if (typeof value === 'boolean') return true
  if (typeof value === 'object') return Object.values(value as Record<string, unknown>).some(isFilled)
  return false
}

export function briefCompletion(input: {
  selectedCount: number
  applicant: BriefApplicant
  fields: PaidFieldDef[]
  answers: Record<string, unknown>
}): BriefCompletion {
  const { selectedCount, applicant, fields, answers } = input
  const askable = fields.filter((f) => f.control !== 'files')
  const checks: boolean[] = [
    selectedCount > 0,
    applicant.contact_name.trim().length >= 2,
    /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(applicant.contact_email.trim()),
    applicant.contact_phone.trim().length > 0,
    applicant.company_name.trim().length >= 2,
    ...askable.map((f) => isFilled(answers[f.token])),
  ]
  const total = checks.length
  const done = checks.filter(Boolean).length
  return { done, total, percent: total === 0 ? 0 : Math.round((done / total) * 100) }
}
