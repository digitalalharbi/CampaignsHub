import { useQuery } from '@tanstack/react-query'
import { MoreHorizontal, Plus } from 'lucide-react'
import { fetchConnectionHub, type HubConnection } from './api'
import { CONNECTION_COPY, SYNC_COPY } from './connectionCopy'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { PageIntro } from '@/components/ui/PageIntro'
import { StatCard } from '@/components/ui/StatCard'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { Num } from '@/components/ui/Num'
import { platformColor } from '@/features/analytics/components'
import { adAccounts } from '@/lib/counted'
import { fmtDateTime } from '@/lib/datetime'
import { useUi } from '@/stores/ui'

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
export function ConnectionHub({ onConnect, onOpen, onAction }: {
  onConnect: () => void
  onOpen: (connection: HubConnection, tab?: 'overview' | 'accounts') => void
  onAction: (connection: HubConnection) => void
}) {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const hub = useQuery({ queryKey: ['connection-hub'], queryFn: fetchConnectionHub })

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

  const connections = hub.data?.connections ?? []
  /*
   * The hub's own three figures. Counted from the rows it is about to draw, so the head and the
   * list cannot disagree — and «need reconnecting» counts the authorisations that cannot work at
   * all, which is the one number somebody opening this page is looking for.
   */
  const needReauth = connections.filter(
    (c) => c.connection_state === 'REAUTH_REQUIRED' || c.connection_state === 'REVOKED',
  ).length
  const selectedAccounts = connections.reduce((n, c) => n + c.selected_accounts, 0)
  const discoveredAccounts = connections.reduce((n, c) => n + c.discovered_accounts, 0)

  return (
    <section className="flex flex-col gap-4" data-testid="connection-hub">
      {/*
        UX-PAGE-HERO-001 — the hub was the ONE rail surface with no shared head.

        Found by sweeping the advertiser portal: twelve routes drew exactly one `PageIntro` and
        `/app/integrations` drew none, because this component wrote its own `<h2>` when it replaced
        the provider grid. The rows below are untouched — what changes is that the page now answers
        «where am I» in the same place and at the same size as every other surface.

        Three figures, and they are the hub's own question rather than a repeat of any row:
        how many authorisations exist, how many cannot work until somebody re-authorises, and how
        many accounts are actually being read across all of them.
      */}
      <PageIntro
        testid="integrations-intro"
        title={ar ? 'مركز التكاملات' : 'Connection Hub'}
        purpose={ar
          ? 'كل مصدر بيانات مربوط، ومن صرّح به، وما يُزامَن منه.'
          : 'Every connected source, who authorised it, and what it reads.'}
        actions={
          <Button onClick={onConnect} data-testid="hub-connect">
            <Plus size={15} /> {ar ? 'ربط مصدر' : 'Connect a source'}
          </Button>
        }
        kpis={connections.length === 0 ? undefined : (
          <>
            <StatCard
              label={ar ? 'المصادر' : 'Sources'}
              value={connections.length.toLocaleString('en-US')}
              tone="brand"
              dot
              testid="hub-kpi-sources"
            />
            <StatCard
              label={ar ? 'تحتاج إعادة مصادقة' : 'Need reconnecting'}
              value={needReauth.toLocaleString('en-US')}
              tone={needReauth > 0 ? 'danger' : 'success'}
              dot
              testid="hub-kpi-reauth"
            />
            <StatCard
              label={ar ? 'الحسابات المختارة' : 'Selected accounts'}
              value={selectedAccounts.toLocaleString('en-US')}
              hint={`${ar ? 'من' : 'of'} ${adAccounts(discoveredAccounts, locale)}`}
              tone="neutral"
              dot
              testid="hub-kpi-accounts"
            />
          </>
        )}
      />

      {connections.length === 0 ? (
        <div
          className="rounded-2xl border border-dashed border-border px-6 py-10 text-center"
          data-testid="connection-hub-empty"
        >
          <p className="text-sm font-semibold text-text-primary">
            {ar ? 'لا يوجد مصدر مربوط بعد' : 'Nothing is connected yet'}
          </p>
          <p className="mx-auto mt-1 max-w-sm text-xs text-text-secondary">
            {ar
              ? 'اربط حساب منصتك الإعلانية، ثم اختر الحسابات التي تخصّ كل عميل. لن يُزامَن أي حساب لم تختره.'
              : 'Authorise an ad platform, then choose which accounts belong to each client. Nothing you do not choose is ever synced.'}
          </p>
          <Button className="mt-4" onClick={onConnect} data-testid="hub-connect-empty">
            <Plus size={15} /> {ar ? 'ربط مصدر' : 'Connect a source'}
          </Button>
        </div>
      ) : (
        /*
          A list of rows, not a `<table>`: the same row has to become a stacked block on a phone, and
          a table that reflows into blocks stops being a table for assistive technology halfway down.
        */
        <ul className="flex flex-col divide-y divide-border overflow-hidden rounded-2xl border border-border bg-surface">
          {connections.map((connection) => (
            <ConnectionRow
              key={connection.id}
              connection={connection}
              ar={ar}
              onOpen={onOpen}
              onAction={onAction}
            />
          ))}
        </ul>
      )}
    </section>
  )
}

