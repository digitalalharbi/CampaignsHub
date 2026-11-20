import { useMemo, useState } from 'react'
import { ArrowDownWideNarrow, ArrowUpWideNarrow, Info, Sparkles, Trophy } from 'lucide-react'
import { ProviderMark } from '@/components/ProviderMark'
import { Modal } from '@/components/ui/Modal'
import { Num } from '@/components/ui/Num'
import { Skeleton } from '@/components/ui/States'
import { providerLabel } from '@/features/campaigns/labels'
import { ChartCard, ConversionFunnelChart, PlatformDonutChart } from '@/features/analytics/charts'
import { TrendPill, platformColor } from '@/features/analytics/components'
import { compact, num } from '@/features/analytics/format'
import { useCampaigns, type Range } from '@/features/analytics/api'
import { ContentTile } from '@/features/reports/live/LiveContent'
import { cardHasFigure, metricLabel } from '@/features/reports/live/liveMetrics'
import type { ReportAd } from '@/features/reports/ReportAdsSection'
import {
  basisLine, contentLists, funnelHasShape, funnelStages, platformCards, rankPlatforms, resultsSplit, splitTitle,
  type RankRow, type RankSort,
} from '@/features/reportLogic'
import { PlatformCardsView } from '@/features/reportLogic/PlatformCardsView'
import { useBoardReport, type BoardReport } from './useBoardReport'

/**
 * ANALYTICS-REPORT-LOGIC-001 — the two reference reports' sections, as charts on the board.
 *
 * Every figure comes from `features/reportLogic` over the report's own payload; nothing here derives a number. No table and no
 * paragraph: bars, cards, a donut and a funnel, each clickable, with the detail one tap away in a popup. A click on a platform
 * or a campaign narrows the whole page through the shared URL filters (`useBoardReport`).
 */
const selectedPlatform = (r: BoardReport) => (r.providers.length === 1 ? r.providers[0] : null)

function Loading({ testId }: { testId: string }) {
  return <div data-testid={testId} className="grid grid-cols-2 gap-2 lg:grid-cols-4"><Skeleton className="h-24" /><Skeleton className="h-24" /><Skeleton className="h-24" /><Skeleton className="h-24" /></div>
}

/* ───────────────────────────── Summary: platform cards + sources donut ───────────────────────────── */

export function BoardPlatformSummary({ projectId, range }: { projectId: string; range: Range }) {
  const r = useBoardReport(projectId, range)
  if (r.pending) return <Loading testId="board-platform-summary-loading" />
  if (!r.all || !r.allSource) return null
  const models = platformCards(r.all, r.allSource, r.ar)
  if (models.length === 0) return null
  const split = resultsSplit(r.all, r.allSource, r.ar)
  const selected = selectedPlatform(r)

  return (
    <div data-testid="board-platform-summary" className="flex flex-col gap-4">
      <PlatformCardsView models={models} resultName={r.allSource.name} ar={r.ar} selected={selected} onSelect={r.pickPlatform} testPrefix="board" />
      {split.rows.length > 1 && (
        <ChartCard title={splitTitle(r.allSource.key === 'clicks', r.allSource.name, r.ar)}>
          <div data-testid="board-results-split" className="grid grid-cols-1 items-center gap-3 sm:grid-cols-2">
            <PlatformDonutChart
              data={split.rows.map((x) => ({ name: x.name, value: x.value, key: x.key }))}
              height={190}
              legend={false}
              valueKind="num"
              centerValue={num(split.total)}
              centerLabel={r.allSource.name}
            />
            <ul className="flex flex-col gap-1.5">
              {split.rows.map((x) => (
                <li key={x.key}>
                  <button
                    type="button"
                    data-testid={`board-split-${x.key}`}
                    aria-pressed={selected === x.key}
                    onClick={() => r.pickPlatform(x.key)}
                    className={`flex w-full items-center justify-between gap-2 rounded-lg px-2 py-1 text-xs ${selected === x.key ? 'bg-brand-500/10' : 'hover:bg-surface-hover'}`}
                  >
                    <span className="flex min-w-0 items-center gap-1.5 text-text-secondary">
                      <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: platformColor(x.key) }} />
                      <ProviderMark provider={x.key} size={16} />
                      <span className="truncate">{x.name}</span>
                    </span>
                    <span className="tnum font-bold text-text-primary"><Num>{`${Math.round(x.share * 100)}%`}</Num></span>
                  </button>
                </li>
              ))}
            </ul>
          </div>
        </ChartCard>
      )}
    </div>
  )
}

