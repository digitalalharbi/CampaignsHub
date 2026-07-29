import { useEffect, useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, ArrowRight, Archive, Pause, Pencil, Play } from 'lucide-react'
import {
  archiveCampaign,
  campaignAction,
  getCampaign,
  listLinkedExternal,
  unlinkExternal,
  updateCampaign,
} from './api'
import { CampaignFormModal } from './CampaignFormModal'
import { listUsers } from '@/features/projects/api'
import { LinkExternalModal } from './LinkExternalModal'
import {
  PERFORMANCE_KEYS,
  PRIORITY_KEYS,
  STAGE_KEYS,
  campaignStatusLabel,
  campaignStatusTone,
  objectiveLabel,
  performanceLabel,
  performanceTone,
  priorityLabel,
  priorityTone,
  stageLabel,
  stageTone,
} from './labels'
import { isDemoProvider } from './types'
import {
  CampaignActivityTab,
  CampaignAlertsTab,
  CampaignBudgetTab,
  CampaignCreativesTab,
  CampaignExecutiveSummary,
  CampaignFunnelTab,
  CampaignKpis,
  CampaignNotesTab,
  CampaignPerformanceTab,
  CampaignPlatformsTab,
  CampaignReportsTab,
} from './CampaignCommandCenter'
import { useLastNDaysRange } from '@/features/analytics/hooks'
import { RangeTabs } from '@/features/analytics/components'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardTitle } from '@/components/ui/Card'
import { ErrorState, NoPermission, Skeleton } from '@/components/ui/States'
import { Tabs, TabPanel, type TabItem } from '@/components/ui/Tabs'
import { useT } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useProject } from '@/stores/project'

const TAB_KEYS = [
  'overview', 'performance', 'platforms', 'creatives', 'budget',
  'funnel', 'notes', 'alerts', 'reports', 'activity',
] as const
type TabKey = (typeof TAB_KEYS)[number]

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-3 border-b border-border py-2 last:border-0">
      <span className="text-xs text-text-muted">{label}</span>
      <span className="text-sm font-semibold">{children}</span>
    </div>
  )
}

