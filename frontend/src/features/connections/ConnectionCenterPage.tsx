import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, History, Plug, RefreshCw } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import { useProject } from '@/stores/project'
import { toApiError } from '@/lib/api/client'
import {
  getConnectionHistory, listConnectors, syncConnector,
  type Connector, type ConnectionState, type SyncResult, type SyncRun,
} from './api'

/** Bilingual copy — self-contained to this feature (Arabic-first). */
const COPY = {
  ar: {
    title: 'مركز الاتصالات', subtitle: 'حالة كل موصّل وقدراته ومزامنته — حالة صادقة لكل مزوّد ضمن المشروع الحالي.',
    pick_project: 'اختر مشروعًا', pick_project_hint: 'الاتصالات مرتبطة بالمشروع — اختر مشروعًا من المبدّل لعرض موصّلاته.',
    no_permission: 'لا تملك صلاحية عرض مركز الاتصالات.', loading: 'جارٍ التحميل…',
    capabilities: 'القدرات', sync_now: 'مزامنة الآن', syncing: 'جارٍ المزامنة…', history: 'سجل المزامنة',
    last_sync: 'آخر مزامنة ناجحة', last_error: 'آخر خطأ', token_expires: 'انتهاء التوكن', never: 'لا يوجد',
    honest_note: 'الحالات صادقة: لا يظهر «متصل/إنتاجي» إلا بعد مزامنة حقيقية ناجحة. المزوّدات الحقيقية بلا اعتماد تبقى «بانتظار اعتماد».',
    hist_runs: 'عمليات المزامنة', hist_errors: 'الأخطاء', hist_freshness: 'حداثة البيانات',
    run_status: 'الحالة', run_window: 'النطاق', run_metrics: 'المقاييس', run_started: 'البدء', run_finished: 'الانتهاء', run_error: 'الخطأ',
    no_runs: 'لا توجد عمليات مزامنة بعد.', no_errors: 'لا أخطاء.', close: 'إغلاق',
    fresh_last_run: 'آخر تشغيل', fresh_status: 'الحالة', fresh_metrics: 'المقاييس المحدّثة',
    sync_ok: 'اكتملت المزامنة', sync_awaiting: 'لم تُنفّذ: بانتظار اعتماد خارجي',
    records: 'سجلات', upserted: 'محدّث',
  },
  en: {
    title: 'Connection Center', subtitle: 'Each connector\'s state, capabilities, and sync — an honest state per provider within the current project.',
    pick_project: 'Select a project', pick_project_hint: 'Connections are project-scoped — pick a project from the switcher to see its connectors.',
    no_permission: 'You do not have permission to view the Connection Center.', loading: 'Loading…',
    capabilities: 'Capabilities', sync_now: 'Sync now', syncing: 'Syncing…', history: 'Sync history',
    last_sync: 'Last successful sync', last_error: 'Last error', token_expires: 'Token expires', never: 'None',
    honest_note: 'States are honest: nothing shows "Connected/Production" without a real successful sync. Real providers without credentials stay "Awaiting Credentials".',
    hist_runs: 'Runs', hist_errors: 'Errors', hist_freshness: 'Data freshness',
    run_status: 'Status', run_window: 'Window', run_metrics: 'Metrics', run_started: 'Started', run_finished: 'Finished', run_error: 'Error',
    no_runs: 'No sync runs yet.', no_errors: 'No errors.', close: 'Close',
    fresh_last_run: 'Last run', fresh_status: 'Status', fresh_metrics: 'Metrics upserted',
    sync_ok: 'Sync complete', sync_awaiting: 'Did not run: awaiting external dependency',
    records: 'records', upserted: 'upserted',
  },
}
type Copy = (typeof COPY)['ar']

/** Distinct color per honest state — never reuse a hue so the badge reads unambiguously. */
const STATE_STYLE: Record<ConnectionState, string> = {
  available: 'bg-info/15 text-info',
  awaiting_credentials: 'bg-warning/15 text-warning',
  sandbox_verified: 'bg-purple/15 text-purple',
  production_verified: 'bg-success/15 text-success',
  permission_missing: 'bg-brand-500/15 text-brand-600',
  token_expired: 'bg-teal/15 text-teal',
  sync_failed: 'bg-danger/15 text-danger',
}

const STATE_LABEL: Record<ConnectionState, { ar: string; en: string }> = {
  available: { ar: 'متاح', en: 'Available' },
  awaiting_credentials: { ar: 'بانتظار اعتماد', en: 'Awaiting Credentials' },
  sandbox_verified: { ar: 'موثّق (تجريبي)', en: 'Sandbox Verified' },
  production_verified: { ar: 'موثّق (إنتاجي)', en: 'Production Verified' },
  permission_missing: { ar: 'صلاحية ناقصة', en: 'Permission Missing' },
  token_expired: { ar: 'انتهى التوكن', en: 'Token Expired' },
  sync_failed: { ar: 'فشلت المزامنة', en: 'Sync Failed' },
}

