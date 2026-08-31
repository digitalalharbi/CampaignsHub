import type { ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { CalendarClock, FileSignature, Inbox } from 'lucide-react'
import { fetchCreatorProfile, fetchMyCollaborations, type CreatorCollaboration } from './api'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'

/**
 * `/influencers/me` — the creator's own home (INFL-002).
 *
 * Deliberately NOT the operator's collaborations list with fewer columns. The two audiences ask
 * different questions, and the ordering here answers the creator's:
 *
 *   1. Is anyone waiting on ME? Offers to answer, then pieces to submit. Both are things only this
 *      person can unblock, so they come first and nothing else competes with them.
 *   2. What is running?
 *
 * The operator's page opens with a status filter, because their question is "where does the whole
 * book of work stand". Handing the creator that same screen would bury the two rows they actually
 * have to act on inside a list they cannot act on at all.
 *
 * The money shown is what they are PAID. The client's price is not withheld here — it is never sent
 * to this surface at all, so there is no field on the page that could reveal the agency's margin.
 */

const money = (amount: string | null, currency: string) =>
  amount === null ? null : `${Number(amount).toLocaleString('en-US')} ${currency}`

/**
 * A Latin-script run isolated from the surrounding Arabic.
 *
 * Found live: `21,000 SAR` rendered as `SAR 21,000` — the bidi algorithm reordering the run against
 * the paragraph, so the screen stopped matching the code. Every Latin fragment sitting in RTL text
 * needs this; agency-authored values of unknown script get `dir="auto"` instead.
 */
function Ltr({ children, className = '' }: { children: ReactNode; className?: string }) {
  return (
    <span dir="ltr" className={`inline-block ${className}`}>
      {children}
    </span>
  )
}

function OfferCard({ c, ar }: { c: CreatorCollaboration; ar: boolean }) {
  return (
    <Link
      to={`/influencers/me/${c.id}`}
      data-testid="creator-offer"
      className="block rounded-2xl border border-warning/40 bg-warning/5 p-4 transition-colors hover:border-warning"
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <span className="inline-flex items-center gap-1.5 rounded-full bg-warning/15 px-2.5 py-1 text-[11px] font-bold text-warning">
            <FileSignature size={13} aria-hidden />
            {ar ? 'بانتظار ردّك' : 'Awaiting your answer'}
          </span>
          <h3 dir="auto" className="mt-2 truncate font-heading text-[15px] font-bold text-text-primary">{c.title}</h3>
          {c.client_name && <p dir="auto" className="truncate text-[12px] text-text-muted">{c.client_name}</p>}
        </div>
        {c.fee && (
          <div className="text-end">
            <span className="block text-[11px] text-text-muted">{ar ? 'أجرك' : 'Your fee'}</span>
            <Ltr className="font-heading text-[15px] font-extrabold text-text-primary">{money(c.fee, c.currency)}</Ltr>
          </div>
        )}
      </div>
    </Link>
  )
}

function WorkCard({ c, ar }: { c: CreatorCollaboration; ar: boolean }) {
  const waiting = c.progress.awaiting_me

  return (
    <Link
      to={`/influencers/me/${c.id}`}
      data-testid="creator-collaboration"
      className="block rounded-2xl border border-border bg-surface p-4 transition-colors hover:border-brand-500"
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h3 dir="auto" className="truncate font-heading text-[15px] font-bold text-text-primary">{c.title}</h3>
          {c.client_name && <p dir="auto" className="truncate text-[12px] text-text-muted">{c.client_name}</p>}
        </div>
        {c.fee && (
          <Ltr className="font-heading text-sm font-extrabold text-text-primary">{money(c.fee, c.currency)}</Ltr>
        )}
      </div>

      <div className="mt-3 flex flex-wrap items-center gap-2 text-[12px]">
        {waiting > 0 ? (
          <span className="inline-flex items-center gap-1.5 rounded-full bg-warning/15 px-2.5 py-1 font-bold text-warning">
            <CalendarClock size={13} aria-hidden />
            {ar ? `${waiting} بانتظار تسليمك` : `${waiting} awaiting you`}
          </span>
        ) : (
          <span className="rounded-full bg-success/15 px-2.5 py-1 font-bold text-success">
            {ar ? 'لا شيء مطلوب منك' : 'Nothing needed from you'}
          </span>
        )}
        {c.progress.with_agency > 0 && (
          <span className="rounded-full bg-info/15 px-2.5 py-1 font-semibold text-info">
            {ar ? `${c.progress.with_agency} قيد المراجعة` : `${c.progress.with_agency} in review`}
          </span>
        )}
        <span className="text-text-muted">
          {ar
            ? `${c.progress.done} من ${c.progress.total} مكتملة`
            : `${c.progress.done} of ${c.progress.total} done`}
        </span>
      </div>
    </Link>
  )
}

export function CreatorWorkPage() {
  const ar = useUi((s) => s.locale) === 'ar'

  const profile = useQuery({ queryKey: ['creator', 'profile'], queryFn: fetchCreatorProfile })
  const work = useQuery({ queryKey: ['creator', 'collaborations'], queryFn: fetchMyCollaborations })

  if (profile.isPending || work.isPending) {
    return (
      <div className="space-y-4 py-2">
        <Skeleton className="h-8 w-56" />
        <Skeleton className="h-28 w-full" />
        <Skeleton className="h-28 w-full" />
      </div>
    )
  }

  if (profile.isError || work.isError) {
    return (
      <ErrorState
        title={ar ? 'تعذّر تحميل أعمالك' : 'Your work could not be loaded'}
        onRetry={() => {
          void profile.refetch()
          void work.refetch()
        }}
      />
    )
  }

  const all = work.data?.collaborations ?? []
  // An offer is one nobody has answered yet. Split here rather than filtered by the server, so the
  // two lists cannot disagree about what "awaiting you" means.
  const offers = all.filter((c) => c.can_respond)
  const active = all.filter((c) => c.decision === 'accepted')
  const closed = all.filter((c) => c.decision === 'declined')

  return (
    <div className="space-y-7 py-2">
      <header>
        <h1 className="font-heading text-[22px] font-extrabold tracking-tight text-text-primary">
          {ar ? `أهلًا ${profile.data?.creator.name ?? ''}` : `Hi ${profile.data?.creator.name ?? ''}`}
        </h1>
        <p className="mt-1 text-sm text-text-secondary">
          {ar ? 'أعمالك واتفاقياتك مع الوكالة.' : 'Your work and your agreements with the agency.'}
        </p>
      </header>

      {offers.length > 0 && (
        <section className="space-y-3">
          <h2 className="font-heading text-[15px] font-bold text-text-primary">
            {ar ? 'عروض بانتظار ردّك' : 'Offers awaiting your answer'}
          </h2>
          {offers.map((c) => <OfferCard key={c.id} c={c} ar={ar} />)}
        </section>
      )}

      <section className="space-y-3">
        <h2 className="font-heading text-[15px] font-bold text-text-primary">
          {ar ? 'أعمالك الجارية' : 'Your active work'}
        </h2>
        {active.length === 0 ? (
          <div
            data-testid="creator-no-active-work"
            className="rounded-2xl border border-dashed border-border bg-surface p-8 text-center"
          >
            <Inbox className="mx-auto text-text-muted" size={26} aria-hidden />
            <p className="mt-3 text-sm text-text-secondary">
              {offers.length > 0
                ? ar ? 'اقبل عرضًا أعلاه ليبدأ العمل.' : 'Accept an offer above to start work.'
                : ar ? 'لا توجد أعمال جارية حاليًا.' : 'Nothing is running right now.'}
            </p>
          </div>
        ) : (
          active.map((c) => <WorkCard key={c.id} c={c} ar={ar} />)
        )}
      </section>

      {closed.length > 0 && (
        <section className="space-y-3">
          <h2 className="font-heading text-[15px] font-bold text-text-primary">
            {ar ? 'عروض اعتذرت عنها' : 'Offers you declined'}
          </h2>
          {closed.map((c) => (
            <div key={c.id} className="rounded-2xl border border-border bg-surface-secondary p-4">
              <span dir="auto" className="font-heading text-sm font-bold text-text-secondary">{c.title}</span>
              {c.client_name && <span dir="auto" className="ms-2 text-[12px] text-text-muted">{c.client_name}</span>}
            </div>
          ))}
        </section>
      )}
    </div>
  )
}
