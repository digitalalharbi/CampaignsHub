import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { clearBriefDraft, isBlankDraft, loadBriefDraft, saveBriefDraft, type BriefDraft } from './briefDraft'

const draft = (over: Partial<BriefDraft> = {}): BriefDraft => ({
  selectedKeys: ['new_campaign'],
  answers: { budget: '5000' },
  customRequest: '',
  serviceNotes: {},
  applicant: { contact_name: 'Sara', contact_email: 's@e.com', contact_phone: '0500000000', dial_code: '966', company_name: 'Acme' },
  currency: 'SAR',
  notes: '',
  ...over,
})

describe('the brief draft stays on this device', () => {
  beforeEach(() => window.localStorage.clear())
  afterEach(() => vi.restoreAllMocks())

  it('round-trips a draft', () => {
    expect(saveBriefDraft(draft())).toBe(true)
    expect(loadBriefDraft()).toEqual(draft())
  })

  it('forgets a draft after seven days', () => {
    saveBriefDraft(draft(), 1_000)
    expect(loadBriefDraft(1_000 + 7 * 24 * 60 * 60 * 1000 + 1)).toBeNull()
    expect(window.localStorage.length).toBe(0)
  })

  it('clears on demand', () => {
    saveBriefDraft(draft())
    clearBriefDraft()
    expect(loadBriefDraft()).toBeNull()
  })

  it('ignores a corrupt entry instead of throwing', () => {
    window.localStorage.setItem('ch.briefDraft.v1', '{not json')
    expect(loadBriefDraft()).toBeNull()
  })

  it('leaves the form working when storage is blocked', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('blocked') })
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('blocked') })
    expect(saveBriefDraft(draft())).toBe(false)
    expect(loadBriefDraft()).toBeNull()
    expect(() => clearBriefDraft()).not.toThrow()
  })

  it('never stores uploads or verification ids', () => {
    saveBriefDraft(draft())
    const raw = window.localStorage.getItem('ch.briefDraft.v1') ?? ''
    expect(raw).not.toMatch(/upload|verification|token/i)
  })

  it('knows a blank draft', () => {
    expect(isBlankDraft(draft({ selectedKeys: [], answers: {}, applicant: { contact_name: '', contact_email: '', contact_phone: '', dial_code: '966', company_name: '' } }))).toBe(true)
    expect(isBlankDraft(draft())).toBe(false)
  })
})
