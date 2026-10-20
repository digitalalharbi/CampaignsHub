import { describe, expect, it } from 'vitest'

/*
 * IP-AUDIT-001 — what ships to a browser carries no secret and no private operation.
 *
 * Everything under `src` is delivered to anyone who opens the product, so it is read here as a stranger
 * would read the bundle: a credential-shaped string, a private key, a provider secret assigned to a
 * constant, or a `VITE_` variable that is really a secret (Vite inlines every `VITE_` value into the
 * bundle). Test files are exempt — fixtures legitimately hold fake values — and nothing here proves the
 * absence of a secret nobody thought to pattern-match; it makes the obvious ways of shipping one fail.
 *
 * Source maps would hand the original sources, comments and all, to anyone: the build config must not
 * turn them on.
 */
const SOURCES = import.meta.glob('/src/**/*.{ts,tsx}', { query: '?raw', import: 'default', eager: true }) as Record<string, string>
const CONFIG = import.meta.glob('/vite.config.ts', { query: '?raw', import: 'default', eager: true }) as Record<string, string>

const shipped = Object.entries(SOURCES).filter(([path]) => !/\.test\.tsx?$/.test(path) && !path.includes('/test/'))

const SECRET_SHAPES: Array<[string, RegExp]> = [
  ['a live payment key', /\b(?:sk|rk)_live_[A-Za-z0-9]{10,}/],
  ['an AWS access key id', /\bAKIA[0-9A-Z]{16}\b/],
  ['a private key block', /-----BEGIN [A-Z ]*PRIVATE KEY-----/],
  ['a GitHub token', /\bgh[pousr]_[A-Za-z0-9]{30,}/],
  ['a Slack token', /\bxox[baprs]-[A-Za-z0-9-]{10,}/],
  ['a provider secret assigned to a string', /\b(?:client_secret|app_secret|api_secret|secret_key|webhook_secret|private_key)\b\s*[:=]\s*['"][A-Za-z0-9_\-+/=]{16,}['"]/i],
  ['a bearer token literal', /Bearer\s+[A-Za-z0-9\-_.]{24,}['"`]/],
]

describe('what the browser bundle ships', () => {
  it('holds no credential-shaped string', () => {
    const found: string[] = []
    for (const [path, source] of shipped) {
      for (const [what, shape] of SECRET_SHAPES) {
        if (shape.test(source)) found.push(`${path}: ${what}`)
      }
    }
    expect(found, `credential-shaped strings in shipped source:\n  ${found.join('\n  ')}`).toEqual([])
  })

  it('reads no VITE_ variable that is a secret (Vite inlines every VITE_ value into the bundle)', () => {
    const found: string[] = []
    for (const [path, source] of shipped) {
      for (const m of source.matchAll(/import\.meta\.env\.(VITE_[A-Z0-9_]+)/g)) {
        if (/(SECRET|PRIVATE|PASSWORD|TOKEN|KEY)\b/.test(m[1]) && !/PUBLISHABLE|PUBLIC/.test(m[1])) found.push(`${path}: ${m[1]}`)
      }
    }
    expect(found, `secret-named VITE_ variables read by shipped source:\n  ${found.join('\n  ')}`).toEqual([])
  })

  it('does not turn production source maps on', () => {
    const config = Object.values(CONFIG)[0] ?? ''
    expect(config.length).toBeGreaterThan(0)
    expect(config).not.toMatch(/sourcemap\s*:\s*(true|'inline'|'hidden'|"inline"|"hidden")/)
  })

  it('imports nothing server-only (no database, queue, or credential vault client) into the browser', () => {
    const found = shipped.filter(([, source]) => /from ['"](?:pg|mysql2?|ioredis|redis|stripe|@aws-sdk\/[a-z-]+)['"]/.test(source)).map(([p]) => p)
    expect(found).toEqual([])
  })
})
