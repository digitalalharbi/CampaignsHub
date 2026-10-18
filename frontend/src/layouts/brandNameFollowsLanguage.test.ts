import { describe, expect, it } from 'vitest'

/**
 * BRAND-LANG-001 — a shell never prints the Latin name as a literal.
 *
 * Arabic is «كامبينز هب» and English is «CampaignsHub», chosen by the selected language through
 * `productName()` / `brand.lockup`. `AdminShell` wrote `CampaignsHub` as JSX text beside the mark, so
 * the platform console read the English name in an Arabic session while every other shell followed
 * the language. A literal in a shell cannot follow the language, so none is allowed.
 *
 * Read from source for the reason `brandHome.test.ts` gives: the shells mount a router, a session and
 * a workspace switcher, and the property is one line long.
 */
const SHELLS = import.meta.glob('/src/layouts/*Shell.tsx', { query: '?raw', import: 'default', eager: true }) as Record<string, string>

describe('the shells follow the selected language for the product name', () => {
  it('finds the shells', () => {
    expect(Object.keys(SHELLS).length).toBeGreaterThanOrEqual(4)
  })

  it.each(Object.entries(SHELLS))('%s prints no literal «CampaignsHub» as text', (file, source) => {
    // JSX text only: between a closing `>` and the next `<`. Comments and imports are not text.
    const withoutComments = source.replace(/\{\/\*[\s\S]*?\*\/\}/g, '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '')
    const literal = />\s*CampaignsHub\s*</.exec(withoutComments)

    expect(literal, `${file} writes the English name where Arabic should read «كامبينز هب»`).toBeNull()
  })
})
