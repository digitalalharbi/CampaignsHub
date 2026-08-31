import { describe, expect, it } from 'vitest'

import { SYSTEM_ROLE_LABELS, roleLabel } from './roleLabels'

/**
 * ROLE-LABEL-001 — «Tenant Owner» and «Team Member» in the role dropdown of an Arabic page.
 *
 * The slugs below are the roles seeded with `is_system => true` in `database/seeders`. TypeScript
 * cannot read PHP, so they are written out: a system role added there and not here fails HERE.
 */
describe('role labels', () => {
  const SYSTEM_SLUGS = ['tenant-owner', 'member', 'analyst', 'account-manager', 'client-viewer', 'client-portal', 'talent-manager']

  it.each(SYSTEM_SLUGS)('translates the system role %s', (slug) => {
    const label = roleLabel({ slug, name: 'Seeded English', is_system: true }, true)

    expect(label).not.toBe('Seeded English')
    expect(label).not.toBe(slug)
  })

  it('covers exactly the seeded system roles, so a dead entry cannot pass for coverage', () => {
    expect(Object.keys(SYSTEM_ROLE_LABELS).sort()).toEqual([...SYSTEM_SLUGS].sort())
  })

  /** A tenant named this role. Translating it would rename their work. */
  it('leaves a custom role exactly as the customer typed it', () => {
    expect(roleLabel({ slug: 'media-buyer', name: 'مشتري وسائط أول', is_system: false }, true)).toBe('مشتري وسائط أول')
    expect(roleLabel({ slug: 'media-buyer', name: 'مشتري وسائط أول', is_system: false }, false)).toBe('مشتري وسائط أول')
  })

  /**
   * An unknown system slug keeps the stored name. It is at least something a person wrote; a slug
   * is not, and «unknown» would destroy information the reader could act on.
   */
  it('falls back to the stored name for a system role it does not know', () => {
    expect(roleLabel({ slug: 'auditor', name: 'Auditor', is_system: true }, true)).toBe('Auditor')
  })

  /** An older payload without the flag is treated as a system role — the case that shipped. */
  it('translates a known slug even when the payload omits is_system', () => {
    expect(roleLabel({ slug: 'tenant-owner', name: 'Tenant Owner' }, true)).toBe('مالك مساحة العمل')
  })
})
