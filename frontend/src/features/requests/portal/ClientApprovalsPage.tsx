import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowRight, BadgeCheck, Check, ImageOff, Loader2, PencilLine, ScrollText } from 'lucide-react'
import {
  approvePortalContent, formatDate, formatMoney, listPortalContentApprovals, listPortalQuotes, requestPortalContentChanges,
  type PortalContentApproval,
} from './portalAccountApi'
import { PortalShell } from './PortalShell'
import { PortalListCeiling } from './PortalListCeiling'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { usePortalGuard } from './usePortalGuard'
import { usePortalVisibility } from './usePortalVisibility'
import { useClientSpacePath } from './clientSpace'
import { toApiError } from '@/lib/api/client'
import { Num } from '@/components/ui/Num'
import { localStill } from '@/features/content/providerMedia'
import { useUi } from '@/stores/ui'

const COPY = {
  ar: {
    title: 'بانتظار اعتمادك', content: 'المحتوى', quotes: 'عروض الأسعار', none: 'لا شيء بانتظارك الآن.',
    error: 'تعذّر تحميل ما ينتظر اعتمادك.', approve: 'اعتمد', changes: 'اطلب تعديل', send: 'أرسل', cancel: 'إلغاء',
    note: 'ما التعديل المطلوب؟', all_quotes: 'كل العروض', open: 'افتح', decided: 'السابقة',
  },
  en: {
    title: 'Awaiting you', content: 'Content', quotes: 'Quotes', none: 'Nothing is waiting for you.',
    error: 'Could not load what is waiting for you.', approve: 'Approve', changes: 'Request changes', send: 'Send', cancel: 'Cancel',
    note: 'What should change?', all_quotes: 'All quotes', open: 'Open', decided: 'Earlier',
  },
}

const STATUS: Record<string, { ar: string; en: string; tone: string }> = {
  pending: { ar: 'بانتظارك', en: 'Awaiting you', tone: 'bg-warning/15 text-warning' },
  approved: { ar: 'معتمد', en: 'Approved', tone: 'bg-success/15 text-success' },
  changes_requested: { ar: 'طلبت تعديل', en: 'Changes asked', tone: 'bg-surface-secondary text-text-secondary' },
}

/**
 * CLIENT-PORTAL-THREE-SECTIONS-001 + CLIENT-CONTENT-APPROVAL-001 — «بانتظار اعتمادك».
 *
 * Everything that needs the client's yes, on one screen: content first (approve, or ask for a change with
 * a note), then quotes still open (each opens the existing quote page, where approving issues the invoice
 * exactly as before). Whether a button is offered is the SERVER's `can_decide` — a viewer, or a space whose
 * agency has not enabled content approval, sees the item without the buttons, and the server refuses the
 * write either way.
 */
