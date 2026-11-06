import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronDown, Link2, MoreHorizontal, Plus, RefreshCcw, Search, Unplug } from 'lucide-react'
import { fetchConnectionHub, fetchConnectionHubWithRemoved, unlinkAccount, STATUS_DIMENSIONS, type HubConnection } from './api'
import { CONNECTION_COPY, SYNC_COPY, needsReauthorising } from './connectionCopy'
import { StatusChips, statusTone } from './StatusChips'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { StatCard, StatGrid } from '@/components/ui/StatCard'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { ProviderMark } from '@/components/ProviderMark'
import { CopyId } from '@/components/ui/CopyId'
import { lastNDays, useAccounts, useSummary, type AccountRow } from '@/features/analytics/api'
import { money, moneyFromTotals } from '@/features/analytics/format'
import { aggregateMoney, type MoneyTotals } from '@/lib/money/contract'
import { platformLabel } from '@/lib/platforms'
import { campaigns as countedCampaigns, connectedAccounts } from '@/lib/counted'
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

  const spend = useProjectSpend(projectId)
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
                placeholder={ar ? 'بحث' : 'Search'}
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
              projectId={projectId}
              spend={spend}
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

/** A link that still exists through a connection somebody disconnected: listed in the project's space, to be linked again. */
function isDisconnected(connection: HubConnection): boolean {
  return connection.status === 'disconnected' && (connection.removed_at ?? null) === null
}

/** A source that was removed, or whose authorisation was revoked: history only, nothing to operate. */
export function isGone(connection: HubConnection): boolean {
  return (connection.removed_at ?? null) !== null || (connection.connection_state === 'REVOKED' && !isDisconnected(connection))
}

const SPEND_DAYS = 30

/**
 * The project's last 30 days of spend, per linked account and in total — read from the metrics endpoints
 * the analytics pages read, so the centre cannot state a figure they would not. Nothing is summed here that
 * the money contract refuses to sum: a withheld amount stays in its own currency, and «no single figure» is
 * a dash, never a zero.
 */
export function useProjectSpend(projectId: string | null) {
  const range = useMemo(() => lastNDays(SPEND_DAYS), [])
  const summary = useSummary(projectId, range)
  const accounts = useAccounts(projectId, range)
  const currency = summary.data?.currency ?? null
  const rows: AccountRow[] = Array.isArray(accounts.data) ? accounts.data : []

  return {
    currency,
    total: summary.data?.current === undefined ? null : summary.data.current as MoneyTotals,
    of: (accountIds: string[]): string | null => {
      const mine = rows.filter((r) => r.account_id !== null && accountIds.includes(r.account_id))
      if (mine.length === 0) return null
      if (mine.length === 1) return moneyFromTotals(mine[0] as MoneyTotals, 'spend', true, currency).text
      const sum = aggregateMoney(mine as MoneyTotals[], 'spend', currency)
      return sum === null ? '—' : money(sum.amount, sum.currency)
    },
  }
}

type ProjectSpend = ReturnType<typeof useProjectSpend>

/**
 * The ONE chip a collapsed row carries. Not a sixth status: it is the first of the server's five that asks
 * for something, in that status's own words — authorisation first, so a running sync can never stand in
 * front of a refused grant — and the authorisation itself when nothing asks. All five are one press away.
 */
function RowState({ connection, ar }: { connection: HubConnection; ar: boolean }) {
  const statuses = connection.statuses ?? null
  const id = `hub-state-${connection.id}`

  if (isDisconnected(connection)) return <Badge tone="danger" data-testid={id}>{ar ? 'مفصول' : 'Disconnected'}</Badge>

  if (statuses !== null) {
    const dimension = STATUS_DIMENSIONS.find((d) => statuses[d].action !== null && statuses[d].action?.code !== 'wait') ?? 'authorisation'
    const status = statuses[dimension]
    return <Badge tone={statusTone(dimension, status.state)} data-testid={id} data-dimension={dimension}>{ar ? status.label_ar : status.label_en}</Badge>
  }

  const copy = !needsReauthorising(connection.connection_state) && connection.sync_state === 'FAILED'
    ? SYNC_COPY[connection.sync_state]
    : CONNECTION_COPY[connection.connection_state]
  return <Badge tone={copy.tone} data-testid={id}>{ar ? copy.ar : copy.en}</Badge>
}

