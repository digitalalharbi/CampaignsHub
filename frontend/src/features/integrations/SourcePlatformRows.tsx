import { useQuery } from '@tanstack/react-query'
import type { ConnectableProvider } from './api'
import { listStoreProviders } from '@/features/commerce/api'
import { listMeasurementProviders } from '@/features/measurement/api'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ProviderMark } from '@/components/ProviderMark'
import { platformLabel } from '@/lib/platforms'

/**
 * INTEG-PROJECT-LINK-STORES-GA4-001 (Owner 2026-11-06) — سلة, زد and GA4 as rows of the project's «المنصات»,
 * beside the ad platforms and linked the same way: one «ربط», which opens this project's own journey.
 *
 * The rows replace the older connect step — the «belongs to client» and «select for project» dropdowns and
 * the list of every property a sign-in could see. The project is the one the reader is standing in; what is
 * chosen is chosen inside the journey, from what that sign-in can read.
 *
 * A provider the platform operator has not finished setting up has nothing to press: it says so in one chip.
 */
export function SourcePlatformRows({ projectId, ar, onLink }: {
  projectId: string
  ar: boolean
  onLink: (provider: ConnectableProvider) => void
}) {
  const stores = useQuery({ queryKey: ['commerce-stores', projectId], queryFn: () => listStoreProviders(projectId), retry: false })
  const measurement = useQuery({ queryKey: ['measurement-properties', projectId], queryFn: () => listMeasurementProviders(projectId), retry: false })

  const rows = [
    ...(stores.data ?? []).map((p) => ({ key: p.key, label: p.label, label_ar: platformLabel(p.key, true), kind: 'commerce' as const, state: p.state as string, linked: p.stores.length })),
    ...(measurement.data ?? []).map((p) => ({ key: p.key, label: p.label, label_ar: p.label_ar, kind: 'measurement' as const, state: p.state as string, linked: p.properties.length })),
  ]

  return (
    <>
      {rows.map((row) => {
        const startable = row.state !== 'awaiting_credentials' && row.state !== 'unavailable'
        return (
          <li
            key={row.key}
            className="flex min-w-0 items-center gap-3 rounded-2xl border border-border bg-surface px-4 py-3"
            data-testid={`hub-platform-${row.key}`}
            data-kind={row.kind}
            data-state={row.state}
          >
            <ProviderMark provider={row.key} size={26} />
            <span className="min-w-0 flex-1 truncate text-sm font-bold text-text-primary">{platformLabel(row.key, ar)}</span>
            {row.linked > 0 && (
              <span className="tnum rounded-full bg-success/15 px-2 py-0.5 text-[11px] font-bold text-success" data-testid={`hub-platform-linked-${row.key}`}>
                {row.linked.toLocaleString('en-US')}
              </span>
            )}
            {startable ? (
              <Button
                size="sm"
                variant={row.linked > 0 ? 'ghost' : 'primary'}
                onClick={() => onLink({ key: row.key, label: row.label, label_ar: row.label_ar, kind: row.kind, has_parent: false, auth: 'oauth' })}
                data-testid={`hub-platform-link-${row.key}`}
              >
                {row.linked > 0 ? (ar ? 'إعادة الربط' : 'Re-link') : (ar ? 'ربط' : 'Link')}
              </Button>
            ) : (
              <span data-testid={`hub-platform-unavailable-${row.key}`}><Badge tone="neutral">{ar ? 'غير متاح' : 'Unavailable'}</Badge></span>
            )}
          </li>
        )
      })}
    </>
  )
}
