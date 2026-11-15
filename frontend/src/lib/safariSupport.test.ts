import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * NOV-SAFARI-001 — no CSS or JS feature the product's browsers do not all have.
 *
 * The floor is Safari 16.4, the same one Tailwind v4 sets for the stylesheet itself. Everything up to there is
 * used freely (`dvh`, `:has()`, container queries, `structuredClone`, `Array.prototype.at`, `findLast`). What is
 * refused is what arrived after it, or never reached Safari at all, unless the same file feature-detects it:
 *
 *   `requestIdleCallback`            never in Safari
 *   `Object.groupBy` / `Map.groupBy`, `Promise.withResolvers`, `Array.fromAsync`, `Set` algebra   17.4 and later
 *   `crypto.randomUUID()`            secure context only — use `lib/uuid.ts`
 *
 * CSS that an older browser merely ignores (`text-wrap: balance`, `@starting-style`, `field-sizing`) is a progressive
 * enhancement, not a defect: the text wraps normally and the element is still there. It is not listed. */
function sources(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    if (statSync(path).isDirectory()) return name === 'node_modules' ? [] : sources(path)

    return /\.(ts|tsx|css)$/.test(name) && !/\.test\.(ts|tsx)$/.test(name) ? [path] : []
  })
}

const BANNED: Array<{ name: string; re: RegExp; unlessFile?: RegExp }> = [
  { name: 'requestIdleCallback', re: /\brequestIdleCallback\s*\(/, unlessFile: /['"]requestIdleCallback['"]\s+in\s+window|typeof\s+requestIdleCallback/ },
  { name: 'Object.groupBy / Map.groupBy', re: /\b(?:Object|Map)\.groupBy\(/ },
  { name: 'Promise.withResolvers', re: /Promise\.withResolvers\(/ },
  { name: 'Array.fromAsync', re: /Array\.fromAsync\(/ },
  { name: 'Set algebra', re: /\.(?:union|intersection|difference|symmetricDifference|isSubsetOf|isSupersetOf|isDisjointFrom)\(new Set|new Set\([^)]*\)\.(?:union|intersection|difference)\(/ },
  { name: 'crypto.randomUUID() called directly', re: /\bcrypto\.randomUUID\(\)/, unlessFile: /'randomUUID'\s+in\s+crypto|typeof\s+crypto\.randomUUID/ },
]

describe('NOV-SAFARI-001 — only features Safari 16.4 has', () => {
  const root = join(__dirname, '..')
  const files = sources(root)

  for (const rule of BANNED) {
    it(`does not use ${rule.name}`, () => {
      const found: string[] = []
      for (const file of files) {
        const text = readFileSync(file, 'utf8')
        // A line comment or a block comment that merely names the feature is not a use of it.
        const code = text.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')
        if (rule.re.test(code) && !(rule.unlessFile && rule.unlessFile.test(code))) found.push(file.replace(root, 'src'))
      }

      expect(found, found.join('\n')).toEqual([])
    })
  }

  it('keeps the phone foundations in the stylesheet', () => {
    const css = readFileSync(join(root, 'index.css'), 'utf8')
    expect(css).toContain('safe-area-inset-left')
    expect(css).toMatch(/\(max-width: 767px\), \(pointer: coarse\)[\s\S]*font-size: 16px/)
    expect(css).toMatch(/\(pointer: coarse\)[\s\S]*min-height: 44px/)
  })

  it('lets the page reach the notch so the safe-area insets mean something', () => {
    const html = readFileSync(join(root, '..', 'index.html'), 'utf8')
    expect(html).toContain('viewport-fit=cover')
  })
})
