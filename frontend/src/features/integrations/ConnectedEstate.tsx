import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ChevronDown, Plug, Plus, RefreshCw } from 'lucide-react'
import {
  fetchConnectedEstate,
  type ConnectionState, type EstateHealth, type EstateProject, type EstateProvider, type SyncState,
} from './api'
import { AccountsPanel } from './AccountsPanel'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { Num } from '@/components/ui/Num'
import { platformColor } from '@/features/analytics/components'
import { adAccounts, campaigns as countedCampaigns } from '@/lib/counted'
import { fmtDateTime } from '@/lib/datetime'
import { useUi } from '@/stores/ui'

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §17 — what is connected, said the way a customer holds it.
 *
 * ## The question this page was not answering
 *
 * The Integration Center opened on a grid of six provider cards with every discovered account
 * underneath. Both are true; neither is what somebody came for. An agency does not ask «how is
 * Meta» — it asks «is عميل كذا complete», because a client is the unit it invoices, reports on and
 * gets phoned about. Answering that from provider cards meant holding the binding table in your
 * head: this account is that client's, that one is nobody's, this authorisation feeds three clients.
 *
 * So the default view is one row per PROJECT. The provider grid is still here and still right for
 * «is our Snapchat authorisation healthy», and the discovered inventory is still one control away —
 * what changed is which of the three opens first.
 *
 * ## Two chips, never one
 *
 * Each provider inside a row shows its AUTHORISATION and its DATA separately (§16). A single chip
 * has to rank them, and on Production the ranking lost: a stale `running` row outranked a refused
 * Meta grant, so the card said «المزامنة جارية الآن» and hid the Reconnect button it was asking for.
 * Two chips cannot do that to each other.
 *
 * ## Only what was CHOSEN
 *
 * Rows are built from ACTIVE bindings (ACCOUNT-SCOPE-ISOLATION-001). An account consent merely
 * revealed has no client, so it has no row; how many of those exist is stated as a number at the
 * foot of the list, which is the honest way to show that an authorisation reaches further than this
 * page lists without putting a stranger's spend under somebody's name.
 */
