import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { LifeBuoy, Mail, Send, ShieldCheck, X } from 'lucide-react'
import {
  INBOX_STATUSES, fetchInbox, fetchInboxItem, fetchInboxSummary, replyToInboxItem, updateInboxStatus,
  type InboxQueue, type InboxReply, type InboxRow,
} from './api'
import { Button } from '@/components/ui/Button'
import { Num } from '@/components/ui/Num'
import { EmptyState, ErrorState, Skeleton } from '@/components/ui/States'
import { Pager } from '@/features/integrations/PageSizer'
import { toApiError } from '@/lib/api/client'
import { fmtDateTime } from '@/lib/datetime'
import { useUi } from '@/stores/ui'

/**
 * `/admin/inbox` — «الوارد» (PLATFORM-INBOX-ATTENTION-001, ledger #169).
 *
 * What visitors address to the platform itself: the contact form, support tickets and data requests. They were
 * stored with an API and no screen, so nobody read them. Three tabs because they are three clocks — an enquiry,
 * a support case, a legal obligation — each with its own unread count. Opening a row marks it read; a reply goes by
 * email to the address the visitor gave, and the state shown beside it is the mail ledger's, never a guess.
 */

const COPY = {
  ar: {
    title: 'الوارد', subtitle: 'رسائل الموقع للمنصة. ما تظهر لأي مساحة عمل.',
    contact: 'تواصل', support: 'دعم', data: 'بيانات', unread: 'غير مقروء',
    from: 'المرسل', subject: 'الموضوع', status: 'الحالة', received: 'وصل',
    empty: 'ما فيه رسائل', emptyHint: 'أي رسالة من الموقع تظهر هنا.',
    loadError: 'ما قدرنا نحمّل الوارد.', detailError: 'ما قدرنا نفتح الرسالة.',
    close: 'أغلق', reply: 'رد', send: 'أرسل', sent: 'انحفظ الرد', replies: 'الردود', moveTo: 'الحالة', save: 'احفظ',
    replyHint: 'يوصل بالبريد إلى عنوان المرسل.', type: 'النوع', reference: 'المرجع', verified: 'موثّق', unverified: 'غير موثّق',
  },
  en: {
    title: 'Inbox', subtitle: 'What the site sends the platform. No workspace sees it.',
    contact: 'Contact', support: 'Support', data: 'Data', unread: 'Unread',
    from: 'From', subject: 'Subject', status: 'Status', received: 'Received',
    empty: 'Nothing here', emptyHint: 'Anything sent from the site shows up here.',
    loadError: 'The inbox could not be loaded.', detailError: 'The message could not be opened.',
    close: 'Close', reply: 'Reply', send: 'Send', sent: 'Reply saved', replies: 'Replies', moveTo: 'Status', save: 'Save',
    replyHint: 'Emailed to the sender\'s address.', type: 'Type', reference: 'Reference', verified: 'Verified', unverified: 'Not verified',
  },
} as const

type Copy = (typeof COPY)['ar'] | (typeof COPY)['en']

const QUEUES: { key: InboxQueue; icon: typeof Mail }[] = [
  { key: 'contact', icon: Mail },
  { key: 'support', icon: LifeBuoy },
  { key: 'data', icon: ShieldCheck },
]

const DELIVERY: Record<string, { ar: string; en: string; tone: string }> = {
  sent: { ar: 'انرسل', en: 'Sent', tone: 'text-success' },
  queued: { ar: 'بالطابور', en: 'Queued', tone: 'text-text-secondary' },
  awaiting_provider_credentials: { ar: 'البريد غير مفعّل', en: 'Mail not set up', tone: 'text-warning' },
  failed: { ar: 'فشل', en: 'Failed', tone: 'text-danger' },
}

