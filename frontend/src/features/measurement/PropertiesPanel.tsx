import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BarChart3, Clock, Loader2, RefreshCw } from 'lucide-react'
import {
  listMeasurementProviders, startMeasurementOAuth, syncMeasurementProperty,
  type MeasurementProperty, type MeasurementProvider, type MeasurementState,
} from './api'
import { bindAccount, listClientWorkspaces, listProjects } from '@/features/projects/api'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardDescription, CardTitle } from '@/components/ui/Card'
import { ProviderErrorNote } from '@/features/integrations/ProviderErrorNote'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'

/**
 * GA4-INTEGRATION-001 — the client's own Analytics properties, in their own section.
 *
 * ## Why this is not a row on the Connection Hub
 *
 * The hub is the PAID MEDIA board. Its row carries «1 of 17 accounts», a campaign drill-down and a
 * platform breakdown, none of which a property can answer — and anything listed there is read, by
 * the product and by the reader, as a place somebody buys advertising. Constitution §23 files
 * measurement separately for that reason, and the server enforces it: the hub endpoint returns
 * advertising connections only.
 *
 * ## The one sentence this section exists to say
 *
 * «‏2 من 17 خاصية مختارة». An agency's Google identity commonly reaches dozens of clients'
 * properties, and a list of what it can SEE invites the belief that all of it is being read. Only a
 * property somebody selected for a project is ever read — and selecting one goes through the same
 * binding the ad accounts use, so the client-workspace fence and the plan quota apply unchanged.
 *
 * ## And what is never claimed
 *
 * A never-synced property's timezone and currency are shown as not yet known, because discovery does
 * not ask the property for them. A guessed `UTC` would shift a Gulf client's whole report by a day.
 */

const STATE_META: Record<MeasurementState, { tone: 'success' | 'warning' | 'danger' | 'neutral'; ar: string; en: string }> = {
  connected: { tone: 'success', ar: 'متصل', en: 'Connected' },
  error: { tone: 'danger', ar: 'خطأ', en: 'Error' },
  awaiting_credentials: { tone: 'warning', ar: 'بانتظار بيانات الاعتماد', en: 'Awaiting credentials' },
  unavailable: { tone: 'neutral', ar: 'غير متاح حاليًا', en: 'Currently unavailable' },
  disconnected: { tone: 'neutral', ar: 'غير مربوط', en: 'Not connected' },
}

/** The two states a customer cannot act on, and the same honest sentence for both. */
const NEEDS_OPERATOR: readonly MeasurementState[] = ['awaiting_credentials', 'unavailable']

/** Latin digits in both languages, per the product's number rule. */
function stamp(iso: string | null, ar: boolean): string {
  if (!iso) return ar ? 'لم تُقرأ بعد' : 'Not read yet'

  return new Date(iso).toLocaleString(ar ? 'ar-SA-u-nu-latn' : 'en-GB', {
    year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
  })
}

function PropertyRow({ property, ar, projectId, onSelect, onSync, busy }: {
  property: MeasurementProperty
  ar: boolean
  projectId: string | null
  onSelect: () => void
  onSync: () => void
  busy: boolean
}) {
  return (
    <li
      data-testid={`ga4-property-${property.property_id}`}
      data-selected={property.is_selected}
      className="rounded-xl border border-border bg-surface-secondary p-3"
    >
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="truncate font-semibold text-text-primary">{property.name}</p>
          {/* The GA4 hierarchy, so two clients' «Main» properties are tellable apart. */}
          <p className="truncate text-[11px] text-text-muted">
            {property.analytics_account_name ?? (ar ? 'حساب غير مسمّى' : 'Unnamed account')}
            {' · '}
            <span className="tnum" dir="ltr">{property.property_id}</span>
          </p>
        </div>

        {property.is_selected ? (
          <div className="flex shrink-0 items-center gap-2">
            <Badge tone="success" data-testid={`ga4-selected-${property.property_id}`}>
              {ar ? 'مختارة' : 'Selected'}
            </Badge>
            <Button size="sm" variant="secondary" onClick={onSync} disabled={busy}>
              {busy ? <Loader2 size={13} className="animate-spin" /> : <RefreshCw size={13} />}
              {ar ? 'اقرأ الآن' : 'Read now'}
            </Button>
          </div>
        ) : (
          /*
            Disabled without a project in hand rather than hidden.
            A selection belongs to a PROJECT, and a button that silently picked one would file a
            client's site traffic under whichever project happened to sort first.
          */
          <Button
            size="sm"
            variant="secondary"
            onClick={onSelect}
            disabled={busy || projectId === null}
            data-testid={`ga4-select-${property.property_id}`}
            title={projectId === null ? (ar ? 'اختر مشروعًا أولًا' : 'Choose a project first') : undefined}
          >
            {busy ? <Loader2 size={13} className="animate-spin" /> : null}
            {ar ? 'اختر لهذا المشروع' : 'Select for this project'}
          </Button>
        )}
      </div>

      <dl className="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-text-secondary">
        <div>
          <dt className="inline text-text-muted">{ar ? 'التوقيت' : 'Timezone'}: </dt>
          <dd className="inline font-semibold">
            {property.timezone ?? (
              /* Never a guessed UTC: a wrong timezone moves a whole report by a day. */
              <span data-testid={`ga4-timezone-unknown-${property.property_id}`} className="font-normal text-text-muted">
                {ar ? 'يُعرف عند أول قراءة' : 'Known at first read'}
              </span>
            )}
          </dd>
        </div>
        {property.currency !== null && (
          <div>
            <dt className="inline text-text-muted">{ar ? 'العملة' : 'Currency'}: </dt>
            <dd className="inline font-semibold" dir="ltr">{property.currency}</dd>
          </div>
        )}
      </dl>

      <p className="mt-2 flex items-center gap-1.5 text-[11px] text-text-muted">
        <Clock size={11} />
        {ar ? 'آخر قراءة' : 'Last read'}: <span className="tnum">{stamp(property.last_synced_at, ar)}</span>
      </p>
    </li>
  )
}

