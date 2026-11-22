import { useMemo, useState } from 'react'
import { Num } from '@/components/ui/Num'
import { money, num, percent, ratio } from '@/features/analytics/format'
import { kindName } from '@/features/content/ContentTypesComparison'
import type { FormatComparisonPayload } from '@/features/content/api'
import { FormatComparisonStrip } from './FormatComparisonStrip'
import { drawnTable, present, rowWinner, type DrawnTable } from './formatStrip'

/**
 * FORMAT-COMPARE-INTERACTIVE-001 (Owner 2026-11-21: «طور من شكل مقارنة أنواع المحتوى الى شكل تفاعلي أفضل وتحرى الدقة بالبيانات»).
 *
 * The live report's «مقارنة أنواع المحتوى» as a reading the client can turn: one figure at a time (the report's own KPIs), a bar
 * per content type with its value, its share of the figure where a share means something (counts and money, never a rate), the
 * number of creatives behind it and a small-sample mark; the best type named only where the row has a direction, at least two
 * types answer, and no tie. The scope switch reads the same figures for one objective family at a time, from the family's own
 * per-format metrics — no second arithmetic. The server's table stays one click away (and is still what the PDF and the deck draw).
 *
 * Accuracy rules carried over unchanged: no 0 or «—» bar for a type that did not report the figure, money never drawn for a reader
 * the server cut it for, and the coverage line says what the comparison is built on.
 */

type Kind = 'money' | 'rate' | 'multiplier' | 'ratio' | 'count' | undefined

function figure(kind: Kind, value: number, currency: string | null): string {
  switch (kind) {
    case 'money': return money(value, currency)
    case 'rate': return percent(value, 1)
    case 'multiplier': return ratio(value)
    case 'ratio': return value.toFixed(2)
    default: return num(value)
  }
}

/** A share of the figure is stated only where the parts add up to the whole: counts and money, never a rate or a ratio. */
const additive = (kind: Kind) => kind === 'count' || kind === 'money'

interface Bar { format: string; value: number; creatives: number; smallSample: boolean }

interface Scope { key: string; label: { ar: string; en: string } }