/** The queues' own status keys, in words. An unknown key is shown as it is rather than hidden. */
const STATUS: Record<string, { ar: string; en: string }> = {
  new: { ar: 'جديد', en: 'New' }, read: { ar: 'مقروء', en: 'Read' }, answered: { ar: 'تم الرد', en: 'Answered' },
  closed: { ar: 'مغلق', en: 'Closed' }, spam: { ar: 'مزعج', en: 'Spam' },
  open: { ar: 'مفتوحة', en: 'Open' }, in_progress: { ar: 'قيد العمل', en: 'In progress' },
  waiting_on_customer: { ar: 'بانتظار المرسل', en: 'Waiting on sender' }, resolved: { ar: 'محلولة', en: 'Resolved' },
  pending: { ar: 'جديد', en: 'Pending' }, verifying: { ar: 'بانتظار التحقق', en: 'Verifying' }, in_review: { ar: 'قيد المراجعة', en: 'In review' },
  blocked: { ar: 'معلّق', en: 'Blocked' }, completed: { ar: 'مكتمل', en: 'Completed' }, rejected: { ar: 'مرفوض', en: 'Rejected' },
  withdrawn: { ar: 'مسحوب', en: 'Withdrawn' },
}
const statusWord = (key: string, ar: boolean) => (STATUS[key] ? (ar ? STATUS[key].ar : STATUS[key].en) : key)

const isQueue = (v: string | null): v is InboxQueue => v === 'contact' || v === 'support' || v === 'data'

function subjectOf(row: InboxRow): string {
  if ('type' in row && 'verified_at' in row) return row.type
  return ('subject' in row ? row.subject : null) ?? '—'
}

