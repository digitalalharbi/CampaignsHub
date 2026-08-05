import { AlertTriangle, Inbox, Lock, RefreshCw } from 'lucide-react'
import type { ReactNode } from 'react'
import { Button } from './Button'
import { QueryFailure } from './QueryFailure'
import { useUi } from '@/stores/ui'

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
      {description && <p className="max-w-sm text-xs text-text-secondary">{description}</p>}
      {action && <div className="mt-2">{action}</div>}
    </div>
  )
}

export function EmptyState({ title, description }: { title: string; description?: string }) {
  return <Stub icon={<Inbox size={28} />} title={title} description={description} />
}

/**
 * A load failure. Hand it the `error` and it stops guessing (AGENCY-PERMS-006).
 *
 * Without `error` this is what it always was: one red box, one sentence, and a Retry button offered
 * whatever went wrong. That is right for a genuine outage and wrong for the three failures that are
 * not outages — a refusal, an expired session and a missing record — where the sentence misdescribes
 * what happened and the button cannot work. Repeating a request that was refused produces the same
 * refusal.
 *
 * WITH `error` it delegates to `QueryFailure`, which reads the status and answers accordingly. The
 * prop is optional rather than required so the upgrade is one prop per call site instead of a
 * restructure of twenty screens — but a call site that has the error and does not pass it is choosing
 * the guess.
 *
 * The Retry button also said «Retry» in English on an Arabic page, in every one of those screens.
 */
export function ErrorState({ title, description, onRetry, error, ar }: {
  title: string
  description?: string
  onRetry?: () => void
  error?: unknown
  ar?: boolean
}) {
  // Reads the language itself when the caller does not say. Twenty-odd call sites already know
  // whether they are Arabic — and a required `ar` prop would have meant editing every one of them
  // to fix a defect none of them caused.
  const locale = useUi((s) => s.locale)
  const arabic = ar ?? locale === 'ar'

  if (error !== undefined) {
    return <QueryFailure error={error} ar={arabic} onRetry={onRetry} fallbackTitle={title} />
  }

  return (
    <Stub
      icon={<AlertTriangle size={28} className="text-danger" />}
      title={title}
      description={description}
      action={
        onRetry && (
          <Button variant="secondary" onClick={onRetry}>
            <RefreshCw size={14} /> {arabic ? 'إعادة المحاولة' : 'Retry'}
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
