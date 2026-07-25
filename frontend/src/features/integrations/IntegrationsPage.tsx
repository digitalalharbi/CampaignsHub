import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plug, RefreshCw } from 'lucide-react'
import { connectConnector, listConnectors, syncConnector, type Connector } from './api'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardDescription, CardTitle } from '@/components/ui/Card'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'

const statusMeta: Record<Connector['status'], { tone: 'success' | 'warning' | 'danger' | 'neutral'; ar: string; en: string }> = {
  connected: { tone: 'success', ar: 'متصل', en: 'Connected' },
  awaiting_credentials: { tone: 'warning', ar: 'بانتظار المفاتيح', en: 'Awaiting credentials' },
  error: { tone: 'danger', ar: 'خطأ', en: 'Error' },
  disconnected: { tone: 'neutral', ar: 'غير متصل', en: 'Disconnected' },
}

export function IntegrationsPage() {
  const t = useT()
  const locale = useUi((s) => s.locale)
  const queryClient = useQueryClient()

  const query = useQuery({ queryKey: ['connectors'], queryFn: listConnectors })

  const connectMutation = useMutation({
    mutationFn: connectConnector,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['connectors'] }),
  })
  const syncMutation = useMutation({
    mutationFn: syncConnector,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['connectors'] }),
  })

  const connectError = connectMutation.isError ? toApiError(connectMutation.error) : null

  return (
    <section className="space-y-5">
      <div>
        <h1 className="font-[var(--font-heading)] text-xl font-extrabold">{t('integrations')}</h1>
        <p className="mt-1 text-sm text-text-secondary">{t('data_source')}: MediaBuying API</p>
      </div>

      {connectError && (
        <div className="rounded-[12px] bg-[var(--warning-background)] px-4 py-3 text-sm text-warning">
          {connectError.message}
        </div>
      )}

      {query.isLoading ? (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} className="h-32 w-full" />
          ))}
        </div>
      ) : query.isError ? (
        <ErrorState title={t('error')} onRetry={() => query.refetch()} />
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {query.data?.map((c) => {
            const meta = statusMeta[c.status]
            const isConnected = c.status === 'connected'
            return (
              <Card key={c.key}>
                <div className="flex items-start justify-between">
                  <CardTitle>{c.label}</CardTitle>
                  <Badge tone={meta.tone}>{locale === 'ar' ? meta.ar : meta.en}</Badge>
                </div>
                <CardDescription>
                  {c.ad_account_id ? (
                    <span className="tnum">{c.ad_account_id}</span>
                  ) : (
                    t('not_connected')
                  )}
                </CardDescription>
                <div className="mt-3 flex gap-2">
                  {isConnected ? (
                    <Button
                      variant="secondary"
                      loading={syncMutation.isPending && syncMutation.variables === c.key}
                      onClick={() => syncMutation.mutate(c.key)}
                    >
                      <RefreshCw size={14} /> {t('sync')}
                    </Button>
                  ) : (
                    <Button
                      variant="secondary"
                      loading={connectMutation.isPending && connectMutation.variables === c.key}
                      onClick={() => connectMutation.mutate(c.key)}
                    >
                      <Plug size={14} /> {t('connect')}
                    </Button>
                  )}
                </div>
              </Card>
            )
          })}
        </div>
      )}
    </section>
  )
}