export function PlatformInboxPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t: Copy = ar ? COPY.ar : COPY.en
  const [params, setParams] = useSearchParams()
  const queue: InboxQueue = isQueue(params.get('tab')) ? (params.get('tab') as InboxQueue) : 'contact'
  const openId = params.get('open')
  const [page, setPage] = useState(1)

  const summary = useQuery({ queryKey: ['admin', 'inbox', 'summary'], queryFn: fetchInboxSummary })
  const list = useQuery({ queryKey: ['admin', 'inbox', queue, page], queryFn: () => fetchInbox(queue, page) })

  const go = (next: { tab?: InboxQueue; open?: string | null }) => {
    const p = new URLSearchParams(params)
    if (next.tab) { p.set('tab', next.tab); p.delete('open'); setPage(1) }
    if (next.open !== undefined) {
      if (next.open === null) p.delete('open')
      else p.set('open', next.open)
    }
    setParams(p, { replace: true })
  }

  const lastPage = list.data ? Math.max(1, Math.ceil(list.data.total / 25)) : 1

  return (
    <div data-testid="admin-inbox" className="w-full">
      <header className="mb-5">
        <h1 className="font-heading text-3xl font-extrabold tracking-tight text-text-primary">{t.title}</h1>
        <p className="mt-1 max-w-2xl text-sm text-text-secondary">{t.subtitle}</p>
      </header>

      <div role="tablist" className="mb-4 grid grid-cols-3 gap-2 sm:gap-3">
        {QUEUES.map(({ key, icon: Icon }) => {
          const unread = summary.data?.[key].unread
          const active = queue === key
          return (
            <button key={key} type="button" role="tab" aria-selected={active} data-testid={`inbox-tab-${key}`} onClick={() => go({ tab: key })}
              className={`flex min-w-0 items-center gap-3 rounded-xl border bg-surface px-3 py-2.5 text-start sm:px-4 sm:py-3 ${active ? 'border-brand-500' : 'border-border'}`}>
              <span className="hidden h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-surface-secondary text-text-secondary sm:flex"><Icon size={18} aria-hidden /></span>
              <span className="min-w-0">
                <span className="block truncate text-xs font-semibold text-text-muted">{t[key]}<span className="hidden sm:inline"> · {t.unread}</span></span>
                <span data-testid={`inbox-unread-${key}`} className={`block text-2xl font-extrabold ${unread ? 'text-warning' : 'text-text-primary'}`}><Num>{unread ?? '—'}</Num></span>
              </span>
            </button>
          )
        })}
      </div>

      <div className="grid min-w-0 grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]">
        <section className="min-w-0">
          {list.isPending && <Skeleton className="h-40" />}
          {list.isError && <ErrorState error={list.error} ar={ar} title={t.loadError} onRetry={() => void list.refetch()} />}
          {list.data && list.data.rows.length === 0 && <EmptyState title={t.empty} description={t.emptyHint} />}
          {list.data && list.data.rows.length > 0 && (
            <div className="overflow-x-auto rounded-xl border border-border bg-surface">
              <table data-testid="inbox-table" className="w-full min-w-[36rem] text-sm">
                <thead className="text-xs text-text-muted">
                  <tr className="border-b border-border">
                    {[t.from, t.subject, t.status, t.received].map((h) => <th key={h} scope="col" className="px-3 py-2 text-start font-semibold">{h}</th>)}
                  </tr>
                </thead>
                <tbody>
                  {list.data.rows.map((r) => (
                    <tr key={r.id} data-testid="inbox-row" data-unread={r.read_at === null} className={`border-b border-border last:border-0 ${openId === r.id ? 'bg-surface-secondary' : ''}`}>
                      <td className="px-3 py-2">
                        <button type="button" onClick={() => go({ open: r.id })} className={`text-start text-brand-700 underline-offset-4 hover:underline ${r.read_at === null ? 'font-extrabold' : 'font-semibold'}`}>
                          {r.read_at === null && <span aria-label={t.unread} className="me-1.5 inline-block h-2 w-2 rounded-full bg-brand-500" />}
                          {r.name}
                        </button>
                        <span className="block text-xs text-text-muted" dir="ltr">{r.email}</span>
                      </td>
                      <td className="max-w-[16rem] truncate px-3 py-2 text-text-primary" dir="auto">{subjectOf(r)}</td>
                      <td className="px-3 py-2 text-text-secondary" data-status={r.status}>{statusWord(r.status, ar)}</td>
                      <td className="px-3 py-2 text-text-muted"><Num>{fmtDateTime(r.created_at)}</Num></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {list.data && lastPage > 1 && (
            <div className="mt-3 flex justify-end">
              <Pager page={page} lastPage={lastPage} onPage={setPage} ar={ar} testId="inbox-pager" />
            </div>
          )}
        </section>

        {openId && <Detail key={`${queue}-${openId}`} queue={queue} id={openId} ar={ar} t={t} onClose={() => go({ open: null })} />}
      </div>
    </div>
  )
}

function Detail({ queue, id, ar, t, onClose }: { queue: InboxQueue; id: string; ar: boolean; t: Copy; onClose: () => void }) {
  const qc = useQueryClient()
  const detail = useQuery({ queryKey: ['admin', 'inbox-item', queue, id], queryFn: () => fetchInboxItem(queue, id) })
  const [body, setBody] = useState('')
  const [status, setStatus] = useState<string | null>(null)

  const refreshLists = () => {
    void qc.invalidateQueries({ queryKey: ['admin', 'inbox'] })
    void qc.invalidateQueries({ queryKey: ['admin', 'overview'] })
  }

  const reply = useMutation({
    mutationFn: () => replyToInboxItem(queue as Exclude<InboxQueue, 'data'>, id, body.trim()),
    onSuccess: () => { setBody(''); void detail.refetch(); refreshLists() },
  })
  const move = useMutation({
    mutationFn: (next: string) => updateInboxStatus(queue, id, next),
    onSuccess: () => { setStatus(null); void detail.refetch(); refreshLists() },
  })

  const r = detail.data?.row
  const opened = detail.isSuccess
  // Opening it marked it read on the server, once: refresh the counters and the bold row after the first load.
  useEffect(() => {
    if (opened) refreshLists()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [opened])

  const text = r ? ('message' in r ? r.message : 'details' in r ? r.details : null) : null
  const statusValue = status ?? r?.status ?? ''

  return (
    <aside data-testid="inbox-detail" className="min-w-0 rounded-xl border border-border bg-surface p-4">
      <div className="mb-3 flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="font-heading text-lg font-extrabold text-text-primary">{r?.name ?? '…'}</p>
          {r && <p className="truncate text-sm text-text-secondary" dir="ltr">{r.email}{r.phone ? ` · ${r.phone}` : ''}</p>}
        </div>
        <button type="button" onClick={onClose} aria-label={t.close} className="rounded-lg p-1 text-text-muted hover:bg-surface-secondary"><X size={18} aria-hidden /></button>
      </div>

      {detail.isPending && <Skeleton className="h-40" />}
      {detail.isError && <ErrorState error={detail.error} ar={ar} title={t.detailError} onRetry={() => void detail.refetch()} />}

      {r && (
        <>
          <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1.5 text-sm">
            {'reference' in r && (<><dt className="text-text-muted">{t.reference}</dt><dd className="text-text-primary" dir="ltr">{r.reference}</dd></>)}
            <dt className="text-text-muted">{'verified_at' in r ? t.type : t.subject}</dt>
            <dd className="min-w-0 break-words text-text-primary" dir="auto">{subjectOf(r)}</dd>
            {'verified_at' in r && (<><dt className="text-text-muted">{t.status}</dt><dd className="text-text-primary">{r.verified_at ? t.verified : t.unverified}</dd></>)}
            <dt className="text-text-muted">{t.received}</dt><dd className="text-text-primary"><Num>{fmtDateTime(r.created_at)}</Num></dd>
          </dl>

          {text && <p data-testid="inbox-body" className="mt-3 whitespace-pre-line break-words rounded-lg bg-surface-secondary px-3 py-2 text-sm text-text-primary" dir="auto">{text}</p>}

          <div className="mt-4 flex items-end gap-2">
            <label className="block min-w-0 flex-1 text-xs font-semibold text-text-muted">
              {t.moveTo}
              <select data-testid="inbox-status" value={statusValue} onChange={(e) => setStatus(e.target.value)}
                className="mt-1 h-9 w-full rounded-lg border border-border bg-surface px-2 text-sm text-text-primary">
                {INBOX_STATUSES[queue].map((s) => <option key={s} value={s}>{statusWord(s, ar)}</option>)}
              </select>
            </label>
            <Button size="sm" data-testid="inbox-status-save" disabled={statusValue === r.status || move.isPending} onClick={() => move.mutate(statusValue)}>{t.save}</Button>
          </div>
          {move.isError && <p role="alert" className="mt-1 text-xs font-semibold text-danger">{toApiError(move.error).message}</p>}

          {queue !== 'data' && (
            <form className="mt-5" onSubmit={(e) => { e.preventDefault(); if (body.trim().length >= 2) reply.mutate() }}>
              <label htmlFor="inbox-reply" className="block text-xs font-semibold text-text-muted">{t.reply}</label>
              <textarea id="inbox-reply" data-testid="inbox-reply" value={body} onChange={(e) => setBody(e.target.value)} rows={4} maxLength={5000} dir="auto"
                className="mt-1 w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-text-primary" />
              <div className="mt-2 flex items-center justify-between gap-3">
                <span className="text-xs text-text-muted">{t.replyHint}</span>
                <Button type="submit" size="sm" data-testid="inbox-reply-send" disabled={body.trim().length < 2 || reply.isPending}>
                  <Send size={14} aria-hidden /> {t.send}
                </Button>
              </div>
              {reply.isSuccess && <p role="status" className="mt-1 text-xs font-semibold text-success">{t.sent}</p>}
              {reply.isError && <p role="alert" className="mt-1 text-xs font-semibold text-danger">{toApiError(reply.error).message}</p>}
            </form>
          )}

          {(detail.data?.replies.length ?? 0) > 0 && <Replies replies={detail.data?.replies ?? []} ar={ar} t={t} />}
        </>
      )}
    </aside>
  )
}

export function Replies({ replies, ar, t }: { replies: InboxReply[]; ar: boolean; t: { replies: string } }) {
  return (
    <>
      <h2 className="mt-5 text-xs font-semibold text-text-muted">{t.replies}</h2>
      <ol data-testid="inbox-replies" className="mt-1 space-y-2">
        {replies.map((x) => {
          const d = DELIVERY[x.delivery_status]
          return (
            <li key={x.id} className="rounded-lg border border-border px-3 py-2 text-sm">
              <span className="flex justify-between gap-3 text-xs text-text-muted">
                <span className="font-semibold">{x.author ?? '—'}</span>
                <span data-testid="inbox-reply-delivery" data-status={x.delivery_status} className={`font-semibold ${d?.tone ?? 'text-text-secondary'}`}>
                  {d ? (ar ? d.ar : d.en) : x.delivery_status} · <Num>{fmtDateTime(x.at)}</Num>
                </span>
              </span>
              <span className="mt-0.5 block whitespace-pre-line break-words text-text-primary" dir="auto">{x.body}</span>
            </li>
          )
        })}
      </ol>
    </>
  )
}
