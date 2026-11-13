import { AlertTriangle, Info } from 'lucide-react'
import type { UsageMetric } from './api'
import { thresholdOf, worstThreshold, type Threshold } from './planAccess'

/**
 * A ring per limit: used over the limit (NAV-HOME-PLANS-001, master task 14).
 *
 * The ring's tone follows the Owner's 75 / 90 / 100 points and the one line above them says what happens next — at
 * 100% only NEW things are refused and nothing that is running stops. Every figure comes from `usage`, which the
 * server fills from the plan the admin console set; there is no number in this file.
 */
const TONE: Record<Threshold, string> = {
  unlimited: 'var(--brand-600)', ok: 'var(--brand-600)', near: 'var(--warning)', high: 'var(--warning)', full: 'var(--danger)',
}

const fmt = (n: number) => n.toLocaleString('en-US')

function Ring({ metric, size = 52 }: { metric: UsageMetric; size?: number }) {
  const stroke = 5
  const r = (size - stroke) / 2
  const c = 2 * Math.PI * r
  const t = thresholdOf(metric)
  const pct = metric.limit === null || metric.limit <= 0 ? (t === 'full' ? 1 : 0) : Math.min(1, metric.used / metric.limit)

  return (
    <span className="relative inline-flex shrink-0 items-center justify-center" style={{ width: size, height: size }} aria-hidden>
      <svg width={size} height={size} className="-rotate-90">
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="var(--surface-secondary)" strokeWidth={stroke} />
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={TONE[t]} strokeWidth={stroke} strokeLinecap="round" strokeDasharray={c} strokeDashoffset={c * (1 - pct)} />
      </svg>
      <span className="tnum absolute text-xs font-bold text-text-primary">{metric.limit === null ? '∞' : `${Math.round(pct * 100)}%`}</span>
    </span>
  )
}

export function LimitRings({
  usage, labelOf, unlimited, ar,
}: { usage: Record<string, UsageMetric>; labelOf: (metric: string) => string; unlimited: string; ar: boolean }) {
  const entries = Object.entries(usage)
  if (entries.length === 0) return null
  const worst = worstThreshold(usage)

  const advice: Partial<Record<Threshold, string>> = ar
    ? {
        near: 'وصلت 75% من أحد حدود باقتك. ترقّ قبل ما يخلص.',
        high: 'وصلت 90% من أحد حدود باقتك. قرّبت تخلص.',
        full: 'وصلت الحد. الجديد ما ينضاف، وكل شيء شغال يكمل.',
      }
    : {
        near: 'You have used 75% of one of your limits. Upgrade before it runs out.',
        high: 'You have used 90% of one of your limits. It is almost out.',
        full: 'You reached a limit. New items are blocked; everything already running continues.',
      }
  const line = advice[worst]

  return (
    <section data-testid="limit-rings" aria-label={ar ? 'الحدود' : 'Limits'} className="rounded-2xl border border-border bg-surface p-3">
      <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
        {entries.map(([metric, u]) => (
          <li key={metric} data-testid={`limit-ring-${metric}`} data-state={thresholdOf(u)} className="flex min-w-0 items-center gap-2 rounded-xl bg-surface-secondary/50 p-2">
            <Ring metric={u} />
            <span className="min-w-0">
              <span className="block truncate text-xs font-bold text-text-primary">{labelOf(metric)}</span>
              <span className="tnum block truncate text-xs text-text-muted">
                {fmt(u.used)} / {u.limit === null ? unlimited : fmt(u.limit)}
              </span>
            </span>
          </li>
        ))}
      </ul>
      {line !== undefined && (
        <p data-testid="limit-advice" className={`mt-2 flex items-center gap-1.5 text-xs font-semibold ${worst === 'full' ? 'text-danger' : 'text-warning'}`} role="status">
          {worst === 'full' ? <AlertTriangle size={14} aria-hidden /> : <Info size={14} aria-hidden />} {line}
        </p>
      )}
    </section>
  )
}
