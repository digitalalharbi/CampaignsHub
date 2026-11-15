import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { usePortalPath } from '@/app/portalPath'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell, CheckCheck, ExternalLink } from 'lucide-react'
import { BellVisual } from './BellVisual'
import { getNotificationSummary, listNotifications, markAllNotificationsRead, markNotificationRead, type AppNotification, type BellFilter } from './api'
import { badgeText, FILTERS } from './bellFormat'
import { useBellSound } from './useBellSound'
import { usePushRefresh } from './pushRefresh'
import {
  bucketLabel,
  groupByTime,
  notificationScope,
  scopeLabel,
  severityLabel,
  severityTone,
} from './notificationPresentation'
import { TYPE_LABELS, words } from '@/features/settings/messageLabels'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'

/*
 * UX-NOTIFICATION-CARD-001 — severity as a RAIL, and it survives being read.
 *
 * The previous dot was set to `bg-transparent` once a row was read, so a read critical alert and a
 * read info note were the same object on screen: the severity a system took the trouble to record
 * lasted exactly until somebody looked at it. An operator scanning yesterday's list could not tell a
 * failed sync from a finished report, which is that list's entire job.
 *
 * A rail rather than a dot because it reads at a glance down a column of twelve, and it keeps its
 * colour after reading. What `read` costs a row is EMPHASIS — the background tint and the bolder
 * title — not its identity.
 */
const SEVERITY_RAIL: Record<'info' | 'success' | 'warning' | 'danger', string> = {
  info: 'bg-info', success: 'bg-success', warning: 'bg-warning', danger: 'bg-danger',
}

const SEVERITY_CHIP: Record<'info' | 'success' | 'warning' | 'danger', string> = {
  info: 'text-info',
  success: 'text-success',
  warning: 'text-warning',
  danger: 'text-danger',
}

/**
 * The type's name in the reader's language, or null when the product has no name for it.
 *
 * Null rather than a guess: an unknown type keeps the server's own title, which is a real sentence
 * about a real event, and is better than a blank or a database key. `TYPE_LABELS` is the same map
 * the settings screen decides from, and `EveryMessageTypeIsNamedTest` keeps it in step with
 * `MessageCatalogue::keys()` — so a type that reaches here unnamed is a type nobody could subscribe
 * to either, and that test fails first.
 */
function named(type: string, ar: boolean): string | null {
  return TYPE_LABELS[type] ? words(TYPE_LABELS, type, ar) : null
}

/**
 * NOTIF-BELL-001 — the row's heading is what the finding REALLY is.
 *
 * `kind_label` comes from the server, derived from the alert's own type and figures. `type` is only the preference category and
 * five different findings share `budget_risk`, so it must never name a row: a budget_risk row the server could not name is called
 * «تنبيه الميزانية», not «تجاوز حدود الميزانية». The server's sentence is kept beneath as the specifics.
 */
function heading(n: AppNotification, ar: boolean): string {
  if (n.kind_label) return n.kind_label
  if (n.type === 'budget_risk') return ar ? 'تنبيه الميزانية' : 'Budget alert'

  return named(n.type, ar) ?? n.title
}

function detail(n: AppNotification, ar: boolean): string | null {
  const h = heading(n, ar)
  const line = n.message ?? (h === n.title ? null : n.title)

  return line && line !== h ? line : null
}

