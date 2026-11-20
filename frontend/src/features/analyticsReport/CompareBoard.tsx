import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ArrowDownRight, ArrowRight, ArrowUpRight } from 'lucide-react'
import { Num } from '@/components/ui/Num'
import { Skeleton } from '@/components/ui/States'
import { ChartCard, MetricLineChart } from '@/features/analytics/charts'
import { useCampaignOptions, type Range } from '@/features/analytics/api'
import { useUrlList, useUrlState } from '@/features/analytics/filterUrlState'
import { providerLabel } from '@/features/campaigns/labels'
import { listProjects } from '@/features/projects/api'
import { useLiveMetricReader, metricLabel } from '@/features/reports/live/liveMetrics'
import type { LivePayload } from '@/features/reports/api'
import { canonicalPlatform } from '@/lib/platforms'
import { useUi } from '@/stores/ui'
import { baselineRange, compareFigure, comparisonTone, headlineKeys, overlayDaily, resultSource, trendOptions, type Comparison, type Reader } from '@/features/reportLogic'
import { useReportView } from './api'

/**
 * «مقارنة» — one side against another, on the report's own payload.
 *
 * Four modes, and in every one the two sides are read by the SAME builder over the SAME logic (`reportLogic`), so a side's figure is
 * the figure its own report would state: period against period, platform against platform, campaign against campaign, and project
 * against project — the last only among the projects of the reader's own workspace (the list comes from the workspace's own API and
 * the report route refuses any other project). A side that returned nothing is «غير متاح»: no estimate stands in for it.
 */
export type CompareMode = 'period' | 'platform' | 'campaign' | 'project'
const MODES: Array<{ key: CompareMode; ar: string; en: string }> = [
  { key: 'period', ar: 'فترة مقابل فترة', en: 'Period vs period' },
  { key: 'platform', ar: 'منصة مقابل منصة', en: 'Platform vs platform' },
  { key: 'campaign', ar: 'حملة مقابل حملة', en: 'Campaign vs campaign' },
  { key: 'project', ar: 'مشروع مقابل مشروع', en: 'Project vs project' },
]

const BASELINES = [
  { key: 'previous', ar: 'الفترة السابقة', en: 'Previous period' },
  { key: 'month', ar: 'قبل شهر', en: 'A month earlier' },
  { key: 'year', ar: 'قبل سنة', en: 'A year earlier' },
] as const

type Side = { label: string; projectId: string; range: Range; providers: string[]; campaigns: string[] }

const na = (ar: boolean) => (ar ? 'غير متاح' : 'Not available')

