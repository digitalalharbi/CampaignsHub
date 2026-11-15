import { KpiSparkline } from '@/features/analytics/charts'
import { compact, num } from '@/features/analytics/format'
import { FinancialDisclaimer } from '@/features/funding/FinancialDisclaimer'
import { formatMoney } from '@/features/funding/money'
import type { AnswerCard, MessageMeta } from './api'
import { toolText } from './agentCopy'

/**
 * AGENTS-ASSISTANT-001 — the data beside an answer, drawn from the cards the SERVER cut from the tool results.
 *
 * Compact on purpose: at most six rows, one mini chart, Latin digits, money with its currency mark through the shared helper
 * (`formatMoney`), the one financial disclaimer wherever money shows, and «المصدر» naming which data and which period the answer
 * read. A figure the tools did not return is «—», never a zero.
 */
const amount = (n: number | null, currency: string | null) => (n === null ? '—' : currency ? formatMoney(n, currency, n < 100 ? 2 : 0) : compact(n))

const period = (w: { from?: string; to?: string }, ar: boolean) =>
  w.from && w.to ? (w.from === w.to ? w.from : `${w.from} → ${w.to}`) : ar ? 'الفترة غير محددة' : 'period not stated'

const VERDICT: Record<string, { ar: string; en: string; tone: string }> = {
  on_target: { ar: 'على الهدف', en: 'On target', tone: 'bg-[var(--positive-background)] text-success' },
  off_target: { ar: 'خارج الهدف', en: 'Off target', tone: 'bg-[var(--negative-background)] text-danger' },
  not_judged: { ar: 'إنفاق غير كافٍ', en: 'Too little spend', tone: 'bg-surface-secondary text-text-secondary' },
  no_figure: { ar: 'لا توجد بيانات', en: 'No data', tone: 'bg-surface-secondary text-text-muted' },
}

const FATIGUE: Record<string, { ar: string; en: string }> = {
  fatigued: { ar: 'مُجهد', en: 'Fatigued' },
  watch: { ar: 'راقبه', en: 'Watch' },
  stable: { ar: 'مستقر', en: 'Stable' },
  insufficient_data: { ar: 'بيانات قليلة', en: 'Little data' },
}

export function AnswerCards({ meta, ar }: { meta: MessageMeta; ar: boolean }) {
  const cards = meta.cards ?? []
  const showsMoney = cards.some((c) => c.type === 'campaigns' || c.type === 'series')
  if (cards.length === 0 && (meta.sources ?? []).length === 0) return null

  return (
    <div className="mt-2 min-w-0 space-y-2" data-testid="answer-cards">
      {cards.map((c, i) => <Card key={`${c.type}-${i}`} card={c} ar={ar} />)}
      {showsMoney && <FinancialDisclaimer />}
      {(meta.sources ?? []).length > 0 && (
        <p className="flex flex-wrap items-center gap-1 text-xs text-text-muted" data-testid="answer-sources">
          <span className="font-semibold">{ar ? 'المصدر:' : 'Source:'}</span>
          {(meta.sources ?? []).map((s, i) => (
            <span key={`${s.tool}-${i}`} className="rounded-full border border-border px-1.5 py-0.5">
              {toolText(s.tool, ar)}{s.from && s.to ? <> · <bdi dir="ltr" className="tnum">{period(s, ar)}</bdi></> : null}
            </span>
          ))}
        </p>
      )}
    </div>
  )
}

