import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link2, MoreHorizontal, Plus, RefreshCcw, Search, Unplug } from 'lucide-react'
import { fetchConnectionHub, fetchConnectionHubWithRemoved, type HubConnection } from './api'
import { CONNECTION_COPY, SYNC_COPY, needsReauthorising } from './connectionCopy'
import { StatusChips } from './StatusChips'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { StatCard, StatGrid } from '@/components/ui/StatCard'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { platformColor } from '@/features/analytics/components'
import { connectedAccounts } from '@/lib/counted'
import { fmtDateTime } from '@/lib/datetime'
import { useUi } from '@/stores/ui'
import { sortBySource } from '@/lib/sourceOrder'

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §17 — the Connection Hub.
 *
 * ## What this replaced, and why a row rather than a card
 *
 * The page was a grid of six provider cards with every discovered account listed underneath it. A
 * grid says «here are the platforms» — a catalogue — and the reader of this page is not shopping:
 * they already connected something and want to know whether it is still working, which account it
 * is reading, and who authorised it. That is a table's question, so it is a table's shape: one dense
 * row per AUTHORISATION, scannable at a glance, with everything else one click away in a drawer.
 *
 * One row per authorisation rather than per provider, because an agency has two Meta logins — its
 * own and a client's — which expire, fail and get reconnected independently. The provider card that
 * merged them could not say which of the two had gone stale.
 *
 * ## «1 of 17 accounts»
 *
 * Chosen over reachable, on the row, as one phrase. It is ACCOUNT-SCOPE-ISOLATION-001 in four words,
 * and it is the sentence that stops a reader believing all seventeen are being read — a belief this
 * product has had to correct twice on live data. Pressing it opens the drawer on the accounts.
 *
 * ## Two chips, always
 *
 * The authorisation and the data are separate facts and get separate chips (§16). A single merged
 * chip has to rank them, and on Production the ranking lost: a stale `running` row buried a refused
 * Meta grant, so the card announced «المزامنة جارية الآن» over an action area with no Reconnect
 * button. Two chips cannot do that to each other.
 */
/** How the «المصادر المرتبطة» list can be ordered. `provider` is the server's own order and the default. */
export type SourceSort = 'provider' | 'attention' | 'last_sync' | 'name'

/** Everything a reader might type to find a source: its platform, its platform ACCOUNT, and the clients it feeds. */
function haystack(connection: HubConnection): string {
  return [
    connection.label, connection.label_ar, connection.connection_name, connection.provider,
    connection.provider_account?.email ?? '', connection.provider_account?.label ?? '',
    ...connection.projects.map((p) => p.name),
  ].join(' ').toLowerCase()
}

/** Does a person have to do something about this source? Read from the server's five statuses when they are there. */
export function sourceNeedsAction(connection: HubConnection): boolean {
  const statuses = connection.statuses ?? null
  if (statuses !== null) return statuses.error.state !== 'none' || statuses.authorisation.action !== null
  return needsReauthorising(connection.connection_state) || connection.needs_attention
}

export function sortSources(connections: HubConnection[], sort: SourceSort, ar: boolean): HubConnection[] {
  // The one ordering every source list shares: platform, then the organisation and account the row reads.
  if (sort === 'provider') return sortBySource(connections, (c) => ({ platform: c.provider, organisation: c.accounts?.[0]?.organisation ?? null, account: c.accounts?.[0]?.name ?? null }), ar)
  const rows = [...connections]
  if (sort === 'attention') return rows.sort((a, b) => Number(sourceNeedsAction(b)) - Number(sourceNeedsAction(a)))
  // Never synced sorts last: «newest first» is a question about sources that have a sync to compare.
  if (sort === 'last_sync') return rows.sort((a, b) => (b.last_success_at ?? '').localeCompare(a.last_success_at ?? ''))
  return rows.sort((a, b) => (ar ? a.label_ar : a.label).localeCompare(ar ? b.label_ar : b.label, ar ? 'ar' : 'en'))
}