function ConnectionRow({ connection, ar, onOpen, onAction, onRemove, onReauthorise, onRelink, projectId, spend }: {
  connection: HubConnection
  ar: boolean
  onOpen: (connection: HubConnection, tab?: 'overview' | 'accounts') => void
  onAction: (connection: HubConnection) => void
  onRemove?: (connection: HubConnection) => void
  onReauthorise?: (connection: HubConnection) => void
  onRelink?: (connection: HubConnection) => void
  projectId: string | null
  spend: ProjectSpend
}) {
  const [expanded, setExpanded] = useState(false)
  const [unlinking, setUnlinking] = useState<{ id: string; name: string } | null>(null)
  const disconnected = isDisconnected(connection)
  // A workspace-wide source seen from a project's space, or one that was disconnected: the row offers the project's own journey.
  const relinkable = onRelink !== undefined && ((connection.project_id ?? null) === null || disconnected)
  const auth = CONNECTION_COPY[connection.connection_state]
  const data = SYNC_COPY[connection.sync_state]
  const gone = isGone(connection)
  const statuses = connection.statuses ?? null
  /*
   * Re-authorising is offered only where it is the consent screen that answers it. An API-key source has
   * no consent screen — its remedy is a new key — and a disconnected one is linked again, not renewed.
   */
  const reauthorisable = !gone && !disconnected && onReauthorise !== undefined && connection.status_profile?.auth !== 'api_key'
  const reauthDue = statuses === null
    ? needsReauthorising(connection.connection_state)
    : (statuses.authorisation.action?.code ?? '').startsWith('reauthorize')
  const account = connection.provider_account ?? null
  const platformAccount = account === null ? null : (account.email ?? account.label)
  const accounts = connection.accounts ?? []
  const removable = !gone && onRemove !== undefined
  const abandoned = removable && connection.abandoned === true
  const unfinished = !gone && connection.selected_accounts === 0

  /*
   * ONE primary action on the row — the remedy, when there is one. Every other act this source admits is
   * in the panel under it or behind «…», so a row is a name, its numbers, a state and at most one button.
   */
  const primary: 'relink' | 'reauth' | 'remove' | 'choose' | null = gone ? null
    : relinkable ? 'relink'
      : reauthorisable && reauthDue ? 'reauth'
        : abandoned ? 'remove'
          : unfinished ? 'choose'
            : null

  const relink = relinkable && (
    <Button size="sm" variant={disconnected ? 'primary' : 'ghost'} onClick={() => onRelink!(connection)} data-testid={`hub-relink-${connection.id}`}>
      <Link2 size={13} /> {ar ? 'إعادة الربط' : 'Re-link'}
    </Button>
  )
  const reauth = reauthorisable && (
    <Button
      size="sm"
      variant={reauthDue ? 'primary' : 'ghost'}
      onClick={() => onReauthorise!(connection)}
      data-testid={`hub-reauth-${connection.id}`}
      data-due={reauthDue ? 'true' : 'false'}
    >
      <RefreshCcw size={13} /> {ar ? 'إعادة المصادقة' : 'Re-authorise'}
    </Button>
  )
  // INTEG-SOURCE-REMOVAL-001 — started and never finished: the way out is ON the row, not behind a menu.
  const cancel = abandoned && (
    <button
      type="button"
      onClick={() => onRemove!(connection)}
      data-testid={`hub-remove-${connection.id}`}
      className="rounded-[var(--radius-pill)] bg-[var(--negative-background)] px-2.5 py-0.5 text-xs font-bold text-danger hover:underline"
    >
      {ar ? 'إلغاء/إزالة' : 'Cancel / remove'}
    </button>
  )
  // Consent completed and not one account chosen: nothing syncs until somebody chooses here.
  const choose = unfinished && (
    <button
      type="button"
      onClick={() => onOpen(connection, 'accounts')}
      data-testid={`hub-unfinished-${connection.id}`}
      className="rounded-[var(--radius-pill)] bg-[var(--warning-background)] px-2.5 py-0.5 text-xs font-bold text-warning hover:underline"
    >
      {ar ? 'اختر الحسابات' : 'Choose accounts'}
    </button>
  )

  const rowSpend = projectId === null || accounts.length === 0 ? null : spend.of(accounts.map((a) => a.id))
  const panelId = `hub-detail-${connection.id}`

  return (
    <li className="group flex min-w-0 flex-col" data-testid={`hub-row-${connection.id}`} data-provider={connection.provider}>
      <div className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2.5 transition-colors hover:bg-surface-hover">
        <div className="flex min-w-0 flex-1 basis-40 items-center gap-1.5">
          <button
            type="button"
            onClick={() => setExpanded((v) => !v)}
            aria-expanded={expanded}
            aria-controls={panelId}
            aria-label={ar ? 'الحسابات' : 'Accounts'}
            data-testid={`hub-expand-${connection.id}`}
            className="shrink-0 rounded-lg p-1.5 text-text-secondary transition-colors hover:bg-surface-secondary hover:text-text-primary"
          >
            <ChevronDown size={16} className={`transition-transform ${expanded ? 'rotate-180' : ''}`} aria-hidden />
          </button>
          {/* The platform by its short name and its own mark: «سناب شات», never the API's title. */}
          <button type="button" onClick={() => onOpen(connection)} className="flex min-w-0 flex-1 items-center gap-2 text-start" data-testid={`hub-open-${connection.id}`}>
            <ProviderMark provider={connection.provider} size={22} />
            <span className="min-w-0 truncate text-sm font-bold text-text-primary">{platformLabel(connection.provider, ar)}</span>
          </button>
        </div>

        <div className="flex min-w-0 flex-wrap items-center gap-1.5 text-[11px] font-semibold text-text-secondary">
          {/*
            INTEG-PROJECT-LINK-001 — LINKED accounts only: what a sign-in could see but nobody chose is not
            counted. The count opens what it counts — the list under the row where the row carries one.
          */}
          <button
            type="button"
            onClick={() => (accounts.length > 0 ? setExpanded((v) => !v) : onOpen(connection, 'accounts'))}
            data-testid={`hub-accounts-${connection.id}`}
            className="tnum rounded-full bg-surface-secondary px-2 py-0.5 text-text-primary hover:underline"
          >
            {connectedAccounts(connection.selected_accounts, ar ? 'ar' : 'en')}
          </button>
          {connection.campaigns !== undefined && (
            <span className="tnum rounded-full bg-surface-secondary px-2 py-0.5" data-testid={`hub-campaigns-${connection.id}`}>
              {countedCampaigns(connection.campaigns, ar ? 'ar' : 'en')}
            </span>
          )}
          {rowSpend !== null && (
            <span className="tnum rounded-full bg-surface-secondary px-2 py-0.5 text-text-primary" dir="ltr" data-testid={`hub-spend-${connection.id}`}>{rowSpend}</span>
          )}
        </div>

        <div className="flex shrink-0 items-center gap-1.5">
          {gone ? (
            <Badge tone="neutral" data-testid={`hub-removed-${connection.id}`}>
              {(connection.removed_at ?? null) !== null ? (ar ? 'مُزال' : 'Removed') : (ar ? CONNECTION_COPY.REVOKED.ar : CONNECTION_COPY.REVOKED.en)}
            </Badge>
          ) : <RowState connection={connection} ar={ar} />}
          {primary === 'relink' && relink}
          {primary === 'reauth' && reauth}
          {primary === 'remove' && cancel}
          {primary === 'choose' && choose}
          {!gone && (
            <button
              type="button"
              onClick={() => onAction(connection)}
              aria-label={ar ? 'إجراءات المصدر' : 'Source actions'}
              data-testid={`hub-actions-${connection.id}`}
              className="shrink-0 rounded-lg p-1.5 text-text-secondary transition-colors hover:bg-surface-secondary hover:text-text-primary"
            >
              <MoreHorizontal size={18} />
            </button>
          )}
        </div>
      </div>

      {/* Under the row: its linked accounts, one per line — then the five statuses and who authorised it. */}
      <div id={panelId} hidden={!expanded} className="min-w-0 flex-col gap-3 border-t border-border bg-surface-secondary/40 px-3 py-3 [&:not([hidden])]:flex" data-testid={panelId}>
        {accounts.length > 0 && (
          <ul className="flex min-w-0 flex-col divide-y divide-border rounded-xl border border-border bg-surface" data-testid={`hub-account-list-${connection.id}`}>
            {sortBySource(accounts, (a) => ({ organisation: a.organisation, account: a.name }), ar).map((a) => {
              const accountSpend = projectId === null ? null : spend.of([a.id])
              return (
                <li key={a.id} className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 px-3 py-2" data-testid={`hub-account-${a.id}`}>
                  <ProviderMark provider={connection.provider} size={16} />
                  <span className="min-w-0 flex-1 basis-32 truncate text-xs font-semibold text-text-primary">
                    {a.organisation === null ? a.name : `${a.organisation} · ${a.name}`}
                  </span>
                  <span className="flex shrink-0 items-center gap-0.5 text-[11px] text-text-muted">
                    <span className="tnum" dir="ltr">{shortId(a.external_id)}</span>
                    <CopyId value={a.external_id} testId={`hub-account-id-${a.id}`} />
                  </span>
                  {a.campaigns !== undefined && (
                    <span className="tnum shrink-0 rounded-full bg-surface-secondary px-2 py-0.5 text-[11px] font-semibold text-text-secondary">{countedCampaigns(a.campaigns, ar ? 'ar' : 'en')}</span>
                  )}
                  {accountSpend !== null && <span className="tnum shrink-0 text-[11px] font-semibold text-text-primary" dir="ltr" data-testid={`hub-account-spend-${a.id}`}>{accountSpend}</span>}
                  {projectId !== null && !gone && (
                    <button
                      type="button"
                      onClick={() => setUnlinking({ id: a.id, name: a.name })}
                      data-testid={`hub-unlink-${a.id}`}
                      className="shrink-0 rounded-lg px-2 py-1 text-[11px] font-bold text-danger hover:bg-[var(--negative-background)]"
                    >
                      {ar ? 'فصل' : 'Unlink'}
                    </button>
                  )}
                </li>
              )
            })}
          </ul>
        )}

        {/*
          INTEG-UNIFIED-002 (§1.13) — FIVE chips, the server's own five statuses, none implying another.
          The two-chip pair is what an older response without `statuses` still gets.
        */}
        <div className="flex min-w-0 flex-wrap items-center gap-1.5">
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
        </div>

        <div className="flex min-w-0 flex-wrap gap-x-4 gap-y-1 text-[11px] text-text-secondary">
          {/* INTEG-PROVIDER-IDENTITY-001 — the PROVIDER login the grant was made with; never the workspace email. */}
          <span className="min-w-0 truncate" data-testid={`hub-platform-account-${connection.id}`}>
            {platformAccount === null
              ? (ar ? 'لم يُسجَّل حساب المنصة' : 'Platform account not recorded')
              : `${ar ? 'حساب المنصة' : 'Platform account'}: ${platformAccount}`}
          </span>
          <span className="min-w-0 truncate" data-testid={`hub-added-by-${connection.id}`}>
            {connection.authorised_by === null
              ? (ar ? 'لم يُسجَّل من أضافه' : 'Added by: not recorded')
              : `${ar ? 'أضافه' : 'Added by'}: ${connection.authorised_by.name}`}
          </span>
          <span className="flex gap-1">
            <span>{ar ? 'آخر مزامنة' : 'Last sync'}</span>
            <span className="tnum font-semibold text-text-primary">{connection.last_success_at === null ? (ar ? 'لم تتم' : 'None') : fmtDateTime(connection.last_success_at)}</span>
          </span>
          <span className="flex gap-1">
            <span>{ar ? 'التالية' : 'Next'}</span>
            {/* Null is «it is not going to», never «unknown» — the server only states a real one. */}
            <span className="tnum font-semibold text-text-primary">{connection.next_sync_at === null ? (ar ? 'متوقفة' : 'Paused') : fmtDateTime(connection.next_sync_at)}</span>
          </span>
        </div>

        {!gone && (
          <div className="flex min-w-0 flex-wrap items-center gap-1.5">
            {primary !== 'relink' && relink}
            {primary !== 'reauth' && reauth}
            {primary !== 'remove' && cancel}
            {primary !== 'choose' && choose}
            {removable && !abandoned && (
              <Button size="sm" variant="ghost" onClick={() => onRemove!(connection)} data-testid={`hub-detach-${connection.id}`}>
                <Unplug size={13} /> {ar ? 'إزالة المصدر' : 'Remove source'}
              </Button>
            )}
          </div>
        )}
      </div>

      {unlinking !== null && projectId !== null && (
        <UnlinkDialog projectId={projectId} account={unlinking} ar={ar} onClose={() => setUnlinking(null)} />
      )}
    </li>
  )
}

