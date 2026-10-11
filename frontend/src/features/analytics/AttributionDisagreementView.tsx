import { useState } from 'react'
import { num } from './format'
import type { AttributionDisagreement } from './api'
import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import { providerLabel } from '@/features/campaigns/labels'

/**
 * CROSS-PLATFORM-ATTRIBUTION-DEPTH-001 — when each platform's claim parted from the store.
 *
 * The panel above sets the whole-window claim beside the store's confirmed orders. This splits the
 * same pair into periods, one platform at a time, so a platform that matched for three weeks and
 * claimed double in the fourth does not read like one that always over-claimed a little. The
 * period where the claim ran furthest ahead is named. Nothing is summed across platforms.
 */
export function AttributionDisagreementView({ data, ar }: { data: AttributionDisagreement | undefined; ar: boolean }) {
  const [chosen, setChosen] = useState<string | null>(null)
  if (!data) return null

  const locale = ar ? 'ar' : 'en'
  const t = {
    title: ar ? 'متى افترق زعم المنصة عن المتجر' : 'When each platform’s claim parted from the store',
    unavailable: ar ? 'لا يوجد متجر مربوط يُقارن به' : 'No connected store to compare against',
    none: ar ? 'لا زعم ولا طلب مؤكد في هذه الفترة' : 'No claim and no confirmed order in this window',
    period: ar ? (data.grain === 'day' ? 'اليوم' : 'الأسبوع') : (data.grain === 'day' ? 'Day' : 'Week'),
    claimed: ar ? 'زعم المنصة' : 'Platform claimed',
    confirmed: ar ? 'أكّده المتجر' : 'Store confirmed',
    gap: ar ? 'الفرق' : 'Gap',
    ratio: ar ? 'النسبة' : 'Ratio',
    widest: ar ? 'أوسع فرق' : 'Widest gap',
  }

  if (!data.available) {
    return (
      <section data-testid="attribution-disagreement" className="min-w-0 border-t border-border pt-4">
        <h4 className="text-xs font-bold uppercase tracking-wide text-text-muted">{t.title}</h4>
        <p data-testid="attribution-disagreement-unavailable" className="mt-2 text-sm font-semibold text-text-primary">{t.unavailable}</p>
      </section>
    )
  }

  const rows = data.platforms
  const active = rows.find((r) => r.provider === chosen) ?? rows[0]
  const span = (p: { from: string; to: string }) => (p.from === p.to ? p.from : `${p.from} – ${p.to}`)

  return (
    <section data-testid="attribution-disagreement" className="min-w-0 border-t border-border pt-4">
      <h4 className="text-xs font-bold uppercase tracking-wide text-text-muted">{t.title}</h4>
      <p className="mt-1 text-xs text-text-secondary">{ar ? data.note_ar : data.note_en}</p>

      {active === undefined ? (
        <p data-testid="attribution-disagreement-empty" className="mt-2 text-sm text-text-secondary">{t.none}</p>
      ) : (
        <>
          <div className="mt-2 flex flex-wrap gap-2" role="tablist">
            {rows.map((r) => (
              <button
                key={r.provider}
                type="button"
                role="tab"
                aria-selected={r.provider === active.provider}
                data-testid={`disagreement-platform-${r.provider}`}
                onClick={() => setChosen(r.provider)}
                className={`rounded-full border px-3 py-1 text-xs font-semibold ${r.provider === active.provider ? 'border-brand-500 bg-brand-500/10 text-text-primary' : 'border-border text-text-secondary'}`}
              >
                {providerLabel(r.provider, locale)}
              </button>
            ))}
          </div>

          {active.widest_gap !== null && active.widest_gap.gap > 0 && (
            <p data-testid="disagreement-widest" className="mt-2 text-xs text-text-secondary">
              {t.widest}: <span className="font-semibold text-text-primary" dir="ltr">{span(active.widest_gap)}</span>
              {' · '}{t.claimed} <span className="tnum font-semibold text-text-primary" dir="ltr">{num(active.widest_gap.claimed)}</span>
              {' · '}{t.confirmed} <span className="tnum font-semibold text-text-primary" dir="ltr">{num(active.widest_gap.confirmed)}</span>
            </p>
          )}

          <div className="mt-2 min-w-0" data-testid={`disagreement-table-${active.provider}`}>
            <MetricTable
              head={[t.period, t.claimed, t.confirmed, t.gap, t.ratio]}
              rows={active.points.map((p) => [
                <span key="p" dir="ltr" className="text-text-secondary">{span(p)}</span>,
                <span key="c">{num(p.claimed)}</span>,
                <span key="k">{num(p.confirmed)}</span>,
                <span key="g" className={p.gap > 0 ? 'font-semibold text-warning' : 'text-text-secondary'}>{p.gap > 0 ? `+${num(p.gap)}` : num(p.gap)}</span>,
                <span key="r" className="text-text-secondary">{p.ratio === null ? '—' : `${Math.round(p.ratio * 10) / 10}×`}</span>,
              ])}
              values={active.points.map((p): SortValues => [p.from, p.claimed, p.confirmed, p.gap, p.ratio])}
              initialSort={{ column: 0, dir: 'asc' }}
            />
          </div>
        </>
      )}
    </section>
  )
}
