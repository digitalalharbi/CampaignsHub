import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

/*
 * ANALYTICS-VIEW-ONLY-001 (Owner, 2026-11-19) — «التحليلات للاطلاع فقط»: no link to a campaign's control pages, no start or stop
 * button, no alerts, no escalation. The goal is measurement only.
 *
 * Source-level on purpose: a render test sees the data it was given, this sees the doors the code can open. A control
 * door is an import of the alerts, automation, launch or approvals features, a mutation that changes a campaign's status or
 * budget, a link to the campaign's detail, alerts, launch, approvals or automation pages that is not behind the
 * `analysisOnly` / read-only switch, and the words of an escalation.
 */
const ROOT = join(__dirname, '..')
const SURFACES = ['analytics', 'analyticsShared']

function sources(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const full = join(dir, name)
    if (statSync(full).isDirectory()) return sources(full)

    return /\.(ts|tsx)$/.test(name) && !/\.test\.(ts|tsx)$/.test(name) ? [full] : []
  })
}

const FORBIDDEN: Array<[string, RegExp]> = [
  ['imports the alerts feature', /from '@\/features\/alerts/],
  ['imports the automation feature', /from '@\/features\/automation/],
  ['imports the launch feature', /from '@\/features\/launch/],
  ['imports the agents / approvals feature', /from '@\/features\/agents/],
  ['changes a campaign (status / budget mutation)', /\b(?:useUpdateCampaign|useSetCampaignStatus|usePauseCampaign|useResumeCampaign|pauseCampaign|resumeCampaign|updateCampaignStatus|updateCampaignBudget)\b/],
  ['links to the alerts page', /['"`]\/(?:app|agency)\/alerts/],
  ['links to the launch page', /['"`]\/(?:app|agency)\/launch/],
  ['links to approvals or automation', /['"`]\/(?:app|agency)\/(?:agents|automation)/],
  ['escalates', /\bescalat/i],
]

describe('ANALYTICS-VIEW-ONLY-001 — analytics surfaces hold no control doors', () => {
  for (const surface of SURFACES) {
    for (const file of sources(join(ROOT, surface))) {
      it(`${surface}/${file.slice(join(ROOT, surface).length + 1)}`, () => {
        const text = readFileSync(file, 'utf8')
          // comments may name what is refused; only code opens doors
          .replace(/\/\*[\s\S]*?\*\//g, '')
          .replace(/^\s*\/\/.*$/gm, '')
        for (const [what, re] of FORBIDDEN) expect(re.test(text), `${what}: ${re}`).toBe(false)
      })
    }
  }

  it('a campaign name on the analysis surface is not a door (the guard behind CampaignLink)', () => {
    const page = readFileSync(join(ROOT, 'analytics/AnalyticsPage.tsx'), 'utf8')
    expect(page).toMatch(/const AnalysisOnly = createContext\(false\)/)
    expect(page).toMatch(/<AnalysisOnly\.Provider value=\{isAnalysis\}>/)
  })
})
