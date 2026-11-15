import { describe, expect, it } from 'vitest'
import { agencyNavGroups, agencySettingsGroup } from '@/layouts/agencyNav'
import { sectionOfPath } from './accessSections'

/** ACCESS-SECTIONS-001 — every one of the seven sections has a rail entry the member's sections can hide. */
describe('sectionOfPath', () => {
  it('maps the agency rail onto the seven sections and leaves everything else alone', () => {
    const leaves = [...agencyNavGroups, agencySettingsGroup].flatMap((g) => g.leaves)
    const mapped = new Set(leaves.map((l) => sectionOfPath(l.to)).filter((s): s is string => s !== null))
    expect([...mapped].sort()).toEqual(['budgets', 'campaigns', 'integrations', 'invoices', 'reports', 'spend_limits', 'team'])
    expect(sectionOfPath('/agency/dashboard')).toBeNull()
    expect(sectionOfPath('/agency/campaigns/123')).toBe('campaigns')
    expect(sectionOfPath('/app/spend-limits')).toBe('spend_limits')
    expect(sectionOfPath('/agency/content')).toBeNull()
  })
})
