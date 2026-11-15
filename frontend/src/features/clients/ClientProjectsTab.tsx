import { Link } from 'react-router-dom'
import { Badge } from '@/components/ui/Badge'
import { lifecycleLabel, lifecycleTone, lt } from '@/lib/projectLifecycle'

const LISTED = ['draft', 'active', 'paused']

/**
 * PROJECT-LIFECYCLE-001 §8 (builder N2) — the client's «المشاريع» tab, active separated from archived.
 *
 * An active project opens where it always did (its integrations); an archived one opens its own VIEW-ONLY page
 * (`/projects/:id`), never the client page. Statuses through the lifecycle keys — never a raw value.
 */
export function ClientProjectsTab({ projects, ar, portalTo }: {
  projects: { id: string; name: string; status: string }[]
  ar: boolean
  portalTo: (suffix: string) => string
}) {
  const live = projects.filter((p) => LISTED.includes(p.status))
  const archived = projects.filter((p) => p.status === 'archived')

  const group = (title: string, rows: typeof projects, testid: string, href: (id: string) => string) => (
    <section data-testid={testid} className="min-w-0">
      <h3 className="mb-2 flex items-center gap-2 text-sm font-bold text-text-primary">{title} <span className="tnum rounded-full bg-surface-secondary px-2 text-xs text-text-secondary">{rows.length}</span></h3>
      {rows.length === 0 ? <p className="text-sm text-text-muted">—</p> : (
        <ul className="space-y-2">
          {rows.map((p) => (
            <li key={p.id} className="flex min-w-0 items-center justify-between gap-2 rounded-lg border border-border px-4 py-3 text-sm">
              <Link to={href(p.id)} className="truncate font-medium text-brand-600 hover:underline">{p.name}</Link>
              <Badge tone={lifecycleTone(p.status)}>{lifecycleLabel(p.status, ar)}</Badge>
            </li>
          ))}
        </ul>
      )}
    </section>
  )

  return (
    <div className="grid gap-5 lg:grid-cols-2" data-testid="client-projects-tab">
      {group(lt('projects.lifecycle.filter.active', ar), live, 'client-projects-active', (id) => portalTo(`/projects/${id}/integrations`))}
      {group(lt('projects.lifecycle.filter.archived', ar), archived, 'client-projects-archived', (id) => portalTo(`/projects/${id}`))}
    </div>
  )
}