/** A long platform number is shown by its end — the part people read out — with the whole of it behind the copy icon. */
function shortId(id: string): string {
  return id.length > 10 ? `…${id.slice(-8)}` : id
}

/**
 * «فصل» — one account off THIS project, through the unlink endpoint the inventory already uses. Two
 * buttons and one line: what the reader fears losing stays, and the act can be undone by linking again.
 */
function UnlinkDialog({ projectId, account, ar, onClose }: {
  projectId: string
  account: { id: string; name: string }
  ar: boolean
  onClose: () => void
}) {
  const queryClient = useQueryClient()
  const unlink = useMutation({
    mutationFn: () => unlinkAccount(projectId, account.id),
    onSuccess: async () => {
      onClose()
      await Promise.all(['connection-hub', 'projects', 'metrics', 'integration-accounts', 'integration-estate', 'connection-hierarchy']
        .map((key) => queryClient.invalidateQueries({ queryKey: [key] })))
    },
  })

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-4 sm:items-center" role="dialog" aria-modal="true">
      <div className="w-full max-w-sm rounded-2xl border border-border bg-surface p-5 shadow-2xl" data-testid="unlink-account-dialog">
        <h2 className="truncate text-base font-bold text-text-primary">{ar ? `فصل ${account.name}؟` : `Unlink ${account.name}?`}</h2>
        <p className="mt-2 text-xs text-text-secondary">{ar ? 'يبقى السجل، ويمكن ربطه من جديد.' : 'Its history stays, and it can be linked again.'}</p>
        {unlink.isError && <p className="mt-2 text-xs text-danger" role="alert" data-testid="unlink-account-error">{ar ? 'تعذّر الفصل' : 'Could not unlink'}</p>}
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose} data-testid="unlink-account-keep">{ar ? 'إبقاء' : 'Keep'}</Button>
          <Button loading={unlink.isPending} onClick={() => unlink.mutate()} data-testid="unlink-account-confirm">{ar ? 'فصل' : 'Unlink'}</Button>
        </div>
      </div>
    </div>
  )
}

