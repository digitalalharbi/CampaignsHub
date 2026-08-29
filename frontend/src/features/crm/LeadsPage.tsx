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
  const [uniqueOnly, setUniqueOnly] = useState(false)
  const [modalOpen, setModalOpen] = useState(false)

  const leadsQuery = useQuery({
    queryKey: ['leads', { status, source, uniqueOnly }],
    queryFn: () =>
      listLeads({
        status: status || undefined,
        source: source || undefined,
        unique: uniqueOnly ? 1 : undefined,
        per_page: 100,
      }),
  })

  const counts = leadsQuery.data?.counts ?? null

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
      /*
       * LEAD-DEDUP-001 on the row. The same person arriving twice is recorded twice and counted
       * once, and this column is where «counted once» stops being a claim about the database.
       *
       * Three distinct states, deliberately not two. `ambiguous` is not a kind of duplicate: the
       * email says one person and the phone says another, so the lead was linked to NEITHER on
       * purpose. Showing it as a duplicate would present a refusal to guess as a resolved match,
       * and showing it as an ordinary lead would hide the one row a human should look at.
       */
      key: 'duplicate',
      header: locale === 'ar' ? 'التكرار' : 'Duplicate',
      render: (r) =>
        r.duplicate_reason === 'ambiguous' ? (
          <Badge tone="warning" data-testid={`lead-ambiguous-${r.id}`}>
            {locale === 'ar' ? 'هوية متضاربة' : 'Conflicting identity'}
          </Badge>
        ) : r.canonical_lead_id != null ? (
          <Badge tone="neutral" data-testid={`lead-duplicate-${r.id}`}>
            {locale === 'ar' ? 'تكرار' : 'Duplicate'}
          </Badge>
        ) : (r.duplicate_count ?? 0) > 0 ? (
          <span className="tnum text-text-secondary" data-testid={`lead-absorbed-${r.id}`}>
            {locale === 'ar' ? `+${r.duplicate_count} وصول` : `+${r.duplicate_count} arrivals`}
          </span>
        ) : (
          <span className="text-text-muted">—</span>
        ),
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
          {/*
            UX-IDENTITY-001, and a naming regression with it.

            This line read «مصدر البيانات: MediaBuying API» — the product's OLD name, on a page a
            customer can open, months after IDENTITY-PROD renamed everything to CampaignsHub. A
            purpose sentence replaces it, so the fix removes the wrong name rather than correcting it
            into a sentence nobody needed.
          */}
          <p className="mt-1 max-w-2xl text-sm text-text-secondary">
            {locale === 'ar'
              ? 'العملاء المحتملون الواردون من حملاتك ونماذجك — مع مصدر كل واحد وحالته.'
              : 'The leads your campaigns and forms brought in — each with its source and where it stands.'}
          </p>
        </div>
        {canCreate && (
          <Button onClick={() => setModalOpen(true)}>
            <Plus size={15} /> {t('add_lead')}
          </Button>
        )}
      </div>

      {convertError && <Alert severity="danger" title={convertError.message} />}

      {/*
        Both figures, always, side by side.

        A single «total» would have to be arrivals or people, and whichever it was would be wrong for
        the other question with nothing on screen to say which: an operator judging a campaign wants
        arrivals, a salesperson working the list wants people. Rendered only when the server actually
        sent them — a client that invented a zero would be stating something it was never told.
      */}
      {counts !== null && (
        <p data-testid="lead-counts" className="text-sm text-text-secondary">
          {locale === 'ar'
            ? `وصل ${counts.received} — منهم ${counts.unique} شخصًا مختلفًا`
            : `${counts.received} received — ${counts.unique} distinct people`}
        </p>
      )}

      <div className="flex flex-wrap gap-2">
        <Select
          value={uniqueOnly ? 'unique' : 'all'}
          onChange={(e) => setUniqueOnly(e.target.value === 'unique')}
          className="w-auto"
          data-testid="lead-dedup-view"
          options={[
            { value: 'all', label: locale === 'ar' ? 'كل الوصولات' : 'Every arrival' },
            { value: 'unique', label: locale === 'ar' ? 'الأشخاص فقط' : 'Distinct people only' },
          ]}
        />
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
