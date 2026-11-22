import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { ArrowDown, ArrowUp } from 'lucide-react'
import { ProviderMark } from '@/components/ProviderMark'
import { Num } from '@/components/ui/Num'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'
import { formatNumber } from '@/lib/numerals'
import { money } from '@/features/analytics/format'
import { listCreatives, type CreativeCard, type CreativeMetrics } from '../api'
import { Cover } from './Cover'
import { LIB, type LibCopy } from './copy'
import { formatLabel, isoDaysAgo } from './util'

type SortKey = 'ctr' | 'spend' | 'conversions' | 'revenue' | 'impressions'
const SORTS: SortKey[] = ['ctr', 'spend', 'conversions', 'revenue', 'impressions']
/** A click rate over a handful of impressions is noise; below this a creative is not ranked by it. */
const CTR_FLOOR_IMPRESSIONS = 1000

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

/**
 * CONTENT-LIBRARY-SNAP-001 — «رؤى المحتوى»: the platforms' «top performing creatives» strip. One sort, one format, one period;
 * each card is the creative in its own 9:16 frame with the chosen figure and how it moved against the 90 days before. A figure the
 * server did not report reads «غير متاح», never zero, and the change is drawn only where both windows carry one.
 */
export function CreativeInsights() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = LIB[ar ? 'ar' : 'en']
  const navigate = useNavigate()
  const projectId = useProject((s) => s.currentProjectId)
  const [sort, setSort] = useState<SortKey>('ctr')
  const [kind, setKind] = useState<string>('')
  const [days, setDays] = useState<7 | 30>(7)
  const from = isoDaysAgo(days - 1)
  const to = isoDaysAgo(0)

  // The server ranks what it can; a rate it cannot rank by is read over the 48 that spent most and ordered here.
  const serverSort = sort === 'ctr' ? 'spend' : sort
  const current = useQuery({
    queryKey: ['creative-library', 'insights', projectId, sort, kind, days],
    queryFn: () => listCreatives({ from, to, page: 1, per_page: 48, sort: serverSort, project_ids: projectId ? [projectId] : undefined, kinds: kind ? [kind] : undefined }, null),
  })

  const ranked = useMemo<CreativeCard[]>(() => {
    const rows = (current.data?.creatives ?? []).filter((c) => value(c.metrics, sort) !== null)
    const eligible = sort === 'ctr' ? rows.filter((c) => (c.metrics?.impressions ?? 0) >= CTR_FLOOR_IMPRESSIONS) : rows

    return [...eligible].sort((a, b) => (value(b.metrics, sort) ?? 0) - (value(a.metrics, sort) ?? 0)).slice(0, 12)
  }, [current.data, sort])

  const ids = ranked.map((c) => c.id)
  const past = useQuery({
    queryKey: ['creative-library', 'insights-past', projectId, ids],
    enabled: ids.length > 0,
    queryFn: () => listCreatives({ from: isoDaysAgo(days + 89), to: isoDaysAgo(days), page: 1, per_page: ids.length, creative_ids: ids }, null),
  })
  const pastById = new Map((past.data?.creatives ?? []).map((c) => [c.id, c.metrics]))
  const currency = current.data?.currency ?? null

  const select = 'rounded-xl border border-border bg-surface px-3 py-2 text-sm font-semibold text-text-primary'

  return (
    <section className="rounded-2xl border border-border bg-surface p-5" data-testid="creative-insights">
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-bold text-text-primary">{t.insightsTitle}</h2>
        <div className="flex flex-wrap items-center gap-2">
          <label className="flex items-center gap-2 text-sm text-text-secondary">
            {t.sortBy}
            <select value={sort} onChange={(e) => setSort(e.target.value as SortKey)} className={select} data-testid="insights-sort">
              {SORTS.map((k) => <option key={k} value={k}>{t.sorts[k]}</option>)}
            </select>
          </label>
          <select value={kind} onChange={(e) => setKind(e.target.value)} className={select} data-testid="insights-kind">
            <option value="">{t.allFormats}</option>
            <option value="image">{t.image}</option>
            <option value="video">{t.video}</option>
          </select>
          <select value={days} onChange={(e) => setDays(Number(e.target.value) as 7 | 30)} className={select} data-testid="insights-period">
            <option value={7}>{t.last7}</option>
            <option value={30}>{t.last30}</option>
          </select>
        </div>
      </div>

      {current.isLoading && <p className="py-10 text-center text-sm text-text-secondary">{t.loading}</p>}
      {!current.isLoading && ranked.length === 0 && <p className="py-10 text-center text-sm text-text-secondary" data-testid="insights-empty">{t.empty}</p>}

      <div className="flex gap-4 overflow-x-auto pb-2" data-testid="insights-strip">
        {ranked.map((c) => {
          const now = value(c.metrics, sort)
          const before = value(pastById.get(c.id) ?? null, sort)
          const delta = now !== null && before !== null && before !== 0 ? ((now - before) / Math.abs(before)) * 100 : null

          return (
            <button key={c.id} type="button" onClick={() => navigate(c.id)} data-testid={`insights-card-${c.id}`} className="w-44 shrink-0 text-start">
              <div className="relative overflow-hidden rounded-2xl border border-border">
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
