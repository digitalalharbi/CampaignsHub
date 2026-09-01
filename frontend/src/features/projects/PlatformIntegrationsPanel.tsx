import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlertTriangle, Check, KeyRound, Link2, RefreshCw, X,
} from 'lucide-react'
import { getData, postData } from '@/lib/api/client'
import { Badge } from '@/components/ui/Badge'
import { Skeleton } from '@/components/ui/States'
import { StatCard, type StatTone } from '@/components/ui/StatCard'
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
function accountHealthLabel(health: string | undefined, ar: boolean): string {
  switch (health) {
    case 'healthy': return ar ? 'تعمل' : 'Healthy'
    case 'pending_first_sync': return ar ? 'بانتظار أول مزامنة' : 'Awaiting the first sync'
    case 'delayed': return ar ? 'متأخرة' : 'Delayed'
    case 'failed': return ar ? 'فشلت آخر محاولة' : 'Last attempt failed'
    case 'access_lost': return ar ? 'تعذّر الوصول' : 'Access lost'
    case 'revoked': return ar ? 'الربط ملغى' : 'Authorisation revoked'
    default: return ar ? 'مرتبطة' : 'Linked'
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

  /* What this project actually uses, which is the count a project page owes its reader. */
  const linkedAccounts = platforms.reduce((n, p) => n + p.accounts.length, 0)

  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {/*
          INTEGRATION-DATASOURCE-WIZARD-001 §12 — a project screen states what the PROJECT has.

          «لديها بيانات اعتماد: 0» was on this page: a count of how many platforms this INSTALL has
          keys for. It is the platform operator's number, it reads on a customer's project as «zero
          of your platforms work», and no action on this page could change it. It is replaced by the
          number a project reader came for — how many of their accounts are feeding this project.
        */}
        <Stat
          label={ar ? 'المنصات المدعومة' : 'Platforms supported'}
          value={String(summary.total)}
          sub={ar ? 'ميتا · جوجل · تيك توك · سناب · X · لينكدإن' : 'Meta · Google · TikTok · Snapchat · X · LinkedIn'}
        />
        <Stat label={ar ? 'منصات تُغذّي هذا المشروع' : 'Platforms feeding this project'} value={String(summary.with_accounts)} />
        <Stat label={ar ? 'حسابات مرتبطة' : 'Accounts linked'} value={String(linkedAccounts)} />
        <Stat label={ar ? 'حملات مكتشفة' : 'Campaigns discovered'} value={String(summary.discovered_campaigns)} />
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
                {/*
                  §12 — «بانتظار بيانات اعتماد» is a fact about this INSTALL's keys, and the project
                  reader can do nothing with it. What they can act on is whether this platform feeds
                  their project, so that is what the chip says; the platform's own readiness is
                  stated once, in the sources screen, to the person who can change it.
                */}
                {p.accounts.length > 0
                  ? <Badge tone="success"><Check size={11} /> {ar ? 'تُغذّي المشروع' : 'Feeding this project'}</Badge>
                  : <Badge tone="neutral"><KeyRound size={11} /> {ar ? 'لا حسابات هنا' : 'No accounts here'}</Badge>}
              </header>

              {/*
                INTEGRATIONS-VS-PROJECTS-IA-001 — «مرتبطة», not «حسابات».
                The count is of what somebody LINKED to this project. It used to be of everything the
                tenant had ever discovered, which on the live connection meant 309 on a page about one.
              */}
              <div className="grid grid-cols-3 gap-2 text-center">
                <Mini label={ar ? 'حسابات مرتبطة' : 'Accounts linked'} value={p.accounts.length} />
                <Mini label={ar ? 'حملات مكتشفة' : 'Campaigns found'} value={p.discovered_campaigns} />
                <Mini label={ar ? 'مرتبطة بحملة' : 'Linked to a campaign'} value={p.linked_campaigns} />
              </div>

              {/* Capabilities are listed even when disabled — the build is done, the secret is missing. */}
              <ul className="flex flex-wrap gap-1.5">
                {p.capabilities.map((c) => (
                  <li
                    key={c.key}
                    title={c.enabled
                      ? (ar ? 'مفعّلة' : 'Active')
                      : (ar ? 'غير متاحة على هذا الربط بعد' : 'Not available on this connection yet')}
                    className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold ${
                      c.enabled ? 'border-success/40 bg-success/10 text-success' : 'border-border text-text-muted'
                    }`}
                  >
                    {c.enabled ? <Check size={10} /> : <KeyRound size={10} />} {ar ? c.ar : c.en}
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
                          {a.is_demo && <Badge tone="warning">{ar ? 'تجريبي' : 'Demo'}</Badge>}
                        </span>
                        {/* Name first, then the organisation it sits under, then the id. */}
                        <span className="mt-0.5 block text-[11px] text-text-muted">
                          {a.parent_name && <span>{a.parent_name} · </span>}
                          <span className="tnum" dir="ltr">{a.external_id}</span>
                        </span>
                        <span className="mt-0.5 flex flex-wrap items-center gap-2 text-[11px]">
                          <span className={accountHealthTone(a.health)}>{accountHealthLabel(a.health, ar)}</span>
                          {a.last_synced_at && (
                            <span className="tnum text-text-muted">
                              {ar ? 'آخر نجاح: ' : 'Last success: '}{fmtDateTime(a.last_synced_at)}
                            </span>
                          )}
                          {a.next_sync_at && (
                            <span className="tnum text-text-muted">
                              {ar ? 'التالية: ' : 'Next: '}{fmtDateTime(a.next_sync_at)}
                            </span>
                          )}
                        </span>
                      </span>
                      {canManage && (
                        <button
                          data-testid="platform-sync"
                          onClick={() => sync.mutate(a.id)}
                          disabled={sync.isPending}
                          className="inline-flex items-center gap-1 rounded-lg border border-border px-2 py-1 text-[11px] font-semibold text-text-secondary hover:bg-surface-hover disabled:opacity-50"
                        >
                          <RefreshCw size={11} /> {ar ? 'مزامنة الآن' : 'Sync now'}
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
                      <span className="text-text-muted">{ar ? 'آخر مزامنة:' : 'Last sync:'}</span>
                      <Badge tone={syncMeta.tone}>{syncMeta.ar}</Badge>
                      <span className="tnum text-text-muted">{p.last_sync.finished_at ? fmtDateTime(p.last_sync.finished_at) : '—'}</span>
                      <span className="tnum text-text-muted">
                        · {ar ? `${p.last_sync.metrics_upserted} قياسًا` : `${p.last_sync.metrics_upserted} measurements`}
                      </span>
                      {p.last_sync.is_demo && <Badge tone="warning">{ar ? 'تجريبية' : 'Demo'}</Badge>}
                    </span>
                    {p.last_sync.error && <p className="rounded bg-danger/10 p-1.5 text-danger" dir="ltr">{p.last_sync.error}</p>}
                  </>
                ) : (
                  <span className="flex items-center gap-1.5 text-text-muted">
                    <X size={12} /> {ar ? 'لم تُنفَّذ أي مزامنة لهذه المنصة بعد.' : 'No sync has run for this platform yet.'}
                  </span>
                )}
                {p.connections.map((c) => c.last_error && (
                  <p key={c.id} className="flex items-start gap-1.5 rounded bg-warning/10 p-1.5 text-warning">
                    <AlertTriangle size={12} className="mt-0.5 shrink-0" />
                    <span dir="ltr">{c.last_error}</span>
                  </p>
                ))}
                {/*
                  §12 — what used to be here described THIS PRODUCT's build state to a customer:
                  «the structure is complete (OAuth, accounts, campaign discovery, sync, the log) and
                  works as soon as the keys are added». It is true, it is ours, and it is not an
                  answer to «why is there nothing from Meta on my project».
                */}
                {p.accounts.length === 0 && (
                  <p className="text-[11px] text-text-muted">
                    {ar
                      ? `لا يُغذّي ${p.label_ar} هذا المشروع بعد — اختر حساباته من «إدارة مصادر البيانات».`
                      : `${p.label_en} is not feeding this project yet — choose its accounts from «Manage data sources».`}
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
            ? (ar ? 'تم جدولة المزامنة وسيتم جلب البيانات من المنصة.' : 'The sync is queued and will fetch from the platform.')
            : (ar
                ? 'تم تسجيل طلب المزامنة، لكن هذه المنصة غير مهيأة بعد — سيُسجَّل التشغيل ولن يُجلب أي رقم.'
                : 'The sync request was recorded, but this platform is not set up yet — the run is logged and no figure is fetched.')}
        </p>
      )}
    </div>
  )
}

/**
 * UX-KPI-PRESENTATION-001 — the integrations summary, on the product's own card.
 *
 * It drew its own: same shape, its own label size, its own value size, its own padding. A second
 * opinion about type on a page a reader reaches from the same rail as every surface using the
 * shared one. The tone still comes from here, because whether «two accounts awaiting credentials»
 * is a warning is a fact about integrations and not about cards.
 */
function Stat({ label, value, sub, tone }: { label: string; value: string; sub?: string; tone?: StatTone }) {
  return <StatCard label={label} value={value} hint={sub} tone={tone ?? 'neutral'} />
}

function Mini({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-lg bg-surface-secondary p-2">
      <div className="text-[10px] text-text-muted">{label}</div>
      <div className="tnum text-base font-bold text-text-primary">{value}</div>
    </div>
  )
}
