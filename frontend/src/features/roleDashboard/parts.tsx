import { createContext, useCallback, useContext, useEffect, useLayoutEffect, useRef, useState, type CSSProperties, type ReactNode, type RefObject } from 'react'
import { Link } from 'react-router-dom'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { ProgressRing } from '@/features/analytics/charts'
import { compact, money, num, percent, ratio } from '@/features/analytics/format'
import { objectiveLabel } from '@/features/analytics/metricLabels'
import { budgetStatus, STATUS_COLOR, STATUS_LABEL, STATUS_SOFT, STATUS_TEXT, type BudgetStatus } from '@/lib/colors'
import { useUi } from '@/stores/ui'
import { analyticsSectionFor, type AnalyticsSectionId } from '@/features/analyticsShared/homeSections'

/**
 * Local primitives for the role dashboards. They live here, not in the shared `components/ui`, on purpose: the dashboards
 * are one fixed-size screen (a card per figure, a panel per chart) and nothing else in the product wants that shape.
 * Every chart is the shared chart layer (`features/analytics/charts`) and every colour is `lib/colors`.
 */

export const useAr = () => useUi((s) => s.locale) === 'ar'

/** A multiplier read left to right in either direction: «9×», never «×9» inside an Arabic line. */
export const times = (n: number): string => `\u2066${ratio(n)}\u2069`

/** Number formats the dashboards share, so a card and a chart read one figure one way. */
export function useFmt() {
  const ar = useAr()

  return {
    ar,
    money: (n: number | null | undefined, c: string | null | undefined) => (n === null || n === undefined ? '—' : money(n, c)),
    count: (n: number | null | undefined) => (n === null || n === undefined ? '—' : num(n)),
    short: (n: number | null | undefined) => (n === null || n === undefined ? '—' : compact(n)),
    pct: (r: number | null | undefined) => (r === null || r === undefined ? '—' : percent(r, 0)),
  }
}

const FAMILY_FALLBACK: Record<string, { ar: string; en: string }> = {
  video: { ar: 'المشاهدات', en: 'Video' },
  app: { ar: 'التطبيقات', en: 'App' },
}

export function familyName(key: string, ar: boolean): string {
  const base = key === 'app' ? 'app_installs' : key
  const known = objectiveLabel(base, ar)

  return known !== base ? known : (FAMILY_FALLBACK[key]?.[ar ? 'ar' : 'en'] ?? key)
}

/**
 * On a desktop the dashboard fills at least one screen (DASH-PERF-SPLIT-001: at least, no longer exactly — with the
 * performance panels a page held to one screen squeezed its rows into one another; now it grows and the page scrolls).
 *
 * Previously: exactly one screen, no page scroll at all.
 *
 * Measured, not assumed: the box starts where it sits and ends at the bottom of the window, and any overflow the shell adds
 * below it (its own bottom padding) is taken off again, so the document never scrolls whatever the shell around it does.
 * Below 1024px the box is natural height and the page scrolls vertically, which is what a phone does.
 */
export function useFitViewport(): { ref: RefObject<HTMLDivElement | null>; height: number | undefined } {
  const ref = useRef<HTMLDivElement | null>(null)
  const [height, setHeight] = useState<number | undefined>(undefined)

  const fit = useCallback(() => {
    const el = ref.current
    if (!el) return
    if (window.innerWidth < 1024) {
      el.style.minHeight = ''
      setHeight(undefined)

      return
    }
    el.style.minHeight = ''
    const top = el.getBoundingClientRect().top + window.scrollY
    const base = Math.max(480, Math.floor(window.innerHeight - top))
    el.style.minHeight = `${base}px`
    const excess = document.documentElement.scrollHeight - document.documentElement.clientHeight
    const next = Math.max(480, base - Math.max(0, excess))
    el.style.minHeight = `${next}px`
    setHeight(next)
  }, [])

  useLayoutEffect(() => {
    fit()
  }, [fit])

  useEffect(() => {
    window.addEventListener('resize', fit)
    const settle = window.setTimeout(fit, 400)

    return () => {
      window.removeEventListener('resize', fit)
      window.clearTimeout(settle)
    }
  }, [fit])

  return { ref, height }
}

/** The height of a box, kept current — for handing a number to a chart that wants one. */
export function useBoxHeight(min = 80): { ref: RefObject<HTMLDivElement | null>; height: number } {
  const ref = useRef<HTMLDivElement | null>(null)
  const [height, setHeight] = useState(min)

  useLayoutEffect(() => {
    const el = ref.current
    if (!el) return
    const read = () => setHeight(Math.max(min, Math.floor(el.getBoundingClientRect().height)))
    read()
    const ro = new ResizeObserver(read)
    ro.observe(el)

    return () => ro.disconnect()
  }, [min])

  return { ref, height }
}

/* The hint row and its empty placeholder share one class string, so the placeholder reserves exactly the hint's height
   (TYPOGRAPHY-PRODUCT-POLISH-001: one arbitrary size, written once). */
const HINT_ROW = 'tnum min-h-4 truncate text-[11px] text-text-muted'

/**
 * ANALYTICS-POPUP-001 — Home's cards read the analytics data, and a card whose question the board answers opens THAT
 * section in a popup instead of leaving the page. Provided by `RoleDashboardPage`; without it (a test rendering one card)
 * every card is the link it always was.
 */
export const OpenAnalytics = createContext<((section: AnalyticsSectionId) => void) | null>(null)

