import { AlertTriangle, Inbox, Lock, RefreshCw } from 'lucide-react'
import type { ReactNode } from 'react'
import { Button } from './Button'

/** Shimmer skeleton block. */
export function Skeleton({ className = '' }: { className?: string }) {
  return <div className={`animate-pulse rounded-[8px] bg-surface-secondary ${className}`} />
}

function Stub({
  icon,
  title,
  description,
  action,
}: {
  icon: ReactNode
  title: string
  description?: string
  action?: ReactNode
}) {
  return (
    <div className="flex flex-col items-center justify-center gap-2 rounded-[14px] border border-dashed border-border bg-surface px-6 py-10 text-center">
      <div className="text-text-muted">{icon}</div>
      <h4 className="text-sm font-bold text-text-primary">{title}</h4>
      {description && <p className="max-w-sm text-[12px] text-text-secondary">{description}</p>}
      {action && <div className="mt-2">{action}</div>}
    </div>
  )
}

export function EmptyState({ title, description }: { title: string; description?: string }) {
  return <Stub icon={<Inbox size={28} />} title={title} description={description} />
}

export function ErrorState({ title, description, onRetry }: { title: string; description?: string; onRetry?: () => void }) {
  return (
    <Stub
      icon={<AlertTriangle size={28} className="text-danger" />}
      title={title}
      description={description}
      action={
        onRetry && (
          <Button variant="secondary" onClick={onRetry}>
            <RefreshCw size={14} /> Retry
          </Button>
        )
      }
    />
  )
}

export function NoPermission({ description }: { description?: string }) {
  return (
    <Stub
      icon={<Lock size={28} />}
      title="You don't have access"
      description={description ?? 'You do not have permission to view this content.'}
    />
  )
}
