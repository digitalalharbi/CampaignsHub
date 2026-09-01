import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { convertLead, listLeads } from './api'
import { LeadAttributionTrail } from './LeadAttributionTrail'
import { NewLeadModal } from './NewLeadModal'
import { sourceLabel, statusLabel, statusTone } from './labels'
import { LEAD_SOURCES, LEAD_STATUSES, type Lead } from './types'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Modal } from '@/components/ui/Modal'
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
  const [trail, setTrail] = useState<Lead | null>(null)

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
      /*
       * LEAD-SOURCE-ATTRIBUTION-001 — which campaign paid for this row.
       *
       * `source` above says «paid», which is the answer that cannot be acted on: the whole reason a
       * lead-generation client is spending is to learn WHICH ad produced a buyer. The campaign name
       * as it read at ingestion is shown, because a campaign gets renamed and a lead from last
       * quarter must still say what it was called then.
       *
       * The gap marker is not decoration. It appears only where the platform DOES return a rung and
       * this lead has not got it — a real defect in our own pipeline, which otherwise stays
       * invisible behind the same dash as every honest platform limit.
       */
      key: 'attribution',
      header: locale === 'ar' ? 'مصدر الإعلان' : 'Ad source',
      render: (r) => {
        const chain = r.attribution
        if (chain == null) return <span className="text-text-muted">—</span>

        const campaign = chain.rungs.find((rung) => rung.rung === 'campaign')
        const gaps = chain.rungs.filter((rung) => rung.state === 'missing').length

        return (
          <button
            type="button"
            className="hover:text-accent flex flex-col items-start gap-0.5 text-start"
            onClick={() => setTrail(r)}
            data-testid={`lead-source-${r.id}`}
            data-complete={chain.complete ? 'true' : 'false'}
          >
            <span className="truncate">
              {campaign?.state === 'named'
                ? (campaign.name ?? campaign.id)
                : chain.platform.label != null
                  ? (locale === 'ar' ? chain.platform.label : (chain.platform.label_en ?? chain.platform.label))
                  : locale === 'ar'
                    ? chain.route_label
                    : chain.route_label_en}
            </span>
            {gaps > 0 && (
              /*
               * Arabic counts its nouns rather than bracketing a plural: one is «مستوى ناقص», two is
               * a dual, and three or more takes the plural. `${n} مستوى ناقص` reads as broken Arabic
               * to the readers this product is for, and «level(s)» is the English version of the same
               * shrug. The digits stay Latin — the language is not the numerals.
               */
              <span className="text-warning text-xs" data-testid={`lead-source-gap-${r.id}`}>
                {locale === 'ar'
                  ? gaps === 1
                    ? 'مستوى ناقص'
                    : gaps === 2
                      ? 'مستويان ناقصان'
                      : `${gaps} مستويات ناقصة`
                  : gaps === 1
                    ? '1 level missing'
                    : `${gaps} levels missing`}
              </span>
            )}
          </button>
        )
      },
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
        <div className="flex items-center gap-3">
          {/*
            LEAD-OPERATIONS-001 — the way into the follow-up workspace.

            Here rather than in the rail: the rail is gated by an entitlement key and there is no
            «leads» one, so hanging a link there would put a nav decision inside a feature commit.
            This is where a reader asking «what is overdue» already is.
          */}
          <Link
            to="/app/leads/workspace"
            className="text-accent text-sm hover:underline"
            data-testid="leads-workspace-link"
          >
            {locale === 'ar' ? 'المتابعة' : 'Follow-up'}
          </Link>
          {canCreate && (
            <Button onClick={() => setModalOpen(true)}>
              <Plus size={15} /> {t('add_lead')}
            </Button>
          )}
        </div>
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
      <Modal
        open={trail !== null}
        onClose={() => setTrail(null)}
        title={locale === 'ar' ? 'مصدر هذا العميل المحتمل' : 'Where this lead came from'}
      >
        {trail?.attribution != null && (
          <LeadAttributionTrail attribution={trail.attribution} locale={locale} />
        )}
      </Modal>
    </section>
  )
}
