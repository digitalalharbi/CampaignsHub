import { describe, expect, it } from 'vitest'
import { fill, pluralOf, WORDS } from './strings'

const EMOJI = /[\u{1F000}-\u{1FAFF}\u{2600}-\u{27BF}\u{FE0F}\u{2B00}-\u{2BFF}\u{2190}-\u{21FF}]/u

describe('workspace notification strings', () => {
  it('has the same keys in Arabic and English', () => {
    expect(Object.keys(WORDS.ar).sort()).toEqual(Object.keys(WORDS.en).sort())
  })

  it('has no emoji in any string and no empty string', () => {
    for (const lang of ['ar', 'en'] as const) {
      for (const [k, v] of Object.entries(WORDS[lang])) {
        expect(v.length, k).toBeGreaterThan(0)
        expect(EMOJI.test(v), `${lang}.${k}`).toBe(false)
      }
    }
  })

  it('every placeholder in one language exists in the other', () => {
    const ph = (s: string) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort().join(',')
    for (const k of Object.keys(WORDS.ar) as (keyof typeof WORDS.ar)[]) {
      expect(ph(WORDS.ar[k]), k).toBe(ph(WORDS.en[k]))
    }
  })

  it('takes its terms from the workspace type, not from a type name', () => {
    expect(fill(WORDS.ar.pickProjects, { projects: pluralOf('حملة', true) })).toBe('اختر الحملات')
    expect(fill(WORDS.ar.pickProjects, { projects: pluralOf('مشروع', true) })).toBe('اختر المشاريع')
    expect(fill(WORDS.en.pickProjects, { projects: pluralOf('Campaign', false) })).toBe('Pick Campaigns')
  })
})
