import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { getProjectOverview } from './api'
import { Badge } from '@/components/ui/Badge'
import { Card, CardTitle } from '@/components/ui/Card'
import { Tabs, TabPanel } from '@/components/ui/Tabs'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { useT } from '@/lib/i18n'

const TAB_KEYS = [
  'overview',
  'onboarding',
  'media_plans',
  'campaigns',
  'content',
  'approvals',
  'integrations',
  'tracking',
  'analytics',
  'reports',
  'tasks',
  'notifications',
  'files',
  'team',
  'ai',
  'settings',
  'audit',
] as const

const BUILT_TABS = new Set(['overview', 'integrations', 'tasks', 'team'])

export function ProjectOverviewPage() {
  const t = useT()
  const { projectId = '' } = useParams()
  const [tab, setTab] = useState<string>('overview')

  const overview = useQuery({
    queryKey: ['project', projectId, 'overview'],
    queryFn: () => getProjectOverview(projectId),
    enabled: Boolean(projectId),
  })

  const kpis = [
    { label: t('team'), value: overview.data?.built.team_members },
    { label: t('bound_accounts'), value: overview.data?.built.bound_accounts },
    { label: t('open_tasks'), value: overview.data?.built.open_tasks },
    { label: t('overdue'), value: overview.data?.built.overdue_tasks },
    { label: t('unread'), value: overview.data?.built.unread_notifications },
  ]

  return (
    <section className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="font-[var(--font-heading)] text-xl font-extrabold">
            {overview.data?.project.name ?? t('overview')}
          </h1>
          <p className="mt-1 flex items-center gap-2 text-[13px] text-text-secondary">
            {overview.data && (
              <>
                <Badge tone={overview.data.project.status === 'active' ? 'success' : 'neutral'}>
                  {overview.data.project.status}
                </Badge>
                <span>
                  {t('setup')}: <span className="tnum">{overview.data.project.setup_completion}%</span>
                </span>
              </>
            )}
          </p>
        </div>
        <div className="flex gap-3 text-[12px] font-bold text-brand-600">
          <Link to={`/projects/${projectId}/integrations`} className="hover:underline">
            {t('integrations')} →
          </Link>
          <Link to={`/projects/${projectId}/team`} className="hover:underline">
            {t('team')} →
          </Link>
        </div>
      </div>

      <Tabs
        items={TAB_KEYS.map((k) => ({ key: k, label: TAB_LABELS[k] ? t(TAB_LABELS[k]) : k }))}
        active={tab}
        onChange={setTab}
      />

      {tab === 'overview' ? (
        <TabPanel>
          {overview.isLoading ? (
            <div className="grid grid-cols-2 gap-4 md:grid-cols-5">
              {kpis.map((_, i) => (
                <Skeleton key={i} className="h-20 w-full" />
              ))}
            </div>
          ) : overview.isError ? (
            <ErrorState title={t('error')} onRetry={() => overview.refetch()} />
          ) : (
            <>
              <div className="grid grid-cols-2 gap-4 md:grid-cols-5">
                {kpis.map((k) => (
                  <Card key={k.label}>
                    <p className="text-[11px] text-text-muted">{k.label}</p>
                    <p className="mt-1 font-[var(--font-heading)] text-2xl font-extrabold tnum">{k.value ?? 0}</p>
                  </Card>
                ))}
              </div>
              <Card className="mt-4">
                <CardTitle>{t('not_available_yet')}</CardTitle>
                <p className="mt-1 text-[12px] text-text-secondary">{t('not_available_hint')}</p>
                <div className="mt-3 flex flex-wrap gap-2">
                  {overview.data?.not_available_yet.map((m) => (
                    <Badge key={m} tone="neutral">
                      {m}
                    </Badge>
                  ))}
                </div>
              </Card>
            </>
          )}
        </TabPanel>
      ) : BUILT_TABS.has(tab) ? (
        <TabPanel>
          <Card>
            <p className="py-6 text-center text-[13px] text-text-secondary">
              {tab === 'integrations' && (
                <Link to={`/projects/${projectId}/integrations`} className="font-bold text-brand-600 hover:underline">
                  {t('open')} →
                </Link>
              )}
              {tab === 'team' && (
                <Link to={`/projects/${projectId}/team`} className="font-bold text-brand-600 hover:underline">
                  {t('open')} →
                </Link>
              )}
              {tab === 'tasks' && (
                <Link to={`/projects/${projectId}/integrations`} className="font-bold text-brand-600 hover:underline">
                  {t('open')} →
                </Link>
              )}
            </p>
          </Card>
        </TabPanel>
      ) : (
        <TabPanel>
          <Card>
            <div className="py-8 text-center">
              <p className="text-sm font-bold text-text-primary">{t('not_implemented')}</p>
            </div>
          </Card>
        </TabPanel>
      )}
    </section>
  )
}

const TAB_LABELS: Partial<Record<(typeof TAB_KEYS)[number], Parameters<ReturnType<typeof useT>>[0]>> = {
  overview: 'overview',
  campaigns: 'campaigns',
  content: 'content',
  approvals: 'approvals',
  integrations: 'integrations',
  tracking: 'tracking',
  reports: 'reports',
  tasks: 'tasks',
  notifications: 'notifications',
  team: 'team',
  settings: 'settings',
}
