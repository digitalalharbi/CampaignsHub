import { describe, expect, it } from 'vitest'

/**
 * MONEY-MARKS-001 — source guard. A money figure shows its short currency mark (`@/lib/money/mark`), so a file that glues a
 * currency CODE to a number on its own prints «1,234 SAR» where the product says «1,234 ر.س». And the one financial disclaimer
 * (`FinancialDisclaimer`) sits on the team-facing screens that show spend or budgets — never on a client's shared report.
 */
const sources = import.meta.glob('/src/**/*.{ts,tsx}', { query: '?raw', import: 'default', eager: true }) as Record<string, string>

/**
 * CampaignsHub's OWN subscription / billing / payment / invoice pages and the signup commitment text keep the ISO code: they state
 * what CampaignsHub charges, which is a contract figure, not an ad-platform figure. Shown here so an addition is a visible decision.
 */
const OWN_BILLING = [
  '/src/features/admin/', // the platform operator's own console: what CampaignsHub charges and spends, not ad-platform money
  '/src/features/billing/',
  '/src/features/subscriptions/',
  '/src/features/signup/',
  '/src/features/requests/portal/portalAccountApi.ts',
]

/** A figure interpolated, then a currency/unit variable — the shape of «${n} ${currency}». */
const GLUED = /\$\{[^}]+\} \$\{\s*[\w.?!\[\]]*(?:currency|Currency|unit|ccy)\s*\}|\} \{[\w.!?]*(?:currency|Currency)\}(?!\w)/

describe('money marks', () => {
  it('no screen glues a currency code to a figure on its own', () => {
    const offenders: string[] = []
    for (const [path, src] of Object.entries(sources)) {
      if (/\.test\.tsx?$/.test(path) || OWN_BILLING.some((a) => path.startsWith(a))) continue
      src.split('\n').forEach((line, i) => {
        if (line.trim().startsWith('//') || line.trim().startsWith('*')) return
        // chart/test ids and data attributes carry codes by design; they are not text a reader sees
        if (/data-testid|testId=|key=\{|inCurrency/.test(line)) return
        if (GLUED.test(line)) offenders.push(`${path}:${i + 1}: ${line.trim().slice(0, 120)}`)
      })
    }
    expect(offenders, 'format money through withMark / currencyMark / money()').toEqual([])
  })

  it('the shared formatters import the mark and nothing else decides it', () => {
    expect(sources['/src/features/analytics/format.ts']).toContain("from '@/lib/money/mark'")
    expect(sources['/src/lib/money/contract.ts']).toContain("from '@/lib/money/mark'")
    expect(sources['/src/features/funding/money.tsx']).toContain("from '@/lib/money/mark'")
    const own = Object.entries(sources).filter(([p, s]) => !p.endsWith('/lib/money/mark.ts') && !/\.test\./.test(p) && /SAR: 'ر\.س'/.test(s))
    expect(own.map(([p]) => p)).toEqual([])
  })

  it('every team-facing money screen carries the one disclaimer; a client report never does', () => {
    for (const page of [
      'campaigns/CampaignsPage', 'analytics/AnalyticsPage', 'alerts/AlertsPage', 'recommendations/RecommendationsPage',
      'agency/AgencyDashboardPage', 'projects/PortfolioPage', 'budget/SpendLimitsPage', 'funding/BudgetsPage',
    ]) {
      expect(sources[`/src/features/${page}.tsx`], page).toContain('<FinancialDisclaimer')
    }
    for (const [path, src] of Object.entries(sources)) {
      if (path.startsWith('/src/features/reports/') && !/\.test\./.test(path)) {
        expect(src, `${path} is a client-report surface and keeps its own performance note`).not.toContain('FinancialDisclaimer')
      }
    }
  })
})
