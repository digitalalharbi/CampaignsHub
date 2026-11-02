import type { Plugin } from 'vite'

/**
 * PERF-RECOVERY-001 — the entry chunk is what every visitor downloads before anything else.
 *
 * It reached 4.76 MB without anybody deciding it should, because nothing measured it. This fails the
 * build, on every branch, the moment a static import pulls a page's graph back in. Raise the figure on
 * purpose or fix the import; do not remove the check.
 */
export const ENTRY_BUDGET_BYTES = 600_000

/**
 * What a visitor to a shared report actually downloads — the entry chunk AND every chunk reached by
 * a static import from either the entry or the route's own chunk (`/r/:token` → `PublicReport`).
 *
 * The route chunk's own size says little: `PublicReport` is 140 KB and pulled 1.46 MB behind it,
 * because the entry statically imported recharts and the page statically imported the snapshot
 * renderer. Measured by this plugin after the split at 1,154,478 B of JavaScript (uncompressed
 * chunk code, as `generateBundle` sees it — `scripts/route-bytes.mjs` reads the same closure from
 * the manifest and prints 1,205,170 B of files on disk); the ceiling is the plugin's figure × 1.15,
 * so a drift of a page's worth shows up as a failed build rather than a slower report.
 */
export const PUBLIC_REPORT_FACADE = '/features/reports/PublicReport.tsx'
export const PUBLIC_REPORT_BUDGET_BYTES = 1_327_000

export interface BudgetChunk {
  fileName: string
  isEntry: boolean
  bytes: number
  /** File names of the chunks this one imports statically. */
  imports?: string[]
  /** The source module this chunk is the facade for, when it is one. */
  facadeModuleId?: string | null
}

export function overBudget(chunks: BudgetChunk[], budget = ENTRY_BUDGET_BYTES): string[] {
  return chunks
    .filter((chunk) => chunk.isEntry && chunk.bytes > budget)
    .map((chunk) => `${chunk.fileName}: ${chunk.bytes} bytes, budget ${budget}`)
}

/** Every chunk reachable from `start` through static imports, `start` included. */
export function staticClosure(chunks: BudgetChunk[], start: string[]): Set<string> {
  const byName = new Map(chunks.map((chunk) => [chunk.fileName, chunk]))
  const seen = new Set<string>()
  const stack = [...start]

  while (stack.length > 0) {
    const name = stack.pop()!
    if (seen.has(name) || !byName.has(name)) continue
    seen.add(name)
    stack.push(...(byName.get(name)!.imports ?? []))
  }

  return seen
}

/**
 * The bytes a route costs on arrival: the entry's static closure plus the route chunk's.
 *
 * Returns null when no chunk is the facade for `facadeSuffix` — a route that was removed is not a
 * route over budget, and a check that fails for a missing page would be a check somebody deletes.
 */
export function routeBytes(chunks: BudgetChunk[], facadeSuffix: string): number | null {
  const route = chunks.find((chunk) => chunk.facadeModuleId?.endsWith(facadeSuffix))
  if (route === undefined) return null

  const entries = chunks.filter((chunk) => chunk.isEntry).map((chunk) => chunk.fileName)
  const closure = staticClosure(chunks, [...entries, route.fileName])
  const byName = new Map(chunks.map((chunk) => [chunk.fileName, chunk.bytes]))

  return [...closure].reduce((sum, name) => sum + (byName.get(name) ?? 0), 0)
}

export function routeOverBudget(chunks: BudgetChunk[], facadeSuffix = PUBLIC_REPORT_FACADE, budget = PUBLIC_REPORT_BUDGET_BYTES): string | null {
  const bytes = routeBytes(chunks, facadeSuffix)
  if (bytes === null || bytes <= budget) return null

  return `${facadeSuffix}: ${bytes} bytes on arrival, budget ${budget}`
}

export function bundleBudget(): Plugin {
  return {
    name: 'bundle-budget',
    apply: 'build',
    generateBundle(_options, bundle) {
      const chunks: BudgetChunk[] = Object.values(bundle).flatMap((item) =>
        item.type === 'chunk'
          ? [{ fileName: item.fileName, isEntry: item.isEntry, bytes: item.code.length, imports: item.imports, facadeModuleId: item.facadeModuleId }]
          : [],
      )
      const failures = overBudget(chunks)

      if (failures.length > 0) {
        this.error(`Entry chunk over budget — ${failures.join('; ')}`)
      }

      const route = routeOverBudget(chunks)

      if (route !== null) {
        this.error(`Route over budget — ${route}`)
      }

      const entries = chunks.filter((chunk) => chunk.isEntry).map((chunk) => chunk.fileName)
      const entryClosure = staticClosure(chunks, entries)
      const byName = new Map(chunks.map((chunk) => [chunk.fileName, chunk.bytes]))
      const entryBytes = [...entryClosure].reduce((sum, name) => sum + (byName.get(name) ?? 0), 0)
      this.info(`[bundle-budget] entry on arrival ${entryBytes} B; /r/ on arrival ${routeBytes(chunks, PUBLIC_REPORT_FACADE) ?? 'n/a'} B`)
    },
  }
}
