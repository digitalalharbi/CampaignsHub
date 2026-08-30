import { useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { ArrowRight, CheckCircle2, Clock, ExternalLink, MessageSquareWarning, Upload } from 'lucide-react'
import {
  fetchMyCollaboration,
  respondToTerms,
  submitDeliverable,
  type CreatorCollaboration,
  type CreatorDeliverable,
} from './api'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Textarea } from '@/components/ui/Textarea'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'

/**
 * `/influencers/me/:id` — one agreement, from the creator's side (INFL-002).
 *
 * This screen does three things the operator's equivalent must never do, and refuses one it must not:
 *
 *   it states the fee THEY are paid, prominently, because that is the number they are agreeing to;
 *   it lets them answer the terms, once;
 *   it lets them hand in work and read the feedback on it.
 *
 * It cannot approve anything. There is no control here that sets `approved` or `published` — those
 * are the agency's acts, and a creator who could set them would be signing off their own work. The
 * server enforces that too; this page simply does not pretend otherwise.
 */

const money = (amount: string | null, currency: string) =>
  amount === null ? null : `${Number(amount).toLocaleString('en-US')} ${currency}`

/**
 * A Latin-script run inside Arabic text, isolated.
 *
 * Without this the bidi algorithm reorders it against the surrounding paragraph and the screen stops
 * matching the code. Seen live on this page before it existed: `21,000 SAR` rendered as
 * `SAR 21,000`, and worse, the due date `2026-08-17` rendered as `17-08-2026` — the same digits in a
 * different order, which is a date the reader will act on and which no test comparing strings would
 * have caught.
 */
function Ltr({ children, className = '' }: { children: ReactNode; className?: string }) {
  return (
    <span dir="ltr" className={`inline-block ${className}`}>
      {children}
    </span>
  )
}

const DELIVERABLE_STATUS: Record<string, { ar: string; en: string; tone: string }> = {
  pending: { ar: 'بانتظار تسليمك', en: 'Awaiting you', tone: 'bg-warning/15 text-warning' },
  submitted: { ar: 'قيد مراجعة الوكالة', en: 'With the agency', tone: 'bg-info/15 text-info' },
  approved: { ar: 'معتمد', en: 'Approved', tone: 'bg-success/15 text-success' },
  rejected: { ar: 'يحتاج تعديلًا', en: 'Needs changes', tone: 'bg-danger/15 text-danger' },
  published: { ar: 'منشور', en: 'Published', tone: 'bg-brand-primary-soft text-brand-700' },
  cancelled: { ar: 'ملغى', en: 'Cancelled', tone: 'bg-surface-secondary text-text-muted' },
}

/** The terms panel: what is being offered, and the one-time answer. */
function TermsPanel({ c, ar, id }: { c: CreatorCollaboration; ar: boolean; id: string }) {
  const queryClient = useQueryClient()
  const [declining, setDeclining] = useState(false)
  const [reason, setReason] = useState('')

  const respond = useMutation({
    mutationFn: (body: { decision: 'accepted' | 'declined'; reason?: string }) => respondToTerms(id, body),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['creator'] })
    },
  })

  if (!c.can_respond) {
    const accepted = c.decision === 'accepted'
    return (
      <div
        data-testid="creator-terms-answered"
        className={`rounded-2xl border p-4 ${accepted ? 'border-success/40 bg-success/5' : 'border-border bg-surface-secondary'}`}
      >
        <span className={`inline-flex items-center gap-2 text-sm font-bold ${accepted ? 'text-success' : 'text-text-secondary'}`}>
          <CheckCircle2 size={16} aria-hidden />
          {accepted
            ? ar ? 'قبلت هذه الشروط' : 'You accepted these terms'
            : ar ? 'اعتذرت عن هذه الشروط' : 'You declined these terms'}
        </span>
        <p className="mt-1.5 text-[12px] text-text-muted">
          {ar
            ? 'لتغيير الاتفاق تحتاج الوكالة إلى إرسال شروط معدّلة.'
            : 'Changing the agreement means the agency sends revised terms.'}
        </p>
      </div>
    )
  }

  return (
    <div data-testid="creator-terms" className="rounded-2xl border border-warning/40 bg-warning/5 p-4">
      <h2 className="font-heading text-[15px] font-bold text-text-primary">
        {ar ? 'شروط بانتظار ردّك' : 'Terms awaiting your answer'}
      </h2>
      <p className="mt-1 text-[12px] text-text-secondary">
        {ar
          ? 'يمكنك الرد مرة واحدة. بعدها يلزم إرسال شروط معدّلة من الوكالة.'
          : 'You can answer once. After that, changing it means the agency sends revised terms.'}
      </p>

      {respond.isError && (
        <p role="alert" className="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-[12px] font-semibold text-danger">
          {ar ? 'تعذّر حفظ ردّك. حدّث الصفحة وحاول مجددًا.' : 'Your answer could not be saved. Refresh and try again.'}
        </p>
      )}

      {declining ? (
        <div className="mt-4 space-y-3">
          <Field label={ar ? 'سبب الاعتذار (اختياري)' : 'Reason (optional)'} htmlFor="decline-reason">
            <Textarea
              id="decline-reason"
              rows={3}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder={ar ? 'مثال: يتعارض مع اتفاق حصرية قائم.' : 'e.g. It clashes with an exclusivity I already signed.'}
            />
          </Field>
          <div className="flex flex-wrap gap-2">
            <Button
              variant="danger"
              disabled={respond.isPending}
              onClick={() => respond.mutate({ decision: 'declined', reason: reason.trim() || undefined })}
            >
              {ar ? 'تأكيد الاعتذار' : 'Confirm decline'}
            </Button>
            <Button variant="secondary" onClick={() => setDeclining(false)} disabled={respond.isPending}>
              {ar ? 'رجوع' : 'Back'}
            </Button>
          </div>
        </div>
      ) : (
        <div className="mt-4 flex flex-wrap gap-2">
          <Button disabled={respond.isPending} onClick={() => respond.mutate({ decision: 'accepted' })}>
            {ar ? 'أقبل الشروط' : 'Accept terms'}
          </Button>
          <Button variant="secondary" disabled={respond.isPending} onClick={() => setDeclining(true)}>
            {ar ? 'أعتذر' : 'Decline'}
          </Button>
        </div>
      )}
    </div>
  )
}

