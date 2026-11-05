import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  fetchIntegrationAudit, STATUS_DIMENSIONS,
  type HubConnection, type IntegrationAuditEntry, type IntegrationStatus, type StatusDimension,
} from './api'
import { CONNECTION_COPY, needsReauthorising } from './connectionCopy'
import { DIMENSION_COPY, statusTone } from './StatusChips'
import { PageSizer, Pager, type PageSize } from './PageSizer'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { fmtDateTime } from '@/lib/datetime'
import { useUi } from '@/stores/ui'

/**
 * INTEG-UNIFIED-002 (contract §1.12) — «التدقيق»: the third of the centre's three sections.
 *
 * Two questions, in the order they are asked when something is wrong:
 *
 *  1. «ما الذي يحتاج إجراءً؟» — connection health and authorisation, read from the SAME five statuses
 *     the sources section draws. Only what somebody must act on is listed, each with the server's own
 *     remedy and the control that performs it. Nothing here is computed a second time.
 *  2. «من فعل ماذا؟» — the log: the tenant's own read of the existing audit trail, paged at
 *     10 / 25 / 50. The server sends no payload, address or agent, and names the actor by name.
 *
 * It is a reading surface. Every control on it opens something that already exists — the
 * re-authorise flow, the selection editor, the source's own drawer.
 */
