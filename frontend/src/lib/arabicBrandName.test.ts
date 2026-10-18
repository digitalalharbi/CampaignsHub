import { describe, expect, it } from 'vitest'

/**
 * BRAND-LANG-001 — an Arabic sentence names the product in Arabic.
 *
 * The identity gives the product one name per language: «كامبينز هب» in Arabic, «CampaignsHub» in English.
 * Arabic copy that carried the Latin name inside an Arabic sentence put the English wordmark on an Arabic
 * surface — the sign-in card, the policies, the registration journey. Written as a source guard because the
 * same sentence is copied into new screens: a line that has Arabic letters on either side of the Latin name
 * fails here instead of reaching a reader.
 */
const TREE: Record<string, string> = import.meta.glob('/src/**/*.{ts,tsx}', { query: '?raw', import: 'default', eager: true })

describe('the Arabic copy never carries the Latin product name', () => {
  it('has no Arabic sentence with CampaignsHub spelled in Latin inside it', () => {
    const offenders: string[] = []

    for (const [path, source] of Object.entries(TREE)) {
      if (/\.test\.|\/lib\/brand\.ts$/.test(path)) continue
      source.split('\n').forEach((line, i) => {
        const t = line.trim()
        if (t.startsWith('*') || t.startsWith('//') || t.startsWith('/*') || !line.includes('CampaignsHub')) return
        if (/import |CampaignsHubLogo|CampaignsHubMark|CampaignsHubBrand/.test(line)) return
        if (/[؀-ۿ][\s«"'(]*CampaignsHub|CampaignsHub[\s»"')]*[؀-ۿ]/.test(line)) offenders.push(`${path.replace('/src/', 'src/')}:${i + 1}`)
      })
    }

    expect(offenders, `Arabic copy names the product in Latin:\n  ${offenders.join('\n  ')}`).toEqual([])
  })
})