/** One deliverable, with the submit form inline when it is the creator's turn. */
function DeliverableRow({
  d,
  collaborationId,
  ar,
}: {
  d: CreatorDeliverable
  collaborationId: string
  ar: boolean
}) {
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [url, setUrl] = useState('')
  const status = DELIVERABLE_STATUS[d.status] ?? { ar: d.status, en: d.status, tone: 'bg-surface-secondary text-text-muted' }

  const submit = useMutation({
    mutationFn: () => submitDeliverable(collaborationId, d.id, { submitted_url: url.trim() }),
    onSuccess: () => {
      setOpen(false)
      setUrl('')
      void queryClient.invalidateQueries({ queryKey: ['creator'] })
    },
  })

  return (
    <li data-testid="creator-deliverable" className="rounded-2xl border border-border bg-surface p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <Ltr className="font-heading text-sm font-bold text-text-primary">
            {d.type}
            {d.platform && <span className="ps-2 font-sans text-[12px] font-semibold text-text-muted">{d.platform}</span>}
          </Ltr>
          {d.due_on && (
            <span className="mt-1 flex items-center gap-1.5 text-[12px] text-text-muted">
              <Clock size={12.5} aria-hidden />
              {ar ? 'موعد التسليم' : 'Due'} <Ltr>{d.due_on}</Ltr>
              {d.is_overdue && (
                <span className="font-bold text-danger">{ar ? '· متأخر' : '· overdue'}</span>
              )}
            </span>
          )}
        </div>
        <span className={`shrink-0 rounded-full px-2.5 py-1 text-[11px] font-bold ${status.tone}`}>
          {ar ? status.ar : status.en}
        </span>
      </div>

      {/* Feedback is shown ONLY when it is the creator's turn again. Left visible after a fresh
          submission it would read as a complaint about work they have already replaced. */}
      {d.feedback && d.status === 'rejected' && (
        <p
          data-testid="creator-feedback"
          className="mt-3 flex gap-2 rounded-lg bg-danger/10 px-3 py-2 text-[12px] text-danger"
        >
          <MessageSquareWarning size={15} className="mt-px shrink-0" aria-hidden />
          <span dir="auto">{d.feedback}</span>
        </p>
      )}

      {d.submitted_url && (
        <a
          href={d.submitted_url}
          target="_blank"
          rel="noreferrer noopener"
          className="mt-3 inline-flex items-center gap-1.5 text-[12px] font-semibold text-brand-600 hover:underline"
        >
          <ExternalLink size={13} aria-hidden />
          {ar ? 'ما سلّمته' : 'What you submitted'}
        </a>
      )}

      {d.can_submit && (
        <div className="mt-3">
          {open ? (
            <form
              className="space-y-3"
              onSubmit={(e) => {
                e.preventDefault()
                if (url.trim()) submit.mutate()
              }}
            >
              <Field label={ar ? 'رابط المحتوى' : 'Content link'} htmlFor={`url-${d.id}`}>
                <Input
                  id={`url-${d.id}`}
                  type="url"
                  required
                  dir="ltr"
                  value={url}
                  onChange={(e) => setUrl(e.target.value)}
                  placeholder="https://…"
                />
              </Field>
              {submit.isError && (
                <p role="alert" className="text-[12px] font-semibold text-danger">
                  {ar ? 'تعذّر التسليم. تأكد من الرابط وحاول مجددًا.' : 'That could not be submitted. Check the link and try again.'}
                </p>
              )}
              <div className="flex flex-wrap gap-2">
                <Button type="submit" disabled={submit.isPending || !url.trim()}>
                  {ar ? 'سلّم للمراجعة' : 'Submit for review'}
                </Button>
                <Button type="button" variant="secondary" onClick={() => setOpen(false)} disabled={submit.isPending}>
                  {ar ? 'إلغاء' : 'Cancel'}
                </Button>
              </div>
            </form>
          ) : (
            <Button variant="secondary" onClick={() => setOpen(true)}>
              <Upload size={14} aria-hidden />
              {d.status === 'rejected'
                ? ar ? 'سلّم نسخة معدّلة' : 'Submit a new version'
                : ar ? 'سلّم المحتوى' : 'Submit content'}
            </Button>
          )}
        </div>
      )}
    </li>
  )
}

