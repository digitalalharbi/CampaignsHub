import { describe, expect, it } from 'vitest'
import { projectOptionLabels } from './projectOptionLabels'
import type { ClientWorkspace, Project } from './api'

const project = (id: string, name: string, client: string): Project => ({
  id, client_workspace_id: client, name, status: 'active', setup_completion: 0, account_manager_id: null, created_at: null,
})
const clients = [{ id: 'w1', name: 'Acme' }, { id: 'w2', name: 'Nova' }] as ClientWorkspace[]

/** ANALYTICS-PROJECT-IDENTITY-001 — a shared name gets its client; a unique one stays as it is. */
describe('project option labels', () => {
  it('names the client only where two projects share a name', () => {
    const labels = projectOptionLabels([project('p1', 'Q3 Launch', 'w1'), project('p2', 'Q3 Launch', 'w2'), project('p3', 'Store', 'w1')], clients)
    expect(labels.get('p1')).toBe('Q3 Launch · Acme')
    expect(labels.get('p2')).toBe('Q3 Launch · Nova')
    expect(labels.get('p3')).toBe('Store')
  })

  it('stays bare without a client list, rather than guessing', () => {
    const labels = projectOptionLabels([project('p1', 'Q3 Launch', 'w1'), project('p2', 'Q3 Launch', 'w2')], undefined)
    expect(labels.get('p1')).toBe('Q3 Launch')
  })
})
