import { describe, expect, it } from 'vitest'

/**
 * CLIENT-REPORT-ENTITY-BOUNDARY-001, Owner-final — a client-facing report never names a campaign and
 * never drills into campaign management. The client's drilldown is platform → content, and nothing else.
 *
 * `ClientEntityBoundary` removes campaign identity from the PAYLOAD; this holds the other half: the
 * live link's own code reads no campaign field and offers no campaign view, so a key that ever slipped
 * past the server would still have nothing on the page to render it.
 *
 * Two uses are allowed and removed before the check, each for a stated reason: the platform-label
 * helper happens to live under `features/campaigns/`, and the request carries `campaigns: []` because
 * the server's ceiling is expressed in campaign ids that the reader neither sets nor sees.
 */
const TREE: Record<string, string> = import.meta.glob(
  ['/src/features/reports/live/*.ts', '/src/features/reports/live/*.tsx', '/src/features/reports/LiveSharedReport.tsx'],
  { query: '?raw', import: 'default', eager: true },
)

function clientCode(source: string): string {
  return source
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/\{\/\*[\s\S]*?\*\/\}/g, '')
    .replace(/(^|[^:])\/\/[^\n]*/g, '$1')
    .replace(/^import .*$/gm, '')
    .replace(/campaigns: \[\],/g, '')
}

describe('the live client link', () => {
  const files = Object.entries(TREE).filter(([path]) => !/\.test\.tsx?$/.test(path))

  it('is reading the files it claims to', () => {
    expect(files.length, 'the live link moved — point this guard at it').toBeGreaterThanOrEqual(6)
  })

  it('names no campaign and offers no campaign view', () => {
    const offenders = files.flatMap(([path, source]) =>
      clientCode(source)
        .split('\n')
        .map((line, i) => [path, i + 1, line.trim()] as const)
        .filter(([, , line]) => /campaign|حمل/i.test(line))
        .map(([p, n, line]) => `${p}:${n} ${line}`),
    )

    expect(offenders, 'campaign identity or a campaign drilldown reached the client link:\n  ' + offenders.join('\n  ')).toEqual([])
  })
})
