import { AlertTriangle } from 'lucide-react'
import { providerLabel } from '@/features/campaigns/labels'
import { canonicalPlatform } from '@/lib/platforms'
import type { DataStatus, LivePayload } from '../api'

/**
 * REPORT-FOOTER-FRESHNESS-001 (Owner, 2026-11-03, #938) — a report states one time, in its footer; this speaks only when a platform's
 * figures cannot be vouched for.
 *
 * It replaces the card that listed every platform's sync clock above the figures. The reader's fact survives — these figures are
 * late, and which platform's — in one sentence, and nothing renders when all is well. The per-platform detail lives on the operator's
 * side (`ConnectionHub`, `ProjectSyncHistory`). This time is the platform's last confirmation, never the report's own computation
 * time, which is the footer's.
 */

const LATE = new Set<DataStatus>(['delayed', 'stale'])
const PROBLEM = new Set<DataStatus>(['delayed', 'stale', 'failed', 'unknown'])

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

export function DataFreshnessAlert({ freshness, ar }: { freshness: LivePayload['freshness']; ar: boolean }) {
  const problems = freshness.filter((f) => f.state !== 'awaiting_credentials' && f.data_status != null && PROBLEM.has(f.data_status))
  if (problems.length === 0) return null

  const names = problems.map((f) => providerLabel(canonicalPlatform(f.provider), ar ? 'ar' : 'en')).join(ar ? '، ' : ', ')
  const allLate = problems.every((f) => LATE.has(f.data_status as DataStatus))
  const ages = problems.map((f) => f.age_minutes)
  // One platform with no confirmation at all makes «the oldest» unknowable, so no age is claimed.
  const oldest = ages.every((a): a is number => typeof a === 'number') ? Math.max(...ages) : null
  const one = problems.length === 1

  const claim = ar
    ? `تنبيه: بيانات ${names} ${allLate ? 'متأخرة' : 'قد لا تكون محدّثة'}`
    : `Notice: ${names} figures ${allLate ? 'are delayed' : 'may not be up to date'}`
  const age = oldest === null
    ? ''
    : ar
      ? ` — ${one ? 'آخر تأكيد من المنصة' : 'أقدم تأكيد من المنصات'} ${ageWords(oldest, true)}`
      : ` — ${one ? 'last confirmed by the platform' : 'oldest confirmation'} ${ageWords(oldest, false)}`

  return (
    <p data-testid="data-freshness-alert" role="status" className="flex min-w-0 items-start gap-1.5 rounded-xl border border-border bg-[var(--warning-background)] px-3 py-2 text-xs font-semibold text-warning">
      <AlertTriangle size={13} className="mt-0.5 shrink-0" aria-hidden />
      <span className="min-w-0">{claim}{age}.</span>
    </p>
  )
}