export function ConnectionCenterPage() {
  const locale = useUi((s) => s.locale)
  const c = COPY[locale]
  const canView = useAuth((s) => s.hasPermission('integrations.view'))
  const { currentProjectId: projectId } = useProject()
  const [historyProvider, setHistoryProvider] = useState<string | null>(null)

  const q = useQuery({
    queryKey: ['project', projectId, 'connections'],
    queryFn: () => listConnectors(projectId!),
    enabled: Boolean(projectId) && canView,
  })

  if (!canView) {
    return (
      <div className="mx-auto w-full max-w-5xl p-4 md:p-6">
        <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">{c.no_permission}</p>
      </div>
    )
  }

  if (!projectId) {
    return (
      <div className="mx-auto flex w-full max-w-5xl flex-col gap-4 p-4 md:p-6">
        <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">{c.title}</h1>
        <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">
          <span className="block font-semibold text-text-primary">{c.pick_project}</span>
          {c.pick_project_hint}
        </p>
      </div>
    )
  }

  const connectors = q.data ?? []

  return (
    <div className="mx-auto flex w-full max-w-5xl flex-col gap-5 p-4 md:p-6">
      <header className="flex flex-col gap-1">
        <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">{c.title}</h1>
        <p className="text-sm text-text-secondary">{c.subtitle}</p>
      </header>

      <p className="flex items-start gap-2 rounded-lg bg-surface-hover px-3 py-2 text-xs text-text-secondary">
        <AlertTriangle size={14} className="mt-0.5 shrink-0 text-warning" /> {c.honest_note}
      </p>

      {q.isLoading ? (
        <p className="p-8 text-center text-sm text-text-secondary">{c.loading}</p>
      ) : (
        <ul className="flex flex-col gap-3">
          {connectors.map((conn) => (
            <ConnectorRow key={conn.provider} c={c} locale={locale} projectId={projectId} conn={conn} onHistory={() => setHistoryProvider(conn.provider)} />
          ))}
        </ul>
      )}

      {historyProvider && (
        <HistoryPanel c={c} projectId={projectId} provider={historyProvider} onClose={() => setHistoryProvider(null)} />
      )}
    </div>
  )
}

