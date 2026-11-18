import { AccessCenter } from '../access/AccessCenter'
import { RolesPanel } from './RolesPanel'

/**
 * «الفريق والنطاقات» — ACCESS-CENTER-001.
 *
 * The access center is the one place invitations and access are decided, for every audience: the board of members
 * and invitations, the one «دعوة» dialog, the per-row drawer, and the invitation categories. The fine-grained boxes
 * of the ready-made roles (TEAM-ROLE-PRESETS-001) stay under it for whoever wants a single action rather than an
 * area. TEAM-INVITE-001 still holds: nothing exists until the invitee opens the link.
 */
export function TeamTab() {
  return (
    <div className="space-y-4">
      <AccessCenter />
      <RolesPanel />
    </div>
  )
}
