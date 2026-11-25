import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { describe, expect, it } from 'vitest'
import { matchRoutes } from 'react-router-dom'
import { router } from './router'

/**
 * ROUTE-LINKS-001 — every internal address the code links to is a route the router owns.
 *
 * Owner, 2026-11-25: «يجب مراجعة كل روابط التوجيه وتفادي حدوث مشاكل روابط التوجيه مجدداً». A link to an address the
 * router does not declare lands on the catch-all — «الصفحة غير موجودة» — and nothing catches it until a person
 * clicks it. This walks every source file, collects each literal internal target (`to`, `navigate`, `href`,
 * `<Navigate>`, and template targets with their `${…}` read as one path segment), and asks the REAL route tree
 * whether it matches anything other than `*`.
 *
 * Not a substitute for clicking through: a target built entirely at run time (a variable with no literal prefix) is
 * not seen here. Everything written as a path is.
 */
const SRC = join(__dirname, '..')

/** Addresses the SPA does not own: the edge, the API, files and the public short-link and report hops. */
const NOT_SPA = [/^\/api\//, /^\/sanctum\//, /^\/storage\//, /^\/media\//, /^\/l\//, /^\/_ext\//, /^\/assets\//, /^\/demo\//, /^\/broadcasting\//, /^\/oauth\//, /\.[a-z0-9]{2,5}$/i]

/** The patterns a target is written in across the codebase. Group 1 is the address. */
const PATTERNS = [
  /\bto\s*[:=]\s*\{?\s*['"`](\/[^'"`\s]*)['"`]/g,
  /\bnavigate\(\s*['"`](\/[^'"`\s]*)['"`]/g,
  /\bhref\s*[:=]\s*\{?\s*['"`](\/[^'"`\s]*)['"`]/g,
  /\bpath\s*:\s*['"`](\/[^'"`\s]*)['"`]\s*,\s*(?:ar|label|en|icon)\b/g,
]

function sources(dir: string, out: string[] = []): string[] {
  for (const name of readdirSync(dir)) {
    const full = join(dir, name)
    if (statSync(full).isDirectory()) {
      sources(full, out)
    } else if (/\.(tsx?|jsx?)$/.test(name) && !/\.(test|spec)\.[tj]sx?$/.test(name) && !full.includes('__tests__')) {
      out.push(full)
    }
  }
  return out
}

/**
 * A target's possible addresses. A leading `${portal}` / `${base}` segment is the portal the component is mounted
 * in, so it expands to both portal roots; any other `${…}` is one path segment, or — glued to text, as in
 * `content${query}` — a query string, and is dropped.
 */
const PORTAL_ROOTS = ['app', 'agency']

function normalise(raw: string): string[] {
  // An open `${` means the address is concatenated beyond what the pattern caught: not a whole address.
  if ((raw.match(/\$\{/g) ?? []).length !== (raw.match(/\}/g) ?? []).length) return []
  let path = raw.replace(/([A-Za-z0-9-])(\$\{[^}]*\})+$/, '$1').split(/[?#]/)[0]
  const portalLed = /^\/?\$\{[^}]*\}(\/|$)/.test(path)
  path = path.replace(/^\/?\$\{[^}]*\}/, '/__PORTAL__').replace(/\$\{[^}]*\}/g, 'x')
  if (path.length > 1) path = path.replace(/\/+$/, '')
  if (path === '' || path.startsWith('//')) return []
  return portalLed ? PORTAL_ROOTS.map((r) => path.replace('/__PORTAL__', `/${r}`)) : [path]
}

function collect(): Array<{ file: string; target: string; variants: string[] }> {
  const found: Array<{ file: string; target: string; variants: string[] }> = []
  for (const file of sources(SRC)) {
    const text = readFileSync(file, 'utf8')
    for (const pattern of PATTERNS) {
      for (const m of text.matchAll(pattern)) {
        const variants = normalise(m[1]).filter((t) => !NOT_SPA.some((re) => re.test(t)))
        if (variants.length > 0) {
          found.push({ file: relative(SRC, file), target: m[1], variants })
        }
      }
    }
  }
  return found
}

describe('internal link targets', () => {
  const targets = collect()

  it('finds the links it is meant to audit', () => {
    // A regex that silently stopped matching would make the next test vacuous.
    expect(targets.length).toBeGreaterThan(200)
    expect(targets.some((t) => t.variants.includes('/agency/dashboard'))).toBe(true)
  })

  it('every literal target resolves to a declared route, not the catch-all', () => {
    const resolves = (path: string) => {
      const matches = matchRoutes(router.routes, path)
      return Boolean(matches && matches.length > 0 && matches[matches.length - 1].route.path !== '*')
    }
    /*
     * A portal-led target must resolve in at least one portal; a fixed one as written. A section fragment
     * (`'/automation'` in a nav table) is joined to its portal by a helper at render time, so it must resolve under
     * one of the portal roots when it does not resolve on its own.
     */
    const PREFIXES = ['', '/app', '/agency', '/portal', '/admin']
    const broken = targets.filter(({ variants }) => !variants.some((v) => PREFIXES.some((p) => resolves(`${p}${v}`))))

    const report = [...new Set(broken.map((b) => `${b.target}  ←  ${b.file}`))].sort()
    expect(report, `links that land on «الصفحة غير موجودة»:\n${report.join('\n')}`).toEqual([])
  })
})
