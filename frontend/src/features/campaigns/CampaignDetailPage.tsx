import { useState, useEffect } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowRight, Link2, Pencil, Play, Pause, Archive, Unlink } from 'lucide-react'
import { archiveCampaign, campaignAction, getCampaign, listLinkedExternal, unlinkExternal } from './api'
import { CampaignFormModal } from './CampaignFormModal'
import { LinkExternalModal } from './LinkExternalModal'
import { campaignStatusLabel, campaignStatusTone, objectiveLabel, providerLabel } from './labels'
import { isDemoProvider } from './types'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardTitle } from '@/components/ui/Card'
import { EmptyState, ErrorState, NoPermission, Skeleton } from '@/components/ui/States'
import { Tabs, TabPanel, type TabItem } from '@/components/ui/Tabs'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-3 border-b border-border py-2 last:border-0">
      <span className="text-[12px] text-text-muted">{label}</span>
      <span className="text-[13px] font-semibold">{children}</span>
    </div>
  )
}

import { useProject } from '@/stores/project'

export function CampaignDetailPage() {
  const t = useT()
  const locale = useUi((s) => s.locale)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { projectId = '', campaignId = '' } = useParams()

  const { currentProjectId, setCurrentProjectId } = useProject()

  const canView = useAuth((s) => s.hasPermission('campaigns.view'))
  const canUpdate = useAuth((s) => s.hasPermission('campaigns.update'))
  const canPause = useAuth((s) => s.hasPermission('campaigns.pause'))

  const [tab, setTab] = useState('overview')
  const [editOpen, setEditOpen] = useState(false)
  const [linkOpen, setLinkOpen] = useState(false)

  // Sync the global project switcher if the URL points to a different project
  useEffect(() => {
    if (projectId && currentProjectId !== projectId) {
      setCurrentProjectId(projectId)
    }
  }, [projectId, currentProjectId, setCurrentProjectId])

  const campaignQuery = useQuery({
    queryKey: ['project', projectId, 'campaign', campaignId, 'detail'],
    queryFn: () => getCampaign(projectId, campaignId),
    enabled: canView && Boolean(projectId && campaignId),
  })

  const linkedQuery = useQuery({
    queryKey: ['project', projectId, 'campaign', campaignId, 'linked'],
    queryFn: () => listLinkedExternal(projectId, campaignId),
    enabled: canView && Boolean(projectId && campaignId),
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['project', projectId, 'campaign', campaignId] })
    queryClient.invalidateQueries({ queryKey: ['project', projectId, 'campaigns'] })
  }

  const statusMutation = useMutation({
    mutationFn: (action: 'pause' | 'activate') => campaignAction(projectId, campaignId, action),
    onSuccess: invalidate,
  })
  const archiveMutation = useMutation({
    mutationFn: () => archiveCampaign(projectId, campaignId),
    onSuccess: () => {
      invalidate()
      navigate('/campaigns')
    },
  })
  const unlinkMutation = useMutation({
    mutationFn: (externalId: string) => unlinkExternal(projectId, campaignId, externalId),
    onSuccess: invalidate,
  })

  if (!canView) return <NoPermission />

  if (campaignQuery.isLoading) {
    return (
      <div className="space-y-3">
        <Skeleton className="h-8 w-56" />
        <Skeleton className="h-40 w-full" />
      </div>
    )
  }
  if (campaignQuery.isError || !campaignQuery.data) {
    return <ErrorState title={t('error')} onRetry={() => campaignQuery.refetch()} />
  }

  const c = campaignQuery.data
  const actionError = statusMutation.isError ? toApiError(statusMutation.error) : null

  const tabs: TabItem[] = [
    { key: 'overview', label: t('tab_overview') },
    { key: 'linked', label: t('tab_linked') },
    { key: 'performance', label: t('tab_performance') },
    { key: 'notes', label: t('tab_notes') },
    { key: 'activity', label: t('tab_activity') },
  ]

  return (
    <section className="space-y-4">
      <button
        onClick={() => navigate('/campaigns')}
        className="inline-flex items-center gap-1 text-[12px] text-text-secondary hover:text-text-primary"
      >
        <ArrowRight size={14} className="rtl:rotate-180" /> {t('back_to_campaigns')}
      </button>

      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="font-[var(--font-heading)] text-xl font-extrabold">{c.name}</h1>
            <Badge tone={campaignStatusTone(c.status)}>{campaignStatusLabel(c.status, locale)}</Badge>
          </div>
          <p className="mt-1 text-[13px] text-text-secondary">{objectiveLabel(c.objective, locale)}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          {canUpdate && (
            <Button variant="secondary" onClick={() => setEditOpen(true)}>
              <Pencil size={14} /> {t('edit')}
            </Button>
          )}
          {canPause && c.status === 'active' && (
            <Button
              variant="secondary"
              loading={statusMutation.isPending && statusMutation.variables === 'pause'}
              onClick={() => statusMutation.mutate('pause')}
            >
              <Pause size={14} /> {t('pause')}
            </Button>
          )}
          {canUpdate && c.status !== 'active' && c.status !== 'archived' && (
            <Button
              variant="secondary"
              loading={statusMutation.isPending && statusMutation.variables === 'activate'}
              onClick={() => statusMutation.mutate('activate')}
            >
              <Play size={14} /> {t('activate')}
            </Button>
          )}
          {canUpdate && (
            <Button variant="ghost" loading={archiveMutation.isPending} onClick={() => archiveMutation.mutate()}>
              <Archive size={14} /> {t('archive')}
            </Button>
          )}
        </div>
      </div>

      {actionError && <Alert severity="danger" title={actionError.message} />}

      <Tabs items={tabs} active={tab} onChange={setTab} />

      {tab === 'overview' && (
        <TabPanel>
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Card>
              <CardTitle>{t('tab_overview')}</CardTitle>
              <div className="mt-2">
                <Row label={t('objective_label')}>{objectiveLabel(c.objective, locale)}</Row>
                <Row label={t('status_label')}>
                  <Badge tone={campaignStatusTone(c.status)}>{campaignStatusLabel(c.status, locale)}</Badge>
                </Row>
                <Row label={t('budget_label')}>
                  <span className="tnum">
                    {c.total_budget != null ? `${c.total_budget.toLocaleString('en-US')} ${c.budget_currency}` : '—'}
                  </span>
                </Row>
                <Row label={t('period_label')}>
                  <span className="tnum text-[12px]">
                    {c.starts_on || c.ends_on ? `${c.starts_on ?? '…'} → ${c.ends_on ?? '…'}` : '—'}
                  </span>
                </Row>
                <Row label={t('owner_label')}>{c.owner_id != null ? <span className="tnum">#{c.owner_id}</span> : '—'}</Row>
                <Row label={t('linked_count')}>
                  <Badge tone="info">{c.external_campaigns_count ?? linkedQuery.data?.length ?? 0}</Badge>
                </Row>
              </div>
            </Card>
            <Card>
              <CardTitle>{t('audience_label')}</CardTitle>
              <p className="mt-2 whitespace-pre-wrap text-[13px] text-text-secondary">{c.audience || '—'}</p>
            </Card>
          </div>
        </TabPanel>
      )}

      {tab === 'linked' && (
        <TabPanel>
          <div className="mb-3 flex items-center justify-between">
            <CardTitle>{t('linked_campaigns')}</CardTitle>
            {canUpdate && (
              <Button onClick={() => setLinkOpen(true)}>
                <Link2 size={14} /> {t('link_external')}
              </Button>
            )}
          </div>
          {linkedQuery.isLoading ? (
            <div className="space-y-2">
              <Skeleton className="h-12 w-full" />
            </div>
          ) : (linkedQuery.data?.length ?? 0) === 0 ? (
            <EmptyState title={t('no_linked_external')} description={t('no_linked_hint')} />
          ) : (
            <div className="space-y-2">
              {linkedQuery.data?.map((ext) => (
                <div key={ext.id} className="flex items-center justify-between rounded-[9px] border border-border p-2.5">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="truncate text-[13px] font-semibold">{ext.name}</span>
                      <Badge tone={isDemoProvider(ext.provider) ? 'warning' : 'neutral'}>
                        {providerLabel(ext.provider, locale)}
                        {isDemoProvider(ext.provider) ? ` · ${t('demo_label')}` : ''}
                      </Badge>
                      <Badge tone={campaignStatusTone(ext.status)}>{campaignStatusLabel(ext.status, locale)}</Badge>
                    </div>
                    <span className="text-[11px] text-text-muted">
                      {t('ad_account_label')}: <span className="tnum">{ext.external_id}</span>
                    </span>
                  </div>
                  {canUpdate && (
                    <Button
                      variant="ghost"
                      loading={unlinkMutation.isPending && unlinkMutation.variables === ext.id}
                      onClick={() => unlinkMutation.mutate(ext.id)}
                    >
                      <Unlink size={14} /> {t('unlink')}
                    </Button>
                  )}
                </div>
              ))}
            </div>
          )}
        </TabPanel>
      )}

      {tab === 'performance' && (
        <TabPanel>
          <EmptyState title={t('tab_performance')} description={t('performance_pending')} />
        </TabPanel>
      )}
      {tab === 'notes' && (
        <TabPanel>
          <EmptyState title={t('tab_notes')} description={t('notes_pending')} />
        </TabPanel>
      )}
      {tab === 'activity' && (
        <TabPanel>
          <EmptyState title={t('tab_activity')} description={t('activity_pending')} />
        </TabPanel>
      )}

      <CampaignFormModal open={editOpen} onClose={() => setEditOpen(false)} projectId={projectId} campaign={c} />
      <LinkExternalModal open={linkOpen} onClose={() => setLinkOpen(false)} projectId={projectId} campaignId={campaignId} />
    </section>
  )
}
