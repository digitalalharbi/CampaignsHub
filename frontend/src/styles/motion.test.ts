import { describe, expect, it } from 'vitest'

/**
 * UX-MOTION-TOKENS-001 — the product moves in one calm voice, and the rules that keep it so.
 *
 * Owner: «الحركة بالنظام سريعة للماوس … زي الانظمة العالمية». The binding reading (P0 §12.1) is a
 * steady, considered interaction — NOT a slowed pointer and NOT a delay in front of any action. This
 * suite pins what that means so it survives the next component written by hand.
 *
 * Sources are read through Vite (see typeScale.test.ts). The two stylesheets cannot be: this suite runs
 * with `css: false`, under which a `?raw` import of a .css file is EMPTY (measured: 0 bytes), so they are
 * read from disk through a specifier TypeScript cannot resolve — this suite's tsconfig carries no Node types.
 */
const TREE: Record<string, string> = import.meta.glob('/src/**/*.{ts,tsx}', { query: '?raw', import: 'default', eager: true })
const FS_MODULE = 'node:fs'
const { readFileSync } = (await import(/* @vite-ignore */ FS_MODULE)) as { readFileSync: (path: string, enc: string) => string }
/* jsdom replaces the global `URL` with a localhost-based one, so the directory is cut from the file URL by hand. */
const HERE = import.meta.url.replace(/^file:\/\//, '').replace(/\/[^/]*$/, '')
const css = (name: string): string => readFileSync(`${HERE}/../${name}`, 'utf8')
const ROOT: Record<string, string> = import.meta.glob(['/package.json', '/index.html'], { query: '?raw', import: 'default', eager: true })

const TOKENS = css('styles/tokens.css')
const INDEX = css('index.css')

const ms = (name: string): number => {
  const m = new RegExp(`--${name}:\\s*(\\d+)ms`).exec(TOKENS)
  if (!m) throw new Error(`token --${name} is not declared in ms`)

  return Number(m[1])
}

/** The shared primitives — where motion is decided once, not per page. */
const PRIMITIVES = Object.entries(TREE).filter(([path]) =>
  /^\/src\/(components\/ui|layouts)\//.test(path) && /\.tsx$/.test(path) && !/\.test\./.test(path))

describe('motion tokens', () => {
  it('controls answer in 120–220ms and panels in 180–300ms', () => {
    expect(ms('motion-fast')).toBeGreaterThanOrEqual(120)
    expect(ms('motion-control')).toBeGreaterThanOrEqual(120)
    expect(ms('motion-control')).toBeLessThanOrEqual(220)
    expect(ms('motion-panel')).toBeGreaterThanOrEqual(180)
    expect(ms('motion-panel')).toBeLessThanOrEqual(300)
    expect(ms('motion-fast')).toBeLessThanOrEqual(ms('motion-control'))
    expect(ms('motion-control')).toBeLessThan(ms('motion-panel'))
  })

  it('declares an entering curve and a leaving curve', () => {
    expect(TOKENS).toMatch(/--motion-ease:\s*cubic-bezier\(/)
    expect(TOKENS).toMatch(/--motion-ease-exit:\s*cubic-bezier\(/)
  })

  it('every bare `transition*` utility inherits the control duration and curve from the tokens', () => {
    expect(INDEX).toMatch(/--default-transition-duration:\s*var\(--motion-control\)/)
    expect(INDEX).toMatch(/--default-transition-timing-function:\s*var\(--motion-ease\)/)
  })
})

describe('motion rules', () => {
  it('prefers-reduced-motion collapses every animation and transition', () => {
    const block = /@media \(prefers-reduced-motion: reduce\)\s*\{[^}]*\{[^}]*\}[^}]*\}/.exec(TOKENS)?.[0] ?? ''
    expect(block).toMatch(/animation-duration:\s*0\.01ms\s*!important/)
    expect(block).toMatch(/transition-duration:\s*0\.01ms\s*!important/)
  })

  it('the user’s scrolling is never smoothed (a deliberate jump to one row may animate; the wheel and touch may not)', () => {
    const offenders = Object.entries(TREE)
      .filter(([path, source]) => !/\.test\./.test(path) && /scroll-behavior\s*:\s*smooth|scroll-smooth/.test(source))
      .map(([path]) => path)
    expect(offenders).toEqual([])
    expect(Object.values(ROOT).join('\n')).not.toMatch(/scroll-behavior\s*:\s*smooth/)
  })

  it('hover is Tailwind’s own (hover-capable pointers only): the variant is not overridden, and no stylesheet rule hovers unguarded', () => {
    expect(INDEX).not.toMatch(/@custom-variant\s+hover/)
    const unguarded = [TOKENS, INDEX].flatMap((sheet) => {
      const withoutGuarded = sheet.replace(/@media\s*\(hover:\s*hover\)\s*\{(?:[^{}]|\{[^{}]*\})*\}/g, '')

      return withoutGuarded.match(/[^{}]*:hover[^{]*\{/g) ?? []
    })
    expect(unguarded).toEqual([])
  })

  it('no animation library, only CSS', () => {
    const pkg = JSON.parse(ROOT['/package.json']) as { dependencies?: Record<string, string>; devDependencies?: Record<string, string> }
    const all = Object.keys({ ...pkg.dependencies, ...pkg.devDependencies })
    expect(all.filter((d) => /framer-motion|^motion$|react-spring|gsap|animejs|react-transition-group|auto-animate|lottie|popmotion/.test(d))).toEqual([])
  })

  it('the pointer itself is never touched', () => {
    const offenders = Object.entries(TREE)
      .filter(([path, source]) => !/\.test\./.test(path) && /pointer-events-none[^\n]*mousemove|cursor:\s*none|addEventListener\(['"]mousemove['"][^)]*preventDefault/.test(source))
      .map(([path]) => path)
    expect(offenders).toEqual([])
  })

  it('no shared primitive animates `all`, writes its own millisecond number, or delays an action', () => {
    const bad: string[] = []
    for (const [path, src] of PRIMITIVES) {
      if (/transition-all|transition:\s*all/.test(src)) bad.push(`${path}: transition-all`)
      if (/\bduration-\d+\b/.test(src)) bad.push(`${path}: literal duration`)
      if (/\b(?:delay|ease-(?:in|out|in-out|linear))-?\d*\b(?![-\w]*\[)/.test(src.replace(/ease-\[var\(--motion-ease(?:-exit)?\)\]/g, ''))) bad.push(`${path}: delay or stock easing`)
    }
    expect(bad).toEqual([])
  })

  it('a dialog fades in through the tokens and only when motion is allowed', () => {
    const modal = TREE['/src/components/ui/Modal.tsx']
    expect(modal).toMatch(/motion-safe:animate-\[fadeIn_var\(--motion-panel\)_var\(--motion-ease\)/)
  })
})