function Card({ card, ar }: { card: AnswerCard; ar: boolean }) {
  const head = 'flex items-center justify-between gap-2 text-xs text-text-muted'
  const box = 'min-w-0 rounded-xl border border-border bg-surface p-2.5'

  if (card.type === 'campaigns') {
    return (
      <div className={box} data-testid="card-campaigns">
        <p className={head}><span className="font-semibold text-text-secondary">{ar ? `الحملات (${card.count})` : `Campaigns (${card.count})`}</span><bdi dir="ltr" className="tnum">{period(card.window, ar)}</bdi></p>
        <div className="mt-1.5 overflow-x-auto">
          <table className="w-full min-w-0 table-fixed text-xs">
            <thead className="text-xs text-text-muted">
              <tr>
                <th className="w-2/5 truncate py-1 text-start font-medium">{ar ? 'الحملة' : 'Campaign'}</th>
                <th className="py-1 text-end font-medium">{ar ? 'الإنفاق' : 'Spend'}</th>
                <th className="py-1 text-end font-medium">{ar ? 'النتائج' : 'Results'}</th>
                <th className="py-1 text-end font-medium">{ar ? 'تكلفة النتيجة' : 'Cost/result'}</th>
              </tr>
            </thead>
            <tbody>
              {card.rows.map((r, i) => (
                <tr key={r.id ?? i} className="border-t border-border">
                  <td className="truncate py-1 pe-2" dir="auto" title={r.name ?? ''}>{r.name ?? '—'}</td>
                  <td className="py-1 text-end"><bdi dir="ltr" className="tnum">{amount(r.spend, card.currency)}</bdi></td>
                  <td className="py-1 text-end"><bdi dir="ltr" className="tnum">{r.results === null ? '—' : num(r.results)}</bdi></td>
                  <td className="py-1 text-end"><bdi dir="ltr" className="tnum">{amount(r.cpa, card.currency)}</bdi></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {card.rows.length === 0 && <p className="mt-1 text-xs text-text-muted">{ar ? 'لا توجد بيانات' : 'No data'}</p>}
      </div>
    )
  }

  if (card.type === 'targets') {
    const keys = ['on_target', 'off_target', 'not_judged', 'no_figure'] as const
    return (
      <div className={box} data-testid="card-targets">
        <p className={head}><span className="font-semibold text-text-secondary">{ar ? 'مقابل الأهداف' : 'Against targets'}</span><bdi dir="ltr" className="tnum">{period(card.window, ar)}</bdi></p>
        {card.rules === 0
          ? <p className="mt-1 text-xs text-text-muted">{ar ? 'لم تُكتب أهداف بعد.' : 'No targets written yet.'}</p>
          : <div className="mt-1.5 flex flex-wrap gap-1.5">{keys.map((k) => (
            <span key={k} className={`rounded-full px-2 py-0.5 text-xs font-semibold ${VERDICT[k].tone}`}>{ar ? VERDICT[k].ar : VERDICT[k].en} <bdi dir="ltr" className="tnum">{card.counts[k]}</bdi></span>
          ))}</div>}
      </div>
    )
  }

  if (card.type === 'series') {
    const spend = card.points.map((p) => p.spend ?? 0)
    return (
      <div className={box} data-testid="card-series">
        <p className={head}><span className="font-semibold text-text-secondary">{ar ? 'الإنفاق اليومي' : 'Daily spend'}</span><bdi dir="ltr" className="tnum">{period(card.window, ar)}</bdi></p>
        {spend.length > 1 ? <div className="mt-1" aria-hidden><KpiSparkline points={spend} height={40} /></div> : <p className="mt-1 text-xs text-text-muted">{ar ? 'لا توجد بيانات' : 'No data'}</p>}
      </div>
    )
  }

  return (
    <div className={box} data-testid="card-creatives">
      <p className={head}><span className="font-semibold text-text-secondary">{ar ? 'الإعلانات الأعلى إنفاقًا' : 'Top-spending ads'}</span><bdi dir="ltr" className="tnum">{period(card.window, ar)}</bdi></p>
      <ul className="mt-1.5 space-y-1">
        {card.rows.map((r, i) => (
          <li key={r.id ?? i} className="flex min-w-0 items-center justify-between gap-2 text-xs">
            <span className="min-w-0 truncate" dir="auto">{r.name ?? '—'}</span>
            <span className="flex shrink-0 items-center gap-1.5 text-text-muted">
              {r.ctr !== null && <bdi dir="ltr" className="tnum">CTR {compact(r.ctr)}%</bdi>}
              {r.fatigue && FATIGUE[r.fatigue] && <span className="rounded-full bg-surface-secondary px-1.5">{ar ? FATIGUE[r.fatigue].ar : FATIGUE[r.fatigue].en}</span>}
            </span>
          </li>
        ))}
      </ul>
    </div>
  )
}
