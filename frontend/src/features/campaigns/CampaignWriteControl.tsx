import type { ReactNode } from 'react'
import { Badge } from '@/components/ui/Badge'
import { Skeleton } from '@/components/ui/States'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { platformLabel } from '@/lib/platforms'
import { useUi } from '@/stores/ui'
import {
  CAPABILITY_LABELS, WRITE_STATUS_LABELS, useWriteCapabilities, writeAllowed, type WriteStatus,
} from './writeControl'

/**
 * CAMPAIGN-MGMT-DOMAIN-001 — the gate in front of every provider write in the interface.
 *
 * Renders `children` only when the server says this reader may perform this exact write on this
 * exact provider. Until then it renders `fallback` (default: nothing). There is deliberately no prop
 * that forces it open: a surface that wants a provider write must have the registry say so.
 */
export function ProviderWriteGate({ projectId, provider, capability, children, fallback = null }: {
  projectId: string
  provider: string
  capability: string
  children: ReactNode
  fallback?: ReactNode
}) {
  const caps = useWriteCapabilities(projectId)
  if (!writeAllowed(caps.data, provider, capability)) return <>{fallback}</>
  return <>{children}</>
}

const tone: Record<WriteStatus, 'neutral' | 'warning' | 'info' | 'success'> = {
  not_implemented: 'neutral',
  implemented_not_verified: 'warning',
  awaiting_credentials: 'info',
  verified: 'success',
}

/**
 * CAMPAIGN-MGMT-SURFACE-001 — the honest state of campaign write control, per provider.
 *
 * This is the surface the Owner asked for before any write exists: it says, for every provider and
 * every capability, whether CampaignsHub can perform it, whether this reader may, and whether a real
 * Production round-trip has ever proved it. Nothing here is a button. Buttons appear elsewhere, one
 * by one, as the registry admits them — and this panel is where that admission becomes visible.
 */
export function CampaignWriteControl({ projectId }: { projectId: string }) {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const caps = useWriteCapabilities(projectId)

  if (caps.isPending) return <Skeleton className="h-40" />
  if (caps.isError || !caps.data) {
    return (
      <QueryFailure
        error={caps.error}
        ar={ar}
        fallbackTitle={ar ? 'تعذّر قراءة سجل قدرات الكتابة' : 'The write-capability registry could not be read'}
        onRetry={() => { void caps.refetch() }}
        testId="write-control-failure"
      />
    )
  }

  const data = caps.data
  const implemented = data.providers.flatMap((p) => p.capabilities).filter((c) => c.status !== 'not_implemented').length
  const verified = data.providers.flatMap((p) => p.capabilities).filter((c) => c.status === 'verified').length
  const allowed = data.providers.flatMap((p) => p.capabilities).filter((c) => c.allowed).length

  return (
    <section data-testid="campaign-write-control" className="space-y-4">
      <div className="rounded-xl border border-border bg-surface p-4">
        <h3 className="text-sm font-bold text-text-primary">{ar ? 'التحكم بالكتابة على المنصات' : 'Provider write control'}</h3>
        <p className="mt-1 text-xs text-text-muted" data-testid="write-control-rule">{ar ? data.rule_ar : data.rule_en}</p>
        <dl className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-3">
          <div className="min-w-0 rounded-lg border border-border p-2" data-testid="write-control-implemented">
            <dt className="text-[11px] font-semibold uppercase tracking-wide text-text-muted">{ar ? 'قدرات منفَّذة' : 'Implemented'}</dt>
            <dd className="mt-0.5 text-sm font-semibold tabular-nums text-text-primary" dir="ltr">{implemented}</dd>
          </div>
          <div className="min-w-0 rounded-lg border border-border p-2" data-testid="write-control-verified">
            <dt className="text-[11px] font-semibold uppercase tracking-wide text-text-muted">{ar ? 'موثَّقة على الإنتاج' : 'Verified in Production'}</dt>
            <dd className="mt-0.5 text-sm font-semibold tabular-nums text-text-primary" dir="ltr">{verified}</dd>
          </div>
          <div className="min-w-0 rounded-lg border border-border p-2" data-testid="write-control-allowed">
            <dt className="text-[11px] font-semibold uppercase tracking-wide text-text-muted">{ar ? 'متاحة لك الآن' : 'Available to you now'}</dt>
            <dd className="mt-0.5 text-sm font-semibold tabular-nums text-text-primary" dir="ltr">{allowed}</dd>
          </div>
        </dl>
        {implemented === 0 && (
          <p className="mt-3 text-xs text-text-muted" data-testid="write-control-none">
            {ar
              ? 'لا توجد اليوم أي قدرة كتابة منفَّذة على أي منصة؛ الإدارة من هنا للقراءة والمتابعة فقط حتى تُنفَّذ كل قدرة وتُوثَّق.'
              : 'No provider write capability is implemented today; management here is read-and-monitor only until each capability is implemented and verified.'}
          </p>
        )}
      </div>

      <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
        {data.providers.map((row) => (
          <div key={row.provider} className="min-w-0 rounded-xl border border-border bg-surface p-3" data-testid={`write-control-${row.provider}`}>
            <div className="text-sm font-bold text-text-primary">{platformLabel(row.provider, ar)}</div>
            <ul className="mt-2 grid grid-cols-1 gap-1.5 sm:grid-cols-2">
              {row.capabilities.map((c) => (
                <li key={c.capability} className="flex min-w-0 items-center justify-between gap-2 text-xs" data-testid={`write-cap-${row.provider}-${c.capability}`}>
                  <span className="min-w-0 truncate text-text-primary">{CAPABILITY_LABELS[c.capability]?.[ar ? 'ar' : 'en'] ?? c.capability}</span>
                  <Badge tone={tone[c.status]} data-testid={`write-cap-status-${row.provider}-${c.capability}`}>
                    {WRITE_STATUS_LABELS[c.status][ar ? 'ar' : 'en']}
                  </Badge>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>
    </section>
  )
}
