import { describe, expect, it } from 'vitest'
import { isAdPlatform, platformLabel, SOURCE_LABELS, PLATFORM_LABELS } from '@/lib/platforms'
import { providerLabel } from '@/features/campaigns/labels'

/**
 * REPORT-WEBSITE-TAB-001 — the word «GA4» is never shown to a person, and a raw provider key can never print.
 *
 * The Owner, 2026-11-07: «استبدل مصطلح GA4 بـ الموقع أو الهبوط أو ويب سايت». The internal provider kind stays `ga4`
 * (keys, ids, test ids, class names); only the labels change, and they come from the ONE label module.
 */
const TREE: Record<string, string> = import.meta.glob('/src/**/*.{ts,tsx}', { query: '?raw', import: 'default', eager: true })

/** Strip block comments and line comments (a `//` after whitespace or at the start, so `https://` inside a string survives). */
function code(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|\s)\/\/.*$/gm, '$1')
}

/** The uppercase token, but not an identifier such as GA4_PARTS. Lower-case `ga4` is the internal key and is allowed. */
const WORD = /GA4(?![_A-Za-z0-9-])/

describe('the website is never called GA4 in the interface', () => {
  const files = Object.entries(TREE).filter(([path]) => !/\.test\.(ts|tsx)$/.test(path) && !path.includes('/src/test/'))

  it('has no user-visible «GA4» in any source string or JSX text', () => {
    const offenders = files
      .map(([path, source]) => [path, code(source).split('\n').map((line, i) => [i + 1, line] as const).filter(([, line]) => WORD.test(line))] as const)
      .filter(([, hits]) => hits.length > 0)
      .map(([path, hits]) => `${path}:${hits.map(([n]) => n).join(',')}`)

    expect(offenders, 'a user-visible «GA4» — say «الموقع» / «Website» through the label module').toEqual([])
  })

  it('names the website from the one label module, in both languages, never as the raw key', () => {
    expect(SOURCE_LABELS.ga4).toEqual({ ar: 'الموقع', en: 'Website' })
    expect(platformLabel('ga4', true)).toBe('الموقع')
    expect(platformLabel('ga4', false)).toBe('Website')
    expect(providerLabel('ga4', 'ar')).toBe('الموقع')
    expect(providerLabel('ga4', 'en')).toBe('Website')
  })

  it('never prints a raw key for any ad platform or source the product knows', () => {
    for (const key of [...Object.keys(PLATFORM_LABELS), ...Object.keys(SOURCE_LABELS)]) {
      expect(providerLabel(key, 'ar'), key).not.toBe(key)
      expect(providerLabel(key, 'en'), key).not.toBe(key)
      expect(providerLabel(key, 'en')).not.toMatch(/ga4/i)
    }
  })

  it('does not count the website, a store or a measurement source as an ad platform', () => {
    expect(isAdPlatform('ga4')).toBe(false)
    expect(isAdPlatform('salla')).toBe(false)
    expect(isAdPlatform('meta')).toBe(true)
    expect(isAdPlatform('snapchat')).toBe(true)
  })
})
