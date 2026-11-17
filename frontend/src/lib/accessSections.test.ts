import { describe, expect, it } from 'vitest'
import { agencyFoldedLeaves, agencyNavGroups, agencySettingsGroup } from '@/layouts/agencyNav'
import { permissionOfPath, sectionOfPath } from './accessSections'

/** ACCESS-SECTIONS-001 — every one of the seven sections has a rail entry the member's sections can hide. */
describe('sectionOfPath', () => {
  it('maps the agency rail onto the seven sections and leaves everything else alone', () => {
    // ONE-BUDGET-UI-001 — «حدود الإنفاق» left the rail for the budget's panel; its address (a folded leaf) keeps its section.
    const leaves = [...[...agencyNavGroups, agencySettingsGroup].flatMap((g) => g.leaves), ...agencyFoldedLeaves]
    const mapped = new Set(leaves.map((l) => sectionOfPath(l.to)).filter((s): s is string => s !== null))
    expect([...mapped].sort()).toEqual(['budgets', 'campaigns', 'integrations', 'invoices', 'reports', 'spend_limits', 'team'])
    expect(sectionOfPath('/agency/dashboard')).toBeNull()
    expect(sectionOfPath('/agency/campaigns/123')).toBe('campaigns')
    expect(sectionOfPath('/app/spend-limits')).toBe('spend_limits')
    expect(sectionOfPath('/agency/content')).toBeNull()
  })
})

/** NAV-PAGE-PERMISSION-001 — every rail page that refuses without a read permission is offered only to its holders. */
describe('permissionOfPath', () => {
  it('names the read permission of each gated page, in both portals, and none for the home page', () => {
    expect(permissionOfPath('/agency/clients')).toBe('clients.view')
    expect(permissionOfPath('/agency/requests')).toBe('requests.view')
    expect(permissionOfPath('/agency/messages')).toBe('messaging.view')
    expect(permissionOfPath('/agency/ads')).toBe('campaigns.view')
    expect(permissionOfPath('/agency/content')).toBe('content.view')
    expect(permissionOfPath('/agency/files')).toBe('drive.view')
    expect(permissionOfPath('/agency/analytics')).toBe('analytics.view')
    expect(permissionOfPath('/agency/delivery')).toBe('reports.view')
    expect(permissionOfPath('/agency/forms')).toBe('leads.view')
    expect(permissionOfPath('/agency/spend-limits')).toBe('budget.view')
    expect(permissionOfPath('/agency/agents/approvals')).toBe('ai.view')
    expect(permissionOfPath('/agency/automation')).toBe('automations.view')
    expect(permissionOfPath('/app/campaigns/123')).toBe('campaigns.view')
    expect(permissionOfPath('/agency/dashboard')).toBeNull()
  })

  it('gives every agency rail page except home a permission', () => {
    const leaves = [...agencyNavGroups, agencySettingsGroup].flatMap((g) => g.leaves)
    const open = leaves.filter((l) => permissionOfPath(l.to) === null).map((l) => l.to)
    expect(open.sort()).toEqual(['/agency/dashboard', '/agency/home', '/agency/team'])
  })
})
