import type { Plugin } from 'vite'

/**
 * PERF-RECOVERY-001 — the entry chunk is what every visitor downloads before anything else.
 *
 * It reached 4.76 MB without anybody deciding it should, because nothing measured it. This fails the
 * build, on every branch, the moment a static import pulls a page's graph back in. Raise the figure on
 * purpose or fix the import; do not remove the check.
 */
export const ENTRY_BUDGET_BYTES = 600_000

export function overBudget(chunks: { fileName: string; isEntry: boolean; bytes: number }[], budget = ENTRY_BUDGET_BYTES): string[] {
  return chunks
    .filter((chunk) => chunk.isEntry && chunk.bytes > budget)
    .map((chunk) => `${chunk.fileName}: ${chunk.bytes} bytes, budget ${budget}`)
}

export function bundleBudget(): Plugin {
  return {
    name: 'bundle-budget',
    apply: 'build',
    generateBundle(_options, bundle) {
      const chunks = Object.values(bundle).flatMap((item) =>
        item.type === 'chunk' ? [{ fileName: item.fileName, isEntry: item.isEntry, bytes: item.code.length }] : [],
      )
      const failures = overBudget(chunks)

      if (failures.length > 0) {
        this.error(`Entry chunk over budget — ${failures.join('; ')}`)
      }
    },
  }
}
