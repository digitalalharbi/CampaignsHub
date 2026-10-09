import { Activity, Clock, RefreshCw, Webhook } from 'lucide-react'
import { MetricTable } from '@/components/ui/MetricTable'
import { SyncStatusPill } from '@/components/ui/SyncStatusPill'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { fmtDateTime } from '@/lib/datetime'
import { platformLabel } from '@/lib/platforms'
import { useUi } from '@/stores/ui'
import { useLiveView, type LiveSource, type SchedulerRow } from './liveView'

/**
 * LIVE-OPERATING-VIEW-001 — the consolidated live operating view.
 *
 * One row per source the project reads from: the latest SUCCESSFUL sync (not the latest attempt), the
 * latest timestamp the source itself vouched for, the next sync, and the state the freshness engine
 * gives it — then how the figures move: the scheduled sweep, its incremental window, the manual
 * refresh, and whether the provider publishes webhooks at all.
 *
 * The first thing on the screen is the sentence the Owner required: this is live as of the latest
 * successful sync, and provider-delayed data is never called real-time. The server sends that
 * sentence; the page shows it before any figure.
 */

/** A cron expression, said in words where the product's own schedules fall into a known shape. */
export function cadence(expression: string | null | undefined, ar: boolean): string {
  if (!expression) return ar ? 'جدول غير معروف' : 'Unknown schedule'
  const everyMinutes = /^\*\/(\d+) \* \* \* \*$/.exec(expression)
  if (everyMinutes) return ar ? `كل ${everyMinutes[1]} دقيقة` : `Every ${everyMinutes[1]} minutes`
  if (/^\d+ \* \* \* \*$/.test(expression)) return ar ? 'كل ساعة' : 'Hourly'
  const everyHours = /^\d+ \*\/(\d+) \* \* \*$/.exec(expression)
  if (everyHours) return ar ? `كل ${everyHours[1]} ساعات` : `Every ${everyHours[1]} hours`
  if (/^\d+ \d+ \* \* \*$/.test(expression)) return ar ? 'يومياً' : 'Daily'
  return expression
}

const WEBHOOKS: Record<LiveSource['mechanisms']['webhooks'], { ar: string; en: string }> = {
  supported: { ar: 'Webhooks مدعومة', en: 'Webhooks supported' },
  polling_only: { ar: 'استطلاع فقط — لا Webhooks', en: 'Polling only — no webhooks' },
  requires_confirmation: { ar: 'Webhooks تتطلب تأكيداً', en: 'Webhooks need confirmation' },
}

const OUTCOME: Record<string, { ar: string; en: string }> = {
  success: { ar: 'نجح', en: 'Succeeded' },
  failed: { ar: 'فشل', en: 'Failed' },
  skipped: { ar: 'تخطّى', en: 'Skipped' },
}

function when(iso: string | null | undefined, ar: boolean): string {
  return iso ? fmtDateTime(iso) : (ar ? 'لم يحدث بعد' : 'Not yet')
}

function sourceName(s: LiveSource, ar: boolean): string {
  const platform = platformLabel(s.provider, ar)
  return s.kind === 'store' && s.name ? `${s.name} · ${platform}` : platform
}

function mechanisms(s: LiveSource, ar: boolean): string {
  const parts: string[] = []
  if (s.mechanisms.scheduled) parts.push(cadence(s.mechanisms.scheduled.expression, ar))
  if ('window_days' in s.mechanisms.incremental) {
    parts.push(ar ? `تزايدي: آخر ${s.mechanisms.incremental.window_days} أيام` : `Incremental: last ${s.mechanisms.incremental.window_days} days`)
  } else {
    parts.push(ar ? 'تزايدي بمؤشّر المتجر' : 'Incremental by store cursor')
  }
  if (s.mechanisms.manual) parts.push(ar ? 'يدوي متاح' : 'Manual available')
  parts.push(WEBHOOKS[s.mechanisms.webhooks][ar ? 'ar' : 'en'])
  return parts.join(' · ')
}