function StateBadge({ state, label }: { state: ConnectionState; label: string }) {
  return <span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${STATE_STYLE[state]}`}>{label}</span>
}

function ConnectorRow({ c, locale, projectId, conn, onHistory }: {
  c: Copy; locale: 'ar' | 'en'; projectId: string; conn: Connector; onHistory: () => void
}) {
  const qc = useQueryClient()
  const [result, setResult] = useState<SyncResult | null>(null)
  const [error, setError] = useState<string | null>(null)
  const syncM = useMutation({
    mutationFn: () => syncConnector(projectId, conn.provider),
    onSuccess: (r) => {
      setResult(r); setError(null)
      qc.invalidateQueries({ queryKey: ['project', projectId, 'connections'] })
      qc.invalidateQueries({ queryKey: ['connection-history', projectId, conn.provider] })
    },
    onError: (e) => setError(toApiError(e).message),
  })
  const stateLabel = STATE_LABEL[conn.state]?.[locale] ?? conn.state_label

  return (
    <li className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-surface-hover text-text-secondary"><Plug size={16} /></span>
          <div className="flex flex-col gap-0.5">
            <span className="font-semibold text-text-primary">{conn.label}</span>
            <span className="text-[11px] text-text-tertiary tnum">{conn.provider}</span>
          </div>
        </div>
        <StateBadge state={conn.state} label={stateLabel} />
      </div>

      <div className="flex flex-wrap gap-1.5">
        {conn.capabilities.map((cap) => (
          <span key={cap} className="rounded-md bg-surface-hover px-1.5 py-0.5 text-[11px] text-text-secondary">{cap}</span>
        ))}
      </div>

      {conn.connection && (
        <div className="flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-text-tertiary">
          <span>{c.last_sync}: <span className="tnum">{fmt(conn.connection.last_successful_sync_at) || c.never}</span></span>
          {conn.connection.token_expires_at && <span>{c.token_expires}: <span className="tnum">{fmt(conn.connection.token_expires_at)}</span></span>}
          {conn.connection.last_error && <span className="text-danger">{c.last_error}: {conn.connection.last_error}</span>}
        </div>
      )}

      <div className="flex flex-wrap items-center gap-2">
        <button
          onClick={() => syncM.mutate()} disabled={syncM.isPending}
          className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700 disabled:opacity-50"
        >
          <RefreshCw size={13} className={syncM.isPending ? 'animate-spin' : ''} /> {syncM.isPending ? c.syncing : c.sync_now}
        </button>
        <button
          onClick={onHistory}
          className="flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-text-secondary hover:border-brand-500 hover:text-brand-600"
        >
          <History size={13} /> {c.history}
        </button>
        {result && (
          <span className={`text-[11px] font-semibold ${result.status === 'success' ? 'text-success' : 'text-warning'}`}>
            {result.status === 'success' ? c.sync_ok : c.sync_awaiting}
            {' · '}
            <span className="tnum">{result.records} {c.records} · {result.metrics_upserted} {c.upserted}</span>
          </span>
        )}
        {error && <span className="text-[11px] font-semibold text-danger">{error}</span>}
      </div>
    </li>
  )
}

function HistoryPanel({ c, projectId, provider, onClose }: {
  c: Copy; projectId: string; provider: string; onClose: () => void
}) {
  const q = useQuery({
    queryKey: ['connection-history', projectId, provider],
    queryFn: () => getConnectionHistory(projectId, provider),
  })
  const data = q.data

  return (
    <div className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-4">
      <div className="flex items-center justify-between">
        <h3 className="flex items-center gap-2 text-sm font-bold text-text-primary"><History size={15} /> {c.history} · <span className="tnum">{provider}</span></h3>
        <button onClick={onClose} className="rounded-lg border border-border px-2.5 py-1 text-xs font-semibold text-text-secondary hover:text-text-primary">{c.close}</button>
      </div>

      {q.isLoading || !data ? (
        <p className="p-6 text-center text-sm text-text-secondary">{c.loading}</p>
      ) : (
        <div className="flex flex-col gap-4">
          <div className="flex flex-col gap-1.5">
          <span className="text-xs font-semibold text-text-secondary">{c.hist_freshness}</span>
          <div className="grid gap-2 sm:grid-cols-3">
            <FreshCell label={c.fresh_last_run} value={fmt(data.data_freshness.last_run_at) || c.never} />
            <FreshCell label={c.fresh_status} value={data.data_freshness.last_status ?? c.never} />
            <FreshCell label={c.fresh_metrics} value={String(data.data_freshness.metrics_upserted ?? 0)} />
          </div>
          </div>

          <div className="flex flex-col gap-1.5">
            <span className="text-xs font-semibold text-text-secondary">{c.hist_runs}</span>
            <div className="overflow-x-auto rounded-xl border border-border">
              <table className="w-full min-w-[560px] text-sm">
                <thead className="bg-surface-hover text-xs text-text-secondary">
                  <tr>
                    <th className="p-2 text-start font-semibold">{c.run_status}</th>
                    <th className="p-2 text-start font-semibold">{c.run_window}</th>
                    <th className="p-2 text-start font-semibold">{c.run_metrics}</th>
                    <th className="p-2 text-start font-semibold">{c.run_started}</th>
                    <th className="p-2 text-start font-semibold">{c.run_finished}</th>
                  </tr>
                </thead>
                <tbody>
                  {data.runs.map((r) => (
                    <tr key={r.id} className="border-t border-border">
                      <td className="p-2"><RunStatus status={r.status} /></td>
                      <td className="p-2 text-text-secondary tnum">{r.window_start ?? '—'} → {r.window_end ?? '—'}</td>
                      <td className="p-2 text-text-secondary tnum">{r.metrics_upserted ?? 0}</td>
                      <td className="p-2 text-xs text-text-tertiary tnum">{fmt(r.started_at)}</td>
                      <td className="p-2 text-xs text-text-tertiary tnum">{fmt(r.finished_at)}</td>
                    </tr>
                  ))}
                  {data.runs.length === 0 && <tr><td colSpan={5} className="p-6 text-center text-text-secondary">{c.no_runs}</td></tr>}
                </tbody>
              </table>
            </div>
          </div>

          <div className="flex flex-col gap-1.5">
            <span className="text-xs font-semibold text-text-secondary">{c.hist_errors}</span>
            {data.errors.length === 0 ? (
              <p className="rounded-lg bg-surface-hover px-3 py-2 text-xs text-text-secondary">{c.no_errors}</p>
            ) : (
              <ul className="flex flex-col gap-1.5">
                {data.errors.map((r: SyncRun) => (
                  <li key={r.id} className="rounded-lg border border-danger/30 bg-danger/5 px-3 py-2 text-xs text-danger">
                    <span className="tnum">{fmt(r.started_at)}</span> — {r.error}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

function FreshCell({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col gap-0.5 rounded-xl border border-border bg-background p-3">
      <span className="text-[11px] text-text-tertiary">{label}</span>
      <span className="text-sm font-semibold text-text-primary tnum">{value}</span>
    </div>
  )
}

function RunStatus({ status }: { status: string }) {
  const cls = status === 'success' ? 'bg-success/15 text-success'
    : status === 'failed' ? 'bg-danger/15 text-danger'
    : status === 'running' ? 'bg-info/15 text-info'
    : 'bg-surface-hover text-text-secondary'
  return <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${cls}`}>{status}</span>
}

function fmt(iso: string | null): string {
  if (!iso) return ''
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}
