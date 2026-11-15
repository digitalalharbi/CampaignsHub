import { describe, expect, it } from 'vitest'
import { COPY } from './centerCopy'
import { applyUnread, titleWithCount } from './unreadBadge'

const EMOJI = /[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}]|\u{FE0F}|\u{20E3}/u

describe('the centre copy', () => {
  it('has the same keys in Arabic and English and no emoji anywhere', () => {
    expect(Object.keys(COPY.ar).sort()).toEqual(Object.keys(COPY.en).sort())
    for (const table of [COPY.ar, COPY.en]) {
      for (const [key, text] of Object.entries(table)) {
        expect(EMOJI.test(text), key).toBe(false)
        expect(text.trim(), key).not.toBe('')
      }
    }
  })

  it('names the three tabs the Owner listed', () => {
    expect([COPY.ar.tab_all, COPY.ar.tab_unread, COPY.ar.tab_followup]).toEqual(['الكل', 'غير مقروء', 'متابعة'])
  })
})

describe('the unread number outside the bell', () => {
  it('prefixes the tab title, replaces a stale count, caps at 99+ and clears at zero', () => {
    expect(titleWithCount('كمبينز هب', 3)).toBe('(3) كمبينز هب')
    expect(titleWithCount('(3) كمبينز هب', 7)).toBe('(7) كمبينز هب')
    expect(titleWithCount('(7) كمبينز هب', 345)).toBe('(99+) كمبينز هب')
    expect(titleWithCount('(99+) كمبينز هب', 0)).toBe('كمبينز هب')
  })

  it('uses the Badging API where the browser has it, and keeps only the title where it does not', () => {
    const doc = { title: 'Hub' } as Document
    const set = { calls: [] as unknown[] }
    const withApi = { setAppBadge: (n?: number) => { set.calls.push(n); return Promise.resolve() }, clearAppBadge: () => { set.calls.push('clear'); return Promise.resolve() } } as unknown as Navigator

    expect(applyUnread(5, doc, withApi)).toEqual({ title: '(5) Hub', badgeApi: true })
    expect(applyUnread(0, doc, withApi)).toEqual({ title: 'Hub', badgeApi: true })
    expect(set.calls).toEqual([5, 'clear'])

    const doc2 = { title: 'Hub' } as Document
    expect(applyUnread(2, doc2, {} as Navigator)).toEqual({ title: '(2) Hub', badgeApi: false })
  })

  it('never throws when the browser refuses the badge', () => {
    const nav = { setAppBadge: () => { throw new Error('not installed') } } as unknown as Navigator
    expect(() => applyUnread(1, { title: 'x' } as Document, nav)).not.toThrow()
  })
})
