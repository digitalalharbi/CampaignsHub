import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, CheckCircle2, ExternalLink, Handshake, Send } from 'lucide-react'
import { fetchCollaborations, sendTerms, type Collaboration } from './api'
import { Button } from '@/components/ui/Button'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'

/**
 * `/influencers` — the agreements this operator can reach (INFL-001).
 *
 * Two things this page refuses to blur:
 *
 *   Progress is per deliverable. "Two of three posts are live, one is late" is the question everyone
 *   asks, and a single status on the agreement cannot answer it.
 *
 *   Cost is a separate permission. When the server withholds the creator's fee and the margin, they
 *   are ABSENT — this page shows nothing in their place rather than a zero or a dash that could be
 *   read as the real figure.
 */

const STATUS_LABELS: Record<string, { ar: string; en: string; tone: string }> = {
  draft: { ar: 'مسودة', en: 'Draft', tone: 'bg-surface-secondary text-text-muted' },
  negotiating: { ar: 'قيد التفاوض', en: 'Negotiating', tone: 'bg-info/15 text-info' },
  active: { ar: 'نشط', en: 'Active', tone: 'bg-success/15 text-success' },
  completed: { ar: 'مكتمل', en: 'Completed', tone: 'bg-brand-primary-soft text-brand-700' },
  cancelled: { ar: 'ملغي', en: 'Cancelled', tone: 'bg-surface-secondary text-text-muted' },
}

const money = (amount: string | null, currency: string) =>
  amount === null ? null : `${Number(amount).toLocaleString('en-US')} ${currency}`

export function CollaborationsPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const [status, setStatus] = useState<string>('')

  const query = useQuery({
    queryKey: ['influencers', 'collaborations', status],
    queryFn: () => fetchCollaborations(status || undefined),
  })

  if (query.isPending) {
    return <div className="grid gap-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-36" />)}</div>
  }

  if (query.isError || !query.data) {
    return (
      <ErrorState
        title={ar ? 'تعذّر تحميل التعاونات.' : 'Collaborations could not be loaded.'}
        onRetry={() => void query.refetch()}
      />
    )
  }

  const { collaborations, can_see_costs: canSeeCosts } = query.data

  return (
    <div className="w-full">
      <header className="mb-5">
        <h1 className="font-heading text-3xl font-extrabold tracking-tight text-text-primary">
          {ar ? 'التعاونات' : 'Collaborations'}
        </h1>
        <p className="mt-1 text-sm text-text-secondary">
          {ar
            ? 'كل اتفاق: المؤثر، العميل، والمخرجات المستحقة — لا أكثر مما تصل إليه.'
            : 'Each agreement: the creator, the client, and what is owed — no more than you can reach.'}
        </p>
      </header>

      <div className="mb-4 flex flex-wrap items-center gap-2">
        {['', 'active', 'negotiating', 'completed'].map((key) => (
          <button
            key={key || 'all'}
            type="button"
            onClick={() => setStatus(key)}
            aria-pressed={status === key}
            className={`rounded-lg px-3 py-1.5 text-sm font-semibold transition-colors ${
              status === key ? 'bg-brand-primary-soft text-brand-700' : 'text-text-secondary hover:bg-surface-hover'
            }`}
          >
            {key === ''
              ? (ar ? 'الكل' : 'All')
              : (ar ? STATUS_LABELS[key].ar : STATUS_LABELS[key].en)}
          </button>
        ))}
      </div>

      {collaborations.length === 0 ? (
        <p className="rounded-2xl border border-dashed border-border px-4 py-12 text-center text-sm text-text-muted">
          {ar ? 'لا توجد تعاونات ضمن نطاقك بعد.' : 'No collaborations within your scope yet.'}
        </p>
      ) : (
        <ul data-testid="collaborations" className="grid gap-3">
          {collaborations.map((c) => (
            <CollaborationCard key={c.id} c={c} ar={ar} canSeeCosts={canSeeCosts} />
          ))}
        </ul>
      )}
    </div>
  )
}

