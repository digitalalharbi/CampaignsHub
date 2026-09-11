import { useMemo, useState } from 'react'
import { ChevronDown, Search } from 'lucide-react'
import { MetricStrip } from '@/components/ui/MetricStrip'
import { metricsForKeys, selectableMetrics } from './metricCatalog'
import type { Summary, TimePoint } from './api'

/**
 * KPI-SELECTION-001 — four cards, and the metric on each is the reader's to choose.
 *
 * «More metrics / مؤشرات إضافية» expanded a second row nobody asked for and hid the ones somebody
 * did. This is the shape an ads manager uses instead: four cards, the metric NAME on each one a
 * control, and a search over the catalogue behind it.
 *
 * ## It is the same card
 *
 * The items come from `metricsForKeys`, which calls the catalogue's own builder — the one the
 * objective layout uses. So a chosen metric carries the same reading, comparison, trend, sparkline,
 * definition and currency as that metric chosen by the layout, and there is no second arithmetic for
 * the two to disagree about. Everything the filters narrow still narrows it: the cards are built
 * from the summary the page already fetched under the active scope.
 */
export const DEFAULT_KPIS = ['spend', 'impressions', 'clicks', 'ctr'] as const

/** Per user and per browser, which is what a display preference is. */
const STORE_KEY = 'campaignshub.dashboard.kpis'

function remembered(): string[] {
  try {
    const raw = window.localStorage.getItem(STORE_KEY)
    const parsed = raw === null ? null : (JSON.parse(raw) as unknown)

    if (Array.isArray(parsed) && parsed.length === 4 && parsed.every((k) => typeof k === 'string')) {
      return parsed as string[]
    }
  } catch {
    /* A browser that refuses storage still gets a dashboard; the default is not an error state. */
  }

  return [...DEFAULT_KPIS]
}

export function KpiCards({
  objective,
  summary,
  ar,
  series,
  comparable,
  loading,
  error,
  onRetry,
}: {
  objective: string
  summary: Summary | undefined
  ar: boolean
  series?: readonly TimePoint[]
  /** Without a comparison window there is no delta to state — the same rule the strip follows. */
  comparable: boolean
  loading?: boolean
  error?: unknown
  onRetry?: () => void
}) {
  const [keys, setKeys] = useState<string[]>(remembered)
  const [picking, setPicking] = useState<number | null>(null)
  const [term, setTerm] = useState('')

  const catalogue = useMemo(() => selectableMetrics(ar), [ar])

  const items = useMemo(() => {
    const built = metricsForKeys(keys, objective, summary, ar, series)

    return comparable ? built : built.map((m) => ({ ...m, delta: undefined }))
  }, [keys, objective, summary, ar, series, comparable])

  const choose = (index: number, key: string) => {
    setKeys((current) => {
      const next = [...current]
      next[index] = key

      try {
        window.localStorage.setItem(STORE_KEY, JSON.stringify(next))
      } catch {
        /* The choice still applies for this visit even where it cannot be remembered. */
      }

      return next
    })
    setPicking(null)
    setTerm('')
  }

  const shown = term.trim() === ''
    ? catalogue
    : /*
         Key as well as label: an operator looking for ROAS types «roas», and the catalogue's own name
         for it is «Return on ad spend» — matching the label alone answered that with «No metric by
         that name». The key is also the stable term across AR and EN, so an Arabic reader who knows
         the metric by its English abbreviation still finds it.
       */
      catalogue.filter((m) => {
        const q = term.trim().toLowerCase()
        return m.label.toLowerCase().includes(q) || m.key.toLowerCase().includes(q)
      })

  return (
    <div data-testid="dashboard-kpis" className="relative">
      {/*
        Rendered THROUGH `MetricStrip`, not beside it.

        The strip already owns the states these cards must keep: the request still in flight, a
        refusal told as a refusal, a metric the platform never reported said instead of its coalesced
        zero, and an empty filter scope replacing the row with one sentence. A second strip for
        selectable cards would have to reimplement all four, and the guards that hold them would stop
        covering the dashboard.
      */}
      <MetricStrip
        id="dashboard"
        ar={ar}
        primary={items}
        hasRows={summary === undefined ? undefined : summary.rows_in_scope}
        loading={loading}
        error={error}
        onRetry={onRetry}
        labelControl={(index) => {
          const item = items[index]

          if (item === undefined) {
            return null
          }

          return (
            <span className="relative inline-flex">
              <button
                type="button"
                onClick={() => { setPicking(picking === index ? null : index); setTerm('') }}
                aria-expanded={picking === index}
                aria-haspopup="listbox"
                /*
                  The visible text is the metric's name, which alone reads as a label rather than a
                  control — a screen reader would announce «Spend, button» and leave the reader to
                  guess what pressing it does. The action goes in the name; the name still carries the
                  metric, so the card is still identifiable.
                */
                aria-label={ar ? `غيّر المؤشر: ${item.label}` : `Change metric: ${item.label}`}
                data-testid={`kpi-picker-${index}`}
                className="inline-flex items-center gap-1 text-start hover:text-text-primary"
              >
                <span className="line-clamp-2">{item.label}</span>
                <ChevronDown size={12} aria-hidden className="shrink-0" />
              </button>

              {picking === index && (
                <span className="absolute z-40 mt-1 block max-h-72 w-64 overflow-y-auto rounded-xl border border-border-strong bg-surface p-2 shadow-[var(--shadow-medium)] start-0 top-full">
                  <span className="relative mb-1 block">
                    <Search size={13} aria-hidden className="absolute top-2.5 text-text-muted start-2" />
                    <input
                      autoFocus
                      type="search"
                      value={term}
                      onChange={(e) => setTerm(e.target.value)}
                      aria-label={ar ? 'ابحث عن مؤشر' : 'Search for a metric'}
                      placeholder={ar ? 'ابحث عن مؤشر…' : 'Search for a metric…'}
                      data-testid="kpi-search"
                      className="h-8 w-full rounded-lg border border-border bg-surface text-sm font-normal text-text-primary ps-7 pe-2"
                    />
                  </span>

                  {shown.length === 0 ? (
                    <span className="block px-2 py-3 text-xs text-text-muted">
                      {ar ? 'لا مؤشر بهذا الاسم.' : 'No metric by that name.'}
                    </span>
                  ) : (
                    shown.map((m) => (
                      <button
                        key={m.key}
                        type="button"
                        onClick={() => choose(index, m.key)}
                        data-testid={`kpi-option-${m.key}`}
                        className={`block w-full rounded-lg px-2 py-1.5 text-start text-sm font-normal hover:bg-surface-hover ${
                          keys[index] === m.key ? 'font-bold text-brand-600' : 'text-text-primary'
                        }`}
                      >
                        {m.label}
                      </button>
                    ))
                  )}
                </span>
              )}
            </span>
          )
        }}
      />
    </div>
  )
}
