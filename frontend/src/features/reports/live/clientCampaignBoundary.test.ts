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
 *
 * CLIENT-REPORT-FRAMING-001 (Owner, 2026-10-12): campaign data belongs to campaign management, not
 * the client — not even a COUNT of campaigns. The summary's «campaigns that ran» card was removed, and
 * the exception that let it through is gone with it; content names are the only entity a client reads.
 *
 * NASEL-CAMPAIGN-BREAKDOWN-001 (Owner, 2026-10-28) — ONE explicit exception: `LiveCampaignBreakdown`, drawn only from
 * `campaign_rows`, which the server fills only when the link's own `campaign_breakdown` switch is on, with client-safe fields (no id,
 * no ad set, no audience). That file and its two mounts are exempt; the last case below holds it to those fields.
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
    .replace(/^\s*<LiveCampaignBreakdown [^\n]*\/>$/gm, '')
}

describe('the live client link', () => {
  const files = Object.entries(TREE).filter(([path]) => !/\.test\.tsx?$/.test(path) && !path.endsWith('/LiveCampaignBreakdown.tsx'))

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

  it('states no campaign count either', () => {
    const counted = files.filter(([, source]) => /campaignCount|delivering_campaigns/.test(source)).map(([path]) => path)
    expect(counted, 'a campaign count reached the client link').toEqual([])
  })

  it('lets the one opt-in breakdown read only its own client-safe rows', () => {
    const source = Object.entries(TREE).find(([path]) => path.endsWith('/LiveCampaignBreakdown.tsx'))?.[1] ?? ''
    expect(source).toMatch(/payload\.campaign_rows/)
    expect(clientCode(source)).not.toMatch(/payload\.campaigns\b|ad_sets|\.id\b|campaign_id|audience|targeting/)
  })

})
