import { money, percent, ratio, compact } from './format'
import { Num } from '@/components/ui/Num'
import type { Locale } from '@/stores/ui'

/**
 * OBJECTIVE-PATHS-001 — one total spend, its split by objective, and one compact card per objective.
 *
 * A campaign can buy two things at once: 1KCOFFEE's squads buy online-store purchases AND branch
 * visits. The backend classifies every ad set by its own optimisation goal and returns one path per
 * objective (`ObjectivePerformance::objectivePaths()`). This renders it the way the Owner asked
 * (2026-11-13):
 *
 *   - the total spend ONCE — never a second «total» per objective;
 *   - a split bar under it: each objective's share of that one total;
 *   - one card per objective with its own figures: spend (and share), its own result and cost per
 *     result; revenue and ROAS on the sales card ONLY, ROAS = sales revenue ÷ sales-path spend.
 *
 * Shared by the analytics objective tab, the client's live link and the saved report, so all three
 * say the same thing in the same shape.
 */
export interface ObjectivePath {
  family: string
  label: { ar: string; en: string }
  spend: number
  share: number | null
  revenue: number | null
  roas: number | null
  campaigns: number
  ad_sets: number
  result_key: string | null
  result: number | null
  result_label: { ar: string; en: string } | null
  cost_per_result: number | null
  cost_per_result_label: { ar: string; en: string } | null
  result_missing_label: { ar: string; en: string } | null
}

export interface ObjectivePathsBlock {
  total_spend: number
  spend_withheld_rows: number
  mixed: boolean
  mixed_campaigns: number
  paths: ObjectivePath[]
}

const TONE: Record<string, string> = {
  sales: 'bg-brand-600',
  traffic: 'bg-brand-400',
  awareness: 'bg-brand-300',
  engagement: 'bg-brand-200',
  video: 'bg-brand-200',
  leads: 'bg-success',
  app: 'bg-warning',
  unknown: 'bg-border',
}

const T = {
  title: { ar: 'الإنفاق حسب الهدف', en: 'Spend by objective' },
  total: { ar: 'إجمالي الإنفاق', en: 'Total spend' },
  spend: { ar: 'الإنفاق', en: 'Spend' },
  revenue: { ar: 'الإيراد', en: 'Revenue' },
  roas: { ar: 'العائد', en: 'ROAS' },
  mixed: { ar: 'حملات بأكثر من هدف', en: 'Campaigns with more than one objective' },
  withheld: { ar: 'جزء من الإنفاق بلا سعر صرف — لا تُحسب النسب عليه', en: 'Part of the spend has no exchange rate — no ratios over it' },
}

/**
 * `showMoney` false — a client link whose settings hide spend: no total, no split (shares are spend
 * read another way), no cost, no revenue, no ROAS; each objective keeps its own result.
 */
export function ObjectivePathCards({ block, locale, currency, showMoney = true }: { block: ObjectivePathsBlock | null | undefined; locale: Locale; currency: string | null; showMoney?: boolean }) {
  // A payload of another shape (an older saved report, a stubbed endpoint) draws nothing rather than crashing the page.
  if (!block || !Array.isArray(block.paths) || block.paths.length === 0 || typeof block.total_spend !== 'number') return null
  if (!block.paths.every((p) => p && typeof p.family === 'string' && p.label && typeof p.label === 'object')) return null
  const l = locale === 'ar' ? 'ar' : 'en'

  return (
    <section data-testid="objective-path-cards" className="rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-small)]">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="text-base font-bold tracking-tight text-text-primary">{T.title[l]}</h3>
        {block.mixed_campaigns > 0 && (
          <span className="rounded-full bg-brand-500/10 px-2 py-0.5 text-xs text-text-secondary" data-testid="objective-paths-mixed">
            {T.mixed[l]}: <Num>{block.mixed_campaigns}</Num>
          </span>
        )}
      </div>

      {showMoney && (<>
      <div className="mt-3">
        <div className="text-xs text-text-secondary">{T.total[l]}</div>
        <div className="text-2xl font-bold text-text-primary" data-testid="objective-paths-total">
          <Num>{money(block.total_spend, currency)}</Num>
        </div>
      </div>

      {/* One bar, one total: each objective's share of it. */}
      <div className="mt-3 flex h-2.5 w-full overflow-hidden rounded-full bg-border/40" role="img" aria-label={T.title[l]} data-testid="objective-paths-split">
        {block.paths.map((p) => (
          <span key={p.family} className={`block h-full ${TONE[p.family] ?? 'bg-border'}`} style={{ width: `${Math.max(0, (p.share ?? 0) * 100)}%` }} title={`${p.label[l]} ${percent(p.share, 0)}`} />
        ))}
      </div>
      </>)}

      <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {block.paths.map((p) => (
          <article key={p.family} data-testid={`objective-path-${p.family}`} className="min-w-0 rounded-xl border border-border p-3">
            <div className="flex items-center gap-2">
              <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${TONE[p.family] ?? 'bg-border'}`} />
              <span className="truncate text-sm font-semibold text-text-primary">{p.label[l]}</span>
              {showMoney && <span className="ms-auto text-xs text-text-secondary"><Num>{percent(p.share, 0)}</Num></span>}
            </div>
            <dl className="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
              {showMoney && (
                <>
                  <dt className="text-text-secondary">{T.spend[l]}</dt>
                  <dd className="text-end font-medium"><Num>{money(p.spend, currency)}</Num></dd>
                </>
              )}
              <dt className="text-text-secondary">{p.result_label?.[l] ?? '—'}</dt>
              <dd className="text-end font-medium" title={p.result_missing_label?.[l]}>
                <Num>{p.result === null ? '—' : compact(p.result)}</Num>
              </dd>
              {showMoney && p.cost_per_result_label && (
                <>
                  <dt className="text-text-secondary">{p.cost_per_result_label[l]}</dt>
                  <dd className="text-end font-medium"><Num>{money(p.cost_per_result, currency)}</Num></dd>
                </>
              )}
              {showMoney && p.family === 'sales' && (
                <>
                  <dt className="text-text-secondary">{T.revenue[l]}</dt>
                  <dd className="text-end font-medium"><Num>{money(p.revenue, currency)}</Num></dd>
                  <dt className="text-text-secondary">{T.roas[l]}</dt>
                  <dd className="text-end font-medium" data-testid="objective-path-roas"><Num>{ratio(p.roas)}</Num></dd>
                </>
              )}
            </dl>
          </article>
        ))}
      </div>

      {showMoney && block.spend_withheld_rows > 0 && <p className="mt-3 text-xs text-text-secondary">{T.withheld[l]}</p>}
    </section>
  )
}
