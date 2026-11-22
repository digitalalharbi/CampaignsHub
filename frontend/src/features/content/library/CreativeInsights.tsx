import { useMemo, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { ArrowDown, ArrowUp } from 'lucide-react'
import { ProviderMark } from '@/components/ProviderMark'
import { Num } from '@/components/ui/Num'
import { DataMetricTable, type Row } from '@/components/ui/MetricTable'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'
import { formatNumber } from '@/lib/numerals'
import { money } from '@/features/analytics/format'
import { formatComparison, listCreatives, type CreativeCard, type CreativeMetrics } from '../api'
import { ContentTypesComparison } from '../ContentTypesComparison'
import { metricKind, metricLabel } from '../metrics'
import { Cover } from './Cover'
import { LIB, type LibCopy } from './copy'
import { formatLabel, isoDaysAgo } from './util'

type SortKey = 'ctr' | 'spend' | 'conversions' | 'revenue' | 'impressions'
const SORTS: SortKey[] = ['ctr', 'spend', 'conversions', 'revenue', 'impressions']
/** A click rate over a handful of impressions is noise; below this a creative is not ranked by it. */
const CTR_FLOOR_IMPRESSIONS = 1000
const PER_PAGE = 60
const STRIP = 12
const TABLE_METRICS = ['spend', 'impressions', 'clicks', 'ctr', 'conversions', 'cpa', 'revenue', 'roas'] as const

function value(m: CreativeMetrics | null | undefined, key: SortKey): number | null {
  if (!m) return null
  const v = m[key]

  return typeof v === 'number' && Number.isFinite(v) ? v : null
}

function show(key: SortKey, v: number | null, currency: string | null, t: LibCopy): string {
  if (v === null) return t.notAvailable
  if (key === 'ctr') return `${formatNumber(v * 100, { maximumFractionDigits: 2 })}%`
  if (key === 'spend' || key === 'revenue') return money(v, currency)

  return formatNumber(v, { maximumFractionDigits: 0 })
}

function Strip({ title, testid, rows, sort, currency, pastById, ar, t }: {
  title: string
  testid: string
  rows: CreativeCard[]
  sort: SortKey
  currency: string | null
  pastById: Map<string, CreativeMetrics | null>
  ar: boolean
  t: LibCopy
}) {
  const navigate = useNavigate()

  return (
    <section className="rounded-2xl border border-border bg-surface p-5" data-testid={testid}>
      <h2 className="mb-4 text-lg font-bold text-text-primary">{title}</h2>
      {rows.length === 0 && <p className="py-8 text-center text-sm text-text-secondary">{t.empty}</p>}
      <div className="flex gap-4 overflow-x-auto pb-2">
        {rows.map((c) => {
          const now = value(c.metrics, sort)
          const before = value(pastById.get(c.id) ?? null, sort)
          const delta = now !== null && before !== null && before !== 0 ? ((now - before) / Math.abs(before)) * 100 : null

          return (
            <button key={c.id} type="button" onClick={() => navigate(c.id)} data-testid={`${testid}-card-${c.id}`} className="w-44 shrink-0 text-start">
              <div className="relative overflow-hidden rounded-3xl border-4 border-black bg-black">
                <Cover preview={c.preview} ar={ar} className="aspect-[9/16] w-full" />
                <span className="absolute start-2 top-2 rounded-full bg-surface/90 p-1"><ProviderMark provider={c.provider} size={16} /></span>
                <span className="absolute end-2 top-2 rounded-md bg-surface/90 px-1.5 py-0.5 text-xs font-semibold text-text-primary">{formatLabel(c.kind, t)}</span>
              </div>
              <p className="mt-3 text-xs text-text-secondary">{t.sorts[sort]}</p>
              <p className="text-xl font-bold text-text-primary"><Num>{show(sort, now, currency, t)}</Num></p>
              {delta !== null && (
                <p className={`flex items-center gap-1 text-xs font-semibold ${delta >= 0 ? 'text-success' : 'text-danger'}`}>
                  {delta >= 0 ? <ArrowUp size={12} aria-hidden /> : <ArrowDown size={12} aria-hidden />}
                  <Num>{formatNumber(Math.abs(delta), { maximumFractionDigits: 0 })}%</Num>
                  <span className="font-normal text-text-secondary">{t.vsPast}</span>
                </p>
              )}
              <p className="mt-1 line-clamp-2 text-sm font-semibold text-text-primary">{c.name}</p>
            </button>
          )
        })}
      </div>
    </section>
  )
}

/**
 * CONTENT-LIBRARY-SNAP-001 — «رؤى المحتوى»: the whole content analysis on one tab, the way the reports read it. One sort, one format,
 * one period drive everything below: the best performers and the weakest (each in its own 9:16 frame, with the change against the 90
 * days before), the comparison of content types, and every creative with its figures in one sortable table. A figure the server did not
 * report reads «غير متاح», never zero; a click rate is only ranked over enough impressions to mean something.
 */
export function CreativeInsights() {
  const ar = useUi((s) => s.locale) === 'ar'
  const locale = ar ? 'ar' : 'en'
  const t = LIB[locale]
  const navigate = useNavigate()
  const projectId = useProject((s) => s.currentProjectId)
  const [sort, setSort] = useState<SortKey>('ctr')
  const [kind, setKind] = useState<string>('')
  const [days, setDays] = useState<7 | 30 | 90>(7)
  const [page, setPage] = useState(1)
  const from = isoDaysAgo(days - 1)
  const to = isoDaysAgo(0)

  // The server ranks what it can; a rate it cannot rank by is read over the creatives that spent most and ordered here.
  const serverSort = sort === 'ctr' ? 'spend' : sort
  const current = useQuery({
    queryKey: ['creative-library', 'insights', projectId, sort, kind, days, page],
    queryFn: () => listCreatives({ from, to, sort: serverSort, page, per_page: PER_PAGE, project_ids: projectId ? [projectId] : undefined, kinds: kind ? [kind] : undefined }, null),
    placeholderData: keepPreviousData,
  })
  const rows = useMemo(() => current.data?.creatives ?? [], [current.data])
  const total = current.data?.total ?? 0
  const pages = Math.max(1, Math.ceil(total / PER_PAGE))
  const currency = current.data?.currency ?? null

  const { best, worst } = useMemo(() => {
    const measured = rows.filter((c) => value(c.metrics, sort) !== null && (sort !== 'ctr' || (c.metrics?.impressions ?? 0) >= CTR_FLOOR_IMPRESSIONS))
    const ordered = [...measured].sort((a, b) => (value(b.metrics, sort) ?? 0) - (value(a.metrics, sort) ?? 0))
    const top = ordered.slice(0, STRIP)
    // The weakest are read from what actually spent, and never repeat a creative already in the best row.
    const bottom = ordered.filter((c) => (c.metrics?.spend ?? 0) > 0 && !top.includes(c)).slice(-STRIP).reverse()

    return { best: top, worst: bottom }
  }, [rows, sort])

  const ids = [...best, ...worst].map((c) => c.id)
  const past = useQuery({
    queryKey: ['creative-library', 'insights-past', projectId, days, ids],
    enabled: ids.length > 0,
    queryFn: () => listCreatives({ from: isoDaysAgo(days + 89), to: isoDaysAgo(days), page: 1, per_page: Math.min(ids.length, PER_PAGE), creative_ids: ids }, null),
  })
  const pastById = new Map((past.data?.creatives ?? []).map((c) => [c.id, c.metrics]))

  const types = useQuery({
    queryKey: ['creative-format-comparison', 'insights', projectId, from, to],
    enabled: !!projectId,
    queryFn: () => formatComparison(projectId!, { from, to }),
    retry: false,
  })

  const tableRows: Row[] = rows.map((c) => {
    const row: Row = {
      name: (
        <button type="button" onClick={() => navigate(c.id)} className="flex min-w-0 items-center gap-3 text-start" data-testid={`insights-row-${c.id}`}>
          <Cover preview={c.preview} ar={ar} className="h-14 w-9 shrink-0 rounded-md" />
          <span className="line-clamp-2 font-semibold text-text-primary">{c.name}</span>
        </button>
      ),
      platform: <ProviderMark provider={c.provider} size={18} />,
      format: formatLabel(c.kind, t),
    }
    for (const key of TABLE_METRICS) row[key] = c.metrics?.[key] ?? null

    return row
  })

  const label = (key: (typeof TABLE_METRICS)[number]) => (key === 'ctr' ? t.sorts.ctr : key === 'roas' ? t.roas : key === 'cpa' ? t.cpa : metricLabel(key, locale))
  const select = 'rounded-xl border border-border bg-surface px-3 py-2 text-sm font-semibold text-text-primary'

  return (
    <div className="flex flex-col gap-5" data-testid="creative-insights">
      <div className="flex flex-wrap items-center justify-end gap-2">
        <label className="flex items-center gap-2 text-sm text-text-secondary">
          {t.sortBy}
          <select value={sort} onChange={(e) => setSort(e.target.value as SortKey)} className={select} data-testid="insights-sort">
            {SORTS.map((k) => <option key={k} value={k}>{t.sorts[k]}</option>)}
          </select>
        </label>
        <select value={kind} onChange={(e) => { setPage(1); setKind(e.target.value) }} className={select} data-testid="insights-kind">
          <option value="">{t.allFormats}</option>
          <option value="image">{t.image}</option>
          <option value="video">{t.video}</option>
        </select>
        <select value={days} onChange={(e) => { setPage(1); setDays(Number(e.target.value) as 7 | 30 | 90) }} className={select} data-testid="insights-period">
          <option value={7}>{t.last7}</option>
          <option value={30}>{t.last30}</option>
          <option value={90}>{t.last90}</option>
        </select>
      </div>

      {current.isLoading && <p className="py-10 text-center text-sm text-text-secondary">{t.loading}</p>}
      {!current.isLoading && (
        <>
          <Strip title={t.insightsTitle} testid="insights-best" rows={best} sort={sort} currency={currency} pastById={pastById} ar={ar} t={t} />
          <Strip title={t.worstTitle} testid="insights-worst" rows={worst} sort={sort} currency={currency} pastById={pastById} ar={ar} t={t} />
        </>
      )}

      <section className="rounded-2xl border border-border bg-surface p-5" data-testid="insights-types">
        <h2 className="mb-4 text-lg font-bold text-text-primary">{t.typesTitle}</h2>
        {!projectId && <p className="text-sm text-text-secondary">{t.pickProjectTypes}</p>}
        {projectId && types.data && <ContentTypesComparison data={types.data} ar={ar} title={false} />}
      </section>

      <section className="rounded-2xl border border-border bg-surface p-5" data-testid="insights-all">
        <h2 className="mb-4 text-lg font-bold text-text-primary">{t.allTitle}</h2>
        <DataMetricTable
          columns={[
            { key: 'name', label: t.cols.name, kind: 'text' },
            { key: 'platform', label: t.cols.platform, kind: 'text' },
            { key: 'format', label: t.cols.format, kind: 'text' },
            ...TABLE_METRICS.map((key) => ({ key, label: label(key), kind: metricKind(key), currency, digits: 2 })),
          ]}
          rows={tableRows}
        />
        {pages > 1 && (
          <div className="mt-3 flex items-center justify-between text-sm text-text-secondary">
            <span>{t.page} {formatNumber(page)} {t.of} {formatNumber(pages)}</span>
            <div className="flex gap-2">
              <button type="button" disabled={page <= 1} onClick={() => setPage((p) => p - 1)} className="rounded-lg border border-border px-3 py-1.5 font-semibold text-text-primary disabled:text-text-muted">{t.prev}</button>
              <button type="button" disabled={page >= pages} onClick={() => setPage((p) => p + 1)} className="rounded-lg border border-border px-3 py-1.5 font-semibold text-text-primary disabled:text-text-muted">{t.next}</button>
            </div>
          </div>
        )}
      </section>
    </div>
  )
}
