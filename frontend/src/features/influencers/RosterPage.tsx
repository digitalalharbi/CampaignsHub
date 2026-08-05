import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ExternalLink, Search, Users } from 'lucide-react'
import { fetchRoster, type Influencer } from './api'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'

/**
 * `/influencers/roster` — everyone the agency works with (INFL-001).
 *
 * Tenant-wide on purpose: a creator is not owned by a client, so an account manager confined to
 * three clients still sees the whole roster. Hiding it would only make them re-add people the agency
 * already has terms with. What IS confined is the money and the client relationship, and those live
 * on the collaboration, not here.
 *
 * Contact details and private notes arrive only for someone who may manage the roster — the server
 * omits the keys entirely, so there is nothing here to leak by rendering.
 */

/** Latin digits everywhere, and a compact form so a six-figure following stays readable in a card. */
function followers(n: number | null, ar: boolean): string {
  if (n === null) return ar ? 'غير معروف' : 'Unknown'
  if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`
  if (n >= 1_000) return `${(n / 1_000).toFixed(1)}K`

  return n.toLocaleString('en-US')
}

export function RosterPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const [term, setTerm] = useState('')
  const [submitted, setSubmitted] = useState('')

  const query = useQuery({
    queryKey: ['influencers', 'roster', submitted],
    queryFn: () => fetchRoster({ q: submitted || undefined }),
  })

  return (
    <div className="w-full">
      <header className="mb-5">
        <h1 className="font-heading text-3xl font-extrabold tracking-tight text-text-primary">
          {ar ? 'قائمة المؤثرين' : 'Creator roster'}
        </h1>
        <p className="mt-1 text-sm text-text-secondary">
          {ar
            ? 'كل من تعمل معه الوكالة. القائمة مشتركة على مستوى الوكالة — الاتفاقات والأجور هي المرتبطة بالعميل.'
            : 'Everyone the agency works with. The roster is agency-wide — it is the agreements and fees that belong to a client.'}
        </p>
      </header>

      <form
        className="relative mb-4 w-full max-w-sm"
        onSubmit={(e) => { e.preventDefault(); setSubmitted(term.trim()) }}
      >
        <Search size={16} className="pointer-events-none absolute inset-y-0 my-auto ms-3 text-text-muted" aria-hidden />
        <input
          value={term}
          onChange={(e) => setTerm(e.target.value)}
          placeholder={ar ? 'ابحث بالاسم أو المعرّف' : 'Search by name or handle'}
          aria-label={ar ? 'ابحث في قائمة المؤثرين' : 'Search the creator roster'}
          className="h-10 w-full rounded-lg border border-border bg-surface ps-9 pe-3 text-sm outline-none focus:border-brand-500"
        />
      </form>

      {query.isPending && (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {[0, 1, 2, 3, 4, 5].map((i) => <Skeleton key={i} className="h-40" />)}
        </div>
      )}

      {query.isError && (
        <ErrorState
          error={query.error}
          title={ar ? 'تعذّر تحميل القائمة.' : 'The roster could not be loaded.'}
          onRetry={() => void query.refetch()}
        />
      )}

      {query.data && query.data.influencers.length === 0 && (
        <p className="rounded-2xl border border-dashed border-border px-4 py-12 text-center text-sm text-text-muted">
          {submitted
            ? (ar ? 'لا نتائج تطابق بحثك.' : 'Nothing matches your search.')
            : (ar ? 'لا يوجد مؤثرون في القائمة بعد.' : 'No creators on the roster yet.')}
        </p>
      )}

      {query.data && query.data.influencers.length > 0 && (
        <ul data-testid="creator-roster" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {query.data.influencers.map((i) => (
            <CreatorCard key={i.id} creator={i} ar={ar} />
          ))}
        </ul>
      )}
    </div>
  )
}

function CreatorCard({ creator, ar }: { creator: Influencer; ar: boolean }) {
  return (
    <li data-testid={`creator-${creator.id}`} className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-5">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="truncate font-heading text-[15px] font-bold text-text-primary">{creator.name}</p>
          {creator.handle && (
            <p className="mt-0.5 truncate text-[12.5px] text-text-muted" dir="ltr">@{creator.handle}</p>
          )}
        </div>
        {creator.primary_platform && (
          <span className="shrink-0 rounded-full bg-surface-secondary px-2.5 py-1 text-[11px] font-semibold text-text-secondary">
            {creator.primary_platform}
          </span>
        )}
      </div>

      <div className="flex flex-wrap gap-x-5 gap-y-2 text-[12.5px]">
        <Stat
          label={ar ? 'المتابعون' : 'Followers'}
          value={followers(creator.followers, ar)}
          muted={creator.followers === null}
        />
        <Stat
          label={ar ? 'معدل التفاعل' : 'Engagement'}
          // Stored and shown as a percentage — never a ratio, which reads as a hundredth of itself.
          value={creator.engagement_rate === null ? (ar ? 'غير معروف' : 'Unknown') : `${creator.engagement_rate}%`}
          muted={creator.engagement_rate === null}
        />
        <Stat
          label={ar ? 'التعاونات' : 'Collaborations'}
          value={creator.collaborations_count.toLocaleString('en-US')}
        />
      </div>

      {creator.categories.length > 0 && (
        <ul className="flex flex-wrap gap-1.5">
          {creator.categories.map((c) => (
            <li key={c} className="rounded-full bg-brand-primary-soft px-2 py-0.5 text-[11px] font-semibold text-brand-700">
              {c}
            </li>
          ))}
        </ul>
      )}

      <div className="mt-auto flex items-center justify-between gap-2 pt-1">
        <span className="inline-flex items-center gap-1.5 text-[12px] text-text-muted">
          <Users size={13} aria-hidden />
          {creator.status}
        </span>
        {creator.profile_url && (
          <a
            href={creator.profile_url}
            target="_blank"
            rel="noreferrer noopener"
            className="inline-flex items-center gap-1 text-[12.5px] font-semibold text-brand-600 hover:underline"
          >
            <ExternalLink size={12} aria-hidden />
            {ar ? 'الحساب' : 'Profile'}
          </a>
        )}
      </div>
    </li>
  )
}

function Stat({ label, value, muted = false }: { label: string; value: string; muted?: boolean }) {
  return (
    <span className="flex flex-col">
      <span className="text-[11px] uppercase tracking-wide text-text-muted">{label}</span>
      <span className={`tnum font-bold ${muted ? 'font-normal text-text-muted' : 'text-text-primary'}`} dir="ltr">
        {value}
      </span>
    </span>
  )
}
