import { useQuery } from '@tanstack/react-query'
import { Bell } from 'lucide-react'
import { Card, CardTitle, CardDescription } from '@/components/ui/Card'
import { ErrorState, Skeleton, EmptyState } from '@/components/ui/States'
import { useT } from '@/lib/i18n'
import { useProject } from '@/stores/project'
import { getData } from '@/lib/api/client'

interface AppNotification {
  id: string
  title: string
  message: string
  read_at: string | null
  created_at: string
}

export function NotificationsPage() {
  const t = useT()
  const { currentProjectId } = useProject()

  const query = useQuery({
    queryKey: ['project', currentProjectId, 'notifications'],
    queryFn: () => getData<AppNotification[]>(`/projects/${currentProjectId}/notifications`),
    enabled: Boolean(currentProjectId),
  })

  if (!currentProjectId) {
    return <EmptyState title={t('no_project_selected')} />
  }

  if (query.isLoading) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-20 w-full" />
        <Skeleton className="h-20 w-full" />
      </div>
    )
  }

  if (query.isError) {
    return <ErrorState title={t('error')} onRetry={() => query.refetch()} />
  }

  const notifications = query.data ?? []

  return (
    <section className="space-y-6">
      <div>
        <h1 className="font-[var(--font-heading)] text-xl font-extrabold">{t('notifications') || 'Notifications'}</h1>
        <p className="mt-1 text-[13px] text-text-secondary">Project alerts and system messages.</p>
      </div>

      {notifications.length === 0 ? (
        <EmptyState title="No Notifications" description="You're all caught up!" />
      ) : (
        <div className="grid gap-3">
          {notifications.map((n) => (
            <Card key={n.id} className={`flex items-start gap-4 p-4 ${!n.read_at ? 'border-brand-500 bg-brand-50/30' : ''}`}>
              <div className="mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-100 text-brand-600">
                <Bell size={16} />
              </div>
              <div className="min-w-0 flex-1">
                <div className="flex items-start justify-between gap-2">
                  <div className="text-sm"><CardTitle>{n.title}</CardTitle></div>
                  <span className="shrink-0 text-[11px] text-text-muted">
                    {new Date(n.created_at).toLocaleDateString()}
                  </span>
                </div>
                <div className="mt-1"><CardDescription>{n.message}</CardDescription></div>
              </div>
            </Card>
          ))}
        </div>
      )}
    </section>
  )
}