export function FormatComparisonInteractive({ data, ar, client, heading = true, framed = true }: { data: FormatComparisonPayload; ar: boolean; client: boolean; heading?: boolean; framed?: boolean }) {
  const hidesMoney = client || data.spend_hidden === true
  const all = useMemo(() => drawnTable(data, hidesMoney), [data, hidesMoney])
  const currency = hidesMoney ? null : data.currency

  // One objective family at a time, from the family's own per-format metrics; only families with at least two types reporting.
  const scopes = useMemo<Scope[]>(() => {
    const families = (data.objectives ?? []).filter((o) => o.family !== 'unknown' && (o.formats ?? []).length >= 2)

    return families.length >= 2 ? families.map((o) => ({ key: o.family, label: o.label })) : []
  }, [data.objectives])

  const [scope, setScope] = useState<string>('all')
  const rows = all?.rows ?? []
  const [metric, setMetric] = useState<string>(() => all?.table.verdict_order?.find((r) => rows.includes(r)) ?? rows[0] ?? '')

  if (!all || rows.length === 0) return null
  const active = rows.includes(metric) ? metric : rows[0]
  const kind = all.table.kinds?.[active] as Kind
  const better = all.table.better?.[active]
  const label = all.table.labels?.[active] ?? { ar: active, en: active }

  const bars: Bar[] = scope === 'all' ? barsFromTable(all, active) : barsFromFamily(data, scope, active, hidesMoney, kind)
  const top = bars.reduce((m, b) => Math.max(m, b.value), 0)
  const sum = bars.reduce((s, b) => s + b.value, 0)
  const winner = scope === 'all' ? rowWinner(all, active) : winnerOf(bars, better)

  const familyTotals = scope === 'all' ? null : (data.objectives ?? []).find((o) => o.family === scope)?.totals ?? null
  const creatives = scope === 'all' ? data.totals?.creatives ?? null : familyTotals?.creatives ?? null
  const withMetrics = scope === 'all' ? data.totals?.creatives_with_metrics ?? null : null

  return (
    <section data-testid="format-interactive" className={`flex min-w-0 flex-col gap-3 ${framed ? 'rounded-2xl border border-border bg-surface p-4' : ''}`}>
      <div className={`flex flex-wrap items-center justify-between gap-2 ${!heading && scopes.length === 0 ? 'hidden' : ''}`}>
        {heading ? <h3 className="text-sm font-extrabold tracking-tight text-text-primary">{ar ? 'مقارنة أنواع المحتوى' : 'Content types'}</h3> : <span />}
        {scopes.length > 0 && (
          <div role="tablist" aria-label={ar ? 'الهدف' : 'Objective'} className="inline-flex rounded-lg border border-border p-0.5 text-xs">
            {[{ key: 'all', label: { ar: 'كل الأهداف', en: 'All objectives' } }, ...scopes].map((s) => (
              <button
                key={s.key}
                type="button"
                role="tab"
                aria-selected={scope === s.key}
                data-testid={`format-scope-${s.key}`}
                onClick={() => setScope(s.key)}
                className={`rounded-md px-2.5 py-1 font-semibold transition-colors ${scope === s.key ? 'bg-brand-600 text-white' : 'text-text-secondary hover:bg-surface-hover'}`}
              >
                {ar ? s.label.ar : s.label.en}
              </button>
            ))}
          </div>
        )}
      </div>

      <div role="tablist" aria-label={ar ? 'المؤشر' : 'Figure'} className="flex flex-wrap gap-1.5">
        {rows.map((r) => {
          const l = all.table.labels?.[r] ?? { ar: r, en: r }

          return (
            <button
              key={r}
              type="button"
              role="tab"
              aria-selected={active === r}
              data-testid={`format-metric-${r}`}
              onClick={() => setMetric(r)}
              className={`rounded-full border px-3 py-1 text-xs font-semibold transition-colors ${active === r ? 'border-brand-500 bg-brand-500/10 text-brand-600' : 'border-border text-text-secondary hover:bg-surface-hover'}`}
            >
              {ar ? l.ar : l.en}
            </button>
          )
        })}
      </div>

      {bars.length === 0 ? (
        <p data-testid="format-bars-empty" className="rounded-xl border border-dashed border-border p-4 text-center text-xs text-text-secondary">
          {ar ? `لا تتوفر بيانات «${label.ar}» لأنواع المحتوى في هذا الهدف.` : `No ${label.en} by content type for this objective.`}
        </p>
      ) : (
        <ul data-testid="format-bars" className="flex flex-col gap-2.5">
          {bars.map((b) => {
            const wins = winner === b.format
            const width = top > 0 ? Math.max(4, Math.round((b.value / top) * 100)) : 0
            const share = additive(kind) && sum > 0 && bars.length > 1 ? b.value / sum : null

            return (
              <li key={b.format} data-testid={`format-bar-${b.format}`} data-winner={wins ? 'true' : undefined} className="flex flex-col gap-1">
                <div className="flex items-baseline justify-between gap-2 text-xs">
                  <span className="flex min-w-0 items-center gap-1.5 font-bold text-text-primary">
                    {kindName(b.format, ar)}
                    <span className="font-normal text-text-muted"><Num>{ar ? `${b.creatives} محتوى` : `${b.creatives} creatives`}</Num></span>
                    {b.smallSample && <span className="rounded bg-warning/15 px-1.5 py-0.5 text-xs font-semibold text-warning">{ar ? 'عينة صغيرة' : 'Small sample'}</span>}
                    {wins && <span data-testid="format-bar-best" className="rounded bg-success/15 px-1.5 py-0.5 text-xs font-bold text-success">{ar ? 'الأفضل' : 'Best'}</span>}
                  </span>
                  <span className="tnum whitespace-nowrap font-bold text-text-primary">
                    <Num>{figure(kind, b.value, currency)}</Num>
                    {share !== null && <span className="ms-1.5 font-normal text-text-muted"><Num>{`(${percent(share, 0)})`}</Num></span>}
                  </span>
                </div>
                <div className="h-2.5 w-full overflow-hidden rounded-full bg-surface-secondary" aria-hidden>
                  <div className={`h-full rounded-full transition-[width] duration-500 ${wins ? 'bg-success' : 'bg-brand-500/70'}`} style={{ width: `${width}%` }} />
                </div>
              </li>
            )
          })}
        </ul>
      )}

      <p data-testid="format-coverage" className="text-xs leading-relaxed text-text-muted">
        {coverageLine(ar, creatives, withMetrics)}
        {better && bars.length >= 2 && (ar ? ` الأفضل هنا الأ${better === 'lower' ? 'قل' : 'على'}.` : ` ${better === 'lower' ? 'Lower' : 'Higher'} is better here.`)}
        {RESULT_ROWS.has(active) && (ar ? ' النتائج المحسوبة هي المنسوبة لكل محتوى، وقد تكون أقل من إجمالي التقرير على مستوى الحملات.' : ' Results are those attributed to each creative and can be below the report’s campaign-level total.')}
      </p>

      <details data-testid="format-table-toggle" className="group">
        <summary className="cursor-pointer select-none text-xs font-semibold text-brand-600">{ar ? 'التفاصيل' : 'Details'}</summary>
        <div className="mt-2">
          <FormatComparisonStrip data={data} ar={ar} client={client} title={false} />
        </div>
      </details>
    </section>
  )
}

