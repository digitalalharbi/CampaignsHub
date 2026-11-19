import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { AlertTriangle } from 'lucide-react'
import { fmtDateTime } from '@/lib/datetime'
import { platformLabel } from '@/lib/platforms'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { getFollowUp, type IntakeItem } from './api'
import { UI } from './copy'
import { StatusBadge } from './IntakePanel'

/*
 * CONTENT-INTAKE-001 — the operational view. Four queues from the pipeline, and live content the canonical fatigue assessment
 * flags as a VIEW signal (watch / fatigued). It raises nothing: alerts stay the alert engine's.
 */
export function FollowUpPanel() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = UI[ar ? 'ar' : 'en']
  const canView = useAuth((s) => s.hasPermission('content.view'))
  const [, setParams] = useSearchParams()
  const q = useQuery({ queryKey: ['content-follow-up'], queryFn: getFollowUp, enabled: canView })

  if (!canView) return <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">{t.noPermission}</p>
  const d = q.data

  const open = (id: string) => setParams({ tab: 'review', item: id })
  const queue = (key: string, title: string, items: IntakeItem[], showReason = false) => (
    <section key={key} className="rounded-2xl border border-border bg-surface p-3" data-testid={`follow-${key}`}>
      <h3 className="mb-2 flex items-baseline justify-between text-sm font-bold text-text-primary">
        {title}<span className="text-xs font-semibold text-text-muted" dir="ltr">{items.length}</span>
      </h3>
      {items.length === 0 ? <p className="text-xs text-text-muted">{t.follow.none}</p> : (
        <ul className="flex flex-col divide-y divide-border">
          {items.map((i) => (
            <li key={i.id}>
              <button type="button" onClick={() => open(i.id)} className="flex w-full flex-col gap-1 py-2 text-start hover:bg-surface-hover">
                <span className="flex items-center gap-2">
                  <span className="truncate text-sm font-semibold text-text-primary">{i.name}</span>
                  <StatusBadge status={i.status} ar={ar} />
                </span>
                <span className="text-xs text-text-muted">{[i.provider ? platformLabel(i.provider, ar) : null, i.uploader_name].filter(Boolean).join(' · ')}</span>
                {showReason && i.decision_reason && <span className="text-xs text-text-secondary">{i.decision_reason}</span>}
                <span className="text-xs text-text-muted" dir="ltr">{fmtDateTime(i.updated_at)}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </section>
  )

  return (
    <div className="flex flex-col gap-4" data-testid="follow-up">
      {q.isLoading || !d ? (
        <div className="grid grid-cols-1 gap-3 md:grid-cols-2">{[0, 1, 2, 3].map((i) => <div key={i} className="h-32 animate-pulse rounded-2xl bg-surface-secondary" />)}</div>
      ) : (
        <>
          <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
            {queue('review', t.follow.review, d.awaiting_review)}
            {queue('changes', t.follow.changes, d.needs_changes)}
            {queue('rejected', t.follow.rejected, d.rejected, true)}
            {queue('approved', t.follow.approved, d.approved_not_launched)}
          </div>
          <section className="rounded-2xl border border-border bg-surface p-3" data-testid="follow-fatigue">
            <h3 className="mb-1 flex items-baseline justify-between text-sm font-bold text-text-primary">
              {t.follow.fatigue}<span className="text-xs font-semibold text-text-muted" dir="ltr">{d.live_fatigue.length}</span>
            </h3>
            <p className="mb-2 text-xs text-text-muted">{t.follow.fatigueNote} <Link to="../alerts" className="font-semibold text-brand-600 underline">{ar ? 'التنبيهات' : 'Alerts'}</Link></p>
            {d.live_fatigue.length === 0 ? <p className="text-xs text-text-muted">{t.follow.none}</p> : (
              <ul className="flex flex-col divide-y divide-border">
                {d.live_fatigue.map(({ item, fatigue }) => (
                  <li key={item.id} className="flex items-start gap-2 py-2">
                    <AlertTriangle className={`mt-0.5 h-4 w-4 shrink-0 ${fatigue.status === 'fatigued' ? 'text-danger' : 'text-warning'}`} aria-hidden />
                    <button type="button" onClick={() => open(item.id)} className="text-start">
                      <span className="block text-sm font-semibold text-text-primary">{item.name}</span>
                      <span className="block text-xs text-text-secondary">{ar ? (fatigue.reason_ar ?? fatigue.note_ar) : (fatigue.reason_en ?? fatigue.note_en)}</span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </>
      )}
    </div>
  )
}
