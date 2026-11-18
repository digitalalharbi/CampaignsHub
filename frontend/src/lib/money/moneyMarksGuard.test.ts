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
      'agency/AgencyDashboardPage', 'projects/PortfolioPage', 'budget/SpendLimitsPage', 'funding/BudgetsPage', 'roleDashboard/RoleDashboardPage',
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

/*
 * FX-ENGINE-001 — the source guard that FAILS THE BUILD when a screen puts a currency mark on a number without the central
 * formatter (`withMark` / `formatConversion`) or the backend's convert(). The «253 $» defect had exactly this shape: an amount
 * in its own currency (EUR) printed with another currency's mark, no conversion in between.
 *
 * Three shapes are refused outside `src/lib/money/`:
 *  - MARK  — a literal mark glued to an interpolated figure: `${n} $`, `${n} ر.س`, `$${n}`, `€${n}`, `{n} ر.س<`;
 *  - GUESS — a money formatter (or a chart's `currency=` prop) handed a GUESSED currency: `money(x, c ?? 'SAR')`;
 *  - LIT   — a money formatter handed a literal currency for an amount whose own name does not state that unit
 *            (`money(spend, 'USD')`); a field the backend already converted says so (`revenue_usd`).
 */
const FX_MARK = /\$\{[^}]+\}\s?(?:ر\.س|€|\$(?![{\w]))|(?:^|[^\\\w$])\$\$\{|(?:€|ر\.س)\s?\$\{|\}\s*(?:ر\.س|€)(?=\s*<)/
const FX_GUESS = /\b(?:money|moneyExact|withMark|formatConversion)\((?:[^()]|\([^()]*\))*?,\s*[^,()]*\?\?\s*'(?:USD|SAR|EUR)'\s*\)|\bcurrency=\{[^}]*\?\?\s*'(?:USD|SAR|EUR)'\}/
const FX_LIT = /\b(?:money|moneyExact|withMark)\(\s*((?:[^,()]|\([^()]*\))+?)\s*,\s*'(USD|SAR|EUR)'\s*\)/g
/*
 * FX-APPLY-001 (builder G) — two more shapes, so the guard catches the defect one step before the formatter:
 *  - MARKFN   — `currencyMark()` outside `lib/money/` (and the funding formatter that re-exports it): gluing a mark by hand next
 *               to a figure is how «253» met «€». A figure goes through `withMark` / `formatConversion`; a LABEL unit through
 *               `labelUnit()`.
 *  - FALLBACK — a currency variable given a GUESSED default (`const cur = x.budget_currency || 'SAR'`, `currency ?? 'USD'`): the
 *               «253 $» shape before it reaches a formatter. Input defaults (a form pre-filled with SAR) are not display and are
 *               named one by one in FORM_DEFAULTS below — visible in review, never a pattern.
 */
const FX_MARKFN = /\bcurrencyMark\(/
const FX_FALLBACK = /\b\w*(?:[cC]urrency|\bcur)\w*\s*[:=]\s*[^;\n]*?(?:\?\?|\|\|)\s*'(?:USD|SAR|EUR)'/

/** The ONE file outside lib/money that may call currencyMark: the funding formatter (`Money`, `formatMoney`) re-exports it. */
const MARKFN_HOME = ['/src/features/funding/money.tsx']

/**
 * Visible, reviewed exceptions — each is NOT a figure on screen:
 *  - MARKFN: a unit label beside an INPUT the person types into (there is no figure yet to format);
 *  - FALLBACK: a FORM pre-filled with a currency the person can change (an input default, not a reading of money).
 */
const NOT_A_FIGURE: Array<{ file: string; text: string; rule: 'MARKFN' | 'FALLBACK'; why: string }> = [
  { file: '/src/features/funding/RenewAllDialog.tsx', text: '{currencyMark(currency)}</span>', rule: 'MARKFN', why: 'unit label beside the amount input' },
  { file: '/src/features/clients/billing/TabClientInvoicing.tsx', text: "currency: String(f.currency ?? 'SAR')", rule: 'FALLBACK', why: 'billing-profile form default (CampaignsHub own invoicing)' },
  { file: '/src/features/campaigns/CampaignFormModal.tsx', text: "budget_currency: campaign?.budget_currency ?? 'SAR'", rule: 'FALLBACK', why: 'campaign form default' },
  { file: '/src/features/budget/spendLimitsApi.ts', text: "project_currency: meta?.project_currency ?? 'SAR'", rule: 'FALLBACK', why: 'suggested currency for a NEW limit form' },
  { file: '/src/features/budget/SpendLimitsPage.tsx', text: "const suggestedCurrency = q.data?.project_currency ?? limits[0]?.currency ?? 'SAR'", rule: 'FALLBACK', why: 'new-limit form default' },
]

/** What the rules find on one line. */
function fxFindings(line: string): string[] {
  const t = line.trim()
  if (t.startsWith('//') || t.startsWith('*')) return []
  const out: string[] = []
  if (FX_MARK.test(line)) out.push('MARK')
  if (FX_GUESS.test(line)) out.push('GUESS')
  for (const m of line.matchAll(FX_LIT)) {
    const unit = m[2].toLowerCase()
    // `(x) => money(x, 'USD')` in a column whose value is `revenue_usd`: the unit is stated by the field the backend converted.
    if (!new RegExp(`(^|_)${unit}(_|\\b)`, 'i').test(m[1]) && !new RegExp(`_${unit}\\b`, 'i').test(line)) out.push('LIT')
  }
  if (FX_MARKFN.test(line) && !/^\s*(?:import|export)\b/.test(line)) out.push('MARKFN')
  if (FX_FALLBACK.test(line) && !out.includes('GUESS')) out.push('FALLBACK')
  return out
}

/**
 * KNOWN BYPASSES — emptied by builder G (FX-APPLY-001): the nine «253 $»-shaped defects F listed are fixed on the conversion
 * contract (docs/currency-engine/G-notes.md). The list stays so a NEW bypass has to be added here in review, visibly; the ratchet
 * below fails when an entry no longer matches. It is meant to stay empty.
 */
const KNOWN_BYPASSES: Array<{ file: string; text: string; rule: 'MARK' | 'GUESS' | 'LIT' | 'MARKFN' | 'FALLBACK'; owner: string }> = []

describe('FX-ENGINE-001 — no currency mark on a number outside the central formatter', () => {
  const findings = (): Array<{ file: string; line: string; rule: string }> => {
    const out: Array<{ file: string; line: string; rule: string }> = []
    for (const [path, src] of Object.entries(sources)) {
      if (/\.test\.tsx?$/.test(path) || path.startsWith('/src/lib/money/')) continue
      for (const line of src.split('\n')) {
        for (const rule of fxFindings(line)) {
          if (rule === 'MARKFN' && MARKFN_HOME.includes(path)) continue
          if (NOT_A_FIGURE.some((n) => n.file === path && n.rule === rule && line.includes(n.text))) continue
          out.push({ file: path, line: line.trim(), rule })
        }
      }
    }
    return out
  }

  it('the rules catch the defect shapes and pass the contract shapes', () => {
    expect(fxFindings('const s = `${amount} $`')).toContain('MARK')
    expect(fxFindings('const s = `$${amount}`')).toContain('MARK')
    expect(fxFindings('<span>{spend} ر.س</span>')).toContain('MARK')
    expect(fxFindings("money(row.spend, row.currency ?? 'USD')")).toContain('GUESS')
    expect(fxFindings("<Chart currency={x.currency ?? 'SAR'} />")).toContain('GUESS')
    expect(fxFindings("money(row.spend, 'USD')")).toContain('LIT')
    expect(fxFindings("money(r.revenue_usd, 'USD')")).toEqual([])
    expect(fxFindings("render: (r) => c(r.revenue_usd, (x) => money(x, 'USD'))")).toEqual([])
    expect(fxFindings('money(row.spend, row.currency)')).toEqual([])
    expect(fxFindings('withMark(fixed(c.converted_amount, 2), c.currency)')).toEqual([])
    // FX-APPLY-001 — a hand-glued mark and a guessed default currency
    expect(fxFindings('const own = `${raw} ${currencyMark(p.currency)}`')).toContain('MARKFN')
    expect(fxFindings("const cur = campaign.budget_currency || 'SAR'")).toContain('FALLBACK')
    expect(fxFindings("const currency = vm.currency ?? 'USD'")).toContain('FALLBACK')
    expect(fxFindings("import { currencyMark } from '@/lib/money/mark'")).toEqual([])
    expect(fxFindings('const cur = campaign.budget_currency || null')).toEqual([])
  })

  it('every NOT_A_FIGURE exception still exists — a removed one must leave the list (ratchet)', () => {
    const stale = NOT_A_FIGURE.filter((n) => !(sources[n.file] ?? '').split('\n').some((l) => l.includes(n.text) && fxFindings(l).includes(n.rule)))
    expect(stale.map((n) => `${n.file} [${n.rule}] ${n.text}`)).toEqual([])
  })

  it('no screen bypasses the central formatter beyond the listed, owned defects', () => {
    const unlisted = findings().filter((f) => !KNOWN_BYPASSES.some((k) => k.file === f.file && k.rule === f.rule && f.line.includes(k.text)))
    expect(unlisted.map((f) => `${f.file} [${f.rule}] ${f.line.slice(0, 120)}`), "format money through formatConversion / withMark with the figure's OWN currency").toEqual([])
  })

  it('every listed bypass still exists — a fixed one must be removed from the list (ratchet)', () => {
    const all = findings()
    const stale = KNOWN_BYPASSES.filter((k) => !all.some((f) => k.file === f.file && k.rule === f.rule && f.line.includes(k.text)))
    expect(stale, 'delete the fixed entries from KNOWN_BYPASSES').toEqual([])
  })

  it('the conversion contract renders through the one mark module', () => {
    expect(sources['/src/lib/money/fx.ts']).toContain("from '@/lib/money/mark'")
  })
})