export function LiveOperatingTab({ projectId }: { projectId: string | null }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const q = useLiveView(projectId)

  if (!projectId) return <EmptyState title={ar ? 'اختر مشروعاً' : 'Choose a project'} />
  if (q.isPending) return <Skeleton className="h-48" />
  if (q.isError || !q.data) {
    return <QueryFailure error={q.error} ar={ar} fallbackTitle={ar ? 'تعذّرت قراءة العرض الحي' : 'The live view could not be read'} onRetry={() => { void q.refetch() }} testId="live-view-failure" />
  }

  const v = q.data

  return (
    <section data-testid="live-operating-view" className="space-y-4">
      <p data-testid="live-not-realtime" className="rounded-xl border border-border bg-surface p-3.5 text-sm text-text-secondary">
        <Activity size={14} className="me-1.5 inline-block align-[-2px] text-text-muted" />
        {ar ? v.realtime_statement.ar : v.realtime_statement.en}
      </p>

      <div className="flex flex-wrap items-center gap-3 rounded-xl border border-border bg-surface p-3.5" data-testid="live-verdict">
        <SyncStatusPill status={v.verdict.state} ar={ar} />
        <span className="text-xs text-text-muted">
          {ar ? 'حتى' : 'As of'} <span dir="ltr" className="tabular-nums">{fmtDateTime(v.as_of)}</span>
          {' · '}
          {ar ? `نافذة ${v.window_days} أيام` : `${v.window_days}-day window`}
          {v.verdict.missing_days > 0 && <> · <span data-testid="live-missing-days">{ar ? `${v.verdict.missing_days} أيام ناقصة` : `${v.verdict.missing_days} missing days`}</span></>}
        </span>
      </div>

      {v.sources.length === 0 ? (
        <EmptyState
          title={ar ? 'لا مصادر مرتبطة بهذا المشروع' : 'No sources are bound to this project'}
          description={ar ? 'اربط حساباً إعلانياً أو متجراً ليظهر هنا بحالته ومواعيد مزامنته.' : 'Bind an ad account or a store and it appears here with its state and sync times.'}
        />
      ) : (
        <MetricTable
          head={ar
            ? ['المصدر', 'الحالة', 'آخر مزامنة ناجحة', 'آخر طابع زمني من المصدر', 'المزامنة التالية', 'كيف تتحرك الأرقام']
            : ['Source', 'State', 'Latest successful sync', 'Latest source timestamp', 'Next sync', 'How the figures move']}
          rows={v.sources.map((s) => [
            <span key="n" data-testid={`live-source-${s.provider}`} className="font-semibold text-text-primary">{sourceName(s, ar)}</span>,
            <span key="s" className="inline-flex flex-wrap items-center gap-1">
              <SyncStatusPill status={s.state} ar={ar} />
              {s.missing_grain && <span className="text-[11px] text-text-muted">{ar ? `ناقص: ${s.missing_grain}` : `missing: ${s.missing_grain}`}</span>}
            </span>,
            <span key="ok" dir="ltr" className="tabular-nums" data-testid={`live-success-${s.provider}`}>{when(s.latest_successful_sync_at, ar)}</span>,
            <span key="ts" dir="ltr" className="tabular-nums">{when(s.latest_source_timestamp, ar)}</span>,
            <span key="nx" data-testid={`live-next-sync-${s.provider}`}>
              {s.next_sync_at
                ? <span dir="ltr" className="tabular-nums">{fmtDateTime(s.next_sync_at)}</span>
                : <span className="text-text-muted">{s.next_sync_reason === 'not_connected' ? (ar ? 'لا مزامنة — غير متصل' : 'No sync — not connected') : '—'}</span>}
            </span>,
            <span key="m" className="text-xs text-text-secondary" data-testid={`live-mechanisms-${s.provider}`}>{mechanisms(s, ar)}</span>,
          ])}
          values={v.sources.map((s) => [
            sourceName(s, ar), s.state, s.latest_successful_sync_at ?? '', s.latest_source_timestamp ?? '', s.next_sync_at ?? '', mechanisms(s, ar),
          ])}
        />
      )}

      <div data-testid="live-scheduler" className="rounded-xl border border-border bg-surface p-3.5">
        <h3 className="mb-2 flex items-center gap-1.5 text-sm font-bold text-text-primary"><Clock size={14} /> {ar ? 'المجدوِل' : 'Scheduler'}</h3>
        {v.scheduler.length === 0 ? (
          <p className="text-xs text-text-muted">{ar ? 'لم يسجّل المجدوِل أي تشغيل بعد.' : 'The scheduler has not recorded a run yet.'}</p>
        ) : (
          <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            {v.scheduler.map((row: SchedulerRow) => (
              <li key={row.command} className="min-w-0 rounded-lg border border-border p-2 text-xs" data-testid={`live-scheduler-${row.command}`}>
                <div className="flex items-center justify-between gap-2">
                  <code className="truncate font-mono text-[11px] text-text-primary" dir="ltr">{row.command}</code>
                  <span className="shrink-0 text-text-muted">{cadence(row.expression, ar)}</span>
                </div>
                <div className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-text-secondary">
                  <span><RefreshCw size={11} className="me-1 inline-block align-[-1px]" />{ar ? 'التالي' : 'Next'}: <span dir="ltr" className="tabular-nums">{when(row.next_run_at, ar)}</span></span>
                  <span>{ar ? 'آخر نتيجة' : 'Last outcome'}: {row.last_outcome ? (OUTCOME[row.last_outcome]?.[ar ? 'ar' : 'en'] ?? row.last_outcome) : (ar ? 'لم يُشغَّل بعد' : 'Not run yet')}</span>
                  {row.overdue && <span className="text-warning">{ar ? 'متأخر' : 'Overdue'}</span>}
                </div>
              </li>
            ))}
          </ul>
        )}
        <p className="mt-2 text-[11px] text-text-muted"><Webhook size={11} className="me-1 inline-block align-[-1px]" />{ar ? 'الـ Webhooks تُسرّع المزامنة حيث ينشرها المزوّد؛ وإلا فالاستطلاع المجدوَل هو الطريق.' : 'Webhooks hasten a sync where the provider publishes them; otherwise the scheduled poll is the path.'}</p>
      </div>
    </section>
  )
}
