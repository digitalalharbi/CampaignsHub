import type { ClientWorkspace, Project } from './api'

/**
 * ANALYTICS-PROJECT-IDENTITY-001 — a project option that two clients share a name for says which.
 *
 * The Analytics project selector listed «Q3 Launch — Demo» three times: three projects, three
 * clients, one label. A reader choosing the second had no way to know whose numbers they were about
 * to read, and the figures that followed carried no client name either. A label is an identity, not
 * a caption; where the name alone is not one, the client's name is added — only there, because
 * «Store — Acme» on every option of a single-client list is noise that says nothing.
 *
 * The client list is the agency's (`/client-workspaces` answers 403 to an advertiser), so a caller
 * without it passes nothing and the labels stay bare — in an advertiser portal every project is the
 * same client's, and a repeated name there is a naming problem this cannot solve by guessing.
 */
export function projectOptionLabels(projects: readonly Project[], clients: readonly ClientWorkspace[] | undefined): Map<string, string> {
  const byClient = new Map((clients ?? []).map((c) => [c.id, c.name]))
  const repeats = new Map<string, number>()
  for (const p of projects) repeats.set(p.name, (repeats.get(p.name) ?? 0) + 1)

  return new Map(projects.map((p) => {
    const client = byClient.get(p.client_workspace_id)
    const ambiguous = (repeats.get(p.name) ?? 0) > 1 && client !== undefined

    return [p.id, ambiguous ? `${p.name} · ${client}` : p.name]
  }))
}
