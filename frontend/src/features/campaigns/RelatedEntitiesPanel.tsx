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
 */

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

  if (q.isLoading) return <Skeleton className="h-32" />
  if (q.isError || !q.data) return null

  const { context, relations } = q.data

  return (
    <section data-testid="related-entities" className="rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-small)]">
      <h2 className="flex items-center gap-2 font-bold text-text-primary"><Network size={16} /> الكيانات المرتبطة</h2>

      {/* Where this campaign sits — the upward chain. */}
      <nav className="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-text-secondary">
        {context.client && (
          <>
            <Link to={context.client.to} className="rounded-lg bg-surface-secondary px-2 py-1 font-semibold text-text-primary hover:text-brand-600">{context.client.name}</Link>
            <ArrowLeft size={12} className="text-text-muted rtl:rotate-180" />
          </>
        )}
        <Link to={context.project.to} className="rounded-lg bg-surface-secondary px-2 py-1 font-semibold text-text-primary hover:text-brand-600">المشروع</Link>
        <ArrowLeft size={12} className="text-text-muted rtl:rotate-180" />
        <span className="rounded-lg bg-brand-primary-soft px-2 py-1 font-semibold text-brand-700">{context.campaign.name}</span>
      </nav>

      <ul className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
        {Object.entries(relations).map(([key, rel]) => {
          const target = rel.items[0]?.to ?? rel.to
          const body = (
            <>
              <span className="block text-[11px] text-text-muted">{rel.label_ar}</span>
              <span className={`tnum block text-lg font-extrabold ${rel.count === 0 ? 'text-text-muted' : 'text-text-primary'}`}>{rel.count}</span>
              {rel.items.length > 0 && (
                <span className="mt-0.5 block truncate text-[11px] text-text-secondary">{rel.items.map((i) => i.label).join(' · ')}</span>
              )}
            </>
          )
          return (
            <li key={key} data-testid={`relation-${key}`}>
              {target && rel.count > 0
                ? <Link to={target} className="block rounded-xl bg-surface-secondary p-2.5 transition-colors hover:bg-surface-hover">{body}</Link>
                : <span className="block rounded-xl bg-surface-secondary p-2.5">{body}</span>}
            </li>
          )
        })}
      </ul>
    </section>
  )
}
