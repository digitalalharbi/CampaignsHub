import { useState } from 'react'
import { Bell, Check, CheckCheck, Clock, TriangleAlert, Info, OctagonAlert } from 'lucide-react'
import { useNotifications, useNotificationActions, type AppNotification } from './api'
import { useProject } from '@/stores/project'

const SEV: Record<string, { icon: typeof Info; cls: string }> = {
  info: { icon: Info, cls: 'text-info' },
  warning: { icon: TriangleAlert, cls: 'text-warning' },
  critical: { icon: OctagonAlert, cls: 'text-danger' },
}

/** Header notification centre — unread badge + dropdown with mark-read / resolve / snooze. */
export function NotificationBell() {
  const projectId = useProject((s) => s.currentProjectId)
  const [open, setOpen] = useState(false)
  const { data } = useNotifications(projectId)
  const a = useNotificationActions(projectId)

  const items = (data ?? []).filter((n) => n.status !== 'resolved' && n.status !== 'snoozed')
  const unread = items.filter((n) => n.status === 'unread').length

  return (
    <div className="relative">
      <button
        aria-label="Notifications"
        onClick={() => setOpen((v) => !v)}
        className="relative flex h-9 w-9 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover"
      >
        <Bell size={18} />
        {unread > 0 && (
          <span className="tnum absolute -end-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-bold text-white ring-2 ring-surface">{unread > 9 ? '9+' : unread}</span>
        )}
      </button>

      {open && (
        <>
          <div className="fixed inset-0 z-40" onClick={() => setOpen(false)} />
          <div className="absolute end-0 top-11 z-50 w-[340px] overflow-hidden rounded-2xl border border-border bg-surface shadow-[var(--shadow-large)]">
            <div className="flex items-center justify-between border-b border-border px-4 py-2.5">
              <span className="text-sm font-bold text-text-primary">الإشعارات</span>
              {unread > 0 && (
                <button onClick={() => a.markAllRead.mutate()} className="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 hover:underline">
                  <CheckCheck size={13} /> تعليم الكل كمقروء
                </button>
              )}
            </div>
            <div className="max-h-[380px] overflow-y-auto">
              {items.length === 0 ? (
                <p className="px-4 py-8 text-center text-sm text-text-muted">لا إشعارات جديدة.</p>
              ) : (
                items.map((n: AppNotification) => {
                  const s = SEV[n.severity] ?? SEV.info
                  const Icon = s.icon
                  return (
                    <div key={n.id} className={`border-b border-border px-4 py-2.5 last:border-0 ${n.status === 'unread' ? 'bg-surface-secondary/60' : ''}`}>
                      <div className="flex items-start gap-2.5">
                        <Icon size={16} className={`mt-0.5 shrink-0 ${s.cls}`} />
                        <div className="min-w-0 flex-1">
                          <div className="text-sm font-semibold text-text-primary">{n.title}</div>
                          {n.message && <div className="mt-0.5 text-xs leading-relaxed text-text-secondary">{n.message}</div>}
                          <div className="mt-1.5 flex gap-3 text-[11px]">
                            {n.status === 'unread' && <button onClick={() => a.markRead.mutate(n.id)} className="inline-flex items-center gap-1 font-semibold text-text-secondary hover:text-text-primary"><Check size={12} /> مقروء</button>}
                            <button onClick={() => a.resolve.mutate(n.id)} className="inline-flex items-center gap-1 font-semibold text-success hover:underline"><Check size={12} /> حلّ</button>
                            <button onClick={() => a.snooze.mutate(n.id)} className="inline-flex items-center gap-1 font-semibold text-text-muted hover:text-text-primary"><Clock size={12} /> تأجيل</button>
                          </div>
                        </div>
                      </div>
                    </div>
                  )
                })
              )}
            </div>
          </div>
        </>
      )}
    </div>
  )
}
