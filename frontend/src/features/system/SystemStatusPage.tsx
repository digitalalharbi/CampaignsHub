import { useQuery } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'
import { Badge } from '@/components/ui/Badge'
import { Card, CardDescription, CardTitle } from '@/components/ui/Card'
import { useT } from '@/lib/i18n'
import { brand } from '@/lib/brand'
import type { HealthData } from '@/lib/api/types'

function useHealth(path: string) {
  return useQuery({
    queryKey: ['system', path],
    queryFn: () => getData<HealthData>(path),
    refetchInterval: 15_000,
  })
}

export function SystemStatusPage() {
  const t = useT()
  const health = useHealth('/health')
  const ready = useHealth('/ready')

  return (
    <section className="space-y-5">
      <div>
        <h1 className="font-[var(--font-heading)] text-xl font-extrabold">{t('dashboard')}</h1>
        <p className="mt-1 text-sm text-text-secondary">{t('system_status')}</p>
      </div>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <Card>
          <div className="flex items-start justify-between">
            <div>
              <CardTitle>{t('liveness')}</CardTitle>
              <CardDescription>/api/v1/health</CardDescription>
            </div>
            {health.isLoading ? (
              <Badge tone="neutral">{t('loading')}</Badge>
            ) : health.isError ? (
              <Badge tone="danger">{t('error')}</Badge>
            ) : (
              <Badge tone="success">{t('healthy')}</Badge>
            )}
          </div>
          <p className="mt-3 text-xs text-text-muted">
            {t('data_source')}: {brand.name} API · {t('last_updated')}:{' '}
            <span className="tnum">{new Date(health.dataUpdatedAt).toLocaleTimeString()}</span>
          </p>
        </Card>

        <Card>
          <div className="flex items-start justify-between">
            <div>
              <CardTitle>{t('readiness')}</CardTitle>
              <CardDescription>/api/v1/ready</CardDescription>
            </div>
            {ready.isLoading ? (
              <Badge tone="neutral">{t('loading')}</Badge>
            ) : ready.isError ? (
              <Badge tone="danger">{t('error')}</Badge>
            ) : (
              <Badge tone="success">{t('healthy')}</Badge>
            )}
          </div>

          <dl className="mt-3 space-y-2">
            <DependencyRow label={t('database')} state={ready.data?.checks?.database} t={t} />
            <DependencyRow label={t('redis')} state={ready.data?.checks?.redis} t={t} />
          </dl>
        </Card>
      </div>
    </section>
  )
}

function DependencyRow({
  label,
  state,
  t,
}: {
  label: string
  state?: 'up' | 'down'
  t: ReturnType<typeof useT>
}) {
  return (
    <div className="flex items-center justify-between text-sm">
      <dt className="text-text-secondary">{label}</dt>
      <dd>
        {state === 'up' ? (
          <Badge tone="success">{t('up')}</Badge>
        ) : state === 'down' ? (
          <Badge tone="danger">{t('down')}</Badge>
        ) : (
          <Badge tone="neutral">—</Badge>
        )}
      </dd>
    </div>
  )
}
