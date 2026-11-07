import { describe, expect, it } from 'vitest'

/**
 * PERF-INSTANT-DATA-001 §12.2 — the cache is NOT persisted across reloads, and this keeps it so.
 *
 * Decision: in-memory only. The reasons are structural, not timid:
 *  - the product's query keys carry the project and the filters but not the user or the membership;
 *    isolation today is "the whole client is cleared on sign-out and on a workspace switch". A
 *    storage copy would need a second, independent isolation mechanism, and every key family in the
 *    application (several hundred) would have to be audited into it;
 *  - the payloads are client money, spend and revenue; a browser profile is shared, synced and
 *    inspected by tools this product does not control;
 *  - another tab switching workspace has to end this tab's answers, and storage would outlive it.
 *
 * What replaces it is the in-memory cache with a 30-minute lifetime (`QUERY_GC_TIME_MS`), which
 * serves every revisit inside a session. If persistence is ever added, this test must be replaced
 * by one that proves user, membership, workspace, project, filters, window and currency are in the
 * key and that sign-out and a workspace switch purge it — not deleted.
 */
/* Read through Vite, not `node:fs`: this suite's tsconfig carries no Node types (see typeScale.test.ts). */
const TREE: Record<string, string> = import.meta.glob('/src/**/*.{ts,tsx}', { query: '?raw', import: 'default', eager: true })
const PACKAGE: Record<string, string> = import.meta.glob('/package.json', { query: '?raw', import: 'default', eager: true })

describe('query cache persistence', () => {
  it('no persister is installed and no dependency provides one', () => {
    const pkg = JSON.parse(Object.values(PACKAGE)[0]) as { dependencies?: Record<string, string> }
    const deps = Object.keys(pkg.dependencies ?? {})
    expect(deps.filter((d) => /query.*persist|persist.*client/i.test(d))).toEqual([])
  })

  it('nothing in the source hands the query cache to storage', () => {
    const offenders = Object.entries(TREE)
      .filter(([path, source]) => !/\.test\./.test(path) && /persistQueryClient|PersistQueryClientProvider|dehydrate\(/.test(source))
      .map(([path]) => path)
    expect(offenders).toEqual([])
  })
})
