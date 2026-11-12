import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { AlarmClock, Download, Inbox, MessageSquare, Paperclip, UserRound, X } from 'lucide-react'
import {
  fetchServiceRequest, fetchServiceRequests, serviceRequestFileUrl, updateServiceRequest,
  type ServiceRequestFilter, type ServiceRequestList, type ServiceRequestRow,
} from './api'
import { Button } from '@/components/ui/Button'
import { Pager } from '@/features/integrations/PageSizer'
import { Num } from '@/components/ui/Num'
import { EmptyState, ErrorState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { fmtDate, fmtDateTime } from '@/lib/datetime'
import { useUi } from '@/stores/ui'

/**
 * `/admin/requests` — «الطلبات» (PLATFORM-SERVICE-INBOX-001).
 *
 * The services somebody ordered from the official CampaignsHub site. They belong to the platform owner: the
 * server decides that at intake, from the host the order arrived on, and nothing a subscriber owns is in this
 * list — an agency's own requests stay in that agency's inbox.
 *
 * The three counters are the screen's answer before anyone reads a row: how many are open, how many nobody has
 * picked up, how many are past their time. A row opens beside the list, where the owner picks who handles it and
 * moves it on; the server refuses a move the request's status does not allow and says so.
 */

const COPY = {
  ar: {
    title: 'الطلبات',
    subtitle: 'خدمات انطلبت من موقع كامبينز هب. تخص إدارة المنصة فقط.',
    open: 'مفتوحة', unassigned: 'بدون مسؤول', overdue: 'متأخرة', all: 'الكل',
    reference: 'المرجع', service: 'الخدمة', company: 'الجهة', status: 'الحالة', owner: 'المسؤول', activity: 'آخر نشاط',
    none: 'بدون', empty: 'ما فيه طلبات', emptyHint: 'أي طلب خدمة من الموقع يظهر هنا.',
    loadError: 'ما قدرنا نحمّل الطلبات.', detailError: 'ما قدرنا نفتح الطلب.',
    contact: 'صاحب الطلب', goal: 'الهدف', budget: 'الميزانية', services: 'الخدمات', received: 'وصل', due: 'الموعد',
    pickOwner: 'المسؤول', moveTo: 'انقل إلى', save: 'احفظ', saved: 'انحفظ', close: 'أغلق', log: 'السجل',
    late: 'متأخر', created: 'وصل الطلب', assigned: 'تغيّر المسؤول',
    messages: 'الردود', files: 'المرفقات', fromVisitor: 'صاحب الطلب', fromTeam: 'فريقنا', download: 'نزّل',
  },
  en: {
    title: 'Requests',
    subtitle: 'Services ordered from the CampaignsHub site. They belong to the platform only.',
    open: 'Open', unassigned: 'No owner', overdue: 'Overdue', all: 'All',
    reference: 'Reference', service: 'Service', company: 'Company', status: 'Status', owner: 'Owner', activity: 'Last activity',
    none: 'None', empty: 'No requests', emptyHint: 'A service ordered from the site shows up here.',
    loadError: 'The requests could not be loaded.', detailError: 'The request could not be opened.',
    contact: 'Requester', goal: 'Goal', budget: 'Budget', services: 'Services', received: 'Received', due: 'Due',
    pickOwner: 'Owner', moveTo: 'Move to', save: 'Save', saved: 'Saved', close: 'Close', log: 'Log',
    late: 'Late', created: 'Request received', assigned: 'Owner changed',
    messages: 'Replies', files: 'Attachments', fromVisitor: 'Requester', fromTeam: 'Our team', download: 'Download',
  },
} as const

type Copy = (typeof COPY)['ar'] | (typeof COPY)['en']

const TONE: Record<string, string> = {
  new: 'bg-[var(--warning-background)] text-warning',
  completed: 'bg-[var(--positive-background)] text-success',
  rejected: 'bg-[var(--negative-background)] text-danger',
  cancelled: 'bg-surface-secondary text-text-muted',
}

export function ServiceRequestsPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t: Copy = ar ? COPY.ar : COPY.en
  const [filter, setFilterState] = useState<ServiceRequestFilter>('open')
  const [page, setPage] = useState(1)
  const [params, setParams] = useSearchParams()
  const openId = params.get('open')
  const list = useQuery({ queryKey: ['admin', 'service-requests', filter, page], queryFn: () => fetchServiceRequests(filter, page) })
  const setFilter = (next: ServiceRequestFilter) => { setFilterState(next); setPage(1) }

  const open = (id: string | null) => {
    const next = new URLSearchParams(params)
    if (id === null) next.delete('open')
    else next.set('open', id)
    setParams(next, { replace: true })
  }

  return (
    <div data-testid="admin-service-requests" className="w-full">
      <header className="mb-5">
        <h1 className="font-heading text-3xl font-extrabold tracking-tight text-text-primary">{t.title}</h1>
        <p className="mt-1 max-w-2xl text-sm text-text-secondary">{t.subtitle}</p>
      </header>

      <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <Counter id="open" icon={Inbox} label={t.open} value={list.data?.counts.open} active={filter === 'open'} onClick={() => setFilter('open')} />
        <Counter id="unassigned" icon={UserRound} label={t.unassigned} value={list.data?.counts.unassigned} active={filter === 'unassigned'} onClick={() => setFilter('unassigned')} tone="warning" />
        <Counter id="overdue" icon={AlarmClock} label={t.overdue} value={list.data?.counts.overdue} tone="danger" />
      </div>

      <div className="mb-3 flex justify-end">
        <button type="button" data-testid="service-requests-all" aria-pressed={filter === 'all'} onClick={() => setFilter('all')}
          className={`rounded-full border px-3 py-1 text-xs font-semibold ${filter === 'all' ? 'border-brand-500 text-brand-700' : 'border-border text-text-secondary'}`}>
          {t.all}
        </button>
      </div>

      <div className="grid min-w-0 grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]">
        <section className="min-w-0">
          {list.isPending && <Skeleton className="h-40" />}
          {list.isError && <ErrorState error={list.error} ar={ar} title={t.loadError} onRetry={() => void list.refetch()} />}
          {list.data && list.data.requests.length === 0 && <EmptyState title={t.empty} description={t.emptyHint} />}
          {list.data && list.data.requests.length > 0 && (
            <div className="overflow-x-auto rounded-xl border border-border bg-surface">
              <table data-testid="service-requests-table" className="w-full min-w-[40rem] text-sm">
                <thead className="text-xs text-text-muted">
                  <tr className="border-b border-border">
                    {[t.reference, t.service, t.company, t.status, t.owner, t.activity].map((h) => (
                      <th key={h} scope="col" className="px-3 py-2 text-start font-semibold">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {list.data.requests.map((r) => (
                    <Row key={r.id} r={r} ar={ar} t={t} selected={openId === r.id} onOpen={() => open(r.id)} />
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {list.data && list.data.last_page > 1 && (
            <div className="mt-3 flex justify-end">
              <Pager page={list.data.page} lastPage={list.data.last_page} onPage={setPage} ar={ar} testId="service-requests-pager" />
            </div>
          )}
        </section>

        {openId && list.data && (
          <Detail key={openId} id={openId} ar={ar} t={t} list={list.data} onClose={() => open(null)} />
        )}
      </div>
    </div>
  )
}

function Counter({ id, icon: Icon, label, value, active = false, onClick, tone }: {
  id: string; icon: typeof Inbox; label: string; value: number | undefined; active?: boolean; onClick?: () => void; tone?: 'warning' | 'danger'
}) {
  const colour = value && tone === 'danger' ? 'text-danger' : value && tone === 'warning' ? 'text-warning' : 'text-text-primary'
  const body = (
    <>
      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-surface-secondary text-text-secondary"><Icon size={18} aria-hidden /></span>
      <span className="min-w-0">
        <span className="block text-xs font-semibold text-text-muted">{label}</span>
        <span data-testid={`service-requests-count-${id}`} className={`block text-2xl font-extrabold ${colour}`}><Num>{value ?? '—'}</Num></span>
      </span>
    </>
  )
  const frame = `flex items-center gap-3 rounded-xl border bg-surface px-4 py-3 text-start ${active ? 'border-brand-500' : 'border-border'}`

  return onClick
    ? <button type="button" data-testid={`service-requests-filter-${id}`} aria-pressed={active} onClick={onClick} className={frame}>{body}</button>
    : <div className={frame}>{body}</div>
}

function Row({ r, ar, t, selected, onOpen }: { r: ServiceRequestRow; ar: boolean; t: Copy; selected: boolean; onOpen: () => void }) {
  return (
    <tr data-testid={`service-request-${r.reference}`} className={`border-b border-border last:border-0 ${selected ? 'bg-surface-secondary' : ''}`}>
      <td className="px-3 py-2">
        <button type="button" onClick={onOpen} className="font-semibold text-brand-700 underline-offset-4 hover:underline" dir="ltr">{r.reference}</button>
      </td>
      <td className="px-3 py-2 text-text-primary">{ar ? r.type.name_ar : r.type.name_en}</td>
      <td className="max-w-[12rem] truncate px-3 py-2 text-text-secondary">{r.company ?? r.contact_name ?? '—'}</td>
      <td className="px-3 py-2">
        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ${TONE[r.status.key] ?? 'bg-surface-secondary text-text-secondary'}`}>
          {ar ? r.status.name_ar : r.status.name_en}
        </span>
        {r.overdue && <span className="ms-1 inline-flex items-center gap-1 text-xs font-semibold text-danger"><AlarmClock size={12} aria-hidden />{t.late}</span>}
      </td>
      <td className="px-3 py-2 text-text-secondary">{r.assignee?.name ?? t.none}</td>
      <td className="px-3 py-2 text-text-muted"><Num>{fmtDate(r.last_activity_at ?? r.submitted_at)}</Num></td>
    </tr>
  )
}

function Detail({ id, ar, t, list, onClose }: { id: string; ar: boolean; t: Copy; list: ServiceRequestList; onClose: () => void }) {
  const qc = useQueryClient()
  const detail = useQuery({ queryKey: ['admin', 'service-request', id], queryFn: () => fetchServiceRequest(id) })
  const r = detail.data?.request
  const [owner, setOwner] = useState<string | null>(null)
  const [status, setStatus] = useState<string | null>(null)
  const ownerValue = owner ?? (r?.assignee ? String(r.assignee.id) : '')
  const statusValue = status ?? r?.status.key ?? ''

  const save = useMutation({
    mutationFn: () => updateServiceRequest(id, {
      ...(r && ownerValue !== (r.assignee ? String(r.assignee.id) : '') ? { assigned_to: ownerValue === '' ? null : Number(ownerValue) } : {}),
      ...(r && statusValue !== r.status.key ? { status: statusValue } : {}),
    }),
    onSuccess: (data) => {
      qc.setQueryData(['admin', 'service-request', id], data)
      setOwner(null)
      setStatus(null)
      void qc.invalidateQueries({ queryKey: ['admin', 'service-requests'] })
      void qc.invalidateQueries({ queryKey: ['admin', 'overview'] })
    },
  })

  const changed = r !== undefined && (ownerValue !== (r.assignee ? String(r.assignee.id) : '') || statusValue !== r.status.key)
  const name = (key: string) => {
    const s = list.statuses.find((x) => x.key === key)
    return s ? (ar ? s.name_ar : s.name_en) : key
  }

  return (
    <aside data-testid="service-request-detail" className="min-w-0 rounded-xl border border-border bg-surface p-4">
      <div className="mb-3 flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="font-heading text-lg font-extrabold text-text-primary" dir="ltr">{r?.reference ?? '…'}</p>
          {r && <p className="truncate text-sm text-text-secondary">{ar ? r.type.name_ar : r.type.name_en}</p>}
        </div>
        <button type="button" onClick={onClose} aria-label={t.close} className="rounded-lg p-1 text-text-muted hover:bg-surface-secondary"><X size={18} aria-hidden /></button>
      </div>

      {detail.isPending && <Skeleton className="h-40" />}
      {detail.isError && <ErrorState error={detail.error} ar={ar} title={t.detailError} onRetry={() => void detail.refetch()} />}

      {r && (
        <>
          <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1.5 text-sm">
            <dt className="text-text-muted">{t.contact}</dt>
            <dd className="min-w-0 break-words text-text-primary">
              {r.contact.name}{r.contact.company ? ` · ${r.contact.company}` : ''}
              <span className="block text-text-secondary" dir="ltr">{r.contact.email}</span>
              <span className="block text-text-secondary" dir="ltr">{r.contact.phone}</span>
            </dd>
            {r.objective && (<><dt className="text-text-muted">{t.goal}</dt><dd className="min-w-0 break-words text-text-primary">{r.objective}</dd></>)}
            {r.budget !== null && (<><dt className="text-text-muted">{t.budget}</dt><dd className="text-text-primary"><Num>{r.budget.toLocaleString('en-US')}</Num> {r.currency}</dd></>)}
            {r.services.length > 0 && (<><dt className="text-text-muted">{t.services}</dt><dd className="text-text-primary"><Num>{r.services.length}</Num></dd></>)}
            <dt className="text-text-muted">{t.received}</dt><dd className="text-text-primary"><Num>{fmtDateTime(r.submitted_at)}</Num></dd>
            <dt className="text-text-muted">{t.due}</dt>
            <dd className={r.overdue ? 'font-semibold text-danger' : 'text-text-primary'}><Num>{fmtDateTime(r.sla_due_at)}</Num></dd>
          </dl>

          <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-1">
            <label className="block text-xs font-semibold text-text-muted">
              {t.pickOwner}
              <select data-testid="service-request-owner" value={ownerValue} onChange={(e) => setOwner(e.target.value)}
                className="mt-1 h-9 w-full rounded-lg border border-border bg-surface px-2 text-sm text-text-primary">
                <option value="">{t.none}</option>
                {list.administrators.map((a) => <option key={a.id} value={String(a.id)}>{a.name}</option>)}
              </select>
            </label>
            <label className="block text-xs font-semibold text-text-muted">
              {t.moveTo}
              <select data-testid="service-request-status" value={statusValue} onChange={(e) => setStatus(e.target.value)}
                className="mt-1 h-9 w-full rounded-lg border border-border bg-surface px-2 text-sm text-text-primary">
                <option value={r.status.key}>{ar ? r.status.name_ar : r.status.name_en}</option>
                {r.next_statuses.map((k) => <option key={k} value={k}>{name(k)}</option>)}
              </select>
            </label>
          </div>

          <div className="mt-3 flex items-center gap-3">
            <Button data-testid="service-request-save" size="sm" disabled={!changed || save.isPending} onClick={() => save.mutate()}>{t.save}</Button>
            {save.isSuccess && !changed && <span role="status" className="text-xs font-semibold text-success">{t.saved}</span>}
            {save.isError && <span role="alert" className="text-xs font-semibold text-danger">{toApiError(save.error).message}</span>}
          </div>

          {r.messages.length > 0 && (
            <>
              <h2 className="mt-5 flex items-center gap-1.5 text-xs font-semibold text-text-muted"><MessageSquare size={14} aria-hidden />{t.messages}</h2>
              <ol data-testid="service-request-messages" className="mt-1 space-y-2">
                {r.messages.map((m) => (
                  <li key={m.id} className={`rounded-lg px-3 py-2 text-sm ${m.from_visitor ? 'bg-surface-secondary' : 'border border-border'}`}>
                    <span className="flex justify-between gap-3 text-xs text-text-muted">
                      <span className="font-semibold">{m.from_visitor ? t.fromVisitor : t.fromTeam}</span>
                      <Num>{fmtDateTime(m.at)}</Num>
                    </span>
                    <span className="mt-0.5 block whitespace-pre-line break-words text-text-primary">{m.body}</span>
                  </li>
                ))}
              </ol>
            </>
          )}

          {r.files.length > 0 && (
            <>
              <h2 className="mt-5 flex items-center gap-1.5 text-xs font-semibold text-text-muted"><Paperclip size={14} aria-hidden />{t.files}</h2>
              <ul data-testid="service-request-files" className="mt-1 space-y-1 text-sm">
                {r.files.map((f) => (
                  <li key={f.id} className="flex items-center justify-between gap-3">
                    <span className="min-w-0 truncate text-text-primary" dir="auto">{f.name}</span>
                    <a href={serviceRequestFileUrl(r.id, f.id)} download className="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-brand-700 hover:underline">
                      <Download size={12} aria-hidden />{t.download} <Num>{`${Math.max(1, Math.round(f.size / 1024)).toLocaleString('en-US')} KB`}</Num>
                    </a>
                  </li>
                ))}
              </ul>
            </>
          )}

          <h2 className="mt-5 text-xs font-semibold text-text-muted">{t.log}</h2>
          <ol data-testid="service-request-activity" className="mt-1 space-y-1 text-xs text-text-secondary">
            {r.activity.map((a, i) => (
              <li key={`${a.at}-${i}`} className="flex justify-between gap-3">
                <span className="min-w-0 truncate">{a.type === 'created' ? t.created : a.type === 'assigned' ? t.assigned : a.to ? name(a.to) : a.message}</span>
                <span className="shrink-0 text-text-muted"><Num>{fmtDateTime(a.at)}</Num></span>
              </li>
            ))}
          </ol>
        </>
      )}
    </aside>
  )
}
