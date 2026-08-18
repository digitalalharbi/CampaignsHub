import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlertTriangle, Check, KeyRound, Link2, RefreshCw, X,
} from 'lucide-react'
import { getData, postData } from '@/lib/api/client'
import { Badge } from '@/components/ui/Badge'
import { Skeleton } from '@/components/ui/States'
import { platformColor } from '@/features/analytics/components'
import { useAuth } from '@/stores/auth'
import { syncStatusMeaning } from '@/lib/syncStatus'
import { fmtDateTime } from '@/lib/datetime'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { useUi } from '@/stores/ui'

/**
 * PROJINT-001 / INTEG-UI-001 — project integrations rebuilt around the SIX REAL ad platforms.
 *
 * The panel refuses to overstate the system's reach. For each platform it shows what really exists —
 * connections, ad accounts, discovered and linked campaigns, and the last sync run with its error —
 * and when credentials are missing it says so while still listing the capabilities that are already
 * implemented and waiting. "Awaiting credentials" is a state, never a claim that nothing was built.
 */

/**
 * An account's state in one phrase, matching the wizard's vocabulary.
 *
 * Each names a different next action. «جاهزة» over an account whose metrics are failing is the
 * sentence this whole programme exists to remove.
 */
function accountHealthLabel(health: string | undefined): string {
  switch (health) {
    case 'healthy': return 'تعمل'
    case 'pending_first_sync': return 'بانتظار أول مزامنة'
    case 'delayed': return 'متأخرة'
    case 'failed': return 'فشلت آخر محاولة'
    case 'access_lost': return 'تعذّر الوصول'
    case 'revoked': return 'الربط ملغى'
    default: return 'مرتبطة'
  }
}

/** Colour carries the same distinction the words do, so the state survives a glance. */
function accountHealthTone(health: string | undefined): string {
  switch (health) {
    case 'healthy': return 'text-success'
    case 'delayed': return 'text-warning'
    case 'failed':
    case 'access_lost':
    case 'revoked': return 'text-danger'
    default: return 'text-text-muted'
  }
}

export interface PlatformCapability { key: string; ar: string; en: string; enabled: boolean }

export interface PlatformRow {
  key: string
  label_ar: string
  label_en: string
  connector_label: string | null
  status: string
  has_credentials: boolean
  capabilities: PlatformCapability[]
  connections: Array<{ id: string; name: string; status: string; last_health_check_at: string | null; last_successful_sync_at: string | null; last_error: string | null }>
  accounts: Array<{
    id: string; provider: string; account_type: string
    name: string; external_id: string
    parent_name: string | null; parent_external_id: string | null
    currency: string | null; timezone: string | null; status: string
    /** How this account is doing — the question a project screen exists to answer. */
    health?: string
    last_synced_at: string | null
    last_sync_attempt_at?: string | null
    last_sync_error_category?: string | null
    next_sync_at?: string | null
    access_lost_at?: string | null
    is_demo?: boolean
  }>
  discovered_campaigns: number
  linked_campaigns: number
  last_sync: { status: string; started_at: string | null; finished_at: string | null; metrics_upserted: number; error: string | null; is_demo: boolean } | null
}

export interface PlatformsPayload {
  platforms: PlatformRow[]
  summary: { total: number; with_credentials: number; with_accounts: number; discovered_campaigns: number }
}

