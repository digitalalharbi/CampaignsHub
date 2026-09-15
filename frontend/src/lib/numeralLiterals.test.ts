import { describe, expect, it } from 'vitest'

/**
 * NUMBER-PRESENTATION-001 — a numeral typed into a string is a numeral nothing can reformat.
 *
 * `@/lib/numerals` decides what a digit looks like, and it is careful: the LANGUAGE never changes
 * the numerals — an Arabic screenshot has to stay comparable with the English one — and only an
 * explicit `number_format: 'arabic'` does. All of that reasoning is worth nothing for a digit that
 * was typed straight into a label, because no formatter is involved and no preference can reach it.
 *
 * They were there. The shared client report offered «٧ أيام / ٣٠ يومًا / ٩٠ يومًا» — on the one
 * surface whose reader is not signed in, has expressed no preference, and gets the Latin default by
 * design — beside figures rendered in Latin digits by the formatters. Three more sat in Settings,
 * the schedules panel and the campaign comparison.
 *
 * A grep is the only thing that finds this class: it is invisible to type checking, invisible to
 * every rendering test that does not assert on that exact string, and it reads as perfectly
 * reasonable Arabic in review.
 */

/*
 * Read through Vite rather than `node:fs`: this suite's tsconfig carries no Node types (adding them
 * to type one `readdirSync` would widen the app's type surface to buy nothing), and `import.meta.url`
 * under Vite is a `/@fs/...` dev-server URL rather than a path. `import.meta.glob` is eager and
 * resolved at build time, so the keys are repository paths — `/src/lib/phone.ts` — in every runner.
 */
const SOURCES: Record<string, string> = import.meta.glob('/src/**/*.{ts,tsx}', {
  query: '?raw',
  import: 'default',
  eager: true,
})

/** Arabic-Indic (٠-٩) and Extended Arabic-Indic (۰-۹). */
const ARABIC_INDIC = /[٠-٩۰-۹]/

/**
 * The two files that must contain these digits to do their job.
 *
 * Kept as an explicit list with a reason rather than a pattern: an exemption nobody can read is how
 * a guard stops guarding.
 */
const ALLOWED: Record<string, string> = {
  'src/lib/phone.ts': 'converts Arabic-Indic input INTO Latin — the characters are its input alphabet',
  'src/lib/i18n.ts': 'the label «أرقام عربية (١٢٣)» names the Arabic-digit option and must show one',
}

/** Line and block comments, JSX `{/* … *\/}` included — documentation may say what it likes. */
function stripComments(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '')
}

/** Every source file, test files excluded — a test may name the digit it is guarding against. */
function sourceFiles(): [string, string][] {
  return Object.entries(SOURCES)
    .filter(([path]) => !/\.test\.tsx?$/.test(path))
    .map(([path, source]) => [path.replace(/^\/+/, ''), source])
}

describe('NUMBER-PRESENTATION-001 — no numeral is typed into a user-facing string', () => {
  it('finds no Arabic-Indic digits outside the two files that need them', () => {
    const offenders = sourceFiles()
      .map(([relative, source]) => [relative, stripComments(source)] as const)
      .filter(([relative]) => !(relative in ALLOWED))
      .flatMap(([relative, source]) =>
        source
          .split('\n')
          .map((line, i) => [i + 1, line] as const)
          .filter(([, line]) => ARABIC_INDIC.test(line))
          .map(([n, line]) => `${relative}:${n}  ${line.trim().slice(0, 90)}`),
      )

    expect(offenders, 'these numerals cannot be reformatted — write them in Latin digits').toEqual([])
  })

  /** The scanner is reading something, and it is reading enough. */
  it('reads the whole source tree', () => {
    expect(sourceFiles().length).toBeGreaterThan(300)
  })

  /** And it can still see a digit that a comment does not hide. */
  it('reports a digit in code and ignores one in a comment', () => {
    expect(ARABIC_INDIC.test(stripComments("const label = '٧ أيام'"))).toBe(true)
    expect(ARABIC_INDIC.test(stripComments('// «٤ من ٣٠٩» in one control'))).toBe(false)
    expect(ARABIC_INDIC.test(stripComments('{/* «٤ من ٣٠٩» in one control */}'))).toBe(false)
  })
})

