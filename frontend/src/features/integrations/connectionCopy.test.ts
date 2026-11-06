import { describe, expect, it } from 'vitest'
import { ACCOUNT_SET_COPY, CONNECTION_COPY, SYNC_COPY, selectedOfDiscovered } from './connectionCopy'

/**
 * INTEGRATIONS-CENTRE-001 §5 — one vocabulary for discovered / selected / connected / syncing / failed.
 *
 * The hub, the wizard, the project estate, the selected-accounts list and the drawer all state an
 * account's or a connection's state. Each used to carry its own words for the same state, and the
 * Owner read three screens disagreeing about whether 309 discovered accounts were being synced. The
 * words live in `connectionCopy.ts` and `accountHealth.ts`; this guard holds every surface to them.
 *
 * Read through Vite rather than `node:fs` (this suite's tsconfig carries no Node types; see
 * `numeralLiterals.test.ts` for the same reasoning).
 */
const SOURCES: Record<string, string> = import.meta.glob('/src/features/integrations/*.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
})

/** The surfaces that render a state or a set of accounts, and what each must import. */
const SURFACES: Record<string, string[]> = {
  '/src/features/integrations/ConnectionHub.tsx': ['./connectionCopy'],
  '/src/features/integrations/ConnectionWizard.tsx': ['./connectionCopy', './accountHealth'],
  '/src/features/integrations/SelectedAccounts.tsx': ['./accountHealth'],
  '/src/features/integrations/ConnectionDrawer.tsx': ['./connectionCopy'],
}

const sourceOf = (path: string): string => {
  const source = SOURCES[path]
  if (source === undefined) throw new Error(`${path} is not in the glob — was it moved?`)
  return source.split('\n').filter((line) => !/^\s*(\*|\/\/|\/\*)/.test(line)).join('\n')
}

/** Every phrase the module owns, in both languages. */
const OWNED_PHRASES: string[] = [
  ...Object.values(CONNECTION_COPY).flatMap((c) => [c.ar, c.en]),
  ...Object.values(SYNC_COPY).flatMap((c) => [c.ar, c.en]),
  ...Object.values(ACCOUNT_SET_COPY).flatMap((c) => [c.ar, c.en]),
]

describe('the integrations vocabulary', () => {
  it('covers the five states the Owner named, in both languages', () => {
    expect(ACCOUNT_SET_COPY.discovered.ar).not.toBe('')
    expect(ACCOUNT_SET_COPY.selected.ar).not.toBe('')
    expect(CONNECTION_COPY.CONNECTED.en).toBe('Connected')
    expect(SYNC_COPY.SYNCING.en).toBe('Syncing')
    expect(SYNC_COPY.FAILED.en).toBe('Sync failed')
    for (const phrase of OWNED_PHRASES) expect(phrase.trim()).not.toBe('')
  })

  it('relates selected to discovered in one sentence, with Latin digits', () => {
    expect(selectedOfDiscovered(3, 309, false)).toBe('3 of 309 selected')
    expect(selectedOfDiscovered(3, 309, true)).toBe('اختير 3 من 309')
    expect(selectedOfDiscovered(0, 0, true)).not.toMatch(/[٠-٩]/)
  })

  it.each(Object.entries(SURFACES))('%s draws its state words from the shared module', (path, modules) => {
    const source = sourceOf(path)
    for (const module of modules) {
      expect(source, `${path} must import from '${module}'`).toMatch(new RegExp(`from '${module.replace('.', '\\.')}'`))
    }
  })

  it.each(Object.keys(SURFACES))('%s types none of the owned phrases as its own literal', (path) => {
    const source = sourceOf(path)
    for (const phrase of OWNED_PHRASES) {
      // A quoted literal is a second copy; the module's export used in JSX is not.
      expect(source, `${path} retypes «${phrase}» — use connectionCopy`).not.toContain(`'${phrase}'`)
      expect(source, `${path} retypes «${phrase}» — use connectionCopy`).not.toContain(`"${phrase}"`)
    }
  })

  it('keeps the selected-of-discovered sentence out of the surfaces as a template of their own', () => {
    for (const path of Object.keys(SURFACES)) {
      const source = sourceOf(path)
      expect(source, `${path} builds its own «N of M selected»`).not.toMatch(/of \$\{[^}]*\} (selected|accounts)/)
      expect(source, `${path} builds its own «اختير N من M»`).not.toMatch(/(تم اختيار|اختير) \$\{/)
    }
  })
})
