import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell, CheckCheck } from 'lucide-react'
import { listNotifications, markAllNotificationsRead, markNotificationRead, type AppNotification } from './api'
import { useT } from '@/lib/i18n'

const severityDot: Record<AppNotification['severity'], string> = {
  info: 'bg-info', success: 'bg-success', warning: 'bg-warning', critical: 'bg-danger',
}

export function NotificationCenter() {
  const t = useT()
  const navigate = useNavigate()
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

  const openItem = (n: AppNotification) => {
    if (n.status === 'unread') markRead.mutate(n.id)
    if (n.action_url) { setOpen(false); navigate(n.action_url) }
  }

  return (
    <div className="relative" ref={ref}>
      <button
        aria-label={t('nc_title')}
        onClick={() => setOpen((v) => !v)}
        className="relative flex h-9 w-9 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover"
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
                {items.map((n) => (
                  <li key={n.id}>
                    <button onClick={() => openItem(n)} className={`flex w-full items-start gap-2.5 border-b border-border/60 px-3 py-2.5 text-start last:border-0 hover:bg-surface-secondary ${n.status === 'unread' ? 'bg-brand-primary-soft/40' : ''}`}>
                      <span className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${n.status === 'unread' ? severityDot[n.severity] : 'bg-transparent'}`} />
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-semibold text-text-primary">{n.title}</span>
                        {n.message && <span className="block truncate text-xs text-text-secondary">{n.message}</span>}
                        <span className="mt-0.5 block text-[11px] text-text-muted">{n.created_at ? new Date(n.created_at).toLocaleString('en-CA') : ''}</span>
                      </span>
                    </button>
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
