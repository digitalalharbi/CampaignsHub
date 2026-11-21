import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Num } from '@/components/ui/Num'
import { AdPoster } from '@/features/content/AdPoster'
import { ContentTypesComparison, metricText } from '@/features/content/ContentTypesComparison'
import { formatComparison, listCreatives, type CreativeCard, type FormatComparisonRow } from '@/features/content/api'
import { useUi } from '@/stores/ui'
import { Panel } from './components'

/**
 * CONTENT-FORMAT-COMPARISON-001 — «مقارنة أنواع المحتوى» on the Analytics content tab.
 *
 * The comparison is the server's (`/creatives/format-comparison`), narrowed by the same filter bar the content table
 * above it obeys. The drill shows one page of a format's creatives at a time: the FIGURES are the comparison's own
 * rows (so the drill cannot disagree with the table it opened from) and the thumbnails come from the library's
 * existing preview envelope through `AdPoster` — this panel never draws a cover of its own.
 */
const DRILL_PAGE = 6

export function FormatComparisonPanel({
  projectId, range, providers, campaigns, onOpen,
}: {
  projectId: string | null
  range: { from: string; to: string }
  providers?: string[]
  campaigns?: string[]
  onOpen?: (card: CreativeCard) => void
}) {
  const ar = useUi((u) => u.locale) === 'ar'
  const q = useQuery({
    queryKey: ['analytics', 'format-comparison', projectId, range.from, range.to, providers?.join(',') ?? '', campaigns?.join(',') ?? ''],
    queryFn: () => formatComparison(projectId as string, { from: range.from, to: range.to, providers, campaign_ids: campaigns }),
    enabled: Boolean(projectId),
  })

  return (
    <Panel
      title={ar ? 'مقارنة أنواع المحتوى' : 'Content types'}
      description={ar ? 'صورة، فيديو، كاروسيل… لكل هدف' : 'Image, video, carousel… per goal'}
      loading={q.isLoading}
      error={q.isError}
      empty={!q.isLoading && !q.isError && (Array.isArray(q.data?.objectives) ? q.data.objectives.length : 0) === 0}
    >
      {q.data && Array.isArray(q.data.objectives) && q.data.objectives.length > 0 && (
        <div data-testid="analytics-format-comparison">
          <ContentTypesComparison
            data={q.data}
            ar={ar}
            title={false}
            renderDrill={(row) => <FormatDrillCards key={row.format} projectId={projectId} range={range} row={row} currency={q.data?.currency ?? null} onOpen={onOpen} />}
          />
        </div>
      )}
    </Panel>
  )
}

function FormatDrillCards({ projectId, range, row, currency, onOpen }: { projectId: string | null; range: { from: string; to: string }; row: FormatComparisonRow; currency: string | null; onOpen?: (card: CreativeCard) => void }) {
  const ar = useUi((u) => u.locale) === 'ar'
  const [page, setPage] = useState(0)
  const pages = Math.max(1, Math.ceil(row.creative_list.length / DRILL_PAGE))
  const slice = row.creative_list.slice(page * DRILL_PAGE, page * DRILL_PAGE + DRILL_PAGE)
  const ids = slice.map((c) => c.id)
  const cards = useQuery({
    queryKey: ['analytics', 'format-drill', projectId, range.from, range.to, ids.join(',')],
    queryFn: () => listCreatives({ from: range.from, to: range.to, per_page: DRILL_PAGE, creative_ids: ids }, projectId),
    enabled: Boolean(projectId) && ids.length > 0,
  })
  const byId = new Map((cards.data?.creatives ?? []).map((c) => [c.id, c]))

  return (
    <div className="flex flex-col gap-3">
      <ul className="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3" data-testid="format-drill-cards">
        {slice.map((c) => {
          const card = byId.get(c.id)

          return (
            <li key={c.id} className="min-w-0">
              <button
                type="button"
                disabled={!card || !onOpen}
                onClick={() => card && onOpen?.(card)}
                data-testid={`format-drill-card-${c.id}`}
                className="flex w-full min-w-0 items-center gap-3 rounded-xl border border-border p-2 text-start hover:bg-surface-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-600"
              >
                <AdPoster preview={card?.preview} name={c.name} className="h-14 w-14 shrink-0 rounded-lg" fit="cover" />
                <span className="flex min-w-0 flex-col gap-0.5">
                  <span dir="auto" className="truncate text-sm font-semibold text-text-primary">{c.name}</span>
                  <span className="flex flex-wrap gap-x-3 text-xs text-text-secondary">
                    <span>{ar ? 'نتائج ' : 'Results '}<Num>{c.results_not_attributable ? '—' : metricText('results', c.results, currency)}</Num></span>
                    <span>{ar ? 'تكلفة ' : 'Cost '}<Num>{metricText('cost_per_result', c.cost_per_result, currency)}</Num></span>
                    <span>{ar ? 'إنفاق ' : 'Spend '}<Num>{metricText('spend', c.spend, currency)}</Num></span>
                  </span>
                </span>
              </button>
            </li>
          )
        })}
      </ul>
      {pages > 1 && (
        <div className="flex items-center justify-end gap-2 text-xs text-text-secondary">
          <button type="button" disabled={page === 0} onClick={() => setPage((p) => p - 1)} className="rounded-lg border border-border px-2 py-0.5 disabled:opacity-40" data-testid="format-drill-prev">{ar ? 'السابق' : 'Prev'}</button>
          <Num>{`${page + 1} / ${pages}`}</Num>
          <button type="button" disabled={page >= pages - 1} onClick={() => setPage((p) => p + 1)} className="rounded-lg border border-border px-2 py-0.5 disabled:opacity-40" data-testid="format-drill-next">{ar ? 'التالي' : 'Next'}</button>
        </div>
      )}
    </div>
  )
}