/* ───────────────────────────── Platforms: sortable ranking + funnel ───────────────────────────── */

const SORTS: Array<{ key: RankSort; ar: string; en: string }> = [
  { key: 'result', ar: 'النتيجة', en: 'Result' },
  { key: 'impressions', ar: 'الظهور', en: 'Impressions' },
  { key: 'clicks', ar: 'النقرات', en: 'Clicks' },
  { key: 'ctr', ar: 'معدل النقر', en: 'CTR' },
]

export function BoardPlatformRanking({ projectId, range }: { projectId: string; range: Range }) {
  const r = useBoardReport(projectId, range)
  const [sort, setSort] = useState<RankSort>('result')
  const [dir, setDir] = useState<'asc' | 'desc'>('desc')
  const [detail, setDetail] = useState<RankRow | null>(null)
  if (r.pending) return <Loading testId="board-platform-ranking-loading" />
  if (!r.all || !r.allSource) return null
  const rows = rankPlatforms(r.all, r.allSource, r.ar, sort, dir)
  const selected = selectedPlatform(r)
  const stages = funnelStages(r.all)

  return (
    <div data-testid="board-platform-ranking" className="grid grid-cols-1 gap-4 lg:grid-cols-12">
      <div className="lg:col-span-7">
        <ChartCard title={r.ar ? 'مقارنة نتائج المنصات' : 'Results by platform'}>
          {rows.length === 0 ? (
            <p className="text-sm text-text-muted">{r.ar ? 'لا نتائج مسجّلة لأي منصة في هذه الفترة' : 'No platform recorded results in this period'}</p>
          ) : (
            <>
              <div role="group" aria-label={r.ar ? 'الترتيب حسب' : 'Sort by'} data-testid="board-rank-sorts" className="mb-3 flex flex-wrap items-center gap-1.5">
                {SORTS.map((s) => (
                  <button
                    key={s.key}
                    type="button"
                    data-testid={`board-rank-sort-${s.key}`}
                    aria-pressed={sort === s.key}
                    onClick={() => (sort === s.key ? setDir(dir === 'desc' ? 'asc' : 'desc') : (setSort(s.key), setDir('desc')))}
                    className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-semibold ${sort === s.key ? 'border-brand-500 bg-brand-500/10 text-text-primary' : 'border-border text-text-muted'}`}
                  >
                    {r.ar ? s.ar : s.en}
                    {sort === s.key && (dir === 'desc' ? <ArrowDownWideNarrow size={12} aria-hidden /> : <ArrowUpWideNarrow size={12} aria-hidden />)}
                  </button>
                ))}
              </div>
              <ol data-testid="board-rank-list" data-sort={sort} data-dir={dir} className="flex flex-col gap-2">
                {rows.map((m) => (
                  <li key={m.key} className="flex items-center gap-2">
                    <button
                      type="button"
                      data-testid={`board-rank-${m.key}`}
                      aria-pressed={selected === m.key}
                      onClick={() => r.pickPlatform(m.key)}
                      className={`flex min-w-0 flex-1 items-center gap-2 rounded-xl px-2 py-1.5 text-start ${selected === m.key ? 'bg-brand-500/10' : 'hover:bg-surface-hover'}`}
                    >
                      <ProviderMark provider={m.key} size={18} />
                      <span className="w-20 shrink-0 truncate text-xs font-semibold text-text-primary">{m.name}</span>
                      <span className="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-surface-secondary">
                        <span className="block h-full rounded-full" style={{ width: `${Math.round(m.bar * 100)}%`, background: platformColor(m.key) }} />
                      </span>
                      <span className="tnum w-16 shrink-0 text-end text-sm font-bold text-text-primary"><Num>{num(m.result)}</Num></span>
                      {m.movement !== null && <TrendPill delta={m.movement} calm />}
                    </button>
                    <button type="button" data-testid={`board-rank-detail-${m.key}`} aria-label={r.ar ? `تفاصيل ${m.name}` : `Details of ${m.name}`} onClick={() => setDetail(m)} className="rounded-lg p-1.5 text-text-muted hover:bg-surface-hover hover:text-text-primary">
                      <Info size={15} aria-hidden />
                    </button>
                  </li>
                ))}
              </ol>
            </>
          )}
        </ChartCard>
      </div>
      {stages.length >= 2 && funnelHasShape(r.all) && (
        <div className="lg:col-span-5" data-testid="board-funnel">
          <ChartCard title={r.ar ? 'قمع المنصات: من الظهور إلى النتيجة' : 'Platforms funnel: impression to result'}>
            <ConversionFunnelChart stages={stages} ar={r.ar} />
          </ChartCard>
        </div>
      )}
      <PlatformDetail r={r} row={detail} onClose={() => setDetail(null)} />
    </div>
  )
}

/** The platform's figures in a popup — read through the report's own metric catalogue, so the text is the report's text. */
function PlatformDetail({ r, row, onClose }: { r: BoardReport; row: RankRow | null; onClose: () => void }) {
  const provider = row === null ? null : (r.all?.platforms ?? []).find((p) => String(p.provider) === row.key || providerLabel(String(p.provider), r.ar ? 'ar' : 'en') === row.name)
  const keys = ['spend', 'impressions', 'clicks', 'ctr', 'cpc', 'conversions', 'cpa', 'revenue', 'roas']
  const cells = row === null || !provider || !r.all ? [] : keys.flatMap((key) => {
    const meta = r.reader.meta[key]
    if (!meta) return []
    const read = meta.format(provider as Record<string, number | null>, r.all!, r.reader.asMoney, r.reader.count)

    return cardHasFigure(key, read.text, r.all!) ? [{ key, label: metricLabel(meta, key, r.all!, r.ar), value: read.text }] : []
  })

  return (
    <Modal open={row !== null} onClose={onClose} size="md" title={row?.name}>
      <dl data-testid="board-platform-detail" className="grid grid-cols-2 gap-2 sm:grid-cols-3">
        {cells.map((c) => (
          <div key={c.key} className="min-w-0 rounded-xl border border-border bg-surface px-3 py-2" data-testid={`board-platform-detail-${c.key}`}>
            <dt className="truncate text-xs text-text-muted">{c.label}</dt>
            <dd className="tnum truncate text-base font-extrabold text-text-primary"><Num>{c.value}</Num></dd>
          </div>
        ))}
      </dl>
    </Modal>
  )
}

/* ───────────────────────────── Content: best + room to improve ───────────────────────────── */

export function BoardContentReport({ projectId, range }: { projectId: string; range: Range }) {
  const r = useBoardReport(projectId, range)
  const [open, setOpen] = useState<ReportAd | null>(null)
  const payload = r.view
  const best = useMemo(() => (payload ? contentLists(payload, 'best', null) : []), [payload])
  const improve = useMemo(() => (payload ? contentLists(payload, 'improve', null) : []), [payload])
  if (r.pending && !payload) return <Loading testId="board-content-loading" />
  if (!payload || (best.length === 0 && improve.length === 0)) return null
  const locale = r.ar ? 'ar' : 'en'
  const basis = basisLine(payload.ads_basis, r.ar)

  const block = (kind: 'best' | 'improve', items: ReportAd[]) => items.length === 0 ? null : (
    <ChartCard
      title={kind === 'best' ? (r.ar ? 'أفضل المحتوى أداءً' : 'Best performing content') : (r.ar ? 'محتوى بفرصة للتحسين' : 'Content with room to improve')}
      action={kind === 'best' ? <Trophy size={15} className="text-warning" aria-hidden /> : <Sparkles size={15} className="text-info" aria-hidden />}
    >
      <div data-testid={`board-content-${kind}`} className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {items.map((ad, i) => (
          <ContentTile key={ad.content_key ?? `${ad.provider}-${i}`} content={ad} locale={locale} currency={r.currency || null} onOpen={setOpen} rank={kind === 'best' ? i + 1 : undefined} resultsOnly rankedBy={kind === 'best' ? payload.ads_basis : undefined} />
        ))}
      </div>
    </ChartCard>
  )

  return (
    <div data-testid="board-content-report" className="flex flex-col gap-4">
      {basis && <span data-testid="board-content-basis" className="w-fit rounded-full bg-surface-secondary px-3 py-1 text-xs font-semibold text-text-secondary">{basis}</span>}
      {block('best', best)}
      {block('improve', improve)}
      <Modal open={open !== null} onClose={() => setOpen(null)} size="sm" title={open?.name ?? undefined}>
        {open && (
          <div data-testid="board-content-detail" className="max-w-xs">
            <ContentTile content={open} locale={locale} currency={r.currency || null} resultsOnly rankedBy={payload.ads_basis} />
            <p className="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-text-muted">
              {open.provider && <ProviderMark provider={open.provider} size={14} />}
              {open.provider && providerLabel(open.provider, locale)}
            </p>
          </div>
        )}
      </Modal>
    </div>
  )
}

/* ───────────────────────────── Campaigns: a click filters the page ───────────────────────────── */

export function BoardCampaignRanking({ projectId, range }: { projectId: string; range: Range }) {
  const r = useBoardReport(projectId, range)
  const q = useCampaigns(projectId, range, {})
  const all = q.data ?? []
  // Ranked on what the campaigns produced — results where any campaign has them, delivery (clicks) otherwise. Counts only: a campaign's money is the money contract's.
  const key: 'conversions' | 'clicks' = all.some((c) => Number(c.conversions ?? 0) > 0) ? 'conversions' : 'clicks'
  const rows = all.filter((c) => Number(c[key] ?? 0) > 0).sort((a, b) => Number(b[key] ?? 0) - Number(a[key] ?? 0)).slice(0, 8)
  if (q.isPending) return <Loading testId="board-campaign-ranking-loading" />
  if (rows.length === 0) return null
  const top = Math.max(1, ...rows.map((c) => Number(c[key] ?? 0)))
  const picked = r.campaigns.length === 1 ? r.campaigns[0] : null

  return (
    <ChartCard title={r.ar ? (key === 'conversions' ? 'أكثر الحملات نتائج' : 'أكثر الحملات نقرات') : (key === 'conversions' ? 'Campaigns by results' : 'Campaigns by clicks')}>
      <ol data-testid="board-campaign-ranking" className="flex flex-col gap-1.5">
        {rows.map((c) => (
          <li key={c.campaign_id}>
            <button
              type="button"
              data-testid={`board-campaign-${c.campaign_id}`}
              aria-pressed={picked === c.campaign_id}
              onClick={() => r.pickCampaign(c.campaign_id)}
              className={`flex w-full items-center gap-2 rounded-xl px-2 py-1.5 text-start ${picked === c.campaign_id ? 'bg-brand-500/10' : 'hover:bg-surface-hover'}`}
            >
              <ProviderMark provider={c.provider} size={16} />
              <span dir="auto" className="w-32 shrink-0 truncate text-xs font-semibold text-text-primary" title={c.campaign_name ?? undefined}>{c.campaign_name ?? '—'}</span>
              <span className="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-surface-secondary">
                <span className="block h-full rounded-full" style={{ width: `${Math.round((Number(c[key] ?? 0) / top) * 100)}%`, background: platformColor(c.provider) }} />
              </span>
              <span className="tnum w-16 shrink-0 text-end text-xs font-bold text-text-primary"><Num>{compact(Number(c[key] ?? 0))}</Num></span>
            </button>
          </li>
        ))}
      </ol>
    </ChartCard>
  )
}