export function PlatformIntegrationsPanel({ projectId }: { projectId: string }) {
  const qc = useQueryClient()
  const ar = useUi((s) => s.locale) === 'ar'
  const canManage = useAuth((s) => s.hasPermission('integrations.connect'))

  const q = useQuery({
    queryKey: ['project', projectId, 'platforms'],
    queryFn: () => getData<PlatformsPayload>(`/projects/${projectId}/integrations/platforms`),
    enabled: Boolean(projectId),
  })

  const sync = useMutation({
    mutationFn: (accountId: string) =>
      postData<{ queued: boolean; will_fetch: boolean }>(`/projects/${projectId}/sync-runs`, { external_account_id: accountId }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project', projectId, 'platforms'] })
      qc.invalidateQueries({ queryKey: ['project', projectId, 'sync-runs'] })
    },
  })

  if (q.isLoading) return <div className="grid gap-3 lg:grid-cols-2">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-48" />)}</div>
  if (q.isError || !q.data) {
    return <QueryFailure error={q.error} ar={ar} testId="project-platforms-failure" onRetry={() => void q.refetch()}
      fallbackTitle={ar ? 'تعذّر تحميل المنصات.' : 'The platforms could not be loaded.'} />
  }

  const { platforms, summary } = q.data

  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <Stat label="المنصات المدعومة" value={String(summary.total)} sub="ميتا · جوجل · تيك توك · سناب · X · لينكدإن" />
        <Stat label="لديها بيانات اعتماد" value={String(summary.with_credentials)} tone={summary.with_credentials === 0 ? 'warning' : 'success'} sub={summary.with_credentials === 0 ? 'لا توجد مفاتيح حقيقية بعد' : undefined} />
        <Stat label="لديها حسابات إعلانية" value={String(summary.with_accounts)} />
        <Stat label="حملات مكتشفة" value={String(summary.discovered_campaigns)} />
      </div>

      <div className="grid gap-3 lg:grid-cols-2">
        {platforms.map((p) => {
          // INTEG-RUNTIME §8 — the word and the colour come from the one module that decides them.
          const syncMeta = p.last_sync ? syncStatusMeaning(p.last_sync.status) : null
          return (
            <article key={p.key} data-testid={`platform-${p.key}`} className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-small)]">
              <header className="flex flex-wrap items-start justify-between gap-2">
                <span className="flex items-center gap-2">
                  <span className="h-8 w-1.5 rounded-full" style={{ background: platformColor(p.key) }} />
                  <span>
                    <span className="block font-bold text-text-primary">{p.label_ar}</span>
                    <span className="block text-[11px] text-text-muted">{p.connector_label ?? p.label_en}</span>
                  </span>
                </span>
                {p.has_credentials
                  ? <Badge tone="success"><Check size={11} /> جاهزة</Badge>
                  : <Badge tone="warning"><KeyRound size={11} /> بانتظار بيانات اعتماد</Badge>}
              </header>

              {/*
                INTEGRATIONS-VS-PROJECTS-IA-001 — «مرتبطة», not «حسابات».
                The count is of what somebody LINKED to this project. It used to be of everything the
                tenant had ever discovered, which on the live connection meant 309 on a page about one.
              */}
              <div className="grid grid-cols-3 gap-2 text-center">
                <Mini label="حسابات مرتبطة" value={p.accounts.length} />
                <Mini label="حملات مكتشفة" value={p.discovered_campaigns} />
                <Mini label="مرتبطة بحملة" value={p.linked_campaigns} />
              </div>

              {/* Capabilities are listed even when disabled — the build is done, the secret is missing. */}
              <ul className="flex flex-wrap gap-1.5">
                {p.capabilities.map((c) => (
                  <li
                    key={c.key}
                    title={c.enabled ? 'مفعّلة' : 'مبنيّة وتُفعَّل فور إضافة بيانات الاعتماد'}
                    className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold ${
                      c.enabled ? 'border-success/40 bg-success/10 text-success' : 'border-border text-text-muted'
                    }`}
                  >
                    {c.enabled ? <Check size={10} /> : <KeyRound size={10} />} {c.ar}
                  </li>
                ))}
              </ul>

              {p.accounts.length > 0 && (
                <ul className="space-y-1.5">
                  {p.accounts.map((a) => (
                    <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-surface-secondary p-2 text-xs">
                      <span className="min-w-0">
                        <span className="flex items-center gap-1.5">
                          <Link2 size={12} className="text-text-muted" />
                          <span className="truncate font-semibold text-text-primary">{a.name}</span>
                          {a.is_demo && <Badge tone="warning">تجريبي</Badge>}
                        </span>
                        {/* Name first, then the organisation it sits under, then the id. */}
                        <span className="mt-0.5 block text-[11px] text-text-muted">
                          {a.parent_name && <span>{a.parent_name} · </span>}
                          <span className="tnum" dir="ltr">{a.external_id}</span>
                        </span>
                        <span className="mt-0.5 flex flex-wrap items-center gap-2 text-[11px]">
                          <span className={accountHealthTone(a.health)}>{accountHealthLabel(a.health)}</span>
                          {a.last_synced_at && <span className="tnum text-text-muted">آخر نجاح: {fmtDateTime(a.last_synced_at)}</span>}
                          {a.next_sync_at && <span className="tnum text-text-muted">التالية: {fmtDateTime(a.next_sync_at)}</span>}
                        </span>
                      </span>
                      {canManage && (
                        <button
                          data-testid="platform-sync"
                          onClick={() => sync.mutate(a.id)}
                          disabled={sync.isPending}
                          className="inline-flex items-center gap-1 rounded-lg border border-border px-2 py-1 text-[11px] font-semibold text-text-secondary hover:bg-surface-hover disabled:opacity-50"
                        >
                          <RefreshCw size={11} /> مزامنة الآن
                        </button>
                      )}
                    </li>
                  ))}
                </ul>
              )}

              {/* Last run — including the failure text, which is the point of having a log. */}
              <footer className="mt-auto space-y-1.5 border-t border-border pt-2 text-xs">
                {p.last_sync && syncMeta ? (
                  <>
                    <span className="flex flex-wrap items-center gap-2">
                      <span className="text-text-muted">آخر مزامنة:</span>
                      <Badge tone={syncMeta.tone}>{syncMeta.ar}</Badge>
                      <span className="tnum text-text-muted">{p.last_sync.finished_at ? fmtDateTime(p.last_sync.finished_at) : '—'}</span>
                      <span className="tnum text-text-muted">· {p.last_sync.metrics_upserted} قياسًا</span>
                      {p.last_sync.is_demo && <Badge tone="warning">تجريبية</Badge>}
                    </span>
                    {p.last_sync.error && <p className="rounded bg-danger/10 p-1.5 text-danger" dir="ltr">{p.last_sync.error}</p>}
                  </>
                ) : (
                  <span className="flex items-center gap-1.5 text-text-muted"><X size={12} /> لم تُنفَّذ أي مزامنة لهذه المنصة بعد.</span>
                )}
                {p.connections.map((c) => c.last_error && (
                  <p key={c.id} className="flex items-start gap-1.5 rounded bg-warning/10 p-1.5 text-warning">
                    <AlertTriangle size={12} className="mt-0.5 shrink-0" />
                    <span dir="ltr">{c.last_error}</span>
                  </p>
                ))}
                {!p.has_credentials && (
                  <p className="text-[11px] text-text-muted">
                    البنية كاملة (OAuth، الحسابات، اكتشاف الحملات، المزامنة، السجل) وتعمل فور إضافة مفاتيح اعتماد {p.label_ar} — لا تُعرض أي بيانات مُفترضة قبل ذلك.
                  </p>
                )}
              </footer>
            </article>
          )
        })}
      </div>

      {sync.isSuccess && (
        <p className="rounded-xl border border-border bg-surface-secondary p-3 text-sm text-text-secondary">
          {sync.data?.will_fetch
            ? 'تم جدولة المزامنة وسيتم جلب البيانات من المنصة.'
            : 'تم تسجيل طلب المزامنة، لكن هذه المنصة بلا بيانات اعتماد — سيُسجَّل التشغيل بحالة «بانتظار بيانات اعتماد» ولن يُجلب أي رقم.'}
        </p>
      )}
    </div>
  )
}

function Stat({ label, value, sub, tone }: { label: string; value: string; sub?: string; tone?: 'success' | 'warning' }) {
  return (
    <div className="rounded-2xl border border-border bg-surface p-3.5 shadow-[var(--shadow-small)]">
      <span className="text-sm text-text-secondary">{label}</span>
      <div className={`tnum mt-1 text-2xl font-extrabold ${tone === 'success' ? 'text-success' : tone === 'warning' ? 'text-warning' : 'text-text-primary'}`}>{value}</div>
      {sub && <div className="mt-0.5 text-[11px] text-text-muted">{sub}</div>}
    </div>
  )
}

function Mini({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-lg bg-surface-secondary p-2">
      <div className="text-[10px] text-text-muted">{label}</div>
      <div className="tnum text-base font-bold text-text-primary">{value}</div>
    </div>
  )
}
