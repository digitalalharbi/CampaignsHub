import { describe, expect, it } from 'vitest'

import { PROJECT_ROLE_LABELS, projectRoleLabel } from './ProjectTeamPage'

/**
 * PROJECT-ROLE-LABEL-001 — the role picker's label WAS its key.
 *
 * `options={ROLES.map((r) => ({ value: r, label: r }))}` — so assigning somebody to a project meant
 * choosing between «account_manager» and «media_buyer» in an Arabic page.
 *
 * The list mirrors `ProjectMembershipController::ROLES`. TypeScript cannot read PHP, so it is
 * written out: a role added there and not here fails HERE rather than reaching an operator raw.
 */
describe('project role labels', () => {
  const ROLES = [
    'account_manager', 'media_buyer', 'analyst', 'content', 'finance',
    'client_admin', 'client_approver', 'client_viewer', 'viewer',
  ]

  it.each(ROLES)('labels %s in both languages', (role) => {
    expect(projectRoleLabel(role, true)).not.toBe(role)
    expect(projectRoleLabel(role, false)).not.toBe(role)
  })

  it('covers exactly the roles the backend accepts, so neither list can drift', () => {
    expect(Object.keys(PROJECT_ROLE_LABELS).sort()).toEqual([...ROLES].sort())
  })

  it('distinguishes the client-side roles from the agency-side ones', () => {
    // These three govern what a CLIENT can do, and reading them as agency roles would be a
    // permissions misunderstanding — so their labels say whose side they are on.
    for (const role of ['client_admin', 'client_approver', 'client_viewer']) {
      expect(projectRoleLabel(role, true)).toContain('العميل')
    }
    expect(projectRoleLabel('viewer', true)).not.toContain('العميل')
  })

  it('shows an unrecognised role as itself rather than hiding it', () => {
    expect(projectRoleLabel('auditor', true)).toBe('auditor')
  })
})