export function ConnectionHub({ projectId = null, onConnect, onOpen, onAction, onRemove, onReauthorise, onRelink }: {
  /** INTEG-PROJECT-SPACE-001 — the project whose space this is. Part of every query key, so nothing of another project is ever shown from cache. */
  projectId?: string | null
  onConnect: () => void
  onOpen: (connection: HubConnection, tab?: 'overview' | 'accounts') => void
  onAction: (connection: HubConnection) => void
  /** INTEG-SOURCE-REMOVAL-001 — asks the page to confirm and remove a source. */
  onRemove?: (connection: HubConnection) => void
  /** INTEG-UNIFIED-002 — re-authorise ON the row: the same flow the drawer opens, one press nearer. */
  onReauthorise?: (connection: HubConnection) => void
  /**
   * «إعادة الربط» on a LEGACY row — a source that was authorised workspace-wide, before a connection
   * belonged to one project. It walks the project's own journey (sign-in → organisation → account), which
   * leaves this project reading the account through a connection of its own.
   */
  onRelink?: (connection: HubConnection) => void
}) {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  /* INTEG-SOURCE-REMOVAL-001 — removed and revoked sources are hidden unless asked for; the default key is the page's own. */
  const [showRemoved, setShowRemoved] = useState(false)
  const [search, setSearch] = useState('')
  const [sort, setSort] = useState<SourceSort>('provider')
  const hub = useQuery({
    queryKey: projectId === null
      ? (showRemoved ? ['connection-hub', 'with-removed'] : ['connection-hub'])
      : ['connection-hub', projectId, showRemoved ? 'with-removed' : 'live'],
    queryFn: () => (showRemoved ? fetchConnectionHubWithRemoved(projectId) : fetchConnectionHub(projectId)),
  })

  const listed = useMemo(() => hub.data?.connections ?? [], [hub.data])
  const shown = useMemo(() => {
    const needle = search.trim().toLowerCase()
    const matched = needle === '' ? listed : listed.filter((c) => haystack(c).includes(needle))
    return sortSources(matched, sort, ar)
  }, [listed, search, sort, ar])

  if (hub.isLoading) {
    return (
      <section className="flex flex-col gap-3" data-testid="connection-hub-loading">
        <Skeleton className="h-10 w-64" />
        <Skeleton className="h-16 w-full" />
        <Skeleton className="h-16 w-full" />
      </section>
    )
  }

  if (hub.isError) {
    return (
      <ErrorState
        title={ar ? 'تعذّر تحميل المصادر المربوطة' : 'Could not load your connected sources'}
        onRetry={() => void hub.refetch()}
      />
    )
  }

  return (
    <section className="flex min-w-0 flex-col gap-4" data-testid="hub-section-sources" id="hub-sources" aria-labelledby="hub-sources-title">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <h2 id="hub-sources-title" className="text-base font-bold text-text-primary">{ar ? 'المصادر' : 'Sources'}</h2>

        {listed.length > 0 && (
          <div className="flex min-w-0 flex-wrap items-center gap-2">
            <label className="relative min-w-0">
              <span className="sr-only">{ar ? 'ابحث في المصادر' : 'Search sources'}</span>
              <Search size={14} className="pointer-events-none absolute start-2.5 top-1/2 -translate-y-1/2 text-text-muted" aria-hidden />
              <input
                type="search"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder={ar ? 'ابحث بالمنصة أو حساب المنصة أو المشروع' : 'Search by platform, platform account or project'}
                data-testid="hub-search"
                className="w-full min-w-0 rounded-lg border border-border bg-background py-1.5 pe-3 ps-8 text-sm text-text-primary focus:border-brand-500 focus:outline-none sm:w-72"
              />
            </label>
            <label className="flex items-center gap-1.5 text-xs text-text-secondary">
              {ar ? 'الترتيب' : 'Sort'}
              <select
                value={sort}
                onChange={(e) => setSort(e.target.value as SourceSort)}
                data-testid="hub-sort"
                className="rounded-lg border border-border bg-background px-2 py-1.5 text-xs text-text-primary focus:border-brand-500 focus:outline-none"
              >
                <option value="provider">{ar ? 'المنصة' : 'Platform'}</option>
                <option value="attention">{ar ? 'ما يحتاج إجراءً أولًا' : 'Needs action first'}</option>
                <option value="last_sync">{ar ? 'آخر مزامنة (الأحدث)' : 'Last sync (newest)'}</option>
                <option value="name">{ar ? 'الاسم' : 'Name'}</option>
              </select>
            </label>
          </div>
        )}
      </div>

      {listed.length === 0 ? (
        <div
          className="rounded-2xl border border-dashed border-border px-6 py-10 text-center"
          data-testid="connection-hub-empty"
        >
          <p className="text-sm font-semibold text-text-primary">
            {ar ? 'لا مصدر بعد' : 'No source yet'}
          </p>
          <Button className="mt-4" onClick={onConnect} data-testid="hub-connect-empty">
            <Plus size={15} /> {ar ? 'ربط' : 'Link'}
          </Button>
        </div>
      ) : (
        /*
          A list of rows, not a `<table>`: the same row has to become a stacked block on a phone, and
          a table that reflows into blocks stops being a table for assistive technology halfway down.
        */
        <ul className="flex flex-col divide-y divide-border overflow-hidden rounded-2xl border border-border bg-surface">
          {shown.map((connection) => (
            <ConnectionRow
              key={connection.id}
              connection={connection}
              ar={ar}
              onOpen={onOpen}
              onAction={onAction}
              onRemove={onRemove}
              onReauthorise={onReauthorise}
              onRelink={projectId === null ? undefined : onRelink}
            />
          ))}
          {shown.length === 0 && (
            <li className="px-6 py-8 text-center text-xs text-text-secondary" data-testid="hub-no-match">
              {ar ? 'لا مصدر يطابق البحث.' : 'No source matches the search.'}
            </li>
          )}
        </ul>
      )}

      {/* The way back to a removed source's record — off by default, so the list stays the live estate. */}
      <label className="flex w-fit items-center gap-2 text-xs text-text-secondary">
        <input
          type="checkbox"
          checked={showRemoved}
          onChange={(e) => setShowRemoved(e.target.checked)}
          data-testid="hub-show-removed"
          className="h-4 w-4 accent-brand-600"
        />
        {ar ? 'إظهار المصادر المُزالة والملغاة' : 'Show removed and revoked sources'}
      </label>
    </section>
  )
}

