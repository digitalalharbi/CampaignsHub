import { platformColor } from '@/features/analytics/platformColor'
import type { AccountState } from '@/features/analytics/api'

/**
 * ACCOUNTS-PULSE-001 — the small visual pieces of the accounts page (Owner, 2026-11-21: «ينقصها جانب إبداعي:
 * أيقونات المنصات والتفاصيل الإبداعية وشغالة والا واقفة»).
 *
 * Plain SVG, no charting library: four marks on a page do not justify pulling recharts into this route.
 */

const STATE_TONE: Record<AccountState, { dot: string; chip: string }> = {
  running: { dot: 'bg-emerald-500', chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30' },
  stopped: { dot: 'bg-zinc-400', chip: 'bg-zinc-100 text-zinc-600 ring-zinc-200 dark:bg-zinc-500/10 dark:text-zinc-300 dark:ring-zinc-500/30' },
  disabled: { dot: 'bg-rose-500', chip: 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30' },
  error: { dot: 'bg-rose-500', chip: 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30' },
}

function stateLabel(state: AccountState, ar: boolean): string {
  const labels: Record<AccountState, [string, string]> = {
    running: ['شغّال', 'Running'],
    stopped: ['متوقف', 'Stopped'],
    disabled: ['معطّل من المنصة', 'Disabled by platform'],
    error: ['خطأ في الربط', 'Connection error'],
  }
  return labels[state][ar ? 0 : 1]
}

/** The account's state as a compact chip; «running» carries a soft pulse, and reduced motion keeps it still. */
export function StateChip({ state, ar, title }: { state: AccountState; ar: boolean; title?: string }) {
  const tone = STATE_TONE[state]
  return (
    <span
      data-testid="account-state"
      data-state={state}
      title={title}
      className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-bold ring-1 ${tone.chip}`}
    >
      <span className="relative inline-flex h-2 w-2">
        {state === 'running' && <span aria-hidden className={`absolute inline-flex h-full w-full rounded-full opacity-60 motion-safe:animate-ping ${tone.dot}`} />}
        <span aria-hidden className={`relative inline-flex h-2 w-2 rounded-full ${tone.dot}`} />
      </span>
      {stateLabel(state, ar)}
    </span>
  )
}

/** The shape of an account's days. Shape only: the figures stay with the money reader. */
export function Sparkline({ points, provider, width = 96, height = 28, testId }: { points: number[]; provider: string; width?: number; height?: number; testId?: string }) {
  if (points.length < 2 || points.every((p) => p === 0)) {
    return <span aria-hidden className="inline-block h-px w-16 bg-border" data-testid={testId} data-empty="true" />
  }
  const max = Math.max(...points)
  const min = Math.min(...points)
  const span = max - min || 1
  const step = width / (points.length - 1)
  const xy = points.map((p, i) => [i * step, height - 2 - ((p - min) / span) * (height - 4)] as const)
  const line = xy.map(([x, y], i) => `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`).join(' ')
  const area = `${line} L${width},${height} L0,${height} Z`
  const color = platformColor(provider)
  const id = `spark-${provider}-${points.length}-${Math.round(max)}`

  return (
    <svg aria-hidden width={width} height={height} viewBox={`0 0 ${width} ${height}`} className="overflow-visible" data-testid={testId}>
      <defs>
        <linearGradient id={id} x1="0" x2="0" y1="0" y2="1">
          <stop offset="0%" stopColor={color} stopOpacity="0.28" />
          <stop offset="100%" stopColor={color} stopOpacity="0" />
        </linearGradient>
      </defs>
      <path d={area} fill={`url(#${id})`} />
      <path d={line} fill="none" stroke={color} strokeWidth="1.75" strokeLinejoin="round" strokeLinecap="round" />
      <circle cx={xy[xy.length - 1][0]} cy={xy[xy.length - 1][1]} r="2.25" fill={color} />
    </svg>
  )
}

/** One account's share of the period's spend, as a thin bar in its platform's colour. */
export function ShareBar({ share, provider }: { share: number; provider: string }) {
  const pct = Math.max(0, Math.min(100, share * 100))
  return (
    <span aria-hidden className="mt-1 block h-1 w-full overflow-hidden rounded-full bg-surface-secondary">
      <span className="block h-full rounded-full" style={{ width: `${pct}%`, background: platformColor(provider) }} />
    </span>
  )
}

/** A ring: «2 of 4 running». */
export function Ring({ value, total, size = 44 }: { value: number; total: number; size?: number }) {
  const r = size / 2 - 4
  const c = 2 * Math.PI * r
  const frac = total > 0 ? value / total : 0
  return (
    <svg aria-hidden width={size} height={size} viewBox={`0 0 ${size} ${size}`} className="-rotate-90">
      <circle cx={size / 2} cy={size / 2} r={r} fill="none" strokeWidth="5" className="stroke-surface-secondary" />
      <circle cx={size / 2} cy={size / 2} r={r} fill="none" strokeWidth="5" strokeLinecap="round" className="stroke-emerald-500 transition-[stroke-dasharray] duration-700" strokeDasharray={`${c * frac} ${c}`} />
    </svg>
  )
}

/** Spend split by platform, as a donut in the platforms' own colours. */
export function Donut({ slices, size = 88 }: { slices: Array<{ provider: string; value: number }>; size?: number }) {
  const total = slices.reduce((a, s) => a + s.value, 0)
  if (total <= 0) return null
  const r = size / 2 - 7
  const c = 2 * Math.PI * r
  let offset = 0
  return (
    <svg aria-hidden width={size} height={size} viewBox={`0 0 ${size} ${size}`} className="-rotate-90" data-testid="accounts-donut">
      <circle cx={size / 2} cy={size / 2} r={r} fill="none" strokeWidth="12" className="stroke-surface-secondary" />
      {slices.filter((s) => s.value > 0).map((s) => {
        const len = (s.value / total) * c
        const el = (
          <circle key={s.provider} cx={size / 2} cy={size / 2} r={r} fill="none" strokeWidth="12" stroke={platformColor(s.provider)} strokeDasharray={`${len} ${c - len}`} strokeDashoffset={-offset} />
        )
        offset += len
        return el
      })}
    </svg>
  )
}
