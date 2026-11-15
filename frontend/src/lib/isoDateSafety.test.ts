import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * NOV-SAFARI-001 — Safari reads only ISO 8601 dates, so the source may not hand it anything else.
 *
 * `new Date('2026-11-15 10:30')` and `new Date('2026/11/15')` are valid in Chrome and Firefox and `Invalid Date` in
 * Safari — on every iPhone, where every browser is Safari underneath. The date then prints as «—» or `NaN`, and
 * nothing fails on the machine that wrote it. This is the guard: a literal or template given to `new Date(`
 * or `Date.parse(` must be the ISO shape, with a `T` between the date and the time.
 *
 * It reads source text, not behaviour, because the behaviour differs only in the browser that cannot run here.
 */
function sources(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    if (statSync(path).isDirectory()) return name === 'node_modules' ? [] : sources(path)

    return /\.(ts|tsx)$/.test(name) && !/\.test\.(ts|tsx)$/.test(name) ? [path] : []
  })
}

const CALL = /(?:new Date|Date\.parse)\(\s*(['"`])((?:(?!\1)[^\\\n])*)\1/g

function risky(arg: string): string | null {
  if (/\d{4}-\d{2}-\d{2}\s+\d{1,2}:\d{2}/.test(arg)) return 'a space between the date and the time — write `T`'
  if (/\d{4}\/\d{1,2}\/\d{1,2}/.test(arg)) return 'a slash date — write `YYYY-MM-DD`'
  if (/\$\{[^}]*\}\s+\$\{|\}\s+\d{1,2}:\d{2}/.test(arg)) return 'a space between date and time parts — write `T`'
  if (/^\d{1,2}[-/]\d{1,2}[-/]\d{4}/.test(arg)) return 'a day-first date — write `YYYY-MM-DD`'

  return null
}

describe('NOV-SAFARI-001 — no date string Safari cannot read', () => {
  const root = join(__dirname, '..')
  const files = sources(root)

  it('scans the source tree', () => {
    expect(files.length).toBeGreaterThan(300)
  })

  it('never builds a Date from a spaced, slashed or day-first string', () => {
    const found: string[] = []
    for (const file of files) {
      const text = readFileSync(file, 'utf8')
      for (const match of text.matchAll(CALL)) {
        const why = risky(match[2])
        if (why) found.push(`${file.replace(root, 'src')}: ${match[0]} — ${why}`)
      }
    }

    expect(found, found.join('\n')).toEqual([])
  })

  it('knows the shapes it forbids', () => {
    expect(risky('2026-11-15 10:30:00')).not.toBeNull()
    expect(risky('2026/11/15')).not.toBeNull()
    expect(risky('${day} ${time}')).not.toBeNull()
    expect(risky('15/11/2026')).not.toBeNull()
    expect(risky('2026-11-15T10:30:00')).toBeNull()
    expect(risky('${day}T00:00:00')).toBeNull()
    expect(risky('2026-11-15')).toBeNull()
  })
})