export function CampaignDetailPage() {
  const t = useT()
  const locale = useUi((s) => s.locale)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { projectId = '', campaignId = '' } = useParams()
  const [sp, setSp] = useSearchParams()
  // Resolve the owner id to a real member name (same source as the edit modal's owner select).
  const usersQ = useQuery({ queryKey: ['users'], queryFn: () => listUsers() })
  const [days, setDays] = useState(30)
  const range = useLastNDaysRange(days)

  const { currentProjectId, setCurrentProjectId } = useProject()

  const canView = useAuth((s) => s.hasPermission('campaigns.view'))
  const canUpdate = useAuth((s) => s.hasPermission('campaigns.update'))
  const canPause = useAuth((s) => s.hasPermission('campaigns.pause'))
  const canApprove = useAuth((s) => s.hasPermission('reports.approve'))

  const tabParam = sp.get('tab') as TabKey | null
  const tab: TabKey = tabParam && TAB_KEYS.includes(tabParam) ? tabParam : 'overview'
  const setTab = (key: string) => {
    const next = new URLSearchParams(sp)
    next.set('tab', key)
    setSp(next, { replace: true })
  }

  const [editOpen, setEditOpen] = [sp.get('edit') === '1', (v: boolean) => {
    const next = new URLSearchParams(sp)
    if (v) next.set('edit', '1')
    else next.delete('edit')
    setSp(next, { replace: true })
  }] as const
  const linkOpen = sp.get('link') === '1'
  const setLinkOpen = (v: boolean) => {
    const next = new URLSearchParams(sp)
    if (v) next.set('link', '1')
    else next.delete('link')
    setSp(next, { replace: true })
  }

  // Keep the global project switcher aligned with the URL's project.
  useEffect(() => {
    if (projectId && currentProjectId !== projectId) setCurrentProjectId(projectId)
  }, [projectId, currentProjectId, setCurrentProjectId])

  // Project- AND campaign-scoped query keys (isolation): ['projects', projectId, 'campaigns', campaignId, ...].
  const campaignQuery = useQuery({
    queryKey: ['projects', projectId, 'campaigns', campaignId, 'detail'],
    queryFn: () => getCampaign(projectId, campaignId),
    enabled: canView && Boolean(projectId && campaignId),
  })
  const linkedQuery = useQuery({
    queryKey: ['projects', projectId, 'campaigns', campaignId, 'platforms'],
    queryFn: () => listLinkedExternal(projectId, campaignId),
    enabled: canView && Boolean(projectId && campaignId),
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['projects', projectId, 'campaigns', campaignId] })
    queryClient.invalidateQueries({ queryKey: ['projects', projectId, 'campaigns'] })
  }

  const statusMutation = useMutation({
    mutationFn: (action: 'pause' | 'activate') => campaignAction(projectId, campaignId, action),
    onSuccess: invalidate,
  })
  const archiveMutation = useMutation({
    mutationFn: () => archiveCampaign(projectId, campaignId),
    onSuccess: () => { invalidate(); navigate('/campaigns') },
  })
  const unlinkMutation = useMutation({
    mutationFn: (externalId: string) => unlinkExternal(projectId, campaignId, externalId),
    onSuccess: invalidate,
  })
  // Internal classification — a real PATCH to the campaign (audited server-side), never a UI-only badge.
  const classifyMutation = useMutation({
    mutationFn: (patch: { stage?: string; performance_label?: string; priority?: string }) =>
      updateCampaign(projectId, campaignId, patch),
    onSuccess: invalidate,
  })

  if (!canView) return <NoPermission />
  if (campaignQuery.isLoading) {
    return (
      <div className="space-y-3">
        <Skeleton className="h-8 w-56" />
        <Skeleton className="h-24 w-full" />
        <Skeleton className="h-40 w-full" />
      </div>
    )
  }
  if (campaignQuery.isError || !campaignQuery.data) {
    return <ErrorState title={t('error')} onRetry={() => campaignQuery.refetch()} />
  }

  const c = campaignQuery.data
  const linked = linkedQuery.data ?? []
  const isDemo = linked.some((e) => isDemoProvider(e.provider))
  const platforms = [...new Set(linked.map((e) => e.provider))]
  const actionError = statusMutation.isError ? String(statusMutation.error) : null

  const tabs: TabItem[] = TAB_KEYS.map((k) => ({ key: k, label: t(`tab_${k}` as never) }))

  const classSelect = (
    field: 'stage' | 'performance_label' | 'priority',
    value: string | null | undefined,
    keys: string[],
    toLabel: (k: string) => string,
  ) => (
    <select
      aria-label={field}
      disabled={!canUpdate || classifyMutation.isPending}
      value={value ?? ''}
      onChange={(e) => classifyMutation.mutate({ [field]: e.target.value } as never)}
      className="rounded-lg border border-border bg-surface px-2 py-1 text-xs font-semibold text-text-primary disabled:opacity-60"
    >
      <option value="">{t('cmc_not_set')}</option>
      {keys.map((k) => <option key={k} value={k}>{toLabel(k)}</option>)}
    </select>
  )

  return (
    <section className="space-y-4">
      <button
        onClick={() => navigate('/campaigns')}
        className="inline-flex items-center gap-1 text-xs text-text-secondary hover:text-text-primary"
      >
        <ArrowRight size={14} className="rtl:rotate-180" /> {t('back_to_campaigns')}
      </button>

      {/* ===== Command-center header ===== */}
      <Card className="space-y-3">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="font-[var(--font-heading)] text-xl font-extrabold">{c.client_display_name || c.name}</h1>
              <Badge tone={campaignStatusTone(c.status)}>{campaignStatusLabel(c.status, locale)}</Badge>
              {c.stage && <Badge tone={stageTone(c.stage)}>{stageLabel(c.stage, locale)}</Badge>}
              {c.performance_label && <Badge tone={performanceTone(c.performance_label)}>{performanceLabel(c.performance_label, locale)}</Badge>}
              {c.priority && <Badge tone={priorityTone(c.priority)}>{priorityLabel(c.priority, locale)}</Badge>}
              {isDemo && <Badge tone="warning">{t('demo_label')}</Badge>}
              {c.needs_attention && (
                <Badge tone="danger"><AlertTriangle size={12} className="me-1 inline" />{t('cmc_needs_attention')}</Badge>
              )}
            </div>
            <p className="mt-1 text-sm text-text-secondary">
              {objectiveLabel(c.objective, locale)}
              {c.client_display_name && c.client_display_name !== c.name && (
                <> · <span className="text-text-muted">{t('cmc_internal_name')}: {c.name}</span></>
              )}
            </p>
          </div>

          <div className="flex flex-wrap gap-2">
            {canUpdate && (
              <Button variant="secondary" onClick={() => setEditOpen(true)}><Pencil size={14} /> {t('edit')}</Button>
            )}
            {canPause && c.status === 'active' && (
              <Button variant="secondary" loading={statusMutation.isPending && statusMutation.variables === 'pause'}
                onClick={() => statusMutation.mutate('pause')}><Pause size={14} /> {t('pause')}</Button>
            )}
            {canUpdate && c.status !== 'active' && c.status !== 'archived' && (
              <Button variant="secondary" loading={statusMutation.isPending && statusMutation.variables === 'activate'}
                onClick={() => statusMutation.mutate('activate')}><Play size={14} /> {t('activate')}</Button>
            )}
            {canUpdate && (
              <Button variant="ghost" loading={archiveMutation.isPending} onClick={() => archiveMutation.mutate()}>
                <Archive size={14} /> {t('archive')}
              </Button>
            )}
          </div>
        </div>

        {/* Header facts grid — campaign-scoped context. */}
        <div className="grid grid-cols-2 gap-x-6 gap-y-1.5 border-t border-border pt-3 text-sm sm:grid-cols-3 lg:grid-cols-4">
          <HeaderFact label={t('period_label')}>
            <span className="tnum text-xs">{c.starts_on || c.ends_on ? `${c.starts_on ?? '…'} → ${c.ends_on ?? '…'}` : '—'}</span>
          </HeaderFact>
          <HeaderFact label={t('budget_label')}>
            <span className="tnum">{c.total_budget != null ? `${c.total_budget.toLocaleString('en-US')} ${c.budget_currency}` : '—'}</span>
          </HeaderFact>
          <HeaderFact label={t('owner_label')}>{c.owner_id != null ? ((usersQ.data ?? []).find((u) => String(u.id) === String(c.owner_id))?.name ?? <span className="tnum">#{c.owner_id}</span>) : '—'}</HeaderFact>
          <HeaderFact label={t('cmc_attribution')}>{c.attribution_window || '—'}</HeaderFact>
          <HeaderFact label={t('cmc_source_of_truth')}>{c.primary_conversion_purpose || '—'}</HeaderFact>
          <HeaderFact label={t('linked_count')}><span className="tnum">{platforms.length}</span></HeaderFact>
          <HeaderFact label={t('cmc_stage')}>{classSelect('stage', c.stage, STAGE_KEYS, (k) => stageLabel(k, locale))}</HeaderFact>
          <HeaderFact label={t('cmc_performance')}>{classSelect('performance_label', c.performance_label, PERFORMANCE_KEYS, (k) => performanceLabel(k, locale))}</HeaderFact>
          <HeaderFact label={t('cmc_priority')}>{classSelect('priority', c.priority, PRIORITY_KEYS, (k) => priorityLabel(k, locale))}</HeaderFact>
        </div>
      </Card>

      {actionError && <Alert severity="danger" title={actionError} />}

      <Tabs items={tabs} active={tab} onChange={setTab} />

      {tab === 'overview' && (
        <TabPanel>
          {/* CMC-2 — KPIs + executive summary (this campaign only). */}
          <div className="mb-4 flex items-center justify-between gap-2">
            <CardTitle>{t('cmc_performance')}</CardTitle>
            <RangeTabs value={days} onChange={setDays} />
          </div>
          <CampaignKpis campaign={c} projectId={projectId} range={range} />
          <div className="my-4">
            <CardTitle>الملخص التنفيذي</CardTitle>
            <div className="mt-2"><CampaignExecutiveSummary campaign={c} projectId={projectId} range={range} locale={locale} /></div>
          </div>
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Card>
              <CardTitle>{t('tab_overview')}</CardTitle>
              <div className="mt-2">
                <Row label={t('objective_label')}>{objectiveLabel(c.objective, locale)}</Row>
                <Row label={t('status_label')}><Badge tone={campaignStatusTone(c.status)}>{campaignStatusLabel(c.status, locale)}</Badge></Row>
                <Row label={t('cmc_stage')}>{c.stage ? stageLabel(c.stage, locale) : '—'}</Row>
                <Row label={t('cmc_priority')}>{c.priority ? priorityLabel(c.priority, locale) : '—'}</Row>
                <Row label={t('budget_label')}>
                  <span className="tnum">{c.total_budget != null ? `${c.total_budget.toLocaleString('en-US')} ${c.budget_currency}` : '—'}</span>
                </Row>
                <Row label={t('period_label')}>
                  <span className="tnum text-xs">{c.starts_on || c.ends_on ? `${c.starts_on ?? '…'} → ${c.ends_on ?? '…'}` : '—'}</span>
                </Row>
                <Row label={t('linked_count')}><Badge tone="info">{platforms.length}</Badge></Row>
              </div>
            </Card>
            <Card>
              <CardTitle>{t('audience_label')}</CardTitle>
              <p className="mt-2 whitespace-pre-wrap text-sm text-text-secondary">{c.audience || '—'}</p>
            </Card>
          </div>
          {/* CMC-5 — recent activity timeline. */}
          <Card className="mt-4">
            <CardTitle>{t('tab_activity')}</CardTitle>
            <div className="mt-3"><CampaignActivityTab campaign={c} projectId={projectId} limit={6} /></div>
          </Card>
        </TabPanel>
      )}

      {tab === 'platforms' && (
        <TabPanel>
          <div className="mb-4 flex items-center justify-end"><RangeTabs value={days} onChange={setDays} /></div>
          {linkedQuery.isLoading ? (
            <Skeleton className="h-40 w-full" />
          ) : (
            <CampaignPlatformsTab
              campaign={c} projectId={projectId} range={range} locale={locale} linked={linked}
              onLink={() => setLinkOpen(true)} onUnlink={(id) => unlinkMutation.mutate(id)}
              unlinkingId={unlinkMutation.isPending ? (unlinkMutation.variables as string) : undefined}
              canUpdate={canUpdate}
            />
          )}
        </TabPanel>
      )}

      {tab === 'performance' && (
        <TabPanel>
          <div className="mb-4 flex items-center justify-end"><RangeTabs value={days} onChange={setDays} /></div>
          <CampaignPerformanceTab campaign={c} projectId={projectId} range={range} locale={locale} />
        </TabPanel>
      )}

      {tab === 'budget' && (
        <TabPanel>
          <div className="mb-4 flex items-center justify-end"><RangeTabs value={days} onChange={setDays} /></div>
          <CampaignBudgetTab campaign={c} projectId={projectId} range={range} locale={locale} />
        </TabPanel>
      )}
      {tab === 'funnel' && (
        <TabPanel>
          <div className="mb-4 flex items-center justify-end"><RangeTabs value={days} onChange={setDays} /></div>
          <CampaignFunnelTab campaign={c} projectId={projectId} range={range} />
        </TabPanel>
      )}
      {tab === 'activity' && (
        <TabPanel>
          <CampaignActivityTab campaign={c} projectId={projectId} />
        </TabPanel>
      )}
      {tab === 'alerts' && (
        <TabPanel>
          <CampaignAlertsTab campaign={c} projectId={projectId} />
        </TabPanel>
      )}
      {tab === 'reports' && (
        <TabPanel>
          <CampaignReportsTab campaign={c} projectId={projectId} />
        </TabPanel>
      )}

      {tab === 'creatives' && (
        <TabPanel>
          <div className="mb-4 flex items-center justify-end"><RangeTabs value={days} onChange={setDays} /></div>
          <CampaignCreativesTab campaign={c} projectId={projectId} range={range} locale={locale} />
        </TabPanel>
      )}
      {tab === 'notes' && (
        <TabPanel>
          <CampaignNotesTab campaign={c} projectId={projectId} canUpdate={canUpdate} canApprove={canApprove} />
        </TabPanel>
      )}

      <CampaignFormModal open={editOpen} onClose={() => setEditOpen(false)} projectId={projectId} campaign={c} />
      <LinkExternalModal open={linkOpen} onClose={() => setLinkOpen(false)} projectId={projectId} campaignId={campaignId} />
    </section>
  )
}

function HeaderFact({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-[11px] uppercase tracking-wide text-text-muted">{label}</span>
      <span className="text-sm font-semibold text-text-primary">{children}</span>
    </div>
  )
}
