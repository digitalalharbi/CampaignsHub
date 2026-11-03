/**
 * PERF-BASELINE-WEBVITALS-001 — LCP, INP and CLS from the browser's own PerformanceObserver.
 *
 * ## Off unless asked
 *
 * `VITE_WEB_VITALS_SAMPLE` is a rate from 0 to 1 and defaults to nothing, which is OFF: no observer
 * is constructed for a customer unless the deployment opted in, and then only for that fraction of
 * sessions. There is no switch a customer can reach.
 *
 * ## Nothing personal leaves
 *
 * A report is `{ name, value, rating, path, navigation }`. `path` is the ROUTE SHAPE — the address
 * with its query and hash dropped and every id, token and address-like segment replaced by `:id` —
 * so `/r/<report token>` is reported as `/r/:id`. No user, workspace, project, client, filter,
 * currency, user agent, IP or free text is read, let alone sent. The sink is local unless an
 * endpoint is configured, and `sendBeacon` carries no cookies of its own choosing.
 *
 * ## What the numbers are
 *
 * The three Core Web Vitals, computed the way the field definition states, not a convenient
 * approximation: LCP is the last candidate, CLS is the worst 5-second session window (1-second
 * gaps, shifts after input excluded), INP is the 98th-percentile interaction (the worst when there
 * are fewer than fifty). They are reported when the page is hidden, which is when they are final.
 *
 * The targets are the Owner's (P0 §12.5): LCP ≤ 2.5s, INP ≤ 200ms, CLS ≤ 0.1. They are targets for
 * the test environment, not a claim about any customer's network.
 */
export type VitalName = 'LCP' | 'INP' | 'CLS'
export type Rating = 'good' | 'needs-improvement' | 'poor'

export interface VitalReport {
  name: VitalName
  value: number
  rating: Rating
  /** Route shape only — see {@link routeShape}. */
  path: string
  navigation: string
}

const THRESHOLDS: Record<VitalName, [good: number, poor: number]> = {
  LCP: [2500, 4000],
  INP: [200, 500],
  CLS: [0.1, 0.25],
}

export const rate = (name: VitalName, value: number): Rating => {
  const [good, poor] = THRESHOLDS[name]

  return value <= good ? 'good' : value <= poor ? 'needs-improvement' : 'poor'
}

/** `0`–`1`; anything absent or out of range is OFF. */
export function sampleRate(raw: string | undefined): number {
  if (raw === undefined || raw.trim() === '') return 0
  const n = Number(raw)

  return Number.isFinite(n) && n > 0 && n <= 1 ? n : 0
}

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

/** The address with everything identifying removed: `/app/clients/42?x=1` → `/app/clients/:id`. */
export function routeShape(address: string): string {
  const path = address.split('#')[0].split('?')[0]
  const shaped = path
    .split('/')
    .map((segment) => {
      if (segment === '') return segment
      if (/^\d+$/.test(segment) || UUID.test(segment)) return ':id'
      if (/[@%]/.test(segment) || /^[A-Za-z0-9_-]{16,}$/.test(segment)) return ':id'

      return segment
    })
    .join('/')

  return shaped === '' ? '/' : shaped
}

type Entry = { startTime?: number; value?: number; hadRecentInput?: boolean; interactionId?: number; duration?: number }

export interface VitalsOptions {
  rate: number
  random?: () => number
  report: (r: VitalReport) => void
  path?: () => string
}

export interface VitalsHandle {
  /** Report what has been measured so far, once per metric. Called when the page is hidden. */
  flush: () => void
  stop: () => void
}

export function startWebVitals({ rate: sample, random = Math.random, report, path = () => routeShape(window.location.pathname) }: VitalsOptions): VitalsHandle | null {
  if (sample <= 0 || random() >= sample) return null
  if (typeof PerformanceObserver === 'undefined') return null

  let lcp: number | null = null

  // CLS: the worst session window.
  let clsWindow = 0
  let clsWindowStart = 0
  let clsLast = 0
  let cls: number | null = null

  // INP: the worst duration per interaction id.
  const interactions = new Map<number, number>()

  const observers: PerformanceObserver[] = []
  const watch = (type: string, onEntries: (entries: Entry[]) => void, extra: Record<string, unknown> = {}) => {
    try {
      const supported = (PerformanceObserver as unknown as { supportedEntryTypes?: string[] }).supportedEntryTypes
      if (supported && !supported.includes(type)) return
      const observer = new PerformanceObserver((list) => onEntries(list.getEntries() as unknown as Entry[]))
      observer.observe({ type, buffered: true, ...extra } as PerformanceObserverInit)
      observers.push(observer)
    } catch {
      /* a browser that refuses this entry type simply does not report it */
    }
  }

  watch('largest-contentful-paint', (entries) => {
    for (const e of entries) lcp = e.startTime ?? lcp
  })

  watch('layout-shift', (entries) => {
    for (const e of entries) {
      if (e.hadRecentInput || e.value === undefined || e.startTime === undefined) continue
      if (cls !== null && e.startTime - clsLast < 1000 && e.startTime - clsWindowStart < 5000) {
        clsWindow += e.value
      } else {
        clsWindow = e.value
        clsWindowStart = e.startTime
      }
      clsLast = e.startTime
      cls = Math.max(cls ?? 0, clsWindow)
    }
  })

  watch('event', (entries) => {
    for (const e of entries) {
      if (!e.interactionId || e.duration === undefined) continue
      interactions.set(e.interactionId, Math.max(interactions.get(e.interactionId) ?? 0, e.duration))
    }
  }, { durationThreshold: 40 })

  const navigation = (): string => {
    const nav = performance.getEntriesByType?.('navigation')?.[0] as { type?: string } | undefined

    return nav?.type ?? 'navigate'
  }

  let sent = new Set<VitalName>()
  const emit = (name: VitalName, value: number | null) => {
    if (value === null || sent.has(name)) return
    sent.add(name)
    report({ name, value: name === 'CLS' ? Math.round(value * 1000) / 1000 : Math.round(value), rating: rate(name, value), path: path(), navigation: navigation() })
  }

  const inp = (): number | null => {
    if (interactions.size === 0) return null
    const worst = [...interactions.values()].sort((a, b) => b - a)

    return worst[Math.min(worst.length - 1, Math.floor(worst.length / 50))]
  }

  const flush = () => {
    emit('LCP', lcp)
    emit('INP', inp())
    emit('CLS', cls)
    // A page that stays open and is hidden again later reports its newer values, once more.
    sent = new Set()
  }

  const onHide = () => { if (document.visibilityState === 'hidden') flush() }
  document.addEventListener('visibilitychange', onHide)
  window.addEventListener('pagehide', flush)

  return {
    flush,
    stop: () => {
      observers.forEach((o) => o.disconnect())
      document.removeEventListener('visibilitychange', onHide)
      window.removeEventListener('pagehide', flush)
    },
  }
}

/**
 * Where reports go. Always the in-page buffer (`window.__chubVitals`, for a local baseline); and the
 * configured endpoint by `sendBeacon` when one is set. Never a third party by default.
 */
export function defaultSink(endpoint: string | undefined) {
  return (r: VitalReport) => {
    const w = window as unknown as { __chubVitals?: VitalReport[] }
    ;(w.__chubVitals ??= []).push(r)
    if (endpoint) {
      try { navigator.sendBeacon(endpoint, new Blob([JSON.stringify(r)], { type: 'application/json' })) } catch { /* telemetry never breaks a page */ }
    }
  }
}