function Select({ value, onChange, options, testId, label }: { value: string; onChange: (v: string) => void; options: Array<{ value: string; label: string }>; testId: string; label: string }) {
  return (
    <select aria-label={label} data-testid={testId} value={value} onChange={(e) => onChange(e.target.value)} className="h-9 min-w-0 max-w-full rounded-lg border border-border bg-surface px-2 text-sm text-text-primary">
      {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
    </select>
  )
}

export function CompareBoard({ projectId, range }: { projectId: string; range: Range }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const [modeRaw, setMode] = useUrlState('cmp', 'period')
  const mode = (MODES.some((m) => m.key === modeRaw) ? modeRaw : 'period') as CompareMode
  const [baseline, setBaseline] = useUrlState('cmpbase', 'previous')
  const [a, setA] = useUrlState('cmpa', '')
  const [b, setB] = useUrlState('cmpb', '')
  const [providers] = useUrlList('provider')
  const [campaigns] = useUrlList('campaign')
  const [metric, setMetric] = useState<string | null>(null)

  const base = useReportView(projectId, range, [], [])
  const platformOptions = useMemo(() => [...new Set((base.data?.available?.providers ?? []).map((p) => canonicalPlatform(p)))], [base.data])
  const campaignOptions = useCampaignOptions(projectId, '')
  const projects = useQuery({ queryKey: ['workspace-projects', 'compare'], queryFn: () => listProjects(false), enabled: mode === 'project', staleTime: 5 * 60_000 })

  const platformA = a || platformOptions[0] || ''
  const platformB = b || platformOptions[1] || platformOptions[0] || ''
  const campaignList = campaignOptions.data?.options ?? []
  const campaignA = a || campaignList[0]?.id || ''
  const campaignB = b || campaignList[1]?.id || campaignList[0]?.id || ''
  const projectList = (projects.data ?? []).filter((p) => String(p.id) !== projectId)
  const projectB = b || String(projectList[0]?.id ?? '')

  const sideA: Side | null = mode === 'period' ? { label: ar ? 'الفترة الحالية' : 'This period', projectId, range, providers, campaigns }
    : mode === 'platform' ? (platformA === '' ? null : { label: providerLabel(platformA, ar ? 'ar' : 'en'), projectId, range, providers: [platformA], campaigns })
    : mode === 'campaign' ? (campaignA === '' ? null : { label: campaignList.find((c) => c.id === campaignA)?.name ?? campaignA, projectId, range, providers, campaigns: [campaignA] })
    : { label: ar ? 'هذا المشروع' : 'This project', projectId, range, providers, campaigns }
  const sideB: Side | null = mode === 'period' ? { label: ar ? (BASELINES.find((x) => x.key === baseline)?.ar ?? '') : (BASELINES.find((x) => x.key === baseline)?.en ?? ''), projectId, range: baselineRange(range, baseline), providers, campaigns }
    : mode === 'platform' ? (platformB === '' ? null : { label: providerLabel(platformB, ar ? 'ar' : 'en'), projectId, range, providers: [platformB], campaigns })
    : mode === 'campaign' ? (campaignB === '' ? null : { label: campaignList.find((c) => c.id === campaignB)?.name ?? campaignB, projectId, range, providers, campaigns: [campaignB] })
    : (projectB === '' ? null : { label: projectList.find((p) => String(p.id) === projectB)?.name ?? projectB, projectId: projectB, range, providers, campaigns: [] })

  const qa = useReportView(sideA?.projectId ?? null, sideA?.range ?? range, sideA?.providers ?? [], sideA?.campaigns ?? [], sideA !== null)
  const qb = useReportView(sideB?.projectId ?? null, sideB?.range ?? range, sideB?.providers ?? [], sideB?.campaigns ?? [], sideB !== null)
  const currency = qa.data?.currency ?? qb.data?.currency ?? ''
  const reader = useLiveMetricReader(currency, ar)

  const pickers = mode === 'period' ? (
    <Select label={ar ? 'مقارنة بـ' : 'Compare with'} testId="compare-baseline" value={baseline} onChange={setBaseline} options={BASELINES.map((x) => ({ value: x.key, label: ar ? x.ar : x.en }))} />
  ) : mode === 'platform' ? (
    <>
      <Select label="A" testId="compare-a" value={platformA} onChange={setA} options={platformOptions.map((p) => ({ value: p, label: providerLabel(p, ar ? 'ar' : 'en') }))} />
      <span className="text-text-muted">{ar ? 'مقابل' : 'vs'}</span>
      <Select label="B" testId="compare-b" value={platformB} onChange={setB} options={platformOptions.map((p) => ({ value: p, label: providerLabel(p, ar ? 'ar' : 'en') }))} />
    </>
  ) : mode === 'campaign' ? (
    <>
      <Select label="A" testId="compare-a" value={campaignA} onChange={setA} options={campaignList.map((c) => ({ value: c.id, label: c.name }))} />
      <span className="text-text-muted">{ar ? 'مقابل' : 'vs'}</span>
      <Select label="B" testId="compare-b" value={campaignB} onChange={setB} options={campaignList.map((c) => ({ value: c.id, label: c.name }))} />
    </>
  ) : (
    <Select label={ar ? 'المشروع' : 'Project'} testId="compare-b" value={projectB} onChange={setB} options={projectList.map((p) => ({ value: String(p.id), label: p.name }))} />
  )

  return (
    <div data-testid="compare-board" data-mode={mode} className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2">
        <div role="tablist" aria-label={ar ? 'نوع المقارنة' : 'Comparison'} className="flex flex-wrap gap-1.5" data-testid="compare-modes">
          {MODES.map((m) => (
            <button key={m.key} type="button" role="tab" aria-selected={mode === m.key} data-testid={`compare-mode-${m.key}`} onClick={() => { setMode(m.key); setA(''); setB('') }}
              className={`rounded-full border px-3 py-1 text-xs font-semibold ${mode === m.key ? 'border-brand-500 bg-brand-500/10 text-text-primary' : 'border-border text-text-muted'}`}>
              {ar ? m.ar : m.en}
            </button>
          ))}
        </div>
        <div className="flex min-w-0 flex-wrap items-center gap-2" data-testid="compare-pickers">{pickers}</div>
      </div>
      {sideA === null || sideB === null ? (
        <p data-testid="compare-unavailable" className="rounded-2xl border border-border bg-surface p-4 text-sm text-text-muted">{na(ar)}</p>
      ) : (qa.isPending || qb.isPending) ? (
        <Skeleton className="h-40" />
      ) : (
        <CompareResult ar={ar} reader={reader} a={{ label: sideA.label, payload: qa.data ?? null }} b={{ label: sideB.label, payload: qb.data ?? null }} metric={metric} onMetric={setMetric} currency={currency} />
      )}
    </div>
  )
}

type Loaded = { label: string; payload: LivePayload | null }

const hasData = (p: LivePayload | null) => p !== null && p.no_data_yet !== true && p.totals !== undefined

/** The two sides over each other — pure over two payloads, so it is testable without a network. */
export function CompareResult({ ar, reader, a, b, metric, onMetric, currency }: { ar: boolean; reader: Reader; a: Loaded; b: Loaded; metric: string | null; onMetric: (k: string) => void; currency: string }) {
  const okA = hasData(a.payload)
  const okB = hasData(b.payload)
  const lead = okA ? a.payload! : b.payload
  const keys = lead ? ['spend', 'clicks', headlineKeys(lead, reader).result, 'revenue', 'roas'].filter((k, i, all) => all.indexOf(k) === i && reader.meta[k]) : []
  const rows = keys.map((key) => {
    const meta = reader.meta[key]
    const text = (side: Loaded, ok: boolean) => {
      const t = ok ? meta.format(side.payload!.totals, side.payload!, reader.asMoney, reader.count).text.trim() : ''

      return t === '' || t === '—' ? na(ar) : t
    }
    const c: Comparison = okA && okB ? compareFigure(a.payload!.totals[key], b.payload!.totals[key]) : compareFigure(null, null)

    return { key, label: metricLabel(meta, key, lead!, ar), textA: text(a, okA), textB: text(b, okB), c, invertGood: meta.invertGood === true }
  }).filter((r) => r.textA !== na(ar) || r.textB !== na(ar))
  const source = lead ? resultSource(lead, reader, headlineKeys(lead, reader).result, ar) : null
  const options = lead && source ? trendOptions(lead, reader, source, ar) : []
  const active = options.find((o) => o.key === metric) ?? options[0]
  const data = active && okA && okB ? overlayDaily(a.payload!.timeseries ?? [], b.payload!.timeseries ?? [], active.key).map((p, i) => ({ date: String((a.payload!.timeseries?.[i]?.date ?? b.payload!.timeseries?.[i]?.date ?? p.day)), a: p.a, b: p.b })) : []

  return (
    <div className="flex flex-col gap-4">
      <div data-testid="compare-kpis" className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
        {rows.map((r) => {
          const tone = comparisonTone(r.c, r.invertGood)
          const Arrow = r.c.direction === 'up' ? ArrowUpRight : r.c.direction === 'down' ? ArrowDownRight : ArrowRight

          return (
            <div key={r.key} data-testid={`compare-kpi-${r.key}`} data-direction={r.c.direction} className="min-w-0 rounded-2xl border border-border bg-surface p-3">
              <div className="truncate text-xs font-semibold text-text-muted">{r.label}</div>
              <div className="mt-1 grid grid-cols-2 gap-2">
                <div className="min-w-0"><div className="truncate text-xs text-text-muted">{a.label}</div><div className="tnum truncate text-base font-extrabold text-text-primary" data-testid={`compare-a-${r.key}`}><Num>{r.textA}</Num></div></div>
                <div className="min-w-0"><div className="truncate text-xs text-text-muted">{b.label}</div><div className="tnum truncate text-base font-extrabold text-text-primary" data-testid={`compare-b-${r.key}`}><Num>{r.textB}</Num></div></div>
              </div>
              <div data-testid={`compare-delta-${r.key}`} className={`mt-1.5 inline-flex items-center gap-1 text-xs font-bold ${tone === 'good' ? 'text-success' : tone === 'bad' ? 'text-danger' : 'text-text-muted'}`}>
                {r.c.direction === 'na' ? na(ar) : (
                  <>
                    <Arrow size={14} aria-hidden />
                    <span className="tnum" dir="ltr">{r.c.pct === null ? (ar ? 'بدون نسبة' : 'no %') : `${r.c.pct > 0 ? '+' : ''}${(r.c.pct * 100).toFixed(1)}%`}</span>
                  </>
                )}
              </div>
            </div>
          )
        })}
        {rows.length === 0 && <p data-testid="compare-unavailable" className="rounded-2xl border border-border bg-surface p-4 text-sm text-text-muted sm:col-span-2 lg:col-span-3">{na(ar)}</p>}
      </div>
      {options.length > 0 && (
        <ChartCard title={ar ? 'الأيام فوق بعضها' : 'Days overlaid'}>
          <div role="group" aria-label={ar ? 'المقياس' : 'Metric'} className="mb-2 flex flex-wrap gap-1.5" data-testid="compare-metrics">
            {options.map((o) => (
              <button key={o.key} type="button" aria-pressed={active?.key === o.key} data-testid={`compare-metric-${o.key}`} onClick={() => onMetric(o.key)}
                className={`rounded-full border px-2.5 py-1 text-xs font-semibold ${active?.key === o.key ? 'border-brand-500 bg-brand-500/10 text-text-primary' : 'border-border text-text-muted'}`}>{o.name}</button>
            ))}
          </div>
          {okA && okB ? (
            <div data-testid="compare-overlay"><MetricLineChart data={data} currency={currency} height={240} series={[{ key: 'a', name: a.label, color: 'var(--brand-600)' }, { key: 'b', name: b.label, color: 'var(--warning)' }]} /></div>
          ) : (
            <p data-testid="compare-overlay-unavailable" className="py-8 text-center text-sm text-text-muted">{na(ar)}</p>
          )}
        </ChartCard>
      )}
    </div>
  )
}
