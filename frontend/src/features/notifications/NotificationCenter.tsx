import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { usePortalPath } from '@/app/portalPath'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell, CheckCheck } from 'lucide-react'
import { listNotifications, markAllNotificationsRead, markNotificationRead, type AppNotification } from './api'
import {
  bucketLabel,
  groupByTime,
  notificationScope,
  scopeLabel,
  severityLabel,
  severityTone,
} from './notificationPresentation'
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

export function NotificationCenter() {
  const t = useT()
  const ar = useUi((s) => s.locale) === 'ar'
  const navigate = useNavigate()
  const portalPath = usePortalPath()
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  const q = useQuery({ queryKey: ['notifications'], queryFn: listNotifications, refetchInterval: 60_000 })
  const markRead = useMutation({ mutationFn: markNotificationRead, onSuccess: () => qc.invalidateQueries({ queryKey: ['notifications'] }) })
  const markAll = useMutation({ mutationFn: markAllNotificationsRead, onSuccess: () => qc.invalidateQueries({ queryKey: ['notifications'] }) })

  useEffect(() => {
    if (!open) return
    const onClick = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', onClick)
    return () => document.removeEventListener('mousedown', onClick)
  }, [open])

  const unread = q.data?.unread ?? 0
  const items = q.data?.items ?? []
  const withheld = q.data?.withheld ?? 0
  const total = q.data?.total ?? items.length

  const openItem = (n: AppNotification) => {
    if (n.status === 'unread') markRead.mutate(n.id)
    // Resolved against the CURRENT portal (REG-011): a notification is written once for a tenant
    // whose readers may be in different portals, so it names a section rather than a full path.
    // An action_url that already names a portal is returned untouched, so older rows still work.
    if (n.action_url) { setOpen(false); navigate(portalPath(n.action_url)) }
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
          <span className="absolute -end-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-500 px-1 text-[10px] font-bold text-white ring-2 ring-surface">{unread > 9 ? '9+' : unread}</span>
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
                          <li key={n.id}>
                            <button
                              data-testid={`notification-${n.id}`}
                              data-severity={tone}
                              data-scope={scope}
                              onClick={() => openItem(n)}
                              className={`relative flex w-full items-start gap-2.5 border-b border-border/60 py-2.5 pe-3 ps-4 text-start transition-colors last:border-0 hover:bg-surface-secondary ${isUnread ? 'bg-brand-primary-soft/40' : ''}`}
                            >
                              {/* The rail keeps its colour whether the row has been read or not. */}
                              <span
                                aria-hidden
                                className={`absolute bottom-0 start-0 top-0 w-1 ${SEVERITY_RAIL[tone]} ${isUnread ? '' : 'opacity-50'}`}
                              />
                              <span className="min-w-0 flex-1">
                                <span className={`block truncate text-sm text-text-primary ${isUnread ? 'font-bold' : 'font-semibold'}`}>
                                  {n.title}
                                </span>
                                {n.message && <span className="block truncate text-xs text-text-secondary">{n.message}</span>}
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
                                  {n.created_at && (
                                    <span dir="ltr" className="tnum" title={new Date(n.created_at).toLocaleString('en-CA')}>
                                      {new Date(n.created_at).toLocaleTimeString('en-CA', { hour: '2-digit', minute: '2-digit' })}
                                    </span>
                                  )}
                                </span>
                              </span>
                            </button>
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
