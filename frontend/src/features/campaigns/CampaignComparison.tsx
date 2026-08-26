import { useMemo } from 'react'
import { formatMoneyReading, readMoney } from '@/lib/money/contract'
import { displaySpend } from '@/features/dashboard/platformMoney'
import { Link } from 'react-router-dom'
import { ImageOff, Info, TriangleAlert, X } from 'lucide-react'
import { useCampaignComparison, type CompareCampaign } from './compareApi'
import { resultModel } from './campaignInsights'
import { campaignStatusLabel, campaignStatusTone, objectiveLabel } from './labels'
import type { UnifiedCampaign } from './types'
import { Badge } from '@/components/ui/Badge'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { ChartCard, MetricLineChart } from '@/features/analytics/charts'
import { platformColor } from '@/features/analytics/components'
import { compact, money, num, ratio } from '@/features/analytics/format'

const MAX = 5

/**
 * CAMPAIGN-020 — side-by-side comparison of 2–5 campaigns of the active project.
 *
 * Two product rules are enforced visually, not just described: results are counted with each
 * campaign's OWN objective definition, and when the picked campaigns do not share one objective the
 * view says so and refuses to present a "best/worst" verdict across them.
 */
export function CampaignComparison({
  projectId, campaigns, selected, onToggle, onClear, range, locale,
}: {
  projectId: string
  campaigns: UnifiedCampaign[]
  selected: string[]
  onToggle: (id: string) => void
  onClear: () => void
  range: { from: string; to: string }
  locale: 'ar' | 'en'
}) {
  const q = useCampaignComparison(projectId, selected, range)
  const rows = q.data?.campaigns ?? []
  const mixed = q.data?.mixed_objectives ?? false

  return (
    <div className="space-y-4">
      {/* Picker — the whole project's campaigns as toggles, capped at MAX. */}
      <div className="rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-small)]">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 className="font-bold text-text-primary">اختر حملات للمقارنة</h2>
            <p className="mt-0.5 text-xs text-text-muted">
              من حملتين إلى <span className="tnum">{MAX}</span> حملات من هذا المشروع — <span className="tnum">{selected.length}</span> محددة.
            </p>
          </div>
          {selected.length > 0 && (
            <button onClick={onClear} className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-text-secondary hover:bg-surface-hover">
              <X size={13} /> مسح التحديد
            </button>
          )}
        </div>
        <div className="mt-3 flex flex-wrap gap-1.5">
          {campaigns.map((c) => {
            const on = selected.includes(c.id)
            const full = !on && selected.length >= MAX
            return (
              <button
                key={c.id}
                data-testid="compare-pick"
                aria-pressed={on}
                disabled={full}
                onClick={() => onToggle(c.id)}
                className={`rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors ${
                  on ? 'border-brand-500 bg-brand-primary-soft text-brand-700'
                    : full ? 'cursor-not-allowed border-border text-text-muted opacity-50'
                    : 'border-border text-text-secondary hover:border-brand-300 hover:bg-surface-hover'
                }`}
              >
                {c.name}
              </button>
            )
          })}
        </div>
      </div>

      {selected.length < 2 ? (
        <EmptyState title="اختر حملتين على الأقل" description="المقارنة تحتاج حملتين أو أكثر لتكون ذات معنى." />
      ) : q.isLoading ? (
        <Skeleton className="h-64" />
      ) : q.isError ? (
        <EmptyState title="تعذّر تحميل المقارنة" description="حاول تحديث الصفحة أو تغيير الفترة." />
      ) : rows.length === 0 ? (
        <EmptyState title="لا توجد بيانات للمقارنة" description="لا توجد قياسات لهذه الحملات في الفترة المحددة." />
      ) : (
        <>
          {mixed && (
            <div className="flex items-start gap-2 rounded-xl border border-warning/40 bg-warning/10 p-3 text-sm text-text-primary">
              <TriangleAlert size={16} className="mt-0.5 shrink-0 text-warning" />
              <p>
                الحملات المحددة لا تشترك في هدف واحد ({(q.data?.objectives ?? []).map((o) => objectiveLabel(o, locale)).join(' · ')}).
                لذلك تُعرض نتيجة كل حملة بمقياسها الخاص، ولا يُعرض ترتيب «الأفضل» بينها — مقارنة الوعي بالمبيعات مضللة.
              </p>
            </div>
          )}

          <ComparisonTable rows={rows} locale={locale} mixed={mixed} projectId={projectId} />
          <TrendComparison rows={rows} />
          <div className="grid gap-4 lg:grid-cols-2">
            <PlatformSplit rows={rows} />
            <CreativeSplit rows={rows} />
          </div>
        </>
      )}
    </div>
  )
}

