import { describe, expect, it } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

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
 * `import.meta.url` under Vite is a `/@fs/...` dev-server URL, not a filesystem path, so resolving
 * the tree from it reads a directory that does not exist. `process.cwd()` is the frontend package
 * root when vitest runs, from the terminal or from CI.
 */
const ROOT = process.cwd()
const SRC = join(ROOT, 'src')

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

function sourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((entry) => {
    const path = join(dir, entry)
    if (statSync(path).isDirectory()) return sourceFiles(path)
    if (!/\.tsx?$/.test(entry) || /\.test\.tsx?$/.test(entry)) return []
    return [path]
  })
}

describe('NUMBER-PRESENTATION-001 — no numeral is typed into a user-facing string', () => {
  it('finds no Arabic-Indic digits outside the two files that need them', () => {
    const offenders = sourceFiles(SRC)
      .map((path) => [path.slice(ROOT.length).replace(/^\/+/, ''), stripComments(readFileSync(path, 'utf8'))] as const)
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
    expect(sourceFiles(SRC).length).toBeGreaterThan(300)
  })

  /** And it can still see a digit that a comment does not hide. */
  it('reports a digit in code and ignores one in a comment', () => {
    expect(ARABIC_INDIC.test(stripComments("const label = '٧ أيام'"))).toBe(true)
    expect(ARABIC_INDIC.test(stripComments('// «٤ من ٣٠٩» in one control'))).toBe(false)
    expect(ARABIC_INDIC.test(stripComments('{/* «٤ من ٣٠٩» in one control */}'))).toBe(false)
  })
})
