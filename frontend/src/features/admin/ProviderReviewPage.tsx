import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, CircleDashed, Clock, Lock, ShieldCheck } from 'lucide-react'
import { getReviewChecklists, setReviewRequirement, type ReviewChecklist, type ReviewItem, type ReviewStatus } from './reviewApi'
import { Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'
import { QueryFailure } from '@/components/ui/QueryFailure'

/**
 * REVIEW-001 — eight checklists, one per platform, because there are eight different reviews.
 *
 * ## Why not one list with eight columns
 *
 * Google approves the consent screen and the developer token on separate tracks. Meta wants business
 * verification of the legal entity. TikTok whitelists advertiser ids in sandbox, so an app can pass
 * every internal test and reach nothing in production. Snapchat hangs ad accounts off an
 * organisation. A shared checklist would be wrong in both directions at once — silent about the
 * requirement that actually blocks each one, and demanding things that do not apply.
 *
 * ## Why some rows cannot be ticked
 *
 * The rows this application can answer itself are answered, and shown with their value. Letting an
 * operator mark the redirect URI «approved» by hand would produce a checklist that disagrees with
 * itself the moment the page reloads — and, worse, one that says a submission is ready when the URL
 * it will actually send is still HTTP.
 */

const STATUS_ORDER: ReviewStatus[] = ['missing', 'ready', 'submitted', 'approved']

function statusTone(status: ReviewStatus): string {
  return {
    missing: 'bg-[var(--danger-background)] text-danger',
    ready: 'bg-surface-secondary text-text-secondary',
    submitted: 'bg-[var(--warning-background)] text-text-secondary',
    approved: 'bg-[var(--success-background)] text-success',
  }[status]
}

function StatusIcon({ status }: { status: ReviewStatus }) {
  if (status === 'approved') return <ShieldCheck size={14} />
  if (status === 'submitted') return <Clock size={14} />
  if (status === 'ready') return <Check size={14} />
  return <CircleDashed size={14} />
}

export function ProviderReviewPage() {
  const { locale } = useUi()
  const ar = locale === 'ar'
  const qc = useQueryClient()
  const [openProvider, setOpenProvider] = useState<string | null>(null)

  const query = useQuery({ queryKey: ['admin', 'provider-review'], queryFn: getReviewChecklists })

  const update = useMutation({
    mutationFn: (v: { provider: string; requirement: string; status: ReviewStatus }) =>
      setReviewRequirement(v.provider, v.requirement, { status: v.status }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'provider-review'] }),
  })

  const label = (s: ReviewStatus) => ({
    missing: ar ? 'ناقص' : 'Missing',
    ready: ar ? 'جاهز' : 'Ready',
    submitted: ar ? 'قُدِّم' : 'Submitted',
    approved: ar ? 'معتمد' : 'Approved',
  }[s])

  if (query.isLoading) return <div className="space-y-3"><Skeleton className="h-20" /><Skeleton className="h-64" /></div>
  if (query.isError) {
    return <QueryFailure error={query.error} ar={ar} testId="provider-review-failure" onRetry={() => void query.refetch()}
      fallbackTitle={ar ? 'تعذّر تحميل قوائم المراجعة.' : 'The review checklists could not be loaded.'} />
  }

  const providers = query.data?.providers ?? []

  const renderItem = (checklist: ReviewChecklist, item: ReviewItem) => (
    <li key={item.key} data-testid={`review-${checklist.provider}-${item.key}`} className="rounded-xl border border-border bg-surface p-3.5">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0 flex-1">
          <p className="flex items-center gap-1.5 text-sm font-semibold text-text-primary">
            {ar ? item.label_ar : item.label_en}
            {/*
              A padlock rather than a disabled control: this row is a FACT the system already knows,
              not a task somebody forgot to complete.
            */}
            {!item.editable && <Lock size={12} className="text-text-muted" aria-label={ar ? 'يحدده النظام' : 'Determined by the system'} />}
          </p>
          <p className="mt-1 text-[13px] leading-relaxed text-text-secondary">{ar ? item.why_ar : item.why_en}</p>

          {item.value && (
            <code className="mt-2 block overflow-x-auto rounded-lg bg-surface-secondary px-2.5 py-1.5 text-[12.5px] text-text-primary" dir="ltr">
              {item.value}
            </code>
          )}
          {(ar ? item.detail_ar : item.detail_en) && (
            <p className="mt-1.5 text-[12.5px] font-semibold text-danger">{ar ? item.detail_ar : item.detail_en}</p>
          )}
        </div>

        {item.editable ? (
          <select
            data-testid={`review-${checklist.provider}-${item.key}-status`}
            value={item.status}
            onChange={(e) => update.mutate({ provider: checklist.provider, requirement: item.key, status: e.target.value as ReviewStatus })}
            className="h-9 shrink-0 rounded-lg border border-border bg-surface px-2 text-[13px] font-semibold text-text-primary"
          >
            {STATUS_ORDER.map((s) => <option key={s} value={s}>{label(s)}</option>)}
          </select>
        ) : (
          <span className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${statusTone(item.status)}`}>
            <StatusIcon status={item.status} /> {label(item.status)}
          </span>
        )}
      </div>
    </li>
  )

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-2xl font-extrabold tracking-tight text-text-primary">
          {ar ? 'جاهزية مراجعة المزوّدين' : 'Provider review readiness'}
        </h1>
        <p className="mt-1 text-sm text-text-secondary">
          {ar
            ? 'لكل منصة متطلباتها الخاصة. ما يستطيع النظام تحديده محدَّد هنا ولا يُعدَّل يدويًا؛ وما يحدث داخل لوحة المزوّد تسجّله بنفسك.'
            : 'Each platform has its own requirements. What the system can determine is shown and cannot be edited by hand; what happens inside the provider’s console, you record yourself.'}
        </p>
      </div>

      <div className="grid gap-3">
        {providers.map((c) => {
          const open = openProvider === c.provider
          return (
            <section key={c.provider} data-testid={`review-provider-${c.provider}`} className="rounded-2xl border border-border bg-surface">
              <button
                type="button"
                onClick={() => setOpenProvider(open ? null : c.provider)}
                aria-expanded={open}
                className="flex w-full flex-wrap items-center gap-3 p-4 text-start"
              >
                <span className="font-heading text-base font-extrabold text-text-primary">{ar ? c.label_ar : c.label}</span>

                {/* The one number an operator wants at a glance. */}
                <span
                  data-testid={`review-${c.provider}-summary`}
                  className={`rounded-full px-2.5 py-1 text-xs font-semibold ${c.summary.missing > 0 ? statusTone('missing') : statusTone('ready')}`}
                >
                  {c.summary.missing > 0
                    ? (ar ? `ناقص ${c.summary.missing} من ${c.summary.total}` : `${c.summary.missing} of ${c.summary.total} missing`)
                    : (ar ? 'مكتمل للتقديم' : 'Ready to submit')}
                </span>
                {c.summary.approved > 0 && (
                  <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusTone('approved')}`}>
                    {ar ? `معتمد ${c.summary.approved}` : `${c.summary.approved} approved`}
                  </span>
                )}

                <span className="ms-auto text-sm text-text-muted">{open ? (ar ? 'إخفاء' : 'Hide') : (ar ? 'عرض' : 'Show')}</span>
              </button>

              {open && <ul className="space-y-2 border-t border-border p-4">{c.items.map((i) => renderItem(c, i))}</ul>}
            </section>
          )
        })}
      </div>
    </div>
  )
}