/** Metric rows down the side, one column per campaign. Cost-per-result uses each campaign's own key. */
function ComparisonTable({ rows, locale, mixed, projectId }: {
  rows: CompareCampaign[]; locale: 'ar' | 'en'; mixed: boolean; projectId: string
}) {
  // Best value per numeric row, only when the campaigns share one objective.
  const best = (values: Array<number | null>, lowerIsBetter: boolean): number | null => {
    if (mixed) return null
    const real = values.filter((v): v is number => typeof v === 'number' && Number.isFinite(v))
    if (real.length < 2) return null
    return lowerIsBetter ? Math.min(...real) : Math.max(...real)
  }

  /*
    MONEY-TRUTH-002 — the spend row, and the unit it is stated in.

    `Number(r.totals.spend ?? 0)` is the coalesced zero, and it was then labelled with the campaign's
    `budget_currency` — the unit of the PLAN, not of the spend. Two different mistakes on one cell:
    a campaign spending 2,500 USD against a riyal budget read «0 SAR».

    `totals` carries the withheld provenance, so the contract can answer both.
  */
  const spendReadings = rows.map((r) => readMoney(r.totals as Record<string, unknown>, 'spend', null, true))
  const results = rows.map((r) => {
    const m = resultModel(r.objective)
    return m ? Number(r.totals[m.metric] ?? 0) : null
  })
  const costs = rows.map((r) => {
    const m = resultModel(r.objective)
    return m ? (r.totals[m.costKey] as number | null) ?? null : null
  })
  const roas = rows.map((r) => (r.totals.roas as number | null) ?? null)

  const bestResults = best(results, false)
  const bestCost = best(costs, true)
  const bestRoas = best(roas, false)

  const cell = (isBest: boolean) =>
    `tnum p-3 text-end ${isBest ? 'font-extrabold text-success' : 'text-text-primary'}`

  return (
    <div className="overflow-hidden rounded-2xl border border-border bg-surface shadow-[var(--shadow-small)]">
      <div className="overflow-x-auto">
        <table data-testid="compare-table" className="w-full min-w-[640px] text-sm">
          <thead>
            <tr className="border-b border-border">
              <th className="p-3 text-start text-text-muted">المؤشر</th>
              {rows.map((r) => (
                <th key={r.campaign_id} className="p-3 text-end">
                  <Link to={`/campaigns/${projectId}/${r.campaign_id}`} className="font-bold text-text-primary hover:text-brand-600">{r.name}</Link>
                  <div className="mt-1 flex justify-end gap-1">
                    {r.status && <Badge tone={campaignStatusTone(r.status)}>{campaignStatusLabel(r.status, locale)}</Badge>}
                    {r.objective && <Badge tone="neutral">{objectiveLabel(r.objective, locale)}</Badge>}
                  </div>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            <tr className="border-b border-border">
              <td className="p-3 text-text-secondary">الإنفاق</td>
              {rows.map((r, i) => <td key={r.campaign_id} className="tnum p-3 text-end text-text-primary">{formatMoneyReading(spendReadings[i], money)}</td>)}
            </tr>
            <tr className="border-b border-border">
              <td className="p-3 text-text-secondary">النتائج <span className="text-xs text-text-muted">(حسب هدف كل حملة)</span></td>
              {rows.map((r, i) => {
                const m = resultModel(r.objective)
                return (
                  <td key={r.campaign_id} className={cell(results[i] !== null && results[i] === bestResults)}>
                    {m ? <>{num(results[i])} <span className="block text-xs font-normal text-text-muted">{m.labelAr}</span></> : <span className="text-text-muted">لا تعريف نتيجة</span>}
                  </td>
                )
              })}
            </tr>
            <tr className="border-b border-border">
              <td className="p-3 text-text-secondary">تكلفة النتيجة</td>
              {rows.map((r, i) => {
                const m = resultModel(r.objective)
                return (
                  <td key={r.campaign_id} className={cell(costs[i] !== null && costs[i] === bestCost)}>
                    {m && costs[i] !== null
                      ? <>{money(costs[i], r.budget_currency ?? 'SAR')} <span className="block text-xs font-normal text-text-muted">{m.costLabelAr}</span></>
                      : <span className="text-text-muted">—</span>}
                  </td>
                )
              })}
            </tr>
            <tr className="border-b border-border">
              <td className="p-3 text-text-secondary">العائد على الإنفاق</td>
              {rows.map((r, i) => (
                <td key={r.campaign_id} className={cell(roas[i] !== null && roas[i] === bestRoas)}>
                  {roas[i] !== null ? ratio(roas[i]) : <span className="text-text-muted">—</span>}
                </td>
              ))}
            </tr>
            <tr className="border-b border-border last:border-0">
              <td className="p-3 text-text-secondary">الظهور / النقرات</td>
              {rows.map((r) => (
                <td key={r.campaign_id} className="tnum p-3 text-end text-text-primary">
                  {compact(Number(r.totals.impressions ?? 0))} <span className="text-text-muted">/</span> {compact(Number(r.totals.clicks ?? 0))}
                </td>
              ))}
            </tr>
            <tr>
              <td className="p-3 text-text-secondary">الميزانية المخططة</td>
              {rows.map((r) => (
                <td key={r.campaign_id} className="tnum p-3 text-end text-text-primary">
                  {r.total_budget ? money(r.total_budget, r.budget_currency ?? 'SAR') : <span className="text-text-muted">غير محددة</span>}
                </td>
              ))}
            </tr>
          </tbody>
        </table>
      </div>
      {!mixed && (
        <div className="flex items-center gap-1.5 border-t border-border px-3 py-2 text-xs text-text-muted">
          <Info size={13} /> القيمة <span className="font-bold text-success">الخضراء</span> هي الأفضل بين الحملات المحددة — تظهر فقط عندما تشترك في نفس الهدف.
        </div>
      )}
    </div>
  )
}

/** Daily spend per campaign on one axis — the trend requirement of CAMPAIGN-020. */
function TrendComparison({ rows }: { rows: CompareCampaign[] }) {
  const merged = useMemo(() => {
    const dates = new Set<string>()
    for (const r of rows) for (const p of r.series) dates.add(String(p.date))
    return [...dates].sort().map((date) => {
      const point: Record<string, unknown> = { date }
      for (const r of rows) {
        const hit = r.series.find((p) => String(p.date) === date)
        point[r.campaign_id] = Number(hit?.spend ?? 0)
      }
      return point
    })
  }, [rows])

  return (
    <ChartCard title="اتجاه الإنفاق اليومي" subtitle="كل حملة على حدة خلال نفس الفترة">
      {merged.length === 0
        ? <EmptyState title="لا توجد نقاط زمنية في هذه الفترة" />
        : <MetricLineChart data={merged} series={rows.map((r) => ({ key: r.campaign_id, name: r.name, kind: 'money' as const }))} height={230} />}
    </ChartCard>
  )
}

/** Where each campaign's money actually went, per ad platform. */
function PlatformSplit({ rows }: { rows: CompareCampaign[] }) {
  return (
    <ChartCard title="توزيع الإنفاق حسب المنصة" subtitle="لكل حملة على حدة">
      <div className="space-y-3">
        {rows.map((r) => {
          /*
            MONEY-TRUTH-002 — read the figure that is real, then divide by it.

            `p.spend` is the coalesced 0 when no rate exists, so on such an account every campaign
            here totalled 0, the «total > 0» guard hid the whole chart, and the shares that would
            have been drawn divided by zero. `displaySpend` is the same reader the dashboard's
            platform rows use.
          */
          const spendOf = (p: (typeof r.platforms)[number]) => displaySpend(p)
          const total = r.platforms.reduce((a, p) => a + spendOf(p), 0)

          /*
           * MONEY-TRUTH-002 — proportion bars are a ranking, and a ranking needs one currency.
           *
           * When the server reports `platform_ranking: 'unavailable'` — a converted platform beside a
           * withheld one, or two withheld currencies — summing `displaySpend` across them is a
           * cross-currency total, and drawing shares from it invents the very ranking the backend
           * refused to. The platforms are still listed with each one's real figure, but no bar and no
           * share, because there is no single denominator they can honestly divide.
           */
          if (r.platform_ranking === 'unavailable') {
            return (
              <div key={r.campaign_id}>
                <div className="flex items-center justify-between text-xs">
                  <span className="font-semibold text-text-primary">{r.name}</span>
                  <span className="text-[11px] text-text-muted">لا يمكن ترتيبها بعملة واحدة</span>
                </div>
                <div className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-[11px] text-text-muted">
                  {r.platforms.map((p) => (
                    <span key={p.provider} className="inline-flex items-center gap-1">
                      <span className="h-2 w-2 rounded-full" style={{ background: platformColor(p.provider) }} />
                      {p.provider} <span className="tnum">{compact(spendOf(p))}</span>
                    </span>
                  ))}
                </div>
              </div>
            )
          }

          return (
            <div key={r.campaign_id}>
              <div className="flex items-center justify-between text-xs">
                <span className="font-semibold text-text-primary">{r.name}</span>
                <span className="tnum text-text-muted">{compact(total)}</span>
              </div>
              {total > 0 ? (
                <>
                  <div className="mt-1 flex h-2.5 overflow-hidden rounded-full bg-surface-secondary">
                    {r.platforms.map((p) => (
                      <span key={p.provider} title={`${p.provider} — ${compact(spendOf(p))}`} style={{ width: `${(spendOf(p) / total) * 100}%`, background: platformColor(p.provider) }} />
                    ))}
                  </div>
                  <div className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-[11px] text-text-muted">
                    {r.platforms.map((p) => (
                      <span key={p.provider} className="inline-flex items-center gap-1">
                        <span className="h-2 w-2 rounded-full" style={{ background: platformColor(p.provider) }} />
                        {p.provider} <span className="tnum">{Math.round((spendOf(p) / total) * 100)}%</span>
                      </span>
                    ))}
                  </div>
                </>
              ) : (
                <p className="mt-1 text-[11px] text-text-muted">لا يوجد إنفاق مسجَّل على أي منصة في هذه الفترة.</p>
              )}
            </div>
          )
        })}
      </div>
    </ChartCard>
  )
}

/** Top creatives per campaign. Thumbnails are shown only when the platform actually returned one. */
function CreativeSplit({ rows }: { rows: CompareCampaign[] }) {
  return (
    <ChartCard title="أبرز المحتويات" subtitle="أعلى ٣ إنفاقًا في كل حملة">
      <div className="space-y-3">
        {rows.map((r) => (
          <div key={r.campaign_id}>
            <div className="text-xs font-semibold text-text-primary">{r.name}</div>
            {r.creatives.length === 0 ? (
              <p className="mt-1 text-[11px] text-text-muted">لا توجد محتويات مرتبطة بقياسات في هذه الفترة.</p>
            ) : (
              <ul className="mt-1.5 space-y-1.5">
                {r.creatives.map((cr) => (
                  <li key={cr.creative_id} className="flex items-center gap-2 rounded-lg bg-surface-secondary p-1.5">
                    {cr.thumbnail_url
                      ? <img src={cr.thumbnail_url} alt="" className="h-9 w-9 shrink-0 rounded object-cover" />
                      : <span title="المنصة لم توفر معاينة" className="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-surface text-text-muted"><ImageOff size={14} /></span>}
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-xs font-semibold text-text-primary">{cr.name}</span>
                      <span className="block text-[11px] text-text-muted">{cr.provider} · {cr.format}</span>
                    </span>
                    <span className="tnum shrink-0 text-xs text-text-secondary">{compact(cr.spend)}</span>
                  </li>
                ))}
              </ul>
            )}
          </div>
        ))}
      </div>
    </ChartCard>
  )
}