export function PropertiesPanel() {
  const ar = useUi((s) => s.locale) === 'ar'
  const queryClient = useQueryClient()
  const currentProjectId = useProject((s) => s.currentProjectId)
  const [clientWorkspaceId, setClientWorkspaceId] = useState('')
  const [projectId, setProjectId] = useState<string | null>(null)

  const query = useQuery({ queryKey: ['measurement-properties'], queryFn: listMeasurementProviders })
  const projects = useQuery({ queryKey: ['projects'], queryFn: () => listProjects(), enabled: true })
  const clients = useQuery({ queryKey: ['client-workspaces'], queryFn: listClientWorkspaces })

  /*
   * The reader's own project first, then an explicit choice, and never a silent guess.
   *
   * The integrations page learned this the hard way: taking a connection's first bound project is how
   * a reader managing the agency's Google authorisation was pointed at another client's project and
   * answered 403 by the server.
   */
  const destination = projectId ?? currentProjectId

  const authorize = useMutation({
    mutationFn: (provider: string) => startMeasurementOAuth(provider, clientWorkspaceId || null),
    // A full navigation, not a router push: the destination is Google's own consent screen.
    onSuccess: ({ authorization_url }) => { window.location.assign(authorization_url) },
  })

  const select = useMutation({
    mutationFn: ({ accountId, project }: { accountId: string; project: string }) =>
      /*
       * The EXISTING binding, with `purpose: 'analytics'`. There is no parallel selection path for
       * measurement: this endpoint already enforces the client-workspace fence, the one-active-binding
       * rule and the plan quota, and a second path would be a second place to forget all three.
       */
      bindAccount(project, { external_account_id: accountId, purpose: 'analytics' }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['measurement-properties'] }),
  })

  const sync = useMutation({
    mutationFn: (accountId: string) => syncMeasurementProperty(accountId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['measurement-properties'] }),
  })

  if (query.isLoading) return <Skeleton className="h-40" />
  if (query.isError) {
    return (
      <QueryFailure
        error={query.error} ar={ar} testId="measurement-failure" onRetry={() => void query.refetch()}
        fallbackTitle={ar ? 'تعذّر تحميل خصائص القياس.' : 'The measurement properties could not be loaded.'}
      />
    )
  }

  const providers = query.data ?? []
  const actionError = [authorize, select, sync].map((m) => (m.isError ? toApiError(m.error) : null)).find((e) => e !== null) ?? null
  const projectChoices = projects.data ?? []
  const clientChoices = clients.data ?? []

  return (
    <section className="space-y-4" data-testid="measurement-panel">
      <div>
        <h2 className="font-[var(--font-heading)] text-lg font-extrabold" data-testid="measurement-heading">
          {ar ? 'التحليلات والقياس' : 'Analytics & measurement'}
        </h2>
        <p className="mt-1 text-sm text-text-secondary">
          {/*
            Said plainly, because the distinction is the whole point: this measures the client's own
            site, and its numbers are not the ad platforms' numbers and are never added to them.
          */}
          {ar
            ? 'اربط خاصية أناليتكس ليصل قياس موقع العميل نفسه. قياس الموقع لا يُجمع مع أرقام المنصات الإعلانية — نموذج الإحالة مختلف.'
            : 'Connect an Analytics property to bring in measurement of the client’s own site. Site measurement is never added to the ad platforms’ figures — the attribution model is different.'}
        </p>
      </div>

      {actionError && (
        <p data-testid="measurement-action-error" role="alert" className="rounded-[12px] bg-[var(--warning-background)] px-4 py-3 text-sm text-warning">
          {actionError.message}
        </p>
      )}

      <div className="flex flex-wrap gap-3">
        {clientChoices.length > 0 && (
          <label className="block text-sm">
            <span className="text-text-secondary">{ar ? 'الخاصية تخصّ العميل' : 'This property belongs to'}</span>
            <select
              data-testid="measurement-client-workspace"
              value={clientWorkspaceId}
              onChange={(e) => setClientWorkspaceId(e.target.value)}
              className="mt-1 block w-full max-w-48 rounded-xl border border-border bg-surface px-3 py-2 sm:max-w-none"
            >
              {/* A house property is a real answer, not a missing one. */}
              <option value="">{ar ? 'الوكالة نفسها' : 'The agency itself'}</option>
              {clientChoices.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </label>
        )}

        {projectChoices.length > 0 && (
          <label className="block text-sm">
            <span className="text-text-secondary">{ar ? 'الاختيار لمشروع' : 'Select for project'}</span>
            <select
              data-testid="measurement-project"
              value={destination ?? ''}
              onChange={(e) => setProjectId(e.target.value || null)}
              className="mt-1 block w-full max-w-48 rounded-xl border border-border bg-surface px-3 py-2 sm:max-w-none"
            >
              <option value="">{ar ? 'اختر مشروعًا' : 'Choose a project'}</option>
              {projectChoices.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          </label>
        )}
      </div>

      <div className="grid gap-3">
        {providers.map((provider: MeasurementProvider) => {
          const meta = STATE_META[provider.state]
          const needsOperator = NEEDS_OPERATOR.includes(provider.state)

          return (
            <Card key={provider.key} className="space-y-3" data-testid="measurement-card" data-platform={provider.key}>
              <div className="flex items-start justify-between gap-2">
                <div className="flex min-w-0 items-center gap-2">
                  <BarChart3 size={16} className="shrink-0 text-text-muted" />
                  <CardTitle>{ar ? provider.label_ar : provider.label}</CardTitle>
                </div>
                <Badge tone={meta.tone} data-testid={`measurement-state-${provider.key}`} className="shrink-0 self-start whitespace-nowrap">
                  {ar ? meta.ar : meta.en}
                </Badge>
              </div>

              {needsOperator ? (
                /*
                 * One honest sentence, and no button. Which system key is missing is deliberately not
                 * said: a customer cannot obtain an OAuth client secret for our app, and the shape of
                 * our registration is not theirs to be told.
                 */
                <p data-testid={`measurement-needs-operator-${provider.key}`} className="text-sm leading-relaxed text-text-secondary">
                  {ar
                    ? 'يتولّى مدير المنصة تهيئة هذا التكامل على مستوى النظام. لا يلزمك أي إجراء الآن.'
                    : 'The platform operator sets this integration up at system level. Nothing is needed from you yet.'}
                </p>
              ) : (
                <>
                  {provider.state === 'error' && provider.connection_error && (
                    <div className="text-sm">
                      <ProviderErrorNote
                        error={provider.connection_error}
                        locale={ar ? 'ar' : 'en'}
                        testId={`measurement-error-${provider.key}`}
                      />
                    </div>
                  )}

                  {provider.properties.length === 0 ? (
                    <>
                      <CardDescription>
                        {ar
                          ? 'اضغط «ربط أناليتكس» لتفويضنا من صفحة جوجل الرسمية. نطلب صلاحية قراءة فقط.'
                          : 'Press connect to authorise us from Google’s own consent screen. We ask for read-only access.'}
                      </CardDescription>
                      <Button
                        size="sm"
                        onClick={() => authorize.mutate(provider.key)}
                        disabled={authorize.isPending}
                        data-testid={`measurement-connect-${provider.key}`}
                      >
                        {authorize.isPending ? <Loader2 size={13} className="animate-spin" /> : null}
                        {ar ? 'ربط أناليتكس' : 'Connect Analytics'}
                      </Button>
                    </>
                  ) : (
                    <>
                      {/*
                        Chosen over discovered, in one phrase. A count of discovered properties alone
                        invites the belief that all of them are being read.
                      */}
                      <p data-testid={`measurement-counts-${provider.key}`} className="text-sm text-text-secondary">
                        <span className="tnum font-semibold">{provider.selected_count}</span>
                        {ar ? ' من ' : ' of '}
                        <span className="tnum font-semibold">{provider.discovered_count}</span>
                        {ar ? ' خاصية مختارة للقراءة' : ' properties selected to read'}
                      </p>

                      <ul className="flex flex-col gap-2">
                        {provider.properties.map((property) => (
                          <PropertyRow
                            key={property.id}
                            property={property}
                            ar={ar}
                            projectId={destination}
                            busy={select.isPending || sync.isPending}
                            onSelect={() => {
                              if (destination === null) return
                              select.mutate({ accountId: property.id, project: destination })
                            }}
                            onSync={() => sync.mutate(property.id)}
                          />
                        ))}
                      </ul>
                    </>
                  )}
                </>
              )}
            </Card>
          )
        })}
      </div>
    </section>
  )
}
