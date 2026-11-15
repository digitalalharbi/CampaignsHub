import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useLocation } from 'react-router-dom'
import { Building2, Check, ShieldCheck } from 'lucide-react'
import { fetchMemberships, PORTAL_LABELS, switchMembership, type Membership } from '@/features/auth/memberships'
import { completeWorkspaceSwitch } from '@/features/auth/workspaceSwitch'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

/**
 * CONS-ADMIN-SWITCH — the admin panel and the person's own workspaces, one menu apart, without signing out.
 *
 * Shown only to an account that holds the admin panel (the platform flag) AND at least one workspace membership. The
 * list is the server's (`/auth/memberships`, derived from the signed-in person), the switch is the server's
 * (`POST /auth/memberships/switch`, refused for anything not theirs), and every later request re-verifies the choice.
 * Nothing here decides access: it only offers what the server already listed.
 */
export function WorkspaceHop({ onNavigate }: { onNavigate: (to: string, absolute?: boolean) => void }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const isAdmin = useAuth((s) => s.user?.is_platform_admin === true)
  const inAdmin = useLocation().pathname.startsWith('/admin')
  const queryClient = useQueryClient()
  const [busy, setBusy] = useState<string | null>(null)
  const state = useQuery({ queryKey: ['memberships'], queryFn: () => fetchMemberships(), staleTime: 60_000, enabled: isAdmin })

  const memberships = state.data?.memberships ?? []
  if (!isAdmin || memberships.length === 0) return null

  // The workspace this tab is drawn for: none inside the admin panel.
  const currentId = inAdmin ? null : (state.data?.current?.id ?? null)

  const enter = async (m: Membership) => {
    setBusy(m.id)
    try {
      const res = await switchMembership(m.id)
      completeWorkspaceSwitch(queryClient, res.destination)
    } catch {
      setBusy(null)
    }
  }

  const row = 'flex w-full items-center gap-3 rounded-lg px-3 py-2 text-start text-sm text-text-secondary transition-colors hover:bg-surface-hover hover:text-text-primary disabled:opacity-60'

  return (
    <div className="border-b border-border p-1.5" role="menu" aria-label={ar ? 'المساحات' : 'Spaces'} data-testid="workspace-hop">
      <button role="menuitem" data-testid="workspace-hop-admin" disabled={inAdmin} onClick={() => onNavigate('/admin', true)} className={row}>
        <ShieldCheck size={16} className="shrink-0 text-text-muted" />
        <span className="min-w-0 flex-1 truncate font-semibold">{ar ? 'لوحة الإدارة' : 'Admin panel'}</span>
        {inAdmin && <Check size={14} className="shrink-0 text-brand-600" aria-label={ar ? 'الحالية' : 'Current'} />}
      </button>
      {memberships.map((m) => (
        <button
          key={m.id}
          role="menuitem"
          data-testid={`workspace-hop-${m.id}`}
          disabled={busy !== null || m.id === currentId}
          onClick={() => void enter(m)}
          className={row}
        >
          <Building2 size={16} className="shrink-0 text-text-muted" />
          <span className="min-w-0 flex-1">
            <span className="block truncate font-semibold" dir="auto">{m.tenant.name ?? (ar ? 'مساحة عمل' : 'Workspace')}</span>
            <span className="block truncate text-[11px] text-text-muted">{ar ? PORTAL_LABELS[m.portal].ar : PORTAL_LABELS[m.portal].en}</span>
          </span>
          {m.id === currentId && <Check size={14} className="shrink-0 text-brand-600" aria-label={ar ? 'الحالية' : 'Current'} />}
        </button>
      ))}
    </div>
  )
}
