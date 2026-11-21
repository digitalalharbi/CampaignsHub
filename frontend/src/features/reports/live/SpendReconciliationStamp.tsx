import { AlertTriangle, CheckCircle2, CircleDashed } from 'lucide-react'
import { providerLabel } from '@/features/campaigns/labels'
import { canonicalPlatform } from '@/lib/platforms'
import { Num } from '@/components/ui/Num'
import type { LivePayload, ReconcileStatus } from '../api'

/**
 * SPEND-RECONCILE-RECORD-001 — «آخر مطابقة مؤكدة مع المصدر», per platform, in Riyadh time.
 *
 * A sync says we asked the platform; a reconciliation says the platform's own total for the account was set beside the stored
 * total and the two agreed (or did not). This stamp states the latest recorded check per platform — server-supplied, never the
 * request time — and says «لم تُطابق بعد» where no check was ever recorded, rather than implying one. It is a sibling of the
 * sync-freshness stamp, not a replacement: the strip mounts both.
 */

const TZ = 'Asia/Riyadh'

/** «2026-11-02 14:32» in Riyadh — Latin digits whatever the locale. */
export function riyadhStamp(iso: string): string {
  const at = new Date(iso)
  const day = new Intl.DateTimeFormat('sv-SE-u-nu-latn', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' }).format(at)
  const time = new Intl.DateTimeFormat('en-GB', { timeZone: TZ, hour: '2-digit', minute: '2-digit', hour12: false }).format(at)

  return `${day} ${time}`
}

/** «+15.9%» / «−3.2%» — a signed, one-decimal share with Latin digits. */
export function signedPct(pct: number): string {
  const abs = new Intl.NumberFormat('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(Math.abs(pct))

  return `${pct < 0 ? '−' : '+'}${abs}%`
}

const WORD: Record<ReconcileStatus, { ar: string; en: string }> = {
  matched: { ar: 'مطابق', en: 'Matched' },
  drifted: { ar: 'غير مطابق', en: 'Does not match' },
  failed: { ar: 'تعذّرت المطابقة', en: 'Check failed' },
  never: { ar: 'لم تُطابق بعد', en: 'Not yet reconciled' },
}

export type ReconcileEntry = Pick<LivePayload['freshness'][number], 'provider' | 'state' | 'last_reconciled_at' | 'reconcile_status' | 'reconcile_diff_pct'>

/** The verdict words for one entry — the same text the row prints, exported so a header or an export can reuse it. */
export function reconcileWords(entry: ReconcileEntry, ar: boolean): string {
  const status = (entry.reconcile_status ?? 'never') as ReconcileStatus
  const word = WORD[status][ar ? 'ar' : 'en']
  if (status === 'drifted' && entry.reconcile_diff_pct !== null && entry.reconcile_diff_pct !== undefined) {
    return `${word} (${signedPct(entry.reconcile_diff_pct)})`
  }

  return word
}

export function SpendReconciliationStamp({ freshness, ar }: { freshness: ReconcileEntry[]; ar: boolean }) {
  // Only entries a payload with the stamp produced; a payload that predates it renders nothing.
  const platforms = freshness.filter((f) => f.reconcile_status !== undefined && f.state !== 'awaiting_credentials')
  if (platforms.length === 0) return null

  const tz = ar ? 'بتوقيت الرياض' : 'Riyadh time'
  const anyDrift = platforms.some((f) => f.reconcile_status === 'drifted' || f.reconcile_status === 'failed')
  const tone = anyDrift ? 'border-border bg-[var(--warning-background)] text-warning' : 'border-border bg-surface text-text-secondary'

  return (
    <section data-testid="spend-reconciliation" data-drift={anyDrift ? 'true' : 'false'} aria-label={ar ? 'آخر مطابقة مؤكدة مع المصدر' : 'Last confirmed match with the source'} className={`flex min-w-0 flex-col gap-1 rounded-xl border px-3 py-2 text-xs ${tone}`}>
      <p className="font-bold">{ar ? 'آخر مطابقة مؤكدة مع المصدر' : 'Last confirmed match with the source'}</p>
      <ul className="flex flex-col gap-0.5">
        {platforms.map((f) => {
          const status = (f.reconcile_status ?? 'never') as ReconcileStatus
          const bad = status === 'drifted' || status === 'failed'
          const at = f.last_reconciled_at ?? null

          return (
            <li key={f.provider} data-testid={`spend-reconciliation-${f.provider}`} data-status={status} className={`flex min-w-0 flex-wrap items-center gap-x-1.5 ${bad ? 'font-semibold text-warning' : ''}`}>
              {bad ? <AlertTriangle size={11} className="shrink-0" aria-hidden /> : status === 'matched' ? <CheckCircle2 size={11} className="shrink-0 text-success" aria-hidden /> : <CircleDashed size={11} className="shrink-0" aria-hidden />}
              <span className="font-semibold">{providerLabel(canonicalPlatform(f.provider), ar ? 'ar' : 'en')}</span>
              {at && (
                <span data-testid={`spend-reconciliation-${f.provider}-time`} dir="ltr" className="tabular-nums">
                  <Num>{riyadhStamp(at)}</Num> {tz}
                </span>
              )}
              <span data-testid={`spend-reconciliation-${f.provider}-status`}>— {reconcileWords(f, ar)}</span>
            </li>
          )
        })}
      </ul>
    </section>
  )
}