export function NotificationCenter() {
  const t = useT()
  const ar = useUi((s) => s.locale) === 'ar'
  const navigate = useNavigate()
  const portalPath = usePortalPath()
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  const [filter, setFilter] = useState<BellFilter>('all')

  /*
   * NOTIF-BELL-001 — the number is live and the list is lazy.
   *
   * The bell asks a tiny summary every 30 s, only while the tab is visible (TanStack pauses interval refetching in a hidden tab
   * unless told otherwise) and again the moment the tab regains focus. The 100-row list is fetched only while the panel is open.
   * That is the whole realtime mechanism: no socket, no per-second polling, and a pushed message from the service worker asks
   * for the same refetch (see `pushRefresh.ts`).
   */
  const summary = useQuery({ queryKey: ['notifications', 'summary'], queryFn: getNotificationSummary, refetchInterval: 30_000, refetchOnWindowFocus: true, staleTime: 0 })
  useBellSound(summary.data)
  usePushRefresh()
  const q = useQuery({ queryKey: ['notifications', 'list', filter], queryFn: () => listNotifications(filter), enabled: open, refetchInterval: open ? 30_000 : false })
  const refresh = () => qc.invalidateQueries({ queryKey: ['notifications'] })
  const markRead = useMutation({ meta: { feedback: 'none' }, mutationFn: markNotificationRead, onSuccess: refresh })
  const markAll = useMutation({
    meta: { feedback: 'none' },
    mutationFn: markAllNotificationsRead,
    onSuccess: () => {
      // The server cleared EVERYTHING for this reader; show it at once instead of waiting for the next poll.
      qc.setQueryData(['notifications', 'summary'], (old: { unread: number; attention: unknown[] } | undefined) => (old ? { ...old, unread: 0, attention: [] } : old))
      void refresh()
    },
  })

  useEffect(() => {
    if (!open) return
    const onClick = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', onClick)
    return () => document.removeEventListener('mousedown', onClick)
  }, [open])

  // The summary is the number of record; the list's own figure is the fallback until the first summary lands.
  const unread = summary.data?.unread ?? q.data?.unread ?? 0
  const items = q.data?.items ?? []
  const withheld = q.data?.withheld ?? 0
  const total = q.data?.total ?? items.length

  const openItem = (n: AppNotification) => {
    if (n.status === 'unread') markRead.mutate(n.id)
    // Resolved against the CURRENT portal (REG-011): a notification is written once for a tenant
    // whose readers may be in different portals, so it names a section rather than a full path.
    // An action_url that already names a portal is returned untouched, so older rows still work.
    const target = n.open_url ?? n.action_url
    if (target) { setOpen(false); navigate(portalPath(target)) }
  }

  return (
    <div className="relative" ref={ref}>
      <button
        aria-label={t('nc_title')}
        onClick={() => setOpen((v) => !v)}
        className="relative flex h-11 w-11 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover sm:h-9 sm:w-9"
      >
        <Bell size={18} />
        {unread > 0 && (
          <span className="absolute -end-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-500 px-1 text-[10px] font-bold text-white ring-2 ring-surface"><span data-testid="bell-badge" dir="ltr" className="tnum">{badgeText(unread)}</span></span>
        )}
      </button>

      {open && (
        <div className="absolute end-0 z-50 mt-2 w-80 overflow-hidden rounded-xl border border-border bg-surface shadow-lg">
          <div className="flex items-center justify-between border-b border-border px-3 py-2">
            <span className="text-sm font-bold text-text-primary">{t('nc_title')}{unread > 0 ? ` · ${unread} ${t('nc_unread')}` : ''}</span>
            {unread > 0 && (
              <button onClick={() => markAll.mutate()} className="flex items-center gap-1 text-xs font-semibold text-brand-600 hover:text-brand-700"><CheckCheck size={13} /> {t('nc_mark_all')}</button>
            )}
          </div>
          <div role="tablist" aria-label={t('nc_title')} className="flex gap-1 overflow-x-auto border-b border-border px-2 py-1.5">
            {FILTERS.map((f) => (
              <button
                key={f.key}
                role="tab"
                aria-selected={filter === f.key}
                data-testid={`bell-filter-${f.key}`}
                onClick={() => setFilter(f.key)}
                className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold transition-colors ${filter === f.key ? 'bg-brand-500 text-white' : 'bg-surface-secondary text-text-secondary hover:bg-surface-hover'}`}
              >
                {ar ? f.ar : f.en}
              </button>
            ))}
          </div>
          <div className="max-h-80 overflow-y-auto">
            {items.length === 0 ? (
              <p className="p-6 text-center text-sm text-text-muted">{t('nc_empty')}</p>
            ) : (
              <ul>
                {/*
                  Grouped by day — the axis a reader actually scans.

                  Every row previously carried a full timestamp, so a list of twelve showed twelve
                  near-identical strings and the reader compared them character by character to find
                  what was new. A heading answers that at a glance; the exact stamp stays on hover,
                  where it costs nothing.
                */}
                {groupByTime(items).map((group) => (
                  <li key={group.bucket}>
                    <p className="sticky top-0 z-10 bg-surface/95 px-3 py-1.5 text-[11px] font-bold uppercase tracking-wide text-text-muted backdrop-blur">
                      {bucketLabel(group.bucket, ar)}
                    </p>
                    <ul>
                      {group.items.map((n) => {
                        const tone = severityTone(n.severity)
                        const scope = notificationScope(n)
                        const isUnread = n.status === 'unread'

                        return (
                          <li key={n.id} className="relative">
                            <button
                              data-testid={`notification-${n.id}`}
                              data-kind={n.kind ?? ''}
                              data-severity={tone}
                              data-scope={scope}
                              onClick={() => openItem(n)}
                              className={`relative flex w-full items-start gap-2.5 border-b border-border/60 py-2.5 pe-12 ps-4 text-start transition-colors last:border-0 hover:bg-surface-secondary ${isUnread ? 'bg-brand-primary-soft/40' : ''}`}
                            >
                              {/* The rail keeps its colour whether the row has been read or not. */}
                              <span
                                aria-hidden
                                className={`absolute bottom-0 start-0 top-0 w-1 ${SEVERITY_RAIL[tone]} ${isUnread ? '' : 'opacity-50'}`}
                              />
                              <span className="min-w-0 flex-1">
                                {/*
                                  The TITLE in the reader's language.
                                  
                                  Every notification's title is composed on the server as one English
                                  string — «New client message», «Client needs attention: Acme» — so
                                  an Arabic panel, whose own heading and chips are Arabic, listed
                                  English sentences. `messageLabels` already holds the Arabic name of
                                  every type for the settings screen, and a guard keeps that map in
                                  step with `MessageCatalogue::keys()`, so the name exists; the panel
                                  simply never asked for it.

                                  The server's own string is not thrown away. It carries the
                                  specifics — WHICH client needs attention — so when there is no
                                  message beneath, it takes that line instead of being lost.
                                */}
                                <span
                                  data-testid="notification-title"
                                  title={n.title}
                                  className={`block truncate text-sm text-text-primary ${isUnread ? 'font-bold' : 'font-semibold'}`}
                                >
                                  {heading(n, ar)}
                                </span>
                                {detail(n, ar) && (
                                  <span className="block truncate text-xs text-text-secondary" title={detail(n, ar) ?? undefined}>
                                    {detail(n, ar)}
                                  </span>
                                )}
                                <BellVisual visual={n.visual} ar={ar} />
                                <span className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-text-muted">
                                  {/*
                                    Scope, said rather than inferred. A notification is written for a
                                    tenant whose readers work in different scopes, and `project_id === null`
                                    means the whole portfolio — a distinction this product refuses to
                                    blur everywhere else and the centre simply did not show.
                                  */}
                                  <span className="rounded-full bg-surface-secondary px-1.5 py-0.5 font-semibold">
                                    {scopeLabel(scope, ar)}
                                  </span>
                                  <span className={`font-semibold ${SEVERITY_CHIP[tone]}`}>{severityLabel(n.severity, ar)}</span>
                                  {(n.repeat_count ?? 1) > 1 && (
                                    <span data-testid="notification-repeat" dir="ltr" className="tnum rounded-full bg-surface-secondary px-1.5 py-0.5 font-semibold" title={ar ? 'تكرّر اليوم' : 'Raised again today'}>
                                      ×{n.repeat_count}
                                    </span>
                                  )}
                                  {n.created_at && (
                                    <span dir="ltr" className="tnum" title={new Date(n.created_at).toLocaleString('en-CA')}>
                                      {new Date(n.created_at).toLocaleTimeString('en-CA', { hour: '2-digit', minute: '2-digit' })}
                                    </span>
                                  )}
                                </span>
                              </span>
                            </button>
                            {(n.open_url ?? n.action_url) && (
                              <button
                                type="button"
                                data-testid={`notification-open-${n.id}`}
                                aria-label={ar ? 'افتح' : 'Open'}
                                title={ar ? 'افتح' : 'Open'}
                                onClick={() => openItem(n)}
                                className="absolute end-2 top-2 flex h-8 w-8 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover hover:text-brand-600"
                              >
                                <ExternalLink size={15} />
                              </button>
                            )}
                          </li>
                        )
                      })}
                    </ul>
                  </li>
                ))}
                {/*
                  OPS-LEDGER-001 — the centre says how much of itself it is showing.
                  
                  The bound is right: nobody scrolls three hundred notifications in a dropdown. What
                  was wrong is that `unread` was the only figure on screen, and an unread count says
                  nothing about whether the list is complete.
                */}
                {withheld > 0 && (
                  <li
                    data-testid="notification-centre-bounded"
                    className="border-t border-border/60 px-3 py-2 text-center text-[11px] text-text-muted"
                  >
                    {ar
                      ? `تُعرض ${items.length} من ${total} إشعارًا — الأقدم غير معروضة.`
                      : `Showing ${items.length} of ${total} — the oldest are not listed.`}
                  </li>
                )}
              </ul>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
