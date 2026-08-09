import { type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { ArrowLeft, Network } from 'lucide-react'
import { getData } from '@/lib/api/client'
import { Skeleton } from '@/components/ui/States'

/**
 * XREL-001 — the chain a campaign sits in, and everything hanging off it: client → project → platform →
 * ad account → campaign → ad set → ad → creative, plus alerts and reports that reference it.
 *
 * Counts come from real queries, so a relation with nothing in it shows 0 rather than disappearing. An
 * absent link is information: "no alerts reference this campaign" is a fact worth seeing, not an empty
 * space to be hidden.
 *
 * ## The panel occupies its own height before it has anything to say — CLICK-STABLE-001
 *
 * This sits directly above the campaign's tab strip, so whatever height it settles at is where the
 * tabs are. It used to stand in with `<Skeleton className="h-32" />` — 128px against a panel that
 * settles near 260 — and the tab strip therefore dropped 130px the moment the query landed.
 *
 * That is not a cosmetic jump. A pointer press and its release have to reach the SAME element for a
 * click to exist at all; when the target slides out from under the pointer between the two, the
 * browser reports nothing and the tab simply does not open. It is how a person loses a click they
 * are sure they made, and it is how `campaigns.spec.ts` lost `?tab=performance` on firefox for four
 * gates — recorded as TAB-PARAM-001 and misread as a routing defect the whole time.
 *
 * So the placeholder is the panel's own frame rather than a rectangle near its size, and every tile
 * carries `TILE_H` whether it is a skeleton or a count. The height is then right by construction
 * instead of by a number somebody kept in step by hand.
 */

/**
 * The seven relations `RelatedEntitiesController` always returns — every one of them, every time,
 * counts of zero included. Named here so the placeholder lays out the grid the answer will need.
 */
const RELATION_KEYS = ['platforms', 'ad_accounts', 'ad_sets', 'ads', 'creatives', 'alerts', 'reports']

const TILE = 'block rounded-xl bg-surface-secondary p-2.5'

/**
 * The three lines every tile has, each at a height of its own rather than of its contents.
 *
 * A tile is a label, a count, and a line of sample names — and that third line used to be rendered
 * only for the relations that carry samples, and only when the count was not zero. So a tile was 2
 * lines or 3 depending on the answer, a grid row took the height of its tallest tile, and the panel
 * — and everything below it — settled at a height nobody could predict before the query returned.
 *
 * Fixing the lines is what lets the placeholder be the same height as the answer BY CONSTRUCTION.
 * `min-h` on the tile was the first attempt and it was still arithmetic: it left the 3-line variant
 * free to exceed the reserve, and the tab strip below still moved 10px.
 */
const LINE = {
  label: 'block h-4 text-[11px] text-text-muted',
  count: 'tnum block h-7 text-lg font-extrabold',
  items: 'mt-0.5 block h-4 truncate text-[11px] text-text-secondary',
}

interface Relation {
  label_ar: string
  count: number
  items: Array<{ id: string; label: string; to: string }>
  to?: string
}

interface RelatedPayload {
  context: {
    client: { id: string; name: string; to: string } | null
    project: { id: string; to: string }
    campaign: { id: string; name: string }
  }
  relations: Record<string, Relation>
}

export function RelatedEntitiesPanel({ projectId, campaignId }: { projectId: string; campaignId: string }) {
  const q = useQuery({
    queryKey: ['projects', projectId, 'campaigns', campaignId, 'related'],
    queryFn: () => getData<RelatedPayload>(`/projects/${projectId}/campaigns/${campaignId}/related`),
    enabled: Boolean(projectId && campaignId),
  })

  /*
   * Both non-answers keep the frame. The failure also says so out loud: a panel that returns `null`
   * on error takes its own height with it — moving the tabs below — and tells the reader nothing
   * happened, which is exactly what «an absent link is information» was written against.
   */
  if (q.isLoading || q.isError || !q.data) {
    return (
      <Frame chain={<Skeleton className="h-7 w-64" />}>
        {RELATION_KEYS.map((key) => (
          <li key={key}>
            <span className={TILE}>
              {/*
                Each skeleton IS a line, carrying that line's own class, rather than a rectangle
                nested inside one. Nesting made the placeholder tile 86px against the answer's 82 —
                close enough to look right and still enough to move what is below it.
              */}
              {q.isError ? (
                <span className={LINE.label}>تعذّر تحميل الكيانات المرتبطة</span>
              ) : (
                <Skeleton className={`${LINE.label} w-2/3`} />
              )}
              <Skeleton className={`${LINE.count} w-8`} />
              <span className={LINE.items} />
            </span>
          </li>
        ))}
      </Frame>
    )
  }

  const { context, relations } = q.data

  return (
    <Frame
      testid="related-entities"
      chain={
        <>
          {context.client && (
            <>
              <Link to={context.client.to} className="rounded-lg bg-surface-secondary px-2 py-1 font-semibold text-text-primary hover:text-brand-600">{context.client.name}</Link>
              <ArrowLeft size={12} className="text-text-muted rtl:rotate-180" />
            </>
          )}
          <Link to={context.project.to} className="rounded-lg bg-surface-secondary px-2 py-1 font-semibold text-text-primary hover:text-brand-600">المشروع</Link>
          <ArrowLeft size={12} className="text-text-muted rtl:rotate-180" />
          <span className="rounded-lg bg-brand-primary-soft px-2 py-1 font-semibold text-brand-700">{context.campaign.name}</span>
        </>
      }
    >
      {Object.entries(relations).map(([key, rel]) => {
        const target = rel.items[0]?.to ?? rel.to
        const body = (
          <>
            <span className={LINE.label}>{rel.label_ar}</span>
            <span className={`${LINE.count} ${rel.count === 0 ? 'text-text-muted' : 'text-text-primary'}`}>{rel.count}</span>
            {/* Always rendered, empty or not — see LINE. */}
            <span className={LINE.items}>{rel.items.map((i) => i.label).join(' · ')}</span>
          </>
        )
        return (
          <li key={key} data-testid={`relation-${key}`}>
            {target && rel.count > 0
              ? <Link to={target} className={`${TILE} transition-colors hover:bg-surface-hover`}>{body}</Link>
              : <span className={TILE}>{body}</span>}
          </li>
        )
      })}
    </Frame>
  )
}

/**
 * The panel's shape — heading, the upward chain, and the grid of relation tiles.
 *
 * Shared by the answer and by both non-answers so the three cannot differ in height. `children` are
 * the `<li>` tiles; the grid is here rather than at each call site for the same reason.
 */
function Frame({ testid, chain, children }: { testid?: string; chain: ReactNode; children: ReactNode }) {
  return (
    <section data-testid={testid} className="rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-small)]">
      <h2 className="flex items-center gap-2 font-bold text-text-primary"><Network size={16} /> الكيانات المرتبطة</h2>

      {/* Where this campaign sits — the upward chain. */}
      {/* `min-h-7` is the chain's measured height — 28px, not the 24 its type size suggests. */}
      <nav className="mt-2 flex min-h-7 flex-wrap items-center gap-1.5 text-xs text-text-secondary">
        {chain}
      </nav>

      <ul className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">{children}</ul>
    </section>
  )
}
