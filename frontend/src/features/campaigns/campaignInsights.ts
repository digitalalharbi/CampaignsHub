import { moneyState, spendComparableAmount, type MoneyTotals } from '@/lib/money/contract'
import type { UnifiedCampaign } from './types'

/**
 * Objective-aware "result" model + the needs-attention rules, kept as pure functions so both the
 * comparison view and the needs-attention view read the SAME definitions (and so they can be unit
 * tested without rendering anything).
 *
 * The standing product rule this file encodes: a result is NOT the same thing across objectives, so
 * an awareness campaign is never scored on CPA and a sales campaign is never scored on CPM. Anything
 * that would blend the two returns `null` instead of a number.
 */

export type ResultKind = 'conversions' | 'leads' | 'installs' | 'reach' | 'clicks' | 'engagements'

export interface ResultModel {
  /** Metric that counts as one "result" for this objective. */
  metric: ResultKind
  /** Cost-per-result key on the metrics payload (already computed server-side). */
  costKey: 'cpa' | 'cpl' | 'cpi' | 'cpm' | 'cpc' | 'cpe'
  labelAr: string
  labelEn: string
  costLabelAr: string
  costLabelEn: string
}

/** Objective → what a result means. `other` has no agreed result metric, so it reports spend only. */
const RESULTS: Record<string, ResultModel> = {
  sales: { metric: 'conversions', costKey: 'cpa', labelAr: 'التحويلات', labelEn: 'Conversions', costLabelAr: 'تكلفة التحويل', costLabelEn: 'CPA' },
  conversions: { metric: 'conversions', costKey: 'cpa', labelAr: 'التحويلات', labelEn: 'Conversions', costLabelAr: 'تكلفة التحويل', costLabelEn: 'CPA' },
  leads: { metric: 'leads', costKey: 'cpl', labelAr: 'العملاء المحتملون', labelEn: 'Leads', costLabelAr: 'تكلفة العميل المحتمل', costLabelEn: 'CPL' },
  app_installs: { metric: 'installs', costKey: 'cpi', labelAr: 'التثبيتات', labelEn: 'Installs', costLabelAr: 'تكلفة التثبيت', costLabelEn: 'CPI' },
  awareness: { metric: 'reach', costKey: 'cpm', labelAr: 'الوصول', labelEn: 'Reach', costLabelAr: 'تكلفة الألف ظهور', costLabelEn: 'CPM' },
  traffic: { metric: 'clicks', costKey: 'cpc', labelAr: 'النقرات', labelEn: 'Clicks', costLabelAr: 'تكلفة النقرة', costLabelEn: 'CPC' },
  engagement: { metric: 'engagements', costKey: 'cpe', labelAr: 'التفاعلات', labelEn: 'Engagements', costLabelAr: 'تكلفة التفاعل', costLabelEn: 'CPE' },
}

export function resultModel(objective: string | null | undefined): ResultModel | null {
  return (objective && RESULTS[objective]) || null
}

/** True when a set of campaigns spans more than one result definition — the UI must not blend them. */
export function hasMixedResults(objectives: Array<string | null | undefined>): boolean {
  const kinds = new Set(objectives.map((o) => resultModel(o)?.metric ?? 'none'))
  return kinds.size > 1
}

export type AttentionSeverity = 'high' | 'medium'

export interface AttentionFlag {
  code: string
  severity: AttentionSeverity
  ar: string
  en: string
}

/** Per-campaign metric slice the rules need. Everything is optional — a missing figure is never a flag. */
export interface AttentionMetrics {
  spend?: number | null
  conversions?: number | null
  leads?: number | null
  installs?: number | null
  reach?: number | null
  clicks?: number | null
  engagements?: number | null
  impressions?: number | null
  // PARTIAL-WITHHELD-001 — money provenance, so a withheld spend is never read as «no spend».
  spend_withheld_rows?: number | null
  spend_original?: number | null
  money_original_currency?: string | null
  money_original_currencies?: number | null
}

/** Any one of these above zero means figures ARE reaching this campaign, link or no link. */
const MEASURABLE_KEYS = ['spend', 'conversions', 'leads', 'installs', 'clicks', 'impressions', 'engagements', 'reach'] as const

/**
 * Rules are deliberately conservative: each one fires only when the data actually proves the problem.
 * "No metrics at all" is reported as an unknown-state flag rather than silently looking healthy —
 * but it is never dressed up as a performance problem.
 */