function ConnectionRow({ connection, ar, onOpen, onAction }: {
  connection: HubConnection
  ar: boolean
  onOpen: (connection: HubConnection, tab?: 'overview' | 'accounts') => void
  onAction: (connection: HubConnection) => void
}) {
  const auth = CONNECTION_COPY[connection.connection_state]
  const data = SYNC_COPY[connection.sync_state]

  return (
    <li
      className="group flex flex-col gap-3 p-4 transition-colors hover:bg-surface-hover sm:flex-row sm:items-center sm:gap-4"
      data-testid={`hub-row-${connection.id}`}
      data-provider={connection.provider}
    >
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
          <span className="block truncate text-xs text-text-secondary">
            {connection.authorised_by === null
              ? (ar ? 'لم يُسجَّل من صرّح بالربط' : 'Authoriser not recorded')
              : `${ar ? 'صرّح به' : 'Authorised by'} ${connection.authorised_by.email}`}
          </span>
        </span>
      </button>

        <button
          type="button"
          onClick={() => onAction(connection)}
          aria-label={ar ? 'إجراءات المصدر' : 'Source actions'}
          data-testid={`hub-actions-${connection.id}`}
          className="shrink-0 rounded-lg p-2 text-text-secondary transition-colors hover:bg-surface-secondary hover:text-text-primary sm:hidden"
        >
          <MoreHorizontal size={18} />
        </button>
      </div>

      {/*
        «1 of 17 accounts» is a CONTROL, not a label: it is the shortest path to the thing a reader
        wants after reading it, which is the list of the seventeen.
      */}
      <button
        type="button"
        onClick={() => onOpen(connection, 'accounts')}
        data-testid={`hub-accounts-${connection.id}`}
        className="inline-flex w-fit items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-text-primary underline-offset-4 hover:bg-surface-secondary hover:underline"
      >
        {ar ? (
          <><Num>{connection.selected_accounts}</Num> من <Num>{connection.discovered_accounts}</Num> حساب</>
        ) : (
          <><Num>{connection.selected_accounts}</Num> of <Num>{connection.discovered_accounts}</Num> accounts</>
        )}
      </button>

      <div className="flex flex-wrap items-center gap-1.5">
        <Badge tone={auth.tone} data-testid={`hub-auth-${connection.id}`}>{ar ? auth.ar : auth.en}</Badge>
        <Badge tone={data.tone} data-testid={`hub-sync-${connection.id}`}>{ar ? data.ar : data.en}</Badge>

        {/*
          An authorisation nobody finished, with the way to finish it ON the row.
          
          Consent completed, catalogue full, and not one account chosen — so nothing syncs and the
          page otherwise looks complete. It used to be a banner above the cards describing one such
          connection and naming no provider; here it is the row's own state, and the way back is the
          control beside it rather than a menu a reader has to think to open.
        */}
        {connection.selected_accounts === 0 && (
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

      {/*
        Both times, small and tabular. «Last» without «next» leaves a reader who sees an old
        timestamp unable to tell a broken integration from one merely between runs — the question
        that used to be answered by pressing «Sync now» for reassurance.
      */}
      <dl className="flex shrink-0 flex-col gap-0.5 text-[11px] text-text-secondary sm:w-44 sm:text-end">
        <div className="flex gap-1 sm:justify-end">
          <dt>{ar ? 'آخر مزامنة' : 'Last sync'}</dt>
          <dd className="tnum font-semibold text-text-primary">
            {connection.last_success_at === null
              ? (ar ? 'لم تتم' : 'None')
              : fmtDateTime(connection.last_success_at)}
          </dd>
        </div>
        <div className="flex gap-1 sm:justify-end">
          <dt>{ar ? 'التالية' : 'Next'}</dt>
          <dd className="tnum font-semibold text-text-primary">
            {/* Null is «it is not going to», never «unknown» — the server only states a real one. */}
            {connection.next_sync_at === null
              ? (ar ? 'متوقفة' : 'Paused')
              : fmtDateTime(connection.next_sync_at)}
          </dd>
        </div>
      </dl>

      {/* The same control at desktop width, where it belongs at the end of the row instead. */}
      <button
        type="button"
        onClick={() => onAction(connection)}
        aria-label={ar ? 'إجراءات المصدر' : 'Source actions'}
        data-testid={`hub-actions-desktop-${connection.id}`}
        className="hidden shrink-0 rounded-lg p-2 text-text-secondary transition-colors hover:bg-surface-secondary hover:text-text-primary sm:block"
      >
        <MoreHorizontal size={18} />
      </button>
    </li>
  )
}
