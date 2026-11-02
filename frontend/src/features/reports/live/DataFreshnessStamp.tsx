import { AlertTriangle, CheckCircle2, CircleDashed } from 'lucide-react'
import { providerLabel } from '@/features/campaigns/labels'
import { canonicalPlatform } from '@/lib/platforms'
import { Num } from '@/components/ui/Num'
import type { DataStatus, LivePayload } from '../api'

/**
 * REPORT-FRESHNESS-STAMP-001 — when the data was last CONFIRMED by a finished sync, in Riyadh time.
 *
 * «Live» on a shared link means this system recomputed the page, not that a platform reported just now. This stamp states the
 * completion time of each platform's latest successful sync (server-supplied, never the request time) and, when it is old or the
 * latest attempt failed, says so in the header and on that platform. In those states nothing is labelled «updated».
 */

const TZ = 'Asia/Riyadh'
const WARN = new Set<DataStatus>(['delayed', 'stale', 'failed'])

/** «14:32», or «2026-11-01 14:32» when it is not the same Riyadh day as `reference` — Latin digits whatever the locale. */
export function riyadhClock(iso: string, reference?: string): string {
  const at = new Date(iso)
  const time = new Intl.DateTimeFormat('en-GB', { timeZone: TZ, hour: '2-digit', minute: '2-digit', hour12: false }).format(at)
  const day = (d: Date) => new Intl.DateTimeFormat('sv-SE', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' }).format(d)
  if (reference && day(new Date(reference)) === day(at)) return time

  return `${day(at)} ${time}`
}

/** «قبل 18 دقيقة» / «18 minutes ago» — whole units, never a decimal. */
export function ageWords(minutes: number, ar: boolean): string {
  const m = Math.max(0, Math.floor(minutes))
  if (m < 1) return ar ? 'قبل أقل من دقيقة' : 'less than a minute ago'
  if (m < 60) return ar ? `قبل ${m} ${m === 1 ? 'دقيقة' : m === 2 ? 'دقيقتين' : m <= 10 ? 'دقائق' : 'دقيقة'}` : `${m} minute${m === 1 ? '' : 's'} ago`
  const h = Math.floor(m / 60)
  if (h < 24) return ar ? `قبل ${h === 1 ? 'ساعة' : h === 2 ? 'ساعتين' : `${h} ${h <= 10 ? 'ساعات' : 'ساعة'}`}` : `${h} hour${h === 1 ? '' : 's'} ago`
  const d = Math.floor(h / 24)

  return ar ? `قبل ${d === 1 ? 'يوم' : d === 2 ? 'يومين' : `${d} ${d <= 10 ? 'أيام' : 'يومًا'}`}` : `${d} day${d === 1 ? '' : 's'} ago`
}

const minutesBetween = (fromIso: string, toIso: string) => Math.max(0, Math.floor((new Date(toIso).getTime() - new Date(fromIso).getTime()) / 60000))

const STATUS_WORD: Record<DataStatus, { ar: string; en: string }> = {
  fresh: { ar: 'محدّثة', en: 'Up to date' },
  delayed: { ar: 'متأخرة', en: 'Delayed' },
  stale: { ar: 'قديمة', en: 'Out of date' },
  failed: { ar: 'تعذّرت المزامنة الأخيرة', en: 'Latest sync failed' },
  unknown: { ar: 'وقت التحديث غير متاح', en: 'Update time unavailable' },
}

export function DataFreshnessStamp({ freshness, summary, ar }: { freshness: LivePayload['freshness']; summary: LivePayload['freshness_summary']; ar: boolean }) {
  const platforms = freshness.filter((f) => f.data_status !== undefined && f.data_status !== null && f.state !== 'awaiting_credentials')
  if (!summary || platforms.length === 0) return null

  const worst = summary.worst_status
  const warn = WARN.has(worst)
  const at = summary.oldest_success_at
  const ago = at ? ageWords(minutesBetween(at, summary.generated_at), ar) : null
  const tz = ar ? 'بتوقيت الرياض' : 'Riyadh time'

  const headline = (() => {
    if (!at) return ar ? 'وقت آخر تحديث للبيانات غير متاح' : 'The time of the last data update is not available'
    if (worst === 'failed') return ar ? `تعذّرت المزامنة الأخيرة — آخر مزامنة ناجحة ${ago}` : `The latest sync failed — last successful sync ${ago}`
    if (warn) return ar ? `البيانات متأخرة — آخر مزامنة ناجحة ${ago}` : `Data is delayed — last successful sync ${ago}`

    return null
  })()

  const tone = warn || worst === 'unknown' ? 'border-border bg-[var(--warning-background)] text-warning' : 'border-border bg-surface text-text-secondary'

  return (
    <section data-testid="data-freshness" data-status={worst} aria-label={ar ? 'آخر تحديث للبيانات' : 'Data last updated'} className={`flex min-w-0 flex-col gap-1.5 rounded-xl border px-3 py-2 text-xs ${tone}`}>
      <p className="flex flex-wrap items-center gap-x-1.5 gap-y-0.5" data-testid="data-freshness-header">
        {warn || worst === 'unknown' ? <AlertTriangle size={13} className="shrink-0" aria-hidden /> : <CheckCircle2 size={13} className="shrink-0 text-success" aria-hidden />}
        <span className="font-bold">{ar ? 'آخر تحديث للبيانات' : 'Data last updated'}</span>
        {at && (
          <span data-testid="data-freshness-time" dir="ltr" className="tabular-nums">
            <Num>{riyadhClock(at, summary.generated_at)}</Num> {tz}
          </span>
        )}
        {at && !warn && <span data-testid="data-freshness-age">· {ago}</span>}
        {worst === 'fresh' && <span data-testid="data-freshness-ok" className="font-semibold text-success">· {STATUS_WORD.fresh[ar ? 'ar' : 'en']}</span>}
      </p>
      {headline && <p data-testid="data-freshness-warning" role="status" className="font-semibold">{headline}</p>}
      <ul className="flex flex-col gap-0.5" data-testid="data-freshness-platforms">
        {platforms.map((f) => {
          const s = (f.data_status ?? 'unknown') as DataStatus
          const bad = WARN.has(s)
          const last = f.last_success_at ?? null

          return (
            <li key={f.provider} data-testid={`data-freshness-${f.provider}`} data-status={s} className={`flex min-w-0 flex-wrap items-center gap-x-1.5 ${bad ? 'font-semibold text-warning' : 'text-text-secondary'}`}>
              {bad || s === 'unknown' ? <AlertTriangle size={11} className="shrink-0" aria-hidden /> : <CircleDashed size={11} className="shrink-0 opacity-0" aria-hidden />}
              <span className="font-semibold">{providerLabel(canonicalPlatform(f.provider), ar ? 'ar' : 'en')}</span>
              {last ? (
                <span dir="ltr" className="tabular-nums"><Num>{riyadhClock(last, summary.generated_at)}</Num> {tz}{f.age_minutes !== null && f.age_minutes !== undefined ? ` · ${ageWords(f.age_minutes, ar)}` : ''}</span>
              ) : null}
              <span data-testid={`data-freshness-${f.provider}-status`}>— {STATUS_WORD[s][ar ? 'ar' : 'en']}</span>
            </li>
          )
        })}
      </ul>
    </section>
  )
}