export function attentionFlags(c: UnifiedCampaign, m: AttentionMetrics | undefined, reportingCurrency?: string | null): AttentionFlag[] {
  const flags: AttentionFlag[] = []
  const model = resultModel(c.objective)
  const results = model ? Number(m?.[model.metric] ?? 0) : null

  /*
   * PARTIAL-WITHHELD-001 — classify the spend by what each flag actually needs.
   *
   * PRESENCE («was there spend at all?»): a withheld/partial spend IS spend, even though its coalesced
   * value is 0 — so `spendReported` reads the money state, not `spend === 0`. Only a measured zero or
   * a truly absent figure is «no spend».
   *
   * AMOUNT («is spend over budget?»): a comparison needs a single spend figure in the budget's own
   * currency. A partial/mixed scope, or a withheld spend in another currency, has none — so
   * `spendAmount` is null and the over-budget flag simply cannot fire on a number nobody can compare.
   */
  const spendMoney = moneyState(m as MoneyTotals | undefined, 'spend')
  const spendReported = spendMoney.state !== 'zero' && spendMoney.state !== 'absent'
  // The over-budget AMOUNT comparison needs a single spend figure in the BUDGET's currency — the same
  // contract rule the command centre uses, never assuming reporting == budget currency.
  const spendAmount = spendComparableAmount(m as MoneyTotals | undefined, 'spend', reportingCurrency ?? null, c.budget_currency ?? null)

  /*
   * CAMP-UNLINKED-001 — «لا يمكن قياس أدائها», on a campaign whose performance was on screen.
   *
   * The flag fired on `external_campaigns_count === 0` and asserted that nothing could be measured.
   * But metrics reach a campaign through `unified_campaign_id`, which does not require an
   * `external_campaigns` row — so a campaign showing 176 results and a 15.36× return was labelled
   * unmeasurable in the same view that measured it.
   *
   * The link and the data are two different facts. Missing link with no data is the real problem the
   * flag was written for. Missing link WITH data is a bookkeeping gap: the figures are trustworthy,
   * and what is absent is the mapping that lets the platform's own campaign be opened beside them.
   * Saying the second as though it were the first teaches readers to disbelieve the flag.
   */
  if ((c.external_campaigns_count ?? 0) === 0) {
    const measured = MEASURABLE_KEYS.some((k) => {
      const v = m?.[k]
      return typeof v === 'number' && v > 0
    })

    flags.push(measured
      ? {
          code: 'unlinked_but_measured', severity: 'medium',
          ar: 'غير مرتبطة بحملة على منصة إعلانية — الأرقام تصل، لكن لا يمكن فتح الحملة على المنصة',
          en: 'Not linked to an ad-platform campaign — the figures arrive, but the platform campaign cannot be opened beside them',
        }
      : {
          code: 'unlinked', severity: 'high',
          ar: 'غير مرتبطة بأي حملة على منصة إعلانية — لا يمكن قياس أدائها',
          en: 'Not linked to any ad-platform campaign — its performance cannot be measured',
        })
  }

  if (c.status === 'active' && !spendReported) {
    flags.push({
      code: 'active_no_spend', severity: 'high',
      ar: 'نشطة بلا إنفاق في الفترة المحددة',
      en: 'Active but spent nothing in the selected period',
    })
  }

  if (spendReported && results !== null && results === 0) {
    flags.push({
      code: 'spend_no_results', severity: 'high',
      ar: `إنفاق بلا ${model?.labelAr ?? 'نتائج'}`,
      en: `Spend with no ${model?.labelEn?.toLowerCase() ?? 'results'}`,
    })
  }

  if (c.status === 'paused' && spendReported) {
    flags.push({
      code: 'paused_with_spend', severity: 'medium',
      ar: 'متوقفة رغم وجود إنفاق مسجَّل في الفترة',
      en: 'Paused although spend was recorded in the period',
    })
  }

  const budget = Number(c.total_budget ?? 0)
  // Amount comparison — only when spend is a single figure in the budget's currency (else no verdict).
  if (budget > 0 && spendAmount !== null && spendAmount > budget) {
    flags.push({
      code: 'over_budget', severity: 'high',
      ar: `تجاوزت الميزانية المخططة (${Math.round((spendAmount / budget) * 100)}%)`,
      en: `Over its planned budget (${Math.round((spendAmount / budget) * 100)}%)`,
    })
  }

  if (!model && c.objective === 'other') {
    flags.push({
      code: 'no_result_definition', severity: 'medium',
      ar: 'هدف الحملة «أخرى» — لا يوجد تعريف نتيجة متفق عليه لقياسها',
      en: 'Objective is "other" — no agreed result definition to measure it by',
    })
  }

  if (m === undefined) {
    flags.push({
      code: 'no_metrics', severity: 'medium',
      ar: 'لا توجد بيانات أداء لهذه الحملة في الفترة المحددة',
      en: 'No performance data for this campaign in the selected period',
    })
  }

  return flags
}

/** High-severity first, then by flag count — the worst campaigns surface at the top. */
export function attentionRank(flags: AttentionFlag[]): number {
  return flags.reduce((a, f) => a + (f.severity === 'high' ? 10 : 1), 0)
}