export function IntegrationAudit({ connections, loading = false, onReauthorise, onManage, onOpen }: {
  connections: HubConnection[]
  /** The sources are still being read: «nothing needs action» would be a claim nobody has checked yet. */
  loading?: boolean
  onReauthorise: (connection: HubConnection) => void
  onManage: (connection: HubConnection) => void
  onOpen: (connection: HubConnection) => void
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const findings = connections.flatMap((connection) => findingsOf(connection))

  return (
    <section className="flex min-w-0 flex-col gap-4" data-testid="hub-section-audit" id="hub-audit" aria-labelledby="hub-audit-title">
      <div className="min-w-0">
        <h2 id="hub-audit-title" className="text-base font-bold text-text-primary">{ar ? 'التدقيق' : 'Audit'}</h2>
        <p className="text-xs text-text-secondary">
          {ar
            ? 'صحة الاتصال والتفويض وما يحتاج إجراءً، ثم سجل من فعل ماذا بكل مصدر.'
            : 'Connection and authorisation health and what needs action, then the log of who did what to each source.'}
        </p>
      </div>

      <div className="flex min-w-0 flex-col gap-2" data-testid="audit-attention">
        <h3 className="text-sm font-bold text-text-primary">{ar ? 'ما يحتاج إجراءً' : 'What needs action'}</h3>

        {loading ? (
          <Skeleton className="h-16 w-full" />
        ) : findings.length === 0 ? (
          <p className="rounded-lg border border-dashed border-border p-4 text-center text-xs text-text-secondary" data-testid="audit-attention-empty">
            {connections.length === 0
              ? (ar ? 'لا مصدر مرتبط بعد، فلا شيء يُدقَّق.' : 'Nothing is linked yet, so there is nothing to audit.')
              : (ar ? 'لا شيء يحتاج إجراءً الآن.' : 'Nothing needs action right now.')}
          </p>
        ) : (
          <ul className="flex min-w-0 flex-col divide-y divide-border overflow-hidden rounded-2xl border border-border bg-surface">
            {findings.map((finding) => (
              <FindingRow
                key={`${finding.connection.id}-${finding.dimension}`}
                finding={finding}
                ar={ar}
                onReauthorise={onReauthorise}
                onManage={onManage}
                onOpen={onOpen}
              />
            ))}
          </ul>
        )}
      </div>

      <AuditLog ar={ar} />
    </section>
  )
}

interface Finding {
  connection: HubConnection
  dimension: StatusDimension
  status: IntegrationStatus
}

/**
 * What somebody must act on, for one source.
 *
 * A status is a finding when the server attached a remedy to it, or when the error status says so.
 * One remedy is listed once: «re-authorise» stated by both the authorisation and the error status is
 * one act, and two rows for it would read as two problems.
 */
export function findingsOf(connection: HubConnection): Finding[] {
  if ((connection.removed_at ?? null) !== null) return []

  const statuses = connection.statuses ?? null
  if (statuses === null) {
    // An older response without the five statuses: the one fact this page can still state honestly.
    if (!needsReauthorising(connection.connection_state)) return []
    const copy = CONNECTION_COPY[connection.connection_state]
    return [{
      connection,
      dimension: 'authorisation',
      status: { state: 'reauth_required', label_ar: copy.ar, label_en: copy.en, since: null, as_of: null, detail: null, action: null },
    }]
  }

  const seen = new Set<string>()
  const out: Finding[] = []
  for (const dimension of STATUS_DIMENSIONS) {
    const status = statuses[dimension]
    const raised = status.action !== null || (dimension === 'error' && status.state !== 'none')
    // «wait» is the server saying nobody has to do anything; it is a state, not a finding.
    if (!raised || status.action?.code === 'wait') continue
    const key = status.action?.code ?? `${dimension}:${status.state}`
    if (seen.has(key)) continue
    seen.add(key)
    out.push({ connection, dimension, status })
  }
  return out
}

function FindingRow({ finding, ar, onReauthorise, onManage, onOpen }: {
  finding: Finding
  ar: boolean
  onReauthorise: (connection: HubConnection) => void
  onManage: (connection: HubConnection) => void
  onOpen: (connection: HubConnection) => void
}) {
  const { connection, dimension, status } = finding
  const account = connection.provider_account ?? null
  const platformAccount = account === null ? null : (account.email ?? account.label)
  const code = status.action?.code ?? ''
  /*
   * Which existing control performs the remedy. Re-authorising and choosing accounts have a flow of
   * their own; everything else — retrying a sync, a currency, an unmatched campaign — is answered
   * inside the source's own drawer, so the row opens it rather than inventing a button that cannot
   * do the thing.
   */
  const act = code.startsWith('reauthorize') && connection.status_profile?.auth !== 'api_key'
    ? { label: ar ? 'إعادة المصادقة' : 'Re-authorise', run: () => onReauthorise(connection) }
    : code === 'choose_accounts' || code === 'assign_project'
      ? { label: ar ? 'تعديل الاختيار' : 'Edit the selection', run: () => onManage(connection) }
      : { label: ar ? 'فتح المصدر' : 'Open the source', run: () => onOpen(connection) }

  return (
    <li className="flex min-w-0 flex-col gap-2 p-3 sm:flex-row sm:items-center sm:gap-4" data-testid={`audit-finding-${connection.id}-${dimension}`}>
      <div className="min-w-0 flex-1">
        <p className="flex min-w-0 flex-wrap items-center gap-2">
          <span className="min-w-0 truncate text-sm font-bold text-text-primary">{ar ? connection.label_ar : connection.label}</span>
          <Badge tone={statusTone(dimension, status.state)}>
            <span className="font-semibold opacity-80">{ar ? DIMENSION_COPY[dimension].ar : DIMENSION_COPY[dimension].en}:</span>
            {' '}
            <span>{ar ? status.label_ar : status.label_en}</span>
          </Badge>
        </p>
        <p className="truncate text-[11px] text-text-secondary">
          {platformAccount === null
            ? (ar ? 'لم يُسجَّل حساب المنصة' : 'Platform account not recorded')
            : `${ar ? 'حساب المنصة' : 'Platform account'}: ${platformAccount}`}
        </p>
        {status.action !== null && (
          <p className="mt-0.5 text-xs text-text-primary" data-testid={`audit-remedy-${connection.id}-${dimension}`}>
            {ar ? status.action.label_ar : status.action.label_en}
          </p>
        )}
        {status.since !== null && (
          <p className="text-[11px] text-text-muted">
            {ar ? 'منذ' : 'Since'} <span className="tnum">{fmtDateTime(status.since)}</span>
          </p>
        )}
      </div>
      <Button size="sm" variant="secondary" onClick={act.run} data-testid={`audit-act-${connection.id}-${dimension}`}>
        {act.label}
      </Button>
    </li>
  )
}

/** The acts this log can name. An act it has not met is shown by its own code rather than guessed at. */
const ACTION_COPY: Record<string, { ar: string; en: string }> = {
  'integration.connect': { ar: 'بدء ربط مصدر', en: 'Connection started' },
  'integration.connection.created': { ar: 'إنشاء تفويض', en: 'Authorisation created' },
  'integration.connection.opened': { ar: 'فتح تفويض', en: 'Authorisation opened' },
  'integration.connection.revoked': { ar: 'إلغاء التفويض', en: 'Authorisation revoked' },
  'integration.connection.removed': { ar: 'إزالة المصدر', en: 'Source removed' },
  'integration.measurement.connected': { ar: 'ربط مصدر قياس', en: 'Measurement source connected' },
  'integration.store.connected': { ar: 'ربط متجر', en: 'Store connected' },
  'integration.store.claimed': { ar: 'تثبيت ملكية متجر', en: 'Store claimed' },
  'integration.bound': { ar: 'ربط حساب بمشروع', en: 'Account linked to a project' },
  'integration.detached': { ar: 'فصل حساب عن مشروع', en: 'Account detached from a project' },
  'integration.selection.confirmed': { ar: 'تأكيد اختيار الحسابات', en: 'Account selection confirmed' },
  'integration.selection.updated': { ar: 'تعديل اختيار الحسابات', en: 'Account selection changed' },
  'integration.account.unlinked': { ar: 'إزالة حساب من مشروع', en: 'Account removed from a project' },
  'integration.account.moved_between_meta_apps': { ar: 'نقل حساب بين تطبيقَي ميتا', en: 'Account moved between Meta apps' },
  'commerce.store.assigned': { ar: 'إسناد متجر إلى مشروع', en: 'Store assigned to a project' },
  'commerce.store.unassigned': { ar: 'إلغاء إسناد متجر', en: 'Store unassigned' },
  'integration.discovery.refreshed': { ar: 'تحديث الحسابات المكتشفة', en: 'Discovered accounts refreshed' },
  'metrics.sync_requested': { ar: 'طلب مزامنة يدوية', en: 'Manual sync requested' },
}

const CATEGORY_COPY: Record<'authorisation' | 'binding' | 'sync', { ar: string; en: string }> = {
  authorisation: { ar: 'التفويض', en: 'Authorisation' },
  binding: { ar: 'الربط', en: 'Linking' },
  sync: { ar: 'المزامنة والاكتشاف', en: 'Sync and discovery' },
}

const SUBJECT_COPY: Record<NonNullable<IntegrationAuditEntry['subject_kind']>, { ar: string; en: string }> = {
  connection: { ar: 'تفويض', en: 'Authorisation' },
  account: { ar: 'حساب', en: 'Account' },
  project: { ar: 'مشروع', en: 'Project' },
  binding: { ar: 'ربط', en: 'Link' },
}

type Category = keyof typeof CATEGORY_COPY

function AuditLog({ ar }: { ar: boolean }) {
  const [category, setCategory] = useState<Category | ''>('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState<PageSize>(10)

  const log = useQuery({
    queryKey: ['integration-audit', { category, page, perPage }],
    queryFn: () => fetchIntegrationAudit({ category: category === '' ? null : category, page, perPage }),
    placeholderData: (previous) => previous,
    retry: false,
  })

  const entries = log.data?.entries ?? []
  const meta = log.data?.meta ?? null

  return (
    <div className="flex min-w-0 flex-col gap-2" data-testid="audit-log">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <h3 className="text-sm font-bold text-text-primary">{ar ? 'سجل الإجراءات' : 'Activity log'}</h3>
        <div className="flex flex-wrap items-center gap-3">
          <label className="flex items-center gap-1.5 text-xs text-text-secondary">
            {ar ? 'النوع' : 'Type'}
            <select
              value={category}
              onChange={(e) => { setCategory(e.target.value as Category | ''); setPage(1) }}
              data-testid="audit-category"
              className="rounded-lg border border-border bg-background px-2 py-1 text-xs text-text-primary focus:border-brand-500 focus:outline-none"
            >
              <option value="">{ar ? 'الكل' : 'All'}</option>
              {(Object.keys(CATEGORY_COPY) as Category[]).map((key) => (
                <option key={key} value={key}>{ar ? CATEGORY_COPY[key].ar : CATEGORY_COPY[key].en}</option>
              ))}
            </select>
          </label>
          <PageSizer value={perPage} onChange={(size) => { setPerPage(size); setPage(1) }} ar={ar} testId="audit-page-size" />
        </div>
      </div>

      {log.isLoading ? (
        <Skeleton className="h-32 w-full" />
      ) : log.isError ? (
        <ErrorState title={ar ? 'تعذّر تحميل سجل الإجراءات' : 'Could not load the activity log'} onRetry={() => void log.refetch()} />
      ) : entries.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border p-4 text-center text-xs text-text-secondary" data-testid="audit-log-empty">
          {ar ? 'لا إجراء مسجَّل بعد.' : 'No act has been recorded yet.'}
        </p>
      ) : (
        /* The table may scroll inside its own box on a narrow screen; the page never does. */
        <div className="min-w-0 overflow-x-auto rounded-2xl border border-border bg-surface">
          <table className="w-full min-w-[36rem] text-start text-xs" data-testid="audit-log-table">
            <thead className="bg-surface-secondary text-text-secondary">
              <tr>
                <th scope="col" className="px-3 py-2 text-start font-semibold">{ar ? 'الوقت' : 'When'}</th>
                <th scope="col" className="px-3 py-2 text-start font-semibold">{ar ? 'الإجراء' : 'Act'}</th>
                <th scope="col" className="px-3 py-2 text-start font-semibold">{ar ? 'على ماذا' : 'On what'}</th>
                <th scope="col" className="px-3 py-2 text-start font-semibold">{ar ? 'المنفّذ' : 'By'}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {entries.map((entry) => <AuditRow key={entry.id} entry={entry} ar={ar} />)}
            </tbody>
          </table>
        </div>
      )}

      {meta !== null && meta.total > 0 && (
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-[11px] text-text-muted" data-testid="audit-total">
            {ar ? 'الإجمالي' : 'Total'}: <span className="tnum">{meta.total.toLocaleString('en-US')}</span>
          </p>
          <Pager page={meta.current_page} lastPage={meta.last_page} onPage={setPage} ar={ar} testId="audit-pager" />
        </div>
      )}
    </div>
  )
}

function AuditRow({ entry, ar }: { entry: IntegrationAuditEntry; ar: boolean }) {
  const known = ACTION_COPY[entry.action]
  const provider = ar ? entry.provider_label_ar : entry.provider_label
  const kind = entry.subject_kind === null ? null : (ar ? SUBJECT_COPY[entry.subject_kind].ar : SUBJECT_COPY[entry.subject_kind].en)
  const subject = [provider, entry.subject_name ?? kind].filter((part): part is string => part !== null && part !== '').join(' · ')

  return (
    <tr data-testid={`audit-row-${entry.id}`}>
      <td className="whitespace-nowrap px-3 py-2 text-text-secondary">
        <span className="tnum">{entry.created_at === null ? '—' : fmtDateTime(entry.created_at)}</span>
      </td>
      <td className="px-3 py-2 font-semibold text-text-primary">
        {known !== undefined
          ? (ar ? known.ar : known.en)
          : <span className="font-mono" dir="ltr">{entry.action}</span>}
      </td>
      <td className="px-3 py-2 text-text-secondary">{subject === '' ? '—' : subject}</td>
      <td className="px-3 py-2 text-text-secondary" data-testid={`audit-actor-${entry.id}`}>
        {/* No actor: a scheduled sweep, or a user who has since gone. Said as «not recorded», never as an invented name. */}
        {entry.actor_name ?? (ar ? 'غير مسجَّل' : 'Not recorded')}
      </td>
    </tr>
  )
}