/**
 * Where the agreement stands with the creator, and the one action that moves it (INFL-002).
 *
 * Deliberately states the BLOCKING reason rather than hiding the button. "Send terms" greyed out
 * with no explanation sends an operator hunting through the roster for what is missing; naming it —
 * no portal access, or no creator fee set — is the difference between a dead control and an
 * instruction. `can_send_terms` is the server's own answer, so the button and the endpoint cannot
 * disagree about whether it is allowed.
 */
function AgreementStrip({ c, ar }: { c: Collaboration; ar: boolean }) {
  const queryClient = useQueryClient()
  /*
   * Defaulted rather than assumed present. A browser holding the new bundle can talk to an older
   * backend for the length of a deploy, and reading `.decision` off an absent block threw — which
   * took down the ENTIRE list, not just this strip. Fail-closed: no offer, and no button.
   */
  const a = c.agreement ?? {
    offered_at: null, decision: null, responded_at: null,
    decline_reason: null, creator_has_access: false, can_send_terms: false,
  }

  const send = useMutation({
    mutationFn: () => sendTerms(c.id),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['influencers', 'collaborations'] }),
  })

  if (a.decision === 'accepted') {
    return (
      <p data-testid="agreement-state" className="mt-3 inline-flex items-center gap-1.5 text-[12.5px] font-semibold text-success">
        <CheckCircle2 size={14} aria-hidden />
        {ar ? 'قبل المؤثر الشروط' : 'The creator accepted the terms'}
      </p>
    )
  }

  if (a.decision === 'declined') {
    return (
      <p data-testid="agreement-state" className="mt-3 text-[12.5px] font-semibold text-danger">
        {ar ? 'اعتذر المؤثر' : 'The creator declined'}
        {/* The reason they gave, in their own words — it is the whole value of a decline. */}
        {a.decline_reason && <span dir="auto" className="ms-1.5 font-normal text-text-secondary">— {a.decline_reason}</span>}
      </p>
    )
  }

  if (a.offered_at !== null) {
    return (
      <p data-testid="agreement-state" className="mt-3 text-[12.5px] font-semibold text-warning">
        {ar ? 'أُرسلت الشروط — بانتظار رد المؤثر' : 'Terms sent — awaiting the creator'}
      </p>
    )
  }

  return (
    <div data-testid="agreement-state" className="mt-3 flex flex-wrap items-center gap-2">
      <Button
        size="sm"
        variant="secondary"
        disabled={!a.can_send_terms || send.isPending}
        onClick={() => send.mutate()}
      >
        <Send size={13} aria-hidden />
        {ar ? 'أرسل الشروط للمؤثر' : 'Send terms to the creator'}
      </Button>
      {!a.can_send_terms && (
        <span className="text-[12px] text-text-muted">
          {!a.creator_has_access
            ? ar ? 'امنح المؤثر وصولًا إلى بوابته أولًا.' : 'Give the creator portal access first.'
            : ar ? 'حدّد أجر المؤثر أولًا.' : 'Set what the creator is paid first.'}
        </span>
      )}
      {send.isError && (
        <span role="alert" className="text-[12px] font-semibold text-danger">
          {ar ? 'تعذّر إرسال الشروط.' : 'The terms could not be sent.'}
        </span>
      )}
    </div>
  )
}

