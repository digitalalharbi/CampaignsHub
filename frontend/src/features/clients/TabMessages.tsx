import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { MessagesSquare } from 'lucide-react'
import { listThreads } from '@/features/messaging/api'
import { formatDateTime } from '@/features/messaging/api'
import { useUi } from '@/stores/ui'
import { usePortalPath } from '@/app/portalPath'

/** Client conversations tab — the team-inbox threads that belong to this client workspace. */
export function TabMessages({ clientId }: { clientId: string }) {
  const portalTo = usePortalPath()
  const ar = useUi((s) => s.locale) === 'ar'
  const q = useQuery({ queryKey: ['messaging', 'threads', 'all'], queryFn: () => listThreads() })
  const threads = (q.data ?? []).filter((t) => t.client_workspace_id === clientId)

  if (q.isLoading) return <div className="h-40 animate-pulse rounded-xl bg-surface-secondary" />
  if (q.isError) return <p className="rounded-xl border border-danger/30 bg-danger/5 p-6 text-center text-sm text-danger">{ar ? 'تعذّر تحميل المحادثات.' : 'Could not load conversations.'}</p>

  return threads.length === 0 ? (
    <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border p-10 text-center text-text-secondary">
      <MessagesSquare size={22} /><span className="text-sm">{ar ? 'لا محادثات مرتبطة بهذا العميل بعد.' : 'No conversations linked to this client yet.'}</span>
    </div>
  ) : (
    <ul className="space-y-2 text-sm">
      {threads.map((t) => (
        <li key={t.id}>
          <Link to={portalTo('/messages')} className="flex items-center justify-between gap-3 rounded-xl border border-border p-3 hover:border-brand-400">
            <span className="line-clamp-1 font-semibold text-text-primary">{t.subject}</span>
            <span className="flex shrink-0 items-center gap-2">
              <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${t.status === 'open' ? 'bg-info/15 text-info' : 'bg-surface-hover text-text-secondary'}`}>
                {t.status === 'open' ? (ar ? 'مفتوحة' : 'Open') : (ar ? 'مغلقة' : 'Closed')}
              </span>
              <span className="tnum text-[11px] text-text-tertiary" dir="ltr">{formatDateTime(t.last_message_at)}</span>
            </span>
          </Link>
        </li>
      ))}
    </ul>
  )
}
