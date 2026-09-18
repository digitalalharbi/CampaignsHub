import { useMemo, useState } from 'react'
import { Skeleton } from '@/components/ui/States'
import { Num } from '@/components/ui/Num'
import type { ActionSeverity } from './actionCenter'
import { FindingCard } from './FindingCard'
import { natureCounts, type Finding, type FindingNature } from './findings'
import { natureLabel, severityLabel } from './findingCopy'

/**
 * RECOMMENDATIONS-VISUAL-001 — the queue, as figures first.
 *
 * ## Four states, never collapsed into two
 *
 * Loading is a skeleton. A source that FAILED is said in words above whatever the others returned —
 * «we could not read the alerts» is not «there are no alerts», and a centre that shows an empty queue
 * because a request errored tells somebody everything is fine at exactly the wrong moment. An empty
 * queue with every source read says so plainly, and names what was checked. Only the fourth state —
 * findings — draws cards.
 */
export type SourceKey = 'alerts' | 'limits' | 'creatives'

export function FindingsBoard({
  findings,
  loading,
  failed,
  projectId,
  currency,
  ar,
}: {
  findings: Finding[]
  loading: boolean
  failed: SourceKey[]
  projectId: string
  currency: string | null
  ar: boolean
}) {
  const [nature, setNature] = useState<FindingNature | 'all'>('all')
  const counts = useMemo(() => natureCounts(findings), [findings])
  const severity = useMemo(() => {
    const out: Record<ActionSeverity, number> = { critical: 0, warning: 0, info: 0 }
    for (const f of findings) out[f.severity] += 1
    return out
  }, [findings])
  const shown = nature === 'all' ? findings : findings.filter((f) => f.nature === nature)

  return (
    <section className="flex flex-col gap-3" data-testid="action-center">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-base font-bold text-text-primary">{ar ? 'ما يحتاج قرارًا الآن' : 'What needs a decision now'}</h2>
        <span className="text-[11px] text-text-secondary">
          {ar
            ? 'مُستنتجة من بيانات الحساب نفسه — كل بطاقة تفتح دليلها.'
            : 'Derived from this account’s own data — every card opens its evidence.'}
        </span>
      </div>

      {failed.length > 0 && (
        <p role="status" data-testid="action-center-degraded" className="rounded-xl border border-warning/40 bg-[var(--warning-background)] px-3 py-2 text-xs text-warning">
          {ar ? 'تعذّرت قراءة: ' : 'Could not be read: '}
          {failed.map((s) => SOURCE[s][ar ? 'ar' : 'en']).join(ar ? '، ' : ', ')}
          {ar ? ' — قد تكون القائمة ناقصة.' : ' — this list may be incomplete.'}
        </p>
      )}

      {loading && findings.length === 0 ? (
        <div className="grid gap-3 lg:grid-cols-2" data-testid="action-center-loading">
          <Skeleton className="h-56" />
          <Skeleton className="h-56" />
        </div>
      ) : findings.length === 0 ? (
        failed.length === 3 ? null : (
          <div className="rounded-2xl border border-dashed border-border bg-surface p-5 text-center" data-testid="action-center-empty">
            <p className="text-sm font-bold text-text-primary">{ar ? 'لا شيء يحتاج قرارًا الآن' : 'Nothing needs a decision right now'}</p>
            <p className="mt-1 text-xs text-text-secondary">
              {ar ? 'فُحصت: ' : 'Checked: '}
              {(['alerts', 'limits', 'creatives'] as SourceKey[]).filter((s) => !failed.includes(s)).map((s) => SOURCE[s][ar ? 'ar' : 'en']).join(ar ? '، ' : ', ')}
            </p>
          </div>
        )
      ) : (
        <>
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4" data-testid="action-center-summary">
            {(['critical', 'warning', 'info'] as ActionSeverity[]).map((s) => (
              <div key={s} className="rounded-xl border border-border bg-surface p-3" data-testid={`summary-severity-${s}`}>
                <p className="text-[11px] text-text-muted">{severityLabel(s, ar)}</p>
                <p className={`text-2xl font-extrabold ${s === 'critical' ? 'text-danger' : s === 'warning' ? 'text-warning' : 'text-info'}`}><Num>{severity[s]}</Num></p>
              </div>
            ))}
            <div className="rounded-xl border border-border bg-surface p-3" data-testid="summary-opportunity">
              <p className="text-[11px] text-text-muted">{natureLabel('opportunity', ar)}</p>
              <p className="text-2xl font-extrabold text-success"><Num>{counts.opportunity}</Num></p>
            </div>
          </div>

          <div role="tablist" className="flex flex-wrap gap-1.5" data-testid="action-center-nature">
            {(['all', 'problem', 'opportunity', 'risk', 'tracking'] as const).map((n) => {
              const count = n === 'all' ? findings.length : counts[n]
              if (n !== 'all' && count === 0) return null
              return (
                <button
                  key={n}
                  type="button"
                  role="tab"
                  aria-selected={nature === n}
                  onClick={() => setNature(n)}
                  className={`rounded-full border px-3 py-1 text-xs font-semibold ${nature === n ? 'border-brand-600 bg-brand-600 text-white' : 'border-border text-text-secondary hover:bg-surface-hover'}`}
                >
                  {n === 'all' ? (ar ? 'الكل' : 'All') : natureLabel(n, ar)} <Num>{count}</Num>
                </button>
              )
            })}
          </div>

          <ul className="grid gap-3 lg:grid-cols-2" data-testid="action-center-list">
            {shown.map((f) => (
              <li key={f.id} className="min-w-0">
                <FindingCard finding={f} projectId={projectId} ar={ar} currency={currency} />
              </li>
            ))}
          </ul>
        </>
      )}
    </section>
  )
}

const SOURCE: Record<SourceKey, { ar: string; en: string }> = {
  alerts: { ar: 'التنبيهات', en: 'alerts' },
  limits: { ar: 'حدود الإنفاق', en: 'spend limits' },
  creatives: { ar: 'أداء المحتوى', en: 'creative performance' },
}
