import { describe, expect, it } from 'vitest'

/**
 * The tree, read through Vite rather than through `node:fs` — the same way every other source guard
 * in this suite reads it. CI type-checks the test files, and a `node:` import there fails the build.
 */
const TREE: Record<string, string> = import.meta.glob('/src/**/*.{ts,tsx}', {
  query: '?raw',
  import: 'default',
  eager: true,
})

/**
 * Files whose `money(` is not the compacting one, with the reason.
 *
 * `PrintDocument` defines a LOCAL `money` that delegates to `moneyExact` — a printed page has room
 * for every digit and no hover to reveal them, so it never abbreviates. The call reads `money(cpa)`
 * and is correct; the pattern below cannot see the alias.
 */
const EXEMPT: Record<string, string> = {
  '/src/features/reports/PrintDocument.tsx':
    'its local `money` IS `moneyExact` — a printed page cannot hover, so it abbreviates nothing',
}

/** Product source only: a test may legitimately quote the old string while asserting against it. */
const sources = (): Array<[string, string]> =>
  Object.entries(TREE).filter(([path]) => !/\.test\.tsx?$/.test(path) && !(path in EXEMPT))

/**
 * A cost-per or a return handed to the COMPACTING formatter.
 *
 * Matches `money(<something cpa/cpc/cpl/…>, …)` and nothing else: `money(spend)` and `money(revenue)`
 * are correct and common, and a rule that flagged them would be deleted within a week. Comments are
 * stripped first, so a line explaining the defect does not read as one.
 */
const OFFENDER = /\bmoney\(\s*[^,)]*\b(cpa|cpc|cpl|cpi|cpe|cpm|cost_per|costPer)\b[^,)]*/i

function withoutComments(code: string): string {
  return code.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1')
}

describe('a decision-critical figure keeps its digits', () => {
  it('hands no cost-per to the compacting money formatter', () => {
    const offenders = sources()
      .filter(([, code]) => OFFENDER.test(withoutComments(code)))
      .map(([path]) => path)

    expect(
      offenders,
      'a cost per result went through money(), which keeps three significant digits and prints 1.50 as «2». '
        + 'Use moneyExact() — the decimals are the decision:\n  ' + offenders.join('\n  '),
    ).toEqual([])
  })

  /**
   * And the rule has not been satisfied by deleting the cost-per entirely.
   *
   * A guard whose subject can vanish is a guard that passes by finding nothing, so this pins that the
   * product still shows these figures somewhere — through the reader that keeps them whole.
   */
  it('still shows cost-per figures, through the exact reader', () => {
    const users = sources().filter(([, raw]) => {
      const code = withoutComments(raw)

      return /moneyExact\(/.test(code) && /\b(cpa|cpc|cpl|cost_per)\b/i.test(code)
    })

    expect(users.length, 'no surface reads a cost-per through moneyExact — the rule has nothing to protect')
      .toBeGreaterThan(0)
  })
})
