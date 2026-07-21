import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { convertLead, listLeads } from './api'
import { NewLeadModal } from './NewLeadModal'
import { sourceLabel, statusLabel, statusTone } from './labels'
import { LEAD_SOURCES, LEAD_STATUSES, type Lead } from './types'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Select } from '@/components/ui/Select'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

export function LeadsPage() {
  const t = useT()
  const locale = useUi((s) => s.locale)
  const canConvert = useAuth((s) => s.hasPermission('leads.convert'))
  const canCreate = useAuth((s) => s.hasPermission('leads.create'))
  const queryClient = useQueryClient()

  const [status, setStatus] = useState('')
  const [source, setSource] = useState('')
  const [modalOpen, setModalOpen] = useState(false)

  const leadsQuery = useQuery({
    queryKey: ['leads', { status, source }],
    queryFn: () => listLeads({ status: status || undefined, source: source || undefined, per_page: 100 }),
  })

  const convertMutation = useMutation({
    mutationFn: convertLead,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['leads'] }),
  })

  const columns: Column<Lead>[] = [
    { key: 'name', header: t('name'), sortable: true },
    {
      key: 'source',
      header: t('source_label'),
      sortable: true,
      render: (r) => sourceLabel(r.source, locale),
    },
    {
      key: 'status',
      header: t('status_label'),
      render: (r) => <Badge tone={statusTone(r.status)}>{statusLabel(r.status, locale)}</Badge>,
    },
    {
      key: 'estimated_value',
      header: `${t('value_label')} (SAR)`,
      align: 'end',
      sortable: true,
      value: (r) => r.estimated_value,
      render: (r) => <span className="tnum">{r.estimated_value.toLocaleString('en-US')}</span>,
    },
    {
      key: 'actions',
      header: t('actions'),
      align: 'end',
      render: (r) =>
        r.is_converted ? (
          <Badge tone="success">{t('converted')}</Badge>
        ) : canConvert ? (
          <Button
            variant="secondary"
            onClick={() => convertMutation.mutate(r.id)}
            loading={convertMutation.isPending && convertMutation.variables === r.id}
          >
            {t('convert')}
          </Button>
        ) : (
          <span className="text-text-muted">—</span>
        ),
    },
  ]

  const convertError = convertMutation.isError ? toApiError(convertMutation.error) : null

  return (
    <section className="space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="font-[var(--font-heading)] text-xl font-extrabold">{t('leads')}</h1>
          <p className="mt-1 text-[13px] text-text-secondary">
            {t('data_source')}: MediaBuying API
          </p>
        </div>
        {canCreate && (
          <Button onClick={() => setModalOpen(true)}>
            <Plus size={15} /> {t('add_lead')}
          </Button>
        )}
      </div>

      {convertError && <Alert severity="danger" title={convertError.message} />}

      <div className="flex flex-wrap gap-2">
        <Select
          value={status}
          onChange={(e) => setStatus(e.target.value)}
          className="w-auto"
          options={[
            { value: '', label: `${t('status_label')}: ${t('all')}` },
            ...LEAD_STATUSES.map((s) => ({ value: s, label: statusLabel(s, locale) })),
          ]}
        />
        <Select
          value={source}
          onChange={(e) => setSource(e.target.value)}
          className="w-auto"
          options={[
            { value: '', label: `${t('source_label')}: ${t('all')}` },
            ...LEAD_SOURCES.map((s) => ({ value: s, label: sourceLabel(s, locale) })),
          ]}
        />
      </div>

      <DataTable
        columns={columns}
        rows={leadsQuery.data?.leads ?? []}
        rowKey={(r) => r.id}
        loading={leadsQuery.isLoading}
        error={leadsQuery.isError}
        onRetry={() => leadsQuery.refetch()}
        emptyTitle={t('no_leads')}
      />

      <NewLeadModal open={modalOpen} onClose={() => setModalOpen(false)} />
    </section>
  )
}