/** The element a card or a panel title is: a button opening the matching section, or the link to its page. */
export function Door({ to, className, testId, status, ariaLabel, style, children }: { to: string; className: string; testId?: string; status?: string; ariaLabel?: string; style?: CSSProperties; children: ReactNode }) {
  const open = useContext(OpenAnalytics)
  const section = analyticsSectionFor(to)
  if (open && section) {
    return (
      <button type="button" onClick={() => open(section)} data-testid={testId} data-status={status} data-opens={`analytics:${section}`} aria-haspopup="dialog" aria-label={ariaLabel} style={style} className={`${className} text-start`}>
        {children}
      </button>
    )
  }

  return <Link to={to} data-testid={testId} data-status={status} aria-label={ariaLabel} style={style} className={className}>{children}</Link>
}

/** A figure that opens its detail page. */
export function Kpi({ label, value, hint, to, testId, status, children }: {
  label: string
  value: string
  hint?: string
  to: string
  testId: string
  status?: BudgetStatus
  children?: ReactNode
}) {
  const ar = useAr()
  const s = status ?? null

  return (
    <Door
      to={to}
      testId={testId}
      status={s ?? undefined}
      className="group relative flex min-w-0 flex-col justify-start gap-1 overflow-hidden rounded-2xl border border-border bg-surface px-3.5 py-2.5 shadow-[var(--shadow-small)] transition hover:border-border-strong hover:shadow-[var(--shadow-medium)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
    >
      {/* DASH-KPI-UNIFY-001 — one card shape for every figure: label, value, hint row always present (so values line up), the
          status a soft dot and glow instead of a side bar. */}
      {s && <span aria-hidden className="pointer-events-none absolute -top-8 end-0 h-16 w-24 rounded-full opacity-25 blur-2xl" style={{ background: STATUS_COLOR[s] }} />}
      <span className="flex items-center justify-between gap-2 text-xs font-semibold text-text-secondary">
        <span className="flex min-w-0 items-center gap-1.5">
          {s && <span aria-hidden className="h-2 w-2 shrink-0 rounded-full" style={{ background: STATUS_COLOR[s] }} />}
          <span className="truncate">{label}</span>
        </span>
        {ar ? <ChevronLeft size={14} className="shrink-0 text-text-muted opacity-0 transition group-hover:opacity-100" aria-hidden /> : <ChevronRight size={14} className="shrink-0 text-text-muted opacity-0 transition group-hover:opacity-100" aria-hidden />}
      </span>
      <span className={`tnum truncate text-2xl font-extrabold leading-tight tracking-tight ${s ? STATUS_TEXT[s] : 'text-text-primary'}`} data-testid={`${testId}-value`}>{value}</span>
      {hint ? <span className={HINT_ROW}>{hint}</span> : (children ?? <span aria-hidden className={HINT_ROW}>{'\u00a0'}</span>)}
    </Door>
  )
}

/** A chart panel: a two-word title that opens the detail, and a body that takes the rest of the height. */
export function Panel({ title, to, testId, className = '', children }: { title: string; to?: string; testId: string; className?: string; children: ReactNode }) {
  const ar = useAr()
  const Chevron = ar ? ChevronLeft : ChevronRight
  const head = (
    <h2 className="flex items-center gap-1 text-sm font-bold tracking-tight text-text-primary">
      {title}
      {to && <Chevron size={14} className="text-text-muted" aria-hidden />}
    </h2>
  )

  return (
    <section data-testid={testId} className={`flex min-h-0 min-w-0 flex-col gap-2 rounded-2xl border border-border bg-surface p-3.5 shadow-[var(--shadow-small)] ${className}`}>
      {to ? <Door to={to} className="w-fit rounded focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-600">{head}</Door> : head}
      <div className="min-h-0 flex-1">{children}</div>
    </section>
  )
}

export function Chip({ to, label, value, status, testId }: { to: string; label: string; value: string; status: BudgetStatus; testId: string }) {
  return (
    <Door
      to={to}
      testId={testId}
      status={status}
      style={{ background: STATUS_SOFT[status] }}
      className="inline-flex min-w-0 items-center gap-2 rounded-full border border-border px-3 py-1 text-xs font-semibold text-text-primary hover:border-border-strong focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-600"
    >
      <span aria-hidden className="h-2 w-2 shrink-0 rounded-full" style={{ background: STATUS_COLOR[status] }} />
      <span className="truncate text-text-secondary">{label}</span>
      <span className="tnum shrink-0">{value}</span>
    </Door>
  )
}

/** The bottom row: a handful of status chips. */
export function StatusStrip({ children, testId = 'dash-strip' }: { children: ReactNode; testId?: string }) {
  return <div data-testid={testId} className="flex min-w-0 flex-wrap items-center gap-2">{children}</div>
}

const TONE = { within: 'success', near: 'warning', exceeded: 'danger', unknown: 'brand' } as const

/** A circular gauge for a spend-to-budget ratio: the chart layer's ring, coloured by the shared status. */
export function BudgetGauge({ ratio, name, to, size = 76, testId }: { ratio: number | null; name: string; to: string; size?: number; testId: string }) {
  const ar = useAr()
  const status = budgetStatus(ratio)

  return (
    <Door to={to} testId={testId} status={status} ariaLabel={`${name}: ${ratio === null ? STATUS_LABEL.unknown[ar ? 'ar' : 'en'] : percent(ratio, 0)} — ${STATUS_LABEL[status][ar ? 'ar' : 'en']}`} className="flex min-w-0 flex-col items-center gap-1 rounded-xl p-1 hover:bg-surface-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-600">
      <ProgressRing value={ratio ?? 0} size={size} tone={TONE[status]} label={ratio === null ? '—' : percent(ratio, 0)} />
      <span className="max-w-full truncate text-[11px] font-semibold text-text-secondary">{name}</span>
    </Door>
  )
}

/** A figure that has nothing behind it says so, in one word. */
export function Nothing({ label }: { label: string }) {
  return <div className="flex h-full min-h-16 items-center justify-center text-sm text-text-muted">{label}</div>
}