/** A source that was removed, or whose authorisation was revoked: history only, nothing to operate. */
function isGone(connection: HubConnection): boolean {
  return (connection.removed_at ?? null) !== null || connection.connection_state === 'REVOKED'
}

function ConnectionRow({ connection, ar, onOpen, onAction, onRemove, onReauthorise, onRelink }: {
  connection: HubConnection
  ar: boolean
  onOpen: (connection: HubConnection, tab?: 'overview' | 'accounts') => void
  onAction: (connection: HubConnection) => void
  onRemove?: (connection: HubConnection) => void
  onReauthorise?: (connection: HubConnection) => void
  onRelink?: (connection: HubConnection) => void
}) {
  // A workspace-wide source seen from a project's space: the row offers the project's own journey.
  const legacy = onRelink !== undefined && (connection.project_id ?? null) === null
  const auth = CONNECTION_COPY[connection.connection_state]
  const data = SYNC_COPY[connection.sync_state]
  const gone = isGone(connection)
  const statuses = connection.statuses ?? null
  /*
   * Re-authorising is offered on the row only where it is the consent screen that answers it. An
   * API-key source has no consent screen — its remedy is a new key — so its row does not promise one.
   */
  const reauthorisable = !gone && onReauthorise !== undefined && connection.status_profile?.auth !== 'api_key'
  const reauthDue = statuses === null
    ? needsReauthorising(connection.connection_state)
    : (statuses.authorisation.action?.code ?? '').startsWith('reauthorize')
  const account = connection.provider_account ?? null
  const platformAccount = account === null ? null : (account.email ?? account.label)

  return (
    <li
      className="group flex min-w-0 flex-col gap-3 p-4 transition-colors hover:bg-surface-hover"
      data-testid={`hub-row-${connection.id}`}
      data-provider={connection.provider}
    >
      <div className="flex min-w-0 flex-col gap-3 lg:flex-row lg:items-center lg:gap-4">
        {/*
          Identity and the menu share a line, and on a phone that line is the row's header.

          Measured at 390px: with the menu as the last child of a wrapping row it fell to a line of its
          own at the very bottom, four stacked facts away from the source it acts on. The name and its
          «…» belong together at every width — one says what this is, the other is everything you can
          do to it.
        */}
        <div className="flex min-w-0 flex-1 items-center gap-2">
          <button
            type="button"
            onClick={() => onOpen(connection)}
            className="flex min-w-0 flex-1 items-center gap-3 text-start"
            data-testid={`hub-open-${connection.id}`}
          >
            <span
              className="h-9 w-1 shrink-0 rounded-full"
              style={{ background: platformColor(connection.provider) }}
              aria-hidden
            />
            <span className="min-w-0">
              <span className="block truncate text-sm font-bold text-text-primary">
                {ar ? connection.label_ar : connection.label}
              </span>
              {/*
                INTEG-PROVIDER-IDENTITY-001 — the PROVIDER login the grant was made with, first: the
                email the platform reported, or its display name where it gave no email.
              */}
              {/* INTEG-PROJECT-SPACE-001 — what this source reads, by name: two rows of one platform are told apart here. */}
              {(connection.accounts?.length ?? 0) > 0 && (
                <span className="mt-0.5 flex min-w-0 flex-wrap gap-1" data-testid={`hub-accounts-${connection.id}`}>
                  {sortBySource(connection.accounts!, (a) => ({ organisation: a.organisation, account: a.name }), ar).slice(0, 3).map((a) => (
                    <span key={a.id} className="max-w-full truncate rounded-full bg-surface-secondary px-2 py-0.5 text-[11px] font-semibold text-text-primary">
                      {a.organisation === null ? a.name : `${a.organisation} · ${a.name}`}
                    </span>
                  ))}
                  {connection.accounts!.length > 3 && (
                    <span className="tnum rounded-full bg-surface-secondary px-2 py-0.5 text-[11px] font-semibold text-text-secondary">+{(connection.accounts!.length - 3).toLocaleString('en-US')}</span>
                  )}
                </span>
              )}
              <span className="block truncate text-xs text-text-secondary" data-testid={`hub-platform-account-${connection.id}`}>
                {platformAccount === null
                  ? (ar ? 'لم يُسجَّل حساب المنصة' : 'Platform account not recorded')
                  : `${ar ? 'حساب المنصة' : 'Platform account'}: ${platformAccount}`}
              </span>
              {/*
                INTEG-UNIFIED-002 — the workspace user who pressed connect is named by NAME. Their
                CampaignsHub email is not an identity of this source and is not shown on this page at
                all: beside a platform account it read as a second login for the same grant.
              */}
              <span className="block truncate text-[11px] text-text-muted" data-testid={`hub-added-by-${connection.id}`}>
                {connection.authorised_by === null
                  ? (ar ? 'لم يُسجَّل من أضافه' : 'Added by: not recorded')
                  : `${ar ? 'أضافه' : 'Added by'}: ${connection.authorised_by.name}`}
              </span>
            </span>
          </button>

          {!gone && (
            <button
              type="button"
              onClick={() => onAction(connection)}
              aria-label={ar ? 'إجراءات المصدر' : 'Source actions'}
              data-testid={`hub-actions-${connection.id}`}
              className="shrink-0 rounded-lg p-2 text-text-secondary transition-colors hover:bg-surface-secondary hover:text-text-primary lg:hidden"
            >
              <MoreHorizontal size={18} />
            </button>
          )}
        </div>

        {/*
          «1 of 17 accounts» is a CONTROL, not a label: it is the shortest path to the thing a reader
          wants after reading it, which is the list of the seventeen.
        */}
        <button
          type="button"
          onClick={() => onOpen(connection, 'accounts')}
          data-testid={`hub-accounts-${connection.id}`}
          className="inline-flex w-fit shrink-0 items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-text-primary underline-offset-4 hover:bg-surface-secondary hover:underline"
        >
          {/*
            INTEG-PROJECT-LINK-001 — LINKED accounts only. What a sign-in could see but nobody chose is
            not a source of this workspace and is not counted on the centre: «1 of 309» read as 309
            accounts being synced, which they never were.
          */}
          <span className="tnum">{connectedAccounts(connection.selected_accounts, ar ? 'ar' : 'en')}</span>
        </button>

        {/*
          Both times, small and tabular. «Last» without «next» leaves a reader who sees an old
          timestamp unable to tell a broken integration from one merely between runs — the question
          that used to be answered by pressing «Sync now» for reassurance.
        */}
        <dl className="flex shrink-0 flex-col gap-0.5 text-[11px] text-text-secondary lg:w-44 lg:text-end">
          <div className="flex gap-1 lg:justify-end">
            <dt>{ar ? 'آخر مزامنة' : 'Last sync'}</dt>
            <dd className="tnum font-semibold text-text-primary">
              {connection.last_success_at === null
                ? (ar ? 'لم تتم' : 'None')
                : fmtDateTime(connection.last_success_at)}
            </dd>
          </div>
          <div className="flex gap-1 lg:justify-end">
            <dt>{ar ? 'التالية' : 'Next'}</dt>
            <dd className="tnum font-semibold text-text-primary">
              {/* Null is «it is not going to», never «unknown» — the server only states a real one. */}
              {connection.next_sync_at === null
                ? (ar ? 'متوقفة' : 'Paused')
                : fmtDateTime(connection.next_sync_at)}
            </dd>
          </div>
        </dl>

        {/*
          INTEG-UNIFIED-002 — the two acts a reader comes to this section for, ON the row.

          Re-authorise opens the centre's one flow in `reauthorise` mode (it renews the grant and
          leaves the selection alone); remove asks the page for the same confirmation the menu does.
          Neither is a new endpoint. A source that needs re-authorising gets the filled button, so the
          remedy is the loudest thing on a broken row; a working one gets the quiet one.
        */}
        {!gone && (legacy || reauthorisable || (onRemove !== undefined && connection.abandoned !== true)) && (
          <div className="flex shrink-0 flex-wrap items-center gap-1.5">
            {legacy && (
              <Button size="sm" variant="ghost" onClick={() => onRelink!(connection)} data-testid={`hub-relink-${connection.id}`}>
                <Link2 size={13} /> {ar ? 'إعادة الربط' : 'Re-link'}
              </Button>
            )}
            {reauthorisable && (
              <Button
                size="sm"
                variant={reauthDue ? 'primary' : 'ghost'}
                onClick={() => onReauthorise!(connection)}
                data-testid={`hub-reauth-${connection.id}`}
                data-due={reauthDue ? 'true' : 'false'}
              >
                <RefreshCcw size={13} /> {ar ? 'إعادة المصادقة' : 'Re-authorise'}
              </Button>
            )}
            {onRemove !== undefined && connection.abandoned !== true && (
              <Button size="sm" variant="ghost" onClick={() => onRemove(connection)} data-testid={`hub-detach-${connection.id}`}>
                <Unplug size={13} /> {ar ? 'إزالة المصدر' : 'Remove source'}
              </Button>
            )}
          </div>
        )}

        {/* The same control at desktop width, where it belongs at the end of the row instead. */}
        {!gone && (
          <button
            type="button"
            onClick={() => onAction(connection)}
            aria-label={ar ? 'إجراءات المصدر' : 'Source actions'}
            data-testid={`hub-actions-desktop-${connection.id}`}
            className="hidden shrink-0 rounded-lg p-2 text-text-secondary transition-colors hover:bg-surface-secondary hover:text-text-primary lg:block"
          >
            <MoreHorizontal size={18} />
          </button>
        )}
      </div>

      {/*
        The states get a line of their own. Five chips do not fit beside an identity, a count, two
        times and the controls at any width this page is opened at, and a chip that wraps under a
        button stops reading as a state of the row.
      */}
      <div className="flex min-w-0 flex-wrap items-center gap-1.5">
        {/*
          INTEG-UNIFIED-002 (§1.13) — FIVE chips, the server's own five statuses, none implying another.
          The two-chip pair below is what an older response without `statuses` still gets: the same two
          facts, in the words this page already used for them.
        */}
        {statuses !== null ? (
          <StatusChips
            statuses={statuses}
            ar={ar}
            id={connection.id}
            legacyIds={{ authorisation: `hub-auth-${connection.id}`, sync: `hub-sync-${connection.id}` }}
          />
        ) : (
          <>
            <Badge tone={auth.tone} data-testid={`hub-auth-${connection.id}`}>{ar ? auth.ar : auth.en}</Badge>
            <Badge tone={data.tone} data-testid={`hub-sync-${connection.id}`}>{ar ? data.ar : data.en}</Badge>
          </>
        )}

        {gone && (
          <Badge tone="neutral" data-testid={`hub-removed-${connection.id}`}>
            {(connection.removed_at ?? null) !== null ? (ar ? 'مُزال' : 'Removed') : (ar ? CONNECTION_COPY.REVOKED.ar : CONNECTION_COPY.REVOKED.en)}
          </Badge>
        )}

        {/*
          INTEG-SOURCE-REMOVAL-001 — started and never finished: the way out is ON the row, not behind a menu.
        */}
        {!gone && connection.abandoned === true && onRemove !== undefined && (
          <button
            type="button"
            onClick={() => onRemove(connection)}
            data-testid={`hub-remove-${connection.id}`}
            className="rounded-[var(--radius-pill)] bg-[var(--negative-background)] px-2.5 py-0.5 text-xs font-bold text-danger hover:underline"
          >
            {ar ? 'إلغاء/إزالة' : 'Cancel / remove'}
          </button>
        )}

        {/*
          An authorisation nobody finished, with the way to finish it ON the row.

          Consent completed, catalogue full, and not one account chosen — so nothing syncs and the
          page otherwise looks complete. Discovery is not a link: the accounts this sign-in can see
          are not bound to any project and are not synced until somebody chooses them here.
        */}
        {!gone && connection.selected_accounts === 0 && (
          <button
            type="button"
            onClick={() => onOpen(connection, 'accounts')}
            data-testid={`hub-unfinished-${connection.id}`}
            className="rounded-[var(--radius-pill)] bg-[var(--warning-background)] px-2.5 py-0.5 text-xs font-bold text-warning hover:underline"
          >
            {ar ? 'اختر الحسابات' : 'Choose accounts'}
          </button>
        )}
      </div>
    </li>
  )
}