/**
 * INTEG-PROJECT-SPACE-001 — the project's four figures, on top of its space: linked accounts, campaigns,
 * the last 30 days of spend, and how many sources need an action. Figures only — a word and a number.
 */
export function HubSummary({ connections, campaigns = null, projectId = null }: { connections: HubConnection[]; campaigns?: number | null; projectId?: string | null }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const spend = useProjectSpend(projectId)
  const live = connections.filter((c) => !isGone(c))
  if (live.length === 0) return null

  const attention = live.filter((c) => isDisconnected(c) || sourceNeedsAction(c)).length
  const accounts = live.reduce((n, c) => n + c.selected_accounts, 0)
  // Withheld money is stated in its own currency and «no single figure» is a dash: never a zero.
  const reading = spend.total === null ? null : moneyFromTotals(spend.total, 'spend', ar, spend.currency)

  return (
    <section className="min-w-0" data-testid="hub-summary" aria-label={ar ? 'ملخص' : 'Summary'}>
      <StatGrid columns="grid-cols-2 sm:grid-cols-4">
        <StatCard label={ar ? 'الحسابات' : 'Accounts'} value={accounts.toLocaleString('en-US')} tone="brand" dot testid="hub-kpi-accounts" />
        <StatCard label={ar ? 'الحملات' : 'Campaigns'} value={campaigns === null ? '—' : campaigns.toLocaleString('en-US')} tone="neutral" dot testid="hub-kpi-campaigns" />
        <StatCard label={ar ? 'إنفاق 30 يومًا' : '30-day spend'} value={<span dir="ltr">{reading === null ? '—' : reading.text}</span>} exact={reading?.exact ?? undefined} tone="neutral" dot testid="hub-kpi-spend" />
        <StatCard label={ar ? 'تحتاج إجراء' : 'Need action'} value={attention.toLocaleString('en-US')} tone={attention > 0 ? 'danger' : 'success'} dot testid="hub-kpi-attention" />
      </StatGrid>
    </section>
  )
}
