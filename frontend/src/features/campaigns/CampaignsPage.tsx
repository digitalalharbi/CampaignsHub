import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { listCampaigns } from './api'
import { CampaignFormModal } from './CampaignFormModal'
import { campaignStatusLabel, campaignStatusTone, objectiveLabel } from './labels'
import { CAMPAIGN_OBJECTIVES, CAMPAIGN_STATUSES, type UnifiedCampaign } from './types'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Select } from '@/components/ui/Select'
import { EmptyState } from '@/components/ui/States'
import { useT } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'

function money(value: number | null, currency: string): string {
  if (value == null) return '—'
  return `${value.toLocaleString('en-US')} ${currency}`
}

function period(start: string | null, end: string | null): string {
  if (!start && !end) return '—'
  return `${start ?? '…'} → ${end ?? '…'}`
}

export function CampaignsPage() {
  const t = useT()
  const locale = useUi((s) => s.locale)
  const navigate = useNavigate()
  const canCreate = useAuth((s) => s.hasPermission('campaigns.create'))
  const { currentProjectId: projectId } = useProject()

  const [status, setStatus] = useState('')
  const [objective, setObjective] = useState('')
  const [modalOpen, setModalOpen] = useState(false)

  const campaignsQuery = useQuery({
    queryKey: ['project', projectId, 'campaigns', { status, objective }],
    queryFn: () => listCampaigns(projectId!, { status: status || undefined, objective: objective || undefined }),
    enabled: Boolean(projectId),
  })

  const data = campaignsQuery.data
  const rows = data ?? []
  const summary = useMemo(() => {
    const list = data ?? []
    const active = list.filter((c) => c.status === 'active').length
    const budget = list.reduce((sum, c) => sum + (c.total_budget ?? 0), 0)
    return { total: list.length, active, budget }
  }, [data])

  const columns: Column<UnifiedCampaign>[] = [
    { key: 'name', header: t('campaign_name'), sortable: true },
    { key: 'objective', header: t('objective_label'), sortable: true, render: (r) => objectiveLabel(r.objective, locale) },
    {
      key: 'status',
      header: t('status_label'),
      render: (r) => <Badge tone={campaignStatusTone(r.status)}>{campaignStatusLabel(r.status, locale)}</Badge>,
    },
    {
      key: 'total_budget',
      header: t('budget_label'),
      align: 'end',
      sortable: true,
      value: (r) => r.total_budget ?? 0,
      render: (r) => <span className="tnum">{money(r.total_budget, r.budget_currency)}</span>,
    },
    { key: 'period', header: t('period_label'), render: (r) => <span className="tnum text-[12px]">{period(r.starts_on, r.ends_on)}</span> },
    {
      key: 'linked',
      header: t('linked_count'),
      align: 'center',
      value: (r) => r.external_campaigns_count ?? 0,
      render: (r) => <Badge tone="info">{r.external_campaigns_count ?? 0}</Badge>,
    },
    {
      key: 'actions',
      header: t('actions'),
      align: 'end',
      render: (r) => (
        <Button variant="secondary" onClick={() => navigate(`/campaigns/${projectId}/${r.id}`)}>
          {t('open')}
        </Button>
      ),
    },
  ]

  return (
    <section className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="font-[var(--font-heading)] text-xl font-extrabold">{t('campaigns')}</h1>
          <p className="mt-1 text-[13px] text-text-secondary">{t('campaigns_subtitle')}</p>
        </div>
        <div className="flex items-center gap-2">
          {canCreate && projectId && (
            <Button onClick={() => setModalOpen(true)}>
              <Plus size={15} /> {t('new_campaign')}
            </Button>
          )}
        </div>
      </div>

      {!projectId ? (
        <EmptyState title={t('no_project_selected')} />
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <Card>
              <div className="text-[12px] text-text-muted">{t('campaigns')}</div>
              <div className="tnum mt-1 text-2xl font-extrabold">{summary.total}</div>
            </Card>
            <Card>
              <div className="text-[12px] text-text-muted">{campaignStatusLabel('active', locale)}</div>
              <div className="tnum mt-1 text-2xl font-extrabold">{summary.active}</div>
            </Card>
            <Card>
              <div className="text-[12px] text-text-muted">{t('budget_label')} (SAR)</div>
              <div className="tnum mt-1 text-2xl font-extrabold">{summary.budget.toLocaleString('en-US')}</div>
            </Card>
          </div>

          <div className="flex flex-wrap gap-2">
            <Select
              value={status}
              onChange={(e) => setStatus(e.target.value)}
              className="w-auto"
              options={[
                { value: '', label: `${t('status_label')}: ${t('all')}` },
                ...CAMPAIGN_STATUSES.map((s) => ({ value: s, label: campaignStatusLabel(s, locale) })),
              ]}
            />
            <Select
              value={objective}
              onChange={(e) => setObjective(e.target.value)}
              className="w-auto"
              options={[
                { value: '', label: `${t('objective_label')}: ${t('all')}` },
                ...CAMPAIGN_OBJECTIVES.map((o) => ({ value: o, label: objectiveLabel(o, locale) })),
              ]}
            />
          </div>

          <DataTable
            columns={columns}
            rows={rows}
            rowKey={(r) => r.id}
            loading={campaignsQuery.isLoading}
            error={campaignsQuery.isError}
            onRetry={() => campaignsQuery.refetch()}
            emptyTitle={t('no_campaigns')}
          />
        </>
      )}

      {projectId && (
        <CampaignFormModal open={modalOpen} onClose={() => setModalOpen(false)} projectId={projectId} />
      )}
    </section>
  )
}