export function ConnectedEstate({ onConnect, onManage, onReauthorise, onSync, busySync }: {
  onConnect: () => void
  onManage: (provider: EstateProvider, project: EstateProject) => void
  onReauthorise: (provider: EstateProvider) => void
  onSync: (provider: EstateProvider) => void
  busySync: string | null
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const estate = useQuery({ queryKey: ['connected-estate'], queryFn: fetchConnectedEstate })

  if (estate.isLoading) {
    return (
      <section className="flex flex-col gap-3" data-testid="connected-estate-loading">
        <Skeleton className="h-9 w-56" />
        <Skeleton className="h-24 w-full" />
        <Skeleton className="h-24 w-full" />
      </section>
    )
  }

  if (estate.isError) {
    return (
      <ErrorState
        title={ar ? 'تعذّر تحميل الحسابات المربوطة' : 'Could not load the connected estate'}
        onRetry={() => void estate.refetch()}
      />
    )
  }

  const projects = estate.data?.projects ?? []

  return (
    <section className="flex flex-col gap-3" data-testid="connected-estate">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div className="min-w-0">
          <h2 className="text-base font-bold text-text-primary">
            {ar ? 'العملاء والمنصات المربوطة' : 'Connected clients'}
          </h2>
          <p className="text-xs text-text-secondary">
            {ar
              ? 'كل عميل وما يغذّيه من منصات وحسابات إعلانية مختارة.'
              : 'Each client, and the platforms and selected ad accounts feeding it.'}
          </p>
        </div>

        <Button onClick={onConnect} data-testid="estate-connect">
          <Plus size={15} /> {ar ? 'ربط منصة' : 'Connect a platform'}
        </Button>
      </header>

      {projects.length === 0 ? (
        /*
         * Empty is a real state here and says which emptiness it is: nothing has been CHOSEN yet.
         * A tenant can have seventeen discovered accounts and an empty estate, and «لا توجد بيانات»
         * over that would send somebody looking for a broken sync instead of an unfinished setup.
         */
        <div
          className="rounded-xl border border-dashed border-border bg-surface p-6 text-center"
          data-testid="connected-estate-empty"
        >
          <p className="text-sm font-semibold text-text-primary">
            {ar ? 'لا يوجد عميل مربوط بعد' : 'No client is connected yet'}
          </p>
          <p className="mt-1 text-xs text-text-secondary">
            {ar
              ? 'اربط منصة، ثم اختر الحسابات الإعلانية التي تخصّ كل عميل.'
              : 'Connect a platform, then choose which ad accounts belong to each client.'}
          </p>
        </div>
      ) : (
        <ul className="flex flex-col gap-2">
          {projects.map((project) => (
            <ProjectRow
              key={project.id}
              project={project}
              ar={ar}
              onManage={onManage}
              onReauthorise={onReauthorise}
              onSync={onSync}
              busySync={busySync}
            />
          ))}
        </ul>
      )}

      {(estate.data?.unselected_accounts ?? 0) > 0 && (
        <p className="text-xs text-text-muted" data-testid="estate-unselected">
          {ar
            ? `${adAccounts(estate.data!.unselected_accounts, 'ar')} مكتشفة لم يخترها أحد بعد — لا تُزامَن ولا تظهر لأي عميل.`
            : `${adAccounts(estate.data!.unselected_accounts, 'en')} discovered and chosen by nobody — not synced, and on no client.`}
        </p>
      )}
    </section>
  )
}

function ProjectRow({ project, ar, onManage, onReauthorise, onSync, busySync }: {
  project: EstateProject
  ar: boolean
  onManage: (provider: EstateProvider, project: EstateProject) => void
  onReauthorise: (provider: EstateProvider) => void
  onSync: (provider: EstateProvider) => void
  busySync: string | null
}) {
  const [open, setOpen] = useState(false)
  const health = HEALTH[project.health]

  return (
    <li className="rounded-xl border border-border bg-surface" data-testid={`estate-project-${project.id}`}>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        className="flex w-full flex-col gap-2 rounded-xl p-4 text-start transition-colors hover:bg-surface-hover sm:flex-row sm:items-center sm:gap-4"
      >
        <div className="flex min-w-0 flex-1 items-center gap-2">
          {/*
            `rotate-180` rather than a flipped icon: the chevron points down when open in both
            writing directions, because «open» is vertical and has no direction to mirror.
          */}
          <ChevronDown
            size={16}
            aria-hidden
            className={`shrink-0 text-text-muted transition-transform ${open ? 'rotate-180' : ''}`}
          />
          <div className="min-w-0">
            <p className="truncate text-sm font-bold text-text-primary">{project.name}</p>
            {project.client !== null && project.client.name !== project.name && (
              <p className="truncate text-xs text-text-secondary">{project.client.name}</p>
            )}
          </div>
        </div>

        {/* The platforms, as colour AND name — colour alone is never the message. */}
        <div className="flex flex-wrap items-center gap-1.5">
          {project.providers.map((p) => (
            <span
              key={p.key}
              className="inline-flex items-center gap-1.5 rounded-[var(--radius-pill)] bg-surface-secondary px-2 py-0.5 text-[11px] font-semibold text-text-secondary"
            >
              <span className="h-2 w-2 rounded-full" style={{ background: platformColor(p.key) }} aria-hidden />
              {ar ? p.label_ar : p.label}
            </span>
          ))}
        </div>

        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-text-secondary">
          <span>{adAccounts(project.accounts, ar ? 'ar' : 'en')}</span>
          {project.campaigns > 0 && <span>{countedCampaigns(project.active_campaigns, ar ? 'ar' : 'en')}</span>}
        </div>

        <Badge tone={health.tone} className="shrink-0 self-start whitespace-nowrap sm:self-auto" data-testid={`estate-health-${project.id}`}>
          {ar ? health.ar : health.en}
        </Badge>
      </button>

      {open && (
        <div className="flex flex-col gap-3 border-t border-border p-4" data-testid={`estate-detail-${project.id}`}>
          {project.providers.map((provider) => (
            <ProviderBlock
              key={provider.key}
              provider={provider}
              project={project}
              ar={ar}
              onManage={onManage}
              onReauthorise={onReauthorise}
              onSync={onSync}
              busy={busySync === provider.key}
            />
          ))}

          {/*
            The accounts themselves come from the inventory, pinned to this client. Same request,
            same per-account health, same logs and backfill dialogs — one engine, one answer.
          */}
          <AccountsPanel project={project.id} heading={false} />
        </div>
      )}
    </li>
  )
}

function ProviderBlock({ provider, project, ar, onManage, onReauthorise, onSync, busy }: {
  provider: EstateProvider
  project: EstateProject
  ar: boolean
  onManage: (provider: EstateProvider, project: EstateProject) => void
  onReauthorise: (provider: EstateProvider) => void
  onSync: (provider: EstateProvider) => void
  busy: boolean
}) {
  const auth = CONNECTION[provider.connection_state]
  const data = SYNC[provider.sync_state]
  const needsReauth = provider.connection_state === 'REAUTH_REQUIRED' || provider.connection_state === 'REVOKED'

  return (
    <div
      className="flex flex-col gap-2 rounded-lg bg-surface-secondary p-3"
      data-testid={`estate-provider-${provider.key}`}
    >
      <div className="flex flex-wrap items-center gap-2">
        <span className="h-4 w-1.5 shrink-0 rounded-full" style={{ background: platformColor(provider.key) }} aria-hidden />
        <span className="text-sm font-bold text-text-primary">{ar ? provider.label_ar : provider.label}</span>

        {/*
          Both truths, side by side and unranked (§16). The authorisation chip says whether this can
          work at all; the data chip says what the pipeline last did. A page that shows one of them
          has to choose, and the wrong choice is what hid the Reconnect button on Production.
        */}
        <Badge tone={auth.tone} data-testid={`estate-auth-${provider.key}`}>{ar ? auth.ar : auth.en}</Badge>
        <Badge tone={data.tone} data-testid={`estate-sync-${provider.key}`}>{ar ? data.ar : data.en}</Badge>
      </div>

      <dl className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-text-secondary">
        <div className="flex gap-1">
          <dt>{ar ? 'الحسابات المختارة' : 'Selected accounts'}</dt>
          <dd className="font-semibold text-text-primary"><Num>{provider.accounts}</Num></dd>
        </div>
        <div className="flex gap-1">
          <dt>{ar ? 'حملات نشطة' : 'Active campaigns'}</dt>
          <dd className="font-semibold text-text-primary"><Num>{provider.active_campaigns}</Num></dd>
        </div>
        <div className="flex gap-1">
          <dt>{ar ? 'آخر مزامنة ناجحة' : 'Last successful sync'}</dt>
          <dd className="font-semibold text-text-primary">
            {provider.last_success_at === null
              ? (ar ? 'لم تتم بعد' : 'Not yet')
              : fmtDateTime(provider.last_success_at)}
          </dd>
        </div>
        {provider.currencies.length > 0 && (
          <div className="flex gap-1">
            {/* Listed, never summed: two currencies added together is a number that means nothing. */}
            <dt>{ar ? 'العملات' : 'Currencies'}</dt>
            <dd className="font-semibold text-text-primary">{provider.currencies.join(' · ')}</dd>
          </div>
        )}
      </dl>

      <div className="flex flex-wrap items-center gap-2">
        {needsReauth ? (
          /*
           * The only action that can succeed, so it is the only one offered and it is styled as the
           * one to take. Offering «Sync now» beside it would be offering a button whose whole job is
           * to fail — which is the state this redesign exists to remove.
           */
          <Button variant="secondary" onClick={() => onReauthorise(provider)} data-testid={`estate-reauth-${provider.key}`}>
            <Plug size={14} /> {ar ? 'إعادة المصادقة' : 'Reconnect'}
          </Button>
        ) : (
          <Button variant="ghost" loading={busy} onClick={() => onSync(provider)} data-testid={`estate-sync-now-${provider.key}`}>
            <RefreshCw size={14} /> {ar ? 'مزامنة الآن' : 'Sync now'}
          </Button>
        )}

        <Button variant="ghost" onClick={() => onManage(provider, project)} data-testid={`estate-manage-${provider.key}`}>
          {ar ? 'إدارة الحسابات' : 'Manage accounts'}
        </Button>
      </div>
    </div>
  )
}

const HEALTH: Record<EstateHealth, { tone: 'success' | 'warning' | 'danger' | 'info' | 'neutral'; ar: string; en: string }> = {
  reauth: { tone: 'danger', ar: 'يحتاج إعادة مصادقة', en: 'Needs reconnecting' },
  attention: { tone: 'warning', ar: 'يحتاج انتباه', en: 'Needs attention' },
  syncing: { tone: 'info', ar: 'مزامنة جارية', en: 'Syncing' },
  // Never red: the authorisation works and the provider answered «nothing happened».
  no_data: { tone: 'neutral', ar: 'لا توجد بيانات', en: 'No data' },
  complete: { tone: 'success', ar: 'مكتمل', en: 'Complete' },
}

const CONNECTION: Record<ConnectionState, { tone: 'success' | 'warning' | 'danger' | 'info' | 'neutral'; ar: string; en: string }> = {
  NOT_CONNECTED: { tone: 'neutral', ar: 'غير مربوط', en: 'Not connected' },
  AWAITING_CREDENTIALS: { tone: 'neutral', ar: 'بانتظار التهيئة', en: 'Awaiting setup' },
  CONNECTED: { tone: 'success', ar: 'المصادقة سليمة', en: 'Authorised' },
  REAUTH_REQUIRED: { tone: 'danger', ar: 'المصادقة تحتاج تجديدًا', en: 'Authorisation needs renewing' },
  REVOKED: { tone: 'danger', ar: 'المصادقة ملغاة', en: 'Authorisation revoked' },
}

const SYNC: Record<SyncState, { tone: 'success' | 'warning' | 'danger' | 'info' | 'neutral'; ar: string; en: string }> = {
  NEVER_SYNCED: { tone: 'neutral', ar: 'لم تبدأ المزامنة', en: 'Never synced' },
  QUEUED: { tone: 'info', ar: 'المزامنة في الانتظار', en: 'Sync queued' },
  SYNCING: { tone: 'info', ar: 'المزامنة جارية', en: 'Syncing' },
  SUCCEEDED: { tone: 'success', ar: 'آخر مزامنة نجحت', en: 'Last sync succeeded' },
  FAILED: { tone: 'danger', ar: 'آخر مزامنة فشلت', en: 'Last sync failed' },
}