export function CreatorCollaborationPage() {
  const { collaborationId = '' } = useParams()
  const ar = useUi((s) => s.locale) === 'ar'

  const query = useQuery({
    queryKey: ['creator', 'collaboration', collaborationId],
    queryFn: () => fetchMyCollaboration(collaborationId),
    // A 404 here means "not yours, or never offered to you" — retrying cannot change either, and
    // retrying leaves the page in a state that is neither loading nor failed.
    retry: (count, error) => {
      const status = (error as { status?: number } | null)?.status
      if (status !== undefined && status >= 400 && status < 500) return false
      return count < 2
    },
  })

  if (query.isPending) {
    return (
      <div className="space-y-4 py-2">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-32 w-full" />
      </div>
    )
  }

  if (query.isError || !query.data) {
    return (
      <div className="py-2">
        <ErrorState
          title={ar ? 'هذا العمل غير متاح لك' : 'This work is not available to you'}
          description={
            ar
              ? 'قد يكون لمؤثر آخر، أو لم تُرسل إليك شروطه بعد.'
              : 'It may belong to another creator, or its terms have not been sent to you yet.'
          }
        />
        <Link
          to="/influencers/me"
          className="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:underline"
        >
          <ArrowRight size={15} className="rtl:rotate-180" aria-hidden />
          {ar ? 'العودة إلى أعمالك' : 'Back to your work'}
        </Link>
      </div>
    )
  }

  const c = query.data.collaboration

  return (
    <div className="space-y-6 py-2">
      <div>
        <Link
          to="/influencers/me"
          className="inline-flex items-center gap-1.5 text-[12px] font-semibold text-text-muted hover:text-text-primary"
        >
          <ArrowRight size={14} className="rtl:rotate-180" aria-hidden />
          {ar ? 'أعمالك' : 'Your work'}
        </Link>
        <h1 dir="auto" className="mt-1.5 font-heading text-[22px] font-extrabold tracking-tight text-text-primary">
          {c.title}
        </h1>
        {c.client_name && <p dir="auto" className="text-sm text-text-secondary">{c.client_name}</p>}
      </div>

      {/* The number they are agreeing to, stated plainly. The client's price is not on this
          surface at all — see the api module. */}
      <dl className="grid gap-3 sm:grid-cols-3">
        <div className="rounded-2xl border border-border bg-surface p-4">
          <dt className="text-[11px] font-semibold text-text-muted">{ar ? 'أجرك' : 'Your fee'}</dt>
          <dd className="mt-1 font-heading text-lg font-extrabold text-text-primary">
            {c.fee === null ? (ar ? 'غير محدّد' : 'Not set') : <Ltr>{money(c.fee, c.currency)}</Ltr>}
          </dd>
        </div>
        <div className="rounded-2xl border border-border bg-surface p-4">
          <dt className="text-[11px] font-semibold text-text-muted">{ar ? 'الفترة' : 'Period'}</dt>
          <dd className="mt-1 text-sm font-semibold text-text-primary" dir="ltr">
            {c.starts_on || c.ends_on ? `${c.starts_on ?? '—'} → ${c.ends_on ?? '—'}` : ar ? 'غير محدّدة' : 'Not set'}
          </dd>
        </div>
        <div className="rounded-2xl border border-border bg-surface p-4">
          <dt className="text-[11px] font-semibold text-text-muted">{ar ? 'بانتظارك' : 'Awaiting you'}</dt>
          <dd className="mt-1 font-heading text-lg font-extrabold text-text-primary">{c.progress.awaiting_me}</dd>
        </div>
      </dl>

      <TermsPanel c={c} ar={ar} id={collaborationId} />

      {c.brief && (
        <section>
          <h2 className="font-heading text-[15px] font-bold text-text-primary">{ar ? 'الموجز' : 'The brief'}</h2>
          <p dir="auto" className="mt-2 whitespace-pre-wrap rounded-2xl border border-border bg-surface p-4 text-sm leading-relaxed text-text-secondary">
            {c.brief}
          </p>
        </section>
      )}

      <section>
        <h2 className="font-heading text-[15px] font-bold text-text-primary">
          {ar ? 'المطلوب تسليمه' : 'What you owe'}
        </h2>
        {c.deliverables.length === 0 ? (
          <p className="mt-2 rounded-2xl border border-dashed border-border bg-surface p-6 text-center text-sm text-text-secondary">
            {ar ? 'لم تُضِف الوكالة أي مخرجات بعد.' : 'The agency has not added any deliverables yet.'}
          </p>
        ) : (
          <ul className="mt-2 space-y-3">
            {c.deliverables.map((d) => (
              <DeliverableRow key={d.id} d={d} collaborationId={collaborationId} ar={ar} />
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}
