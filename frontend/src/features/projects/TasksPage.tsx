import { useQuery } from '@tanstack/react-query'
import { CheckCircle2, Circle, Clock } from 'lucide-react'
import { Card, CardTitle, CardDescription } from '@/components/ui/Card'
import { ErrorState, Skeleton, EmptyState } from '@/components/ui/States'
import { Badge } from '@/components/ui/Badge'
import { useT } from '@/lib/i18n'
import { useProject } from '@/stores/project'
import { getData } from '@/lib/api/client'

interface Task {
  id: string
  title: string
  description: string | null
  status: 'todo' | 'in_progress' | 'done'
  due_date: string | null
}

export function TasksPage() {
  const t = useT()
  const { currentProjectId } = useProject()

  const query = useQuery({
    queryKey: ['project', currentProjectId, 'tasks'],
    queryFn: () => getData<Task[]>(`/projects/${currentProjectId}/tasks`),
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

  const tasks = query.data ?? []

  return (
    <section className="space-y-6">
      <div>
        <h1 className="font-[var(--font-heading)] text-xl font-extrabold">{t('tasks') || 'Tasks'}</h1>
        <p className="mt-1 text-[13px] text-text-secondary">Manage and track project tasks.</p>
      </div>

      {tasks.length === 0 ? (
        <EmptyState title="No Tasks" description="There are no tasks assigned to this project yet." />
      ) : (
        <div className="grid gap-3">
          {tasks.map((task) => (
            <Card key={task.id} className="flex items-start justify-between p-4 transition-colors hover:border-brand-500">
              <div className="flex gap-3">
                <div className="mt-0.5 text-text-muted">
                  {task.status === 'done' ? (
                    <CheckCircle2 className="text-success" size={18} />
                  ) : task.status === 'in_progress' ? (
                    <Clock className="text-warning" size={18} />
                  ) : (
                    <Circle size={18} />
                  )}
                </div>
                <div>
                  <div className={task.status === 'done' ? 'line-through opacity-70' : ''}>
                    <CardTitle>{task.title}</CardTitle>
                  </div>
                  {task.description && (
                    <div className="mt-1">
                      <CardDescription>{task.description}</CardDescription>
                    </div>
                  )}
                  {task.due_date && (
                    <div className="mt-2 text-[11px] font-medium text-text-muted">
                      Due: {new Date(task.due_date).toLocaleDateString()}
                    </div>
                  )}
                </div>
              </div>
              <Badge tone={task.status === 'done' ? 'success' : task.status === 'in_progress' ? 'warning' : 'neutral'}>
                {task.status.replace('_', ' ')}
              </Badge>
            </Card>
          ))}
        </div>
      )}
    </section>
  )
}
