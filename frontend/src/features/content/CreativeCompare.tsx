import { TransposedMetricTable } from '@/components/ui/MetricTable'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, X } from 'lucide-react'
import { compareCreatives, type CreativeCard } from './api'
import { formatMetric, metricKind, metricLabel, metricState } from './metrics'
import { imageLoading } from './format'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'
import { objectiveLabel, providerLabel } from '@/features/campaigns/labels'

/**
 * §15.7 — creatives side by side, with a winner per metric and never an overall one.
 *
 * ## Why there is no «best creative» here
 *
 * Ranking an awareness video against a sales image produces a single number that looks authoritative
 * and means nothing: they were bought to do different jobs, and whichever metric the ranking happened
 * to weight decides the answer. §15.7 forbids the overall verdict outright — so this component has no
 * way to render one, rather than a rule saying it should not. What it does show is per-metric
 * winners, which stay meaningful across objectives («this one had the better CTR» is a fact), plus
 * the explicit reason the two are not otherwise comparable.
 *
 * A comparison whose creatives share an objective simply has no warning, and every winner stands.
 */

const COPY = {
  ar: {
    title: 'مقارنة الإعلانات',
    close: 'إغلاق',
    metric: 'المؤشر',
    winner: 'الأفضل في هذا المؤشر',
    notComparable: 'لا تصلح المقارنة العامة',
    error: 'تعذّرت المقارنة.',
    objective: 'الهدف',
    platform: 'المنصة',
    noWinner: 'لا يمكن الترجيح',
  },
  en: {
    title: 'Compare ads',
    close: 'Close',
    metric: 'Metric',
    winner: 'Best on this metric',
    notComparable: 'No overall winner',
    error: 'The comparison could not be built.',
    objective: 'Objective',
    platform: 'Platform',
    noWinner: 'Cannot be ranked',
  },
}

export function CreativeCompare({
  creativeIds,
  creatives,
  window,
  onClose,
}: {
  creativeIds: string[]
  /** The already-loaded cards, so the previews render before the comparison call returns. */
  creatives: CreativeCard[]
  window: { from: string; to: string }
  onClose: () => void
}) {
  const { locale } = useUi()
  const ar = locale === 'ar'
  const t = COPY[ar ? 'ar' : 'en']

  const comparison = useQuery({
    queryKey: ['creative-compare', creativeIds, window],
    queryFn: () => compareCreatives(creativeIds, window),
    enabled: creativeIds.length >= 2,
  })

  const rows = comparison.data?.creatives ?? creatives
  const winners = comparison.data?.winners ?? {}
  const reason = ar ? comparison.data?.reason_ar : comparison.data?.reason_en

  /*
   * The union of every creative's OWN headline metrics.
   *
   * A fixed metric list would ask an awareness video for a cost per order, print «No data» beside it,
   * and leave a reader thinking the video failed at something it was never bought to do.
   */
  const metricKeys = Array.from(new Set(rows.flatMap((c) => c.headline_metrics)))

  return (
    <div role="dialog" aria-modal="true" aria-label={t.title} className="fixed inset-0 z-50 overflow-auto bg-surface p-4">
      <header className="mb-4 flex items-center justify-between gap-3">
        <h2 className="text-lg font-semibold text-text-primary">{t.title}</h2>
        <button type="button" onClick={onClose} aria-label={t.close} className="rounded p-2 hover:bg-surface-hover">
          <X className="h-5 w-5" aria-hidden />
        </button>
      </header>

      {comparison.isError && (
        <ErrorState title={t.error} error={comparison.error} ar={ar} onRetry={() => void comparison.refetch()} />
      )}

      {comparison.isPending && <Skeleton className="h-64" />}

      {comparison.data && comparison.data.comparable === false && reason && (
        <p className="mb-4 flex items-start gap-2 rounded-md border border-warning/40 bg-warning/10 p-3 text-sm text-text-primary">
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-warning" aria-hidden />
          <span>
            <strong className="font-semibold">{t.notComparable}</strong> — {reason}
          </span>
        </p>
      )}

      {rows.length > 0 && (
        <TransposedMetricTable
          columns={rows.map((creative) => ({
            key: creative.id,
            header: (
              <div className="space-y-1">
                {creative.preview.thumbnail_url ?? creative.preview.image_url ? (
                  <img
                    src={(creative.preview.thumbnail_url ?? creative.preview.image_url) as string}
                    alt={creative.name}
                    loading={imageLoading(creative.preview.thumbnail_url ?? creative.preview.image_url)}
                    className="h-20 w-full rounded object-cover"
                  />
                ) : (
                  <div className="flex h-20 items-center justify-center rounded bg-surface-hover text-[11px] text-text-secondary">
                    {ar ? creative.preview.note_ar : creative.preview.note_en}
                  </div>
                )}
                <p className="text-xs font-medium text-text-primary">{creative.name}</p>
                <p className="text-[11px] text-text-secondary">
                  {providerLabel(creative.provider, locale)}
                  {creative.objective ? ` · ${objectiveLabel(creative.objective, locale)}` : ''}
                </p>
              </div>
            ),
          }))}
          rows={metricKeys.map((key) => ({
            key,
            label: metricLabel(key, locale),
            kind: metricKind(key),
            currency: comparison.data?.currency ?? null,
            digits: 2,
            /*
             * A state that is not a figure stays a SENTENCE and reaches the primitive as a node:
             * «not provided» and «no data» are two different facts, and the one dash says neither.
             */
            values: rows.map((creative) => {
              const state = metricState(creative.metrics, key)

              return state.kind === 'value'
                ? state.value
                : <span>{formatMetric(state, key, locale, comparison.data?.currency ?? null)}</span>
            }),
            notes: rows.map((creative) =>
              winners[key] === creative.id ? <span className="sr-only">{t.winner}</span> : null,
            ),
            emphasis: rows.map((creative) => winners[key] === creative.id),
          }))}
        />
      )}
    </div>
  )
}
