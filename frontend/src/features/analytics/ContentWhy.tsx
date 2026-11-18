import { AdPoster } from '@/features/content/AdPoster'
import type { CreativeCard } from '@/features/content/api'
import { percent } from './format'

/*
 * ANALYTICS-CONTENT-WHY-001 — «why did this content work, and why did that one not», in a sentence each.
 *
 * Read-only and evidence-bound: it compares a content item's click rate with the average of the content
 * that shares its objective, and says how much of the spend it carried. It claims no cause it cannot
 * measure — it names the gap and the share, nothing else. A group needs at least three items with
 * a thousand impressions each, otherwise there is nothing fair to compare against.
 */
type Row = { cr: CreativeCard; ctr: number; spend: number; impressions: number }

const MIN_IMPRESSIONS = 1000

function rate(cr: CreativeCard): Row | null {
  const imp = Number(cr.metrics?.impressions ?? 0)
  const clicks = Number(cr.metrics?.clicks ?? 0)
  const spend = typeof cr.metrics?.spend === 'number' ? cr.metrics.spend : 0
  if (!(imp >= MIN_IMPRESSIONS)) return null
  return { cr, ctr: clicks / imp, spend, impressions: imp }
}

export function ContentWhy({ rows, ar, onOpen }: { rows: CreativeCard[]; ar: boolean; onOpen: (cr: CreativeCard) => void }) {
  const groups = new Map<string, Row[]>()
  for (const cr of rows) {
    const r = rate(cr)
    if (!r) continue
    const key = cr.objective ?? 'other'
    groups.set(key, [...(groups.get(key) ?? []), r])
  }
  const comparable = [...groups.values()].filter((g) => g.length >= 3)
  if (comparable.length === 0) return null

  const cards: Array<{ row: Row; good: boolean; avg: number; share: number }> = []
  for (const g of comparable) {
    const avg = g.reduce((a, r) => a + r.ctr, 0) / g.length
    const total = g.reduce((a, r) => a + r.spend, 0)
    const sorted = [...g].sort((a, b) => b.ctr - a.ctr)
    for (const row of sorted.slice(0, 2)) cards.push({ row, good: true, avg, share: total > 0 ? row.spend / total : 0 })
    for (const row of sorted.slice(-1)) cards.push({ row, good: false, avg, share: total > 0 ? row.spend / total : 0 })
  }

  return (
    <section className="space-y-3" data-testid="content-why">
      <div>
        <h3 className="text-base font-bold text-text-primary">{ar ? 'ليش نجح المحتوى وليش ما نجح' : 'Why content worked, and why it did not'}</h3>
        <p className="text-xs text-text-muted">
          {ar ? 'مقارنة بمتوسط المحتوى في نفس الهدف، على المحتوى الذي تجاوز ألف ظهور فقط.' : 'Against the average of content with the same objective, for items above a thousand impressions.'}
        </p>
      </div>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {cards.map(({ row, good, avg, share }) => {
          const gap = avg > 0 ? (row.ctr - avg) / avg : 0
          return (
            <button
              key={`${row.cr.id}-${good ? 'w' : 'l'}`}
              type="button"
              onClick={() => onOpen(row.cr)}
              data-testid={`content-why-${good ? 'win' : 'lag'}-${row.cr.id}`}
              className={`flex items-start gap-3 rounded-2xl border bg-surface p-3 text-start transition-colors hover:bg-surface-hover ${good ? 'border-emerald-500/40' : 'border-rose-500/40'}`}
            >
              <AdPoster preview={row.cr.preview} name={row.cr.name ?? ''} className="h-20 w-14 shrink-0 rounded-lg" fit="cover" testid={`content-why-poster-${row.cr.id}`} />
              <div className="min-w-0 space-y-1">
                <p className={`text-xs font-bold ${good ? 'text-emerald-500' : 'text-rose-500'}`}>
                  {good ? (ar ? 'أقوى من المتوسط' : 'Above average') : (ar ? 'أضعف من المتوسط' : 'Below average')}
                </p>
                <p className="truncate text-sm font-semibold text-text-primary">{row.cr.name}</p>
                <p className="text-xs text-text-secondary">
                  {ar
                    ? `نسبة النقر ${percent(row.ctr, 2)} مقابل متوسط ${percent(avg, 2)} لنفس الهدف (${gap >= 0 ? '+' : ''}${Math.round(gap * 100)}%).`
                    : `Click rate ${percent(row.ctr, 2)} against ${percent(avg, 2)} for the same objective (${gap >= 0 ? '+' : ''}${Math.round(gap * 100)}%).`}
                </p>
                {share > 0 && (
                  <p className="text-xs text-text-muted">
                    {good
                      ? (ar ? `أخذ ${percent(share, 0)} من إنفاق هدفه.` : `Carried ${percent(share, 0)} of its objective's spend.`)
                      : (ar ? `أخذ ${percent(share, 0)} من إنفاق هدفه، فراجع هل يستحق.` : `Took ${percent(share, 0)} of its objective's spend — worth a second look.`)}
                  </p>
                )}
              </div>
            </button>
          )
        })}
      </div>
    </section>
  )
}