function CollaborationCard({ c, ar, canSeeCosts }: { c: Collaboration; ar: boolean; canSeeCosts: boolean }) {
  const label = STATUS_LABELS[c.status]

  return (
    <li data-testid={`collaboration-${c.id}`} className="rounded-2xl border border-border bg-surface p-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="font-heading text-[16px] font-bold text-text-primary">{c.title}</p>
          <p className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] text-text-secondary">
            <span className="inline-flex items-center gap-1.5 font-semibold">
              <Handshake size={14} aria-hidden />
              {c.influencer?.name ?? (ar ? 'مؤثر محذوف' : 'Removed creator')}
            </span>
            {c.influencer?.handle && <span className="text-text-muted" dir="ltr">@{c.influencer.handle}</span>}
            {c.client && <span className="text-text-muted">· {c.client.name}</span>}
          </p>
        </div>
        <span className={`shrink-0 rounded-full px-2.5 py-1 text-[11.5px] font-semibold ${label?.tone ?? 'bg-surface-secondary text-text-muted'}`}>
          {label ? (ar ? label.ar : label.en) : c.status}
        </span>
      </div>

      <AgreementStrip c={c} ar={ar} />

      <div className="mt-4 flex flex-wrap items-center gap-x-6 gap-y-2 text-[13px]">
        <Figure label={ar ? 'قيمة العقد للعميل' : 'Billed to the client'} value={money(c.agreed_fee, c.currency)} ar={ar} />
        {/* Absent, not zeroed: a placeholder here could be mistaken for the real figure. */}
        {canSeeCosts && (
          <>
            <Figure label={ar ? 'أجر المؤثر' : 'Paid to the creator'} value={money(c.influencer_fee ?? null, c.currency)} ar={ar} />
            <Figure label={ar ? 'هامش الوكالة' : 'Agency margin'} value={money(c.margin ?? null, c.currency)} ar={ar} />
          </>
        )}
      </div>

      <div className="mt-4 border-t border-border pt-3">
        <div className="flex flex-wrap items-center gap-3 text-[13px]">
          <span className="font-semibold text-text-primary">
            {ar ? 'المخرجات' : 'Deliverables'}
          </span>
          {c.progress.total === 0 ? (
            <span className="text-text-muted">{ar ? 'لم تُحدَّد بعد' : 'None defined yet'}</span>
          ) : (
            <>
              <span data-testid="progress-published" className="tnum inline-flex items-center gap-1.5 text-success" dir="ltr">
                <CheckCircle2 size={14} aria-hidden />
                {c.progress.published}/{c.progress.total}
                <span className="text-text-secondary">{ar ? 'منشور' : 'published'}</span>
              </span>
              {c.progress.overdue > 0 && (
                <span data-testid="progress-overdue" className="inline-flex items-center gap-1.5 font-semibold text-warning">
                  <AlertTriangle size={14} aria-hidden />
                  <span className="tnum" dir="ltr">{c.progress.overdue}</span>
                  {ar ? 'متأخر' : 'overdue'}
                </span>
              )}
            </>
          )}
        </div>

        {c.deliverables.length > 0 && (
          <ul className="mt-3 grid gap-1.5">
            {c.deliverables.map((d) => (
              <li key={d.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-surface-secondary px-3 py-2 text-[12.5px]">
                <span className="font-semibold text-text-primary">
                  {d.type}
                  {d.platform && <span className="ms-1.5 font-normal text-text-muted">· {d.platform}</span>}
                </span>
                <span className="flex items-center gap-2.5">
                  {d.due_on && (
                    <span className={`tnum ${d.is_overdue ? 'font-semibold text-warning' : 'text-text-muted'}`} dir="ltr">
                      {d.due_on}
                    </span>
                  )}
                  <span className="text-text-secondary">{d.status}</span>
                  {d.submitted_url && (
                    <a
                      href={d.submitted_url}
                      target="_blank"
                      rel="noreferrer noopener"
                      className="inline-flex items-center gap-1 font-semibold text-brand-600 hover:underline"
                    >
                      <ExternalLink size={12} aria-hidden />
                      {ar ? 'المحتوى' : 'View'}
                    </a>
                  )}
                </span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </li>
  )
}

function Figure({ label, value, ar }: { label: string; value: string | null; ar: boolean }) {
  return (
    <span className="flex flex-col">
      <span className="text-[11.5px] uppercase tracking-wide text-text-muted">{label}</span>
      <span className="tnum font-bold text-text-primary" dir="ltr">
        {value ?? <span className="font-normal text-text-muted">{ar ? 'غير محدّد' : 'Not set'}</span>}
      </span>
    </span>
  )
}