/**
 * NUMBER-PRESENTATION-001, the other half — a numeral nothing TYPED, and nothing can find by grep.
 *
 * The suite above guards digits written into strings. A formatter reaches the same screen by a route
 * that leaves no digit in the source at all: `toLocaleString('ar-EG')` renders «١٢٣» at runtime with
 * nothing for a literal-grep to catch, and `toLocaleString(undefined, …)` hands the decision to
 * whatever locale the reader's browser happens to be set to — which is the same defect with an
 * additional property, that it only appears on someone else's machine.
 *
 * `@/lib/numerals` exists precisely so this decision is made once: `formatNumber` asks
 * `numeralLocale()`, which returns Latin unless the reader has explicitly chosen otherwise. Its own
 * docblock records that sixty-eight call sites had each built their own `Intl.NumberFormat` before
 * that, «which is exactly how a preference ends up honoured in four places and ignored in
 * sixty-four». Nothing has stopped a sixty-ninth from appearing.
 *
 * So every formatting call site must name a locale that is Latin-numeral by construction, or come
 * from the module whose job is to decide. The current tree is already clean — this keeps it that
 * way, which is the only thing a guard written after the fact can honestly claim to do.
 */
describe('NUMBER-PRESENTATION-001 — no formatter is left to choose the numerals', () => {
  /**
   * A locale argument that cannot produce Arabic-Indic digits.
   *
   * `en-*` by construction. Any tag carrying `-u-nu-latn`, because that extension pins the numbering
   * system explicitly — it is the right way to get Arabic month names with Latin figures, and it is
   * what six of the seven Arabic call sites already do. `numeralLocale(...)` is the module that owns
   * the decision, so delegating to it is correct by definition.
   *
   * Bare `ar` is NOT on this list, though it happens to render Latin digits: that is a CLDR default
   * for the language-only tag, not something the call site states. `ar-SA` and `ar-EG` both resolve
   * to `arab`, so the obvious edit in a Saudi product — adding the region — silently flips the
   * digits. «Latin by construction» has to mean the code says so.
   */
  const LATIN_BY_CONSTRUCTION = /^(?:en(?:-[A-Za-z]{2})?|[A-Za-z-]+-u-nu-latn)$/

  /** `x.toLocaleString(` and `new Intl.NumberFormat(`, with whatever they were handed first. */
  const CALL = /(?:toLocaleString|Intl\.(?:NumberFormat|DateTimeFormat))\(\s*([^,)]*)/g

  it('every formatting call site names a locale that cannot produce Arabic-Indic digits', () => {
    const offenders: string[] = []

    for (const [path, source] of sourceFiles()) {
      // `numerals.ts` is where the decision is MADE; it necessarily names both systems.
      if (path === 'src/lib/numerals.ts') continue

      const code = stripComments(source)
      for (const [, argument] of code.matchAll(CALL)) {
        const arg = argument.trim()

        // No argument at all is the runtime's locale — the reader's browser decides the digits.
        if (arg === '') {
          offenders.push(`${path}: a formatter with no locale takes the reader’s own, digits included`)
          continue
        }

        /*
         * The locale is usually a ternary — `ar ? '…' : 'en-GB'` — so every branch is checked, not
         * the expression as a whole. The first version matched the argument as one literal and
         * reported six correct call sites as offenders, which is the failure mode a guard is least
         * able to survive: nobody trusts the seventh report after six wrong ones.
         */
        const locales = [...arg.matchAll(/['"]([^'"]+)['"]/g)].map((m) => m[1])

        // Delegating to the module that owns the decision is the correct thing to do.
        if (locales.length === 0) {
          if (!/numeralLocale\(/.test(arg)) {
            offenders.push(`${path}: formats with «${arg.slice(0, 40)}», whose numerals cannot be read here`)
          }
          continue
        }

        for (const locale of locales.filter((l) => !LATIN_BY_CONSTRUCTION.test(l))) {
          offenders.push(`${path}: formats with «${locale}», which may render Arabic-Indic digits`)
        }
      }
    }

    expect(offenders, offenders.join('\n')).toEqual([])
  })

  /** The vacuity check: a guard that matched nothing would pass on an empty search. */
  it('actually finds the formatting call sites it is guarding', () => {
    const found = sourceFiles()
      .filter(([path]) => path !== 'src/lib/numerals.ts')
      .reduce((n, [, source]) => n + [...stripComments(source).matchAll(CALL)].length, 0)

    expect(found, 'the pattern matched no formatter anywhere — it is guarding nothing').toBeGreaterThan(20)
  })
})
