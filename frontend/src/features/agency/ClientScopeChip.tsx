import { useQuery } from '@tanstack/react-query'
import { Building2, ChevronDown } from 'lucide-react'
import { listClientWorkspaces } from '@/features/projects/api'
import { useAgencyClient } from '@/stores/agencyClient'
import { useUi } from '@/stores/ui'

/**
 * CLIENT-SCOPE-TOP-001 — «كل العملاء» or one client's space, at the top of every agency page.
 *
 * The same choice the rail's scope switcher holds (`useAgencyClient`, AGENCY-006): picking a client here narrows every
 * section that reads it — the follow-up board on «الرئيسية», the analytics, the projects — and «كل العملاء» lets go.
 * The list is the server's (`/client-workspaces`, already narrowed to the member's clients), so a member confined to
 * three clients is offered three; the rail's switcher drops a stored id that is no longer reachable.
 *
 * From the small-tablet width up; on a phone the same choice is the follow-up board's chips and the menu's scope switcher,
 * because a fifth control in a 360px bar is what pushes the bar sideways.
 *
 * A native select on purpose: it opens as the phone's own picker, needs no portal over the sticky header, and is
 * reachable by keyboard and screen reader without any of it being rebuilt here.
 */
export function ClientScopeChip() {
  const ar = useUi((s) => s.locale) === 'ar'
  const current = useAgencyClient((s) => s.currentClientId)
  const setClient = useAgencyClient((s) => s.setCurrentClientId)
  const clients = useQuery({ queryKey: ['agency-scope', 'clients'], queryFn: listClientWorkspaces, staleTime: 60_000 })
  const list = clients.data ?? []
  if (list.length < 2) return null
  const value = current !== null && list.some((c) => String(c.id) === current) ? current : ''

  return (
    <label
      data-testid="client-scope-chip"
      className={`relative hidden h-9 min-w-0 max-w-64 items-center gap-1.5 rounded-full border px-3 text-xs font-semibold transition-colors sm:inline-flex ${
        value ? 'border-transparent bg-brand-600 text-white' : 'border-border bg-surface text-text-secondary hover:bg-surface-hover'
      }`}
    >
      <Building2 size={14} aria-hidden className="shrink-0" />
      <span className="sr-only">{ar ? 'العميل' : 'Client'}</span>
      <select
        data-testid="client-scope-select"
        value={value}
        onChange={(e) => setClient(e.target.value === '' ? null : e.target.value)}
        className="min-w-0 flex-1 cursor-pointer appearance-none truncate bg-transparent pe-4 outline-none [&>option]:bg-surface [&>option]:text-text-primary"
      >
        <option value="">{ar ? 'كل العملاء' : 'All clients'}</option>
        {list.map((c) => <option key={c.id} value={String(c.id)}>{c.name}</option>)}
      </select>
      <ChevronDown size={14} aria-hidden className="pointer-events-none absolute end-2.5 shrink-0" />
    </label>
  )
}
