import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * PERF-RECOVERY-001 — one static page import pulls that page's whole graph back into the entry chunk,
 * and nothing about the app looks different when it happens: it just gets slower for everybody.
 */
describe('the router loads pages on demand', () => {
  const source = readFileSync(resolve(__dirname, 'router.tsx'), 'utf8')

  it('imports no page or shell statically', () => {
    const offenders = source
      .split('\n')
      .filter((line) => /^import \{[^}]*\} from '@\/(features|layouts)\//.test(line))
      // Four features declare their own route tables. They are held by the entry-chunk budget instead.
      .filter((line) => !/^import \{ \w+Routes \} from /.test(line))

    expect(offenders).toEqual([])
  })

  it('still routes a shared report through a lazy page', () => {
    expect(source).toMatch(/const PublicReport = lazyPage\(\(\) => import\('@\/features\/reports\/PublicReport'\), 'PublicReport'\)/)
  })
})