/**
 * INTEG-PROJECT-SPACE-001 — the project's four figures, on top of its space: linked accounts, platforms,
 * campaigns, and how many sources need an action. Figures only — a tile carries a word and a number.
 */
export function HubSummary({ connections, campaigns = null }: { connections: HubConnection[]; campaigns?: number | null }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const live = connections.filter((c) => !isGone(c))
  if (live.length === 0) return null

  const attention = live.filter(sourceNeedsAction).length
  const accounts = live.reduce((n, c) => n + c.selected_accounts, 0)
  const platforms = new Set(live.map((c) => c.provider)).size

  return (
    <section className="min-w-0" data-testid="hub-summary" aria-label={ar ? 'ملخص' : 'Summary'}>
      <StatGrid columns="grid-cols-2 sm:grid-cols-4">
        <StatCard label={ar ? 'الحسابات' : 'Accounts'} value={accounts.toLocaleString('en-US')} tone="brand" dot testid="hub-kpi-accounts" />
        <StatCard label={ar ? 'المنصات' : 'Platforms'} value={platforms.toLocaleString('en-US')} tone="neutral" dot testid="hub-kpi-platforms" />
        <StatCard label={ar ? 'الحملات' : 'Campaigns'} value={campaigns === null ? '—' : campaigns.toLocaleString('en-US')} tone="neutral" dot testid="hub-kpi-campaigns" />
        <StatCard label={ar ? 'تحتاج إجراء' : 'Need action'} value={attention.toLocaleString('en-US')} tone={attention > 0 ? 'danger' : 'success'} dot testid="hub-kpi-attention" />
      </StatGrid>
    </section>
  )
}