const RESULT_ROWS = new Set(['purchases', 'conversions', 'revenue', 'roas', 'cpa', 'leads', 'cpl', 'installs', 'cpi', 'add_to_cart', 'conversion_rate'])

function coverageLine(ar: boolean, creatives: number | null, withMetrics: number | null): string {
  if (creatives === null) return ''
  if (withMetrics !== null && withMetrics < creatives) {
    return ar ? `مبنية على ${withMetrics} من ${creatives} محتوى لها أرقام في هذه الفترة.` : `Built on ${withMetrics} of ${creatives} creatives with figures in this period.`
  }

  return ar ? `مبنية على ${creatives} محتوى.` : `Built on ${creatives} creatives.`
}

function barsFromTable(t: DrawnTable, row: string): Bar[] {
  return t.formats
    .map((f) => ({ format: f.format, value: t.cell(row, f.format), creatives: f.creatives, smallSample: f.small_sample }))
    .filter((b): b is Bar => b.value !== null)
    .sort((a, b) => (t.table.better?.[row] === 'lower' ? a.value - b.value : b.value - a.value))
}

function barsFromFamily(data: FormatComparisonPayload, family: string, row: string, hidesMoney: boolean, kind: Kind): Bar[] {
  if (hidesMoney && (kind === 'money' || kind === 'multiplier')) return []
  const o = (data.objectives ?? []).find((x) => x.family === family)
  const better = data.table?.better?.[row]

  return (o?.formats ?? [])
    .map((f) => {
      const raw = (f as unknown as { metrics?: Record<string, unknown> }).metrics?.[row] ?? (f as unknown as Record<string, unknown>)[row]

      return { format: f.format, value: present(raw) ? raw : null, creatives: f.creatives, smallSample: f.small_sample }
    })
    .filter((b): b is Bar => b.value !== null)
    .sort((a, b) => (better === 'lower' ? a.value - b.value : b.value - a.value))
}

function winnerOf(bars: Bar[], better: 'higher' | 'lower' | undefined): string | null {
  if (!better || bars.length < 2) return null
  const [first, second] = bars

  return first.value === second.value ? null : first.format
}