export function ClientApprovalsPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const spaceTo = useClientSpacePath()
  const { allows } = usePortalVisibility()

  const content = useQuery({ queryKey: ['client', 'content-approvals'], queryFn: listPortalContentApprovals, retry: false, enabled: allows('content') })
  const quotes = useQuery({ queryKey: ['client', 'quotes'], queryFn: listPortalQuotes, retry: false, enabled: allows('invoices') })
  usePortalGuard(content.isError, content.error)

  const items = content.data?.items ?? []
  const open = items.filter((a) => a.status === 'pending')
  const decided = items.filter((a) => a.status !== 'pending').slice(0, 6)
  const openQuotes = (quotes.data?.items ?? []).filter((q) => ['draft', 'sent'].includes(q.status))
  const loading = content.isLoading || quotes.isLoading

  return (
    <PortalShell title={t.title} nav showLogout>
      <h1 className="mb-3 font-heading text-xl font-extrabold text-text-primary">{t.title}</h1>

      {loading ? (
        <div className="grid gap-2 sm:grid-cols-2">{[0, 1].map((i) => <div key={i} className="h-24 animate-pulse rounded-2xl bg-surface-secondary" />)}</div>
      ) : content.isError ? (
        <QueryFailure error={content.error} ar={ar} onRetry={() => void content.refetch()} fallbackTitle={t.error} testId="portal-failure" />
      ) : open.length === 0 && openQuotes.length === 0 && decided.length === 0 ? (
        <div data-testid="approvals-empty" className="flex flex-col items-center gap-2 rounded-2xl border border-border bg-surface p-8 text-center text-text-muted">
          <BadgeCheck size={24} /><span className="text-sm">{t.none}</span>
        </div>
      ) : (
        <div className="grid min-w-0 gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,18rem)]">
          <section aria-label={t.content} className="grid min-w-0 content-start gap-2">
            {open.map((a) => <ApprovalCard key={a.id} a={a} ar={ar} t={t} />)}
            {decided.length > 0 && <h2 className="mt-1 text-xs font-bold text-text-muted">{t.decided}</h2>}
            {decided.map((a) => <ApprovalCard key={a.id} a={a} ar={ar} t={t} />)}
            <PortalListCeiling withheld={content.data?.withheld} total={content.data?.total} ar={ar} />
          </section>

          {allows('invoices') && (
            <section aria-label={t.quotes} className="min-w-0 rounded-2xl border border-border bg-surface p-3">
              <div className="mb-2 flex items-center justify-between gap-2">
                <h2 className="flex items-center gap-1.5 text-sm font-bold text-text-primary"><ScrollText size={15} aria-hidden /> {t.quotes}</h2>
                <Link to={spaceTo('/quotes')} className="text-xs font-semibold text-brand-600">{t.all_quotes}</Link>
              </div>
              {openQuotes.length === 0 ? (
                <p className="py-3 text-center text-xs text-text-muted">{t.none}</p>
              ) : (
                <ul className="divide-y divide-border">
                  {openQuotes.map((q) => (
                    <li key={q.id}>
                      <Link to={spaceTo(`/quotes/${q.id}`)} data-testid="approvals-quote" className="flex items-center justify-between gap-2 py-2 hover:opacity-80">
                        <span className="min-w-0">
                          <span className="block truncate font-mono text-xs font-semibold text-brand-600" dir="ltr">{q.number}</span>
                          <span className="tnum block text-sm font-bold text-text-primary"><Num>{formatMoney(q.total, q.currency)}</Num></span>
                        </span>
                        <span className="flex shrink-0 items-center gap-1 text-xs font-semibold text-brand-600">{t.open} <ArrowRight size={13} className="rtl:rotate-180" aria-hidden /></span>
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </section>
          )}
        </div>
      )}
    </PortalShell>
  )
}

function ApprovalCard({ a, ar, t }: { a: PortalContentApproval; ar: boolean; t: typeof COPY.ar }) {
  const qc = useQueryClient()
  const [asking, setAsking] = useState(false)
  const [note, setNote] = useState('')
  const [error, setError] = useState<string | null>(null)
  const status = STATUS[a.status] ?? STATUS.pending

  const done = () => { setError(null); setAsking(false); void qc.invalidateQueries({ queryKey: ['client', 'content-approvals'] }) }
  const approve = useMutation({ mutationFn: () => approvePortalContent(a.id), onSuccess: done, onError: (e) => setError(toApiError(e).message) })
  const changes = useMutation({ mutationFn: () => requestPortalContentChanges(a.id, note.trim()), onSuccess: done, onError: (e) => setError(toApiError(e).message) })
  const busy = approve.isPending || changes.isPending

  return (
    <article data-testid="approval-card" className="flex min-w-0 gap-3 rounded-2xl border border-border bg-surface p-3">
      <div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-surface-secondary text-text-muted">
        {localStill(a.thumbnail_url) ? <img src={localStill(a.thumbnail_url) ?? undefined} alt="" className="h-full w-full object-cover" /> : <ImageOff size={18} aria-hidden />}
      </div>
      <div className="min-w-0 flex-1">
        <div className="flex items-start justify-between gap-2">
          <h3 className="min-w-0 truncate text-sm font-bold text-text-primary">{a.title}</h3>
          <span className={`shrink-0 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold ${status.tone}`}>{ar ? status.ar : status.en}</span>
        </div>
        {a.caption && <p className="mt-0.5 line-clamp-2 break-words text-xs text-text-secondary">{a.caption}</p>}
        {a.decision_note && <p className="mt-1 break-words rounded-lg bg-surface-secondary px-2 py-1 text-xs text-text-secondary">{a.decision_note}</p>}
        <div className="tnum mt-1 text-xs text-text-muted">{formatDate(a.decided_at ?? a.created_at)}</div>

        {a.can_decide && !asking && (
          <div className="mt-2 flex flex-wrap gap-1.5">
            <button onClick={() => approve.mutate()} disabled={busy} className="flex items-center gap-1 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700 disabled:opacity-60">
              {approve.isPending ? <Loader2 size={13} className="animate-spin" /> : <Check size={13} />} {t.approve}
            </button>
            <button onClick={() => setAsking(true)} disabled={busy} className="flex items-center gap-1 rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-text-secondary hover:text-text-primary disabled:opacity-60">
              <PencilLine size={13} /> {t.changes}
            </button>
          </div>
        )}
        {a.can_decide && asking && (
          <form className="mt-2 grid gap-1.5" onSubmit={(e) => { e.preventDefault(); if (note.trim() !== '') changes.mutate() }}>
            <textarea value={note} onChange={(e) => setNote(e.target.value)} maxLength={2000} rows={2} aria-label={t.note} placeholder={t.note}
              className="w-full min-w-0 rounded-lg border border-border bg-background px-2 py-1.5 text-sm text-text-primary" />
            <div className="flex flex-wrap gap-1.5">
              <button type="submit" disabled={busy || note.trim() === ''} className="flex items-center gap-1 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-60">
                {changes.isPending && <Loader2 size={13} className="animate-spin" />} {t.send}
              </button>
              <button type="button" onClick={() => setAsking(false)} disabled={busy} className="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-text-secondary">{t.cancel}</button>
            </div>
          </form>
        )}
        {error && <p role="alert" className="mt-1.5 rounded-lg bg-[var(--negative-background)] px-2 py-1 text-xs text-danger">{error}</p>}
      </div>
    </article>
  )
}
