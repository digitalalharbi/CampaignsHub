import { useUi } from '@/stores/ui'
import { EmptyState, ErrorState, Skeleton } from '@/components/ui/States'
import { fmtDateTime } from '@/lib/datetime'
import { changeFieldLabel, changeValueLabel, changedFields } from './campaignChangeHistory'
import { useProjectCampaignActivity } from './metrics'

/**
 * CAMPAIGN-VIEWS-001 — the project's change history, as a view of the Campaigns surface.
 *
 * The per-campaign timeline answers «what happened to this one»; an operator opening the
 * Campaigns surface asks «what changed here» — who created, paused, re-budgeted or linked what, in
 * one list, newest first, each event naming its campaign and each audited change written the
 * product's way (before → after). Nothing is recomputed: these are the audit log's own rows.
 */
export function ProjectChangeHistory({ projectId }: { projectId: string }) {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const activity = useProjectCampaignActivity(projectId)
  const events = activity.data ?? []

  if (activity.isLoading) return <div className="space-y-2" data-testid="project-history-loading">{Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-12" />)}</div>
  if (activity.isError) return <ErrorState error={activity.error} title={ar ? 'تعذّر تحميل سجل التغييرات' : 'The change history could not be loaded'} onRetry={() => activity.refetch()} />
  if (events.length === 0) {
    return (
      <EmptyState
        title={ar ? 'لا تغييرات مسجَّلة بعد' : 'No changes recorded yet'}
        description={ar ? 'كل إنشاء أو تعديل أو إيقاف أو ربط لحملة في هذا المشروع يظهر هنا لحظة حدوثه.' : 'Every creation, edit, pause or link of a campaign in this project appears here the moment it happens.'}
      />
    )
  }

  return (
    <section data-testid="project-change-history" className="rounded-2xl border border-border bg-surface p-4">
      <h2 className="mb-3 text-base font-bold text-text-primary">{ar ? 'سجل التغييرات' : 'Change history'}</h2>
      <ol className="relative space-y-3 border-s border-border ps-4">
        {events.map((e) => (
          <li key={e.id} className="relative" data-testid={`project-history-${e.id}`}>
            <span className="absolute -start-[21px] top-1.5 h-2.5 w-2.5 rounded-full bg-brand-500 ring-2 ring-surface" aria-hidden />
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <span className="text-sm font-semibold text-text-primary">
                {e.label}
                {e.campaign_name && <span className="ms-2 text-text-secondary">— {e.campaign_name}</span>}
              </span>
              <span className="text-[11px] text-text-muted">{e.actor} · {e.at ? fmtDateTime(e.at) : ''}</span>
            </div>
            {changedFields(e.before, e.after).length > 0 && (
              <div className="mt-0.5 flex flex-wrap gap-x-3 text-[11px] text-text-muted">
                {changedFields(e.before, e.after).map((k) => (
                  <span key={k} className="tnum">
                    {changeFieldLabel(k, locale)}: {changeValueLabel(k, e.before?.[k], locale, e.budget_currency ?? undefined)} → {changeValueLabel(k, e.after?.[k], locale, e.budget_currency ?? undefined)}
                  </span>
                ))}
              </div>
            )}
          </li>
        ))}
      </ol>
    </section>
  )
}
