import { describe, expect, it } from 'vitest'
import { briefCompletion, isFilled } from './briefProgress'
import type { PaidFieldDef } from './paidMediaFields'

const field = (token: string, control: PaidFieldDef['control'] = 'text'): PaidFieldDef =>
  ({ token, labelAr: token, labelEn: token, control, group: 'brief' })

const empty = { contact_name: '', contact_email: '', contact_phone: '', company_name: '' }
const full = { contact_name: 'Sara', contact_email: 's@e.com', contact_phone: '0500000000', company_name: 'Acme' }

describe('the brief says how complete it is', () => {
  it('starts at zero with nothing chosen and nothing typed', () => {
    expect(briefCompletion({ selectedCount: 0, applicant: empty, fields: [], answers: {} })).toEqual({ done: 0, total: 5, percent: 0 })
  })

  it('counts the services, the four applicant fields and every askable field', () => {
    const r = briefCompletion({
      selectedCount: 2,
      applicant: full,
      fields: [field('budget'), field('platforms', 'multi'), field('goal')],
      answers: { budget: '5000', platforms: ['meta'], goal: '' },
    })
    expect(r).toEqual({ done: 7, total: 8, percent: 88 })
  })

  it('does not count attachments: a file is a courtesy, not a gap', () => {
    const r = briefCompletion({ selectedCount: 1, applicant: full, fields: [field('assets', 'files')], answers: {} })
    expect(r.total).toBe(5)
    expect(r.percent).toBe(100)
  })

  it('judges a bad email or a one-letter name as not done', () => {
    const r = briefCompletion({ selectedCount: 1, applicant: { ...full, contact_email: 'nope', contact_name: 'S' }, fields: [], answers: {} })
    expect(r.done).toBe(3)
  })

  it('treats blank strings, empty lists and empty objects as unanswered, and zero as answered', () => {
    expect(isFilled('  ')).toBe(false)
    expect(isFilled([])).toBe(false)
    expect(isFilled([' ', ''])).toBe(false)
    expect(isFilled({ a: '' })).toBe(false)
    expect(isFilled(0)).toBe(true)
    expect(isFilled(false)).toBe(true)
    expect(isFilled(['meta'])).toBe(true)
  })
})
