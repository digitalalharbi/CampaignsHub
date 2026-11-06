import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, RefreshCw } from 'lucide-react'
import { listMeasurementProviders, syncMeasurementProperty, type MeasurementProperty } from './api'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Num } from '@/components/ui/Num'
import { ProviderMark } from '@/components/ProviderMark'
import { ProviderErrorNote } from '@/features/integrations/ProviderErrorNote'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * GA4-INTEGRATION-001 / INTEG-PROJECT-LINK-STORES-GA4-001 — the Analytics property THIS project reads.
 *
 * ## What this used to be, and why it is not any more
 *
 * It was the place a property was CHOSEN: a «belongs to client» dropdown, a «select for project» dropdown,
 * and a list of every property the workspace's Google sign-in could see, each with «اختر لهذا المشروع».
 * An agency's identity reaches dozens of clients' properties, and that list put all of their names on one
 * project's screen. A property is linked from the project now — «المنصات» → GA4 → «ربط» → Google's own
 * sign-in for this project → the property → «تم» — so this panel only shows what that journey produced.
 * With no property it draws nothing; the GA4 row above carries «ربط».
 *
 * ## Why it is still not a row on the sources list
 *
 * The sources list is the PAID MEDIA board. Site measurement is never added to an ad platform's figures —
 * the attribution model is different — and the server keeps it apart: the hub returns advertising
 * connections only.
 *
 * ## And what is never claimed
 *
 * A never-read property's timezone and currency are shown as not yet known, because the listing does not
 * ask the property for them. A guessed `UTC` would shift a Gulf client's whole report by a day.
 */
function stamp(iso: string | null, ar: boolean): string {
  if (!iso) return ar ? 'لم تُقرأ بعد' : 'Not read yet'

  return new Date(iso).toLocaleString(ar ? 'ar-SA-u-nu-latn' : 'en-GB', {
    year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
  })
}

function PropertyRow({ property, ar, onSync, busy }: {
  property: MeasurementProperty
  ar: boolean
  onSync: () => void
  busy: boolean
}) {
  return (
    <li data-testid={`ga4-property-${property.property_id}`} className="min-w-0 rounded-2xl border border-border bg-surface p-3">
      <div className="flex min-w-0 items-center gap-3">
        <ProviderMark provider="ga4" size={26} />
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-bold text-text-primary">{property.name}</p>
          {/* The GA4 hierarchy, so two «Main» properties are tellable apart. */}
          <p className="truncate text-[11px] text-text-muted">
            {property.analytics_account_name !== null && <>{property.analytics_account_name} · </>}
            <Num className="tnum">{property.property_id}</Num>
          </p>
        </div>
        {property.currency !== null && <Badge tone="neutral"><Num>{property.currency}</Num></Badge>}
        <Button size="sm" variant="secondary" onClick={onSync} disabled={busy} data-testid={`ga4-sync-${property.property_id}`}>
          {busy ? <Loader2 size={13} className="animate-spin" /> : <RefreshCw size={13} />}
          {ar ? 'اقرأ' : 'Read'}
        </Button>
      </div>

      <dl className="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-text-secondary">
        <div>
          <dt className="inline text-text-muted">{ar ? 'التوقيت' : 'Timezone'}: </dt>
          <dd className="inline font-semibold">
            {property.timezone ?? <span data-testid={`ga4-timezone-unknown-${property.property_id}`} className="font-normal text-text-muted">—</span>}
          </dd>
        </div>
        <div><dt className="inline text-text-muted">{ar ? 'آخر قراءة' : 'Last read'}: </dt><dd className="tnum inline">{stamp(property.last_synced_at, ar)}</dd></div>
      </dl>
    </li>
  )
}

export function PropertiesPanel({ projectId }: { projectId: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const queryClient = useQueryClient()

  const query = useQuery({ queryKey: ['measurement-properties', projectId], queryFn: () => listMeasurementProviders(projectId) })

  const sync = useMutation({
    mutationFn: (accountId: string) => syncMeasurementProperty(accountId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['measurement-properties'] }),
  })

  if (query.isLoading) return <Skeleton className="h-24" />
  if (query.isError) {
    return (
      <QueryFailure
        error={query.error} ar={ar} testId="measurement-failure" onRetry={() => void query.refetch()}
        fallbackTitle={ar ? 'تعذّر تحميل خصائص القياس.' : 'The measurement properties could not be loaded.'}
      />
    )
  }

  // The server returns only what THIS project reads; the filter states the rule here as well.
  const providers = (query.data ?? []).map((p) => ({ ...p, properties: p.properties.filter((x) => x.is_selected && x.project_id === projectId) })).filter((p) => p.properties.length > 0)
  // Nothing linked: nothing to show. The GA4 row above carries «ربط».
  if (providers.length === 0) return null

  const actionError = sync.isError ? toApiError(sync.error) : null

  return (
    <section className="flex min-w-0 flex-col gap-2" data-testid="measurement-panel">
      <h2 className="text-sm font-bold text-text-primary" data-testid="measurement-heading">{ar ? 'القياس' : 'Measurement'}</h2>

      {actionError && (
        <p data-testid="measurement-action-error" role="alert" className="rounded-[12px] bg-[var(--warning-background)] px-4 py-3 text-sm text-warning">
          {actionError.message}
        </p>
      )}

      {providers.map((provider) => provider.state === 'error' && provider.connection_error ? (
        <div key={`error-${provider.key}`} className="text-sm">
          <ProviderErrorNote error={provider.connection_error} locale={ar ? 'ar' : 'en'} testId={`measurement-error-${provider.key}`} />
        </div>
      ) : null)}

      <ul className="grid grid-cols-1 gap-2 md:grid-cols-2">
        {providers.flatMap((provider) => provider.properties.map((property) => (
          <PropertyRow
            key={property.id}
            property={property}
            ar={ar}
            busy={sync.isPending && sync.variables === property.id}
            onSync={() => sync.mutate(property.id)}
          />
        )))}
      </ul>
    </section>
  )
}
