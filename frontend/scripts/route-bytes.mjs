#!/usr/bin/env node
/**
 * PERF-RECOVERY-001 — what a route costs on arrival, read from the build manifest.
 *
 *   npm run build && node scripts/route-bytes.mjs [src/features/reports/PublicReport.tsx]
 *
 * Follows `imports` (static only — a dynamic import is a later request, not this one) from every
 * entry and from the route's own chunk, and sums the bytes of everything reached. That is what the
 * browser downloads before the route can render; the route chunk's own size is only the last hop.
 * `src/build/bundleBudget.ts` holds the same closure to a ceiling on every build.
 */
import { readFileSync, statSync } from 'node:fs'
import { resolve } from 'node:path'

const root = resolve(new URL('..', import.meta.url).pathname)
const dist = resolve(root, 'dist')
const manifest = JSON.parse(readFileSync(resolve(dist, '.vite/manifest.json'), 'utf8'))
const route = process.argv[2] ?? 'src/features/reports/PublicReport.tsx'

const bytesOf = (file) => statSync(resolve(dist, file)).size

function closure(startKeys) {
  const seen = new Set()
  const stack = [...startKeys]

  while (stack.length > 0) {
    const key = stack.pop()
    if (seen.has(key) || manifest[key] === undefined) continue
    seen.add(key)
    stack.push(...(manifest[key].imports ?? []))
  }

  return seen
}

const sum = (keys) => [...keys].reduce((total, key) => total + bytesOf(manifest[key].file), 0)
const entries = Object.keys(manifest).filter((key) => manifest[key].isEntry)
const entry = closure(entries)
const onArrival = closure([...entries, route])

if (manifest[route] === undefined) {
  console.error(`no chunk is the facade for ${route}; dynamic entries in the manifest:`)
  for (const key of Object.keys(manifest).filter((k) => manifest[k].isDynamicEntry)) console.error('  ' + key)
  process.exit(1)
}

console.log(`entry chunk           ${bytesOf(manifest[entries[0]].file)} B  (${manifest[entries[0]].file})`)
console.log(`entry on arrival      ${sum(entry)} B  (${entry.size} chunks)`)
console.log(`${route}`)
console.log(`  route chunk         ${bytesOf(manifest[route].file)} B  (${manifest[route].file})`)
console.log(`  route on arrival    ${sum(onArrival)} B  (${onArrival.size} chunks; ${sum(onArrival) - sum(entry)} B beyond the entry)`)
console.log('largest chunks on arrival:')
for (const key of [...onArrival].sort((a, b) => bytesOf(manifest[b].file) - bytesOf(manifest[a].file)).slice(0, 12)) {
  console.log(`  ${String(bytesOf(manifest[key].file)).padStart(8)} B  ${manifest[key].file}`)
}
