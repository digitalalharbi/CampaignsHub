import { useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useQuery } from '@tanstack/react-query'
import { Building2, Check, ChevronDown, FolderKanban, Search } from 'lucide-react'
import { listClientWorkspaces, listProjects } from '@/features/projects/api'
import { useAgencyClient } from '@/stores/agencyClient'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'

/**
 * CLIENT-SCOPE-TOP-001 — the organisation switcher at the top of every agency page, the way an ads manager does it.
 *
 * Two lines on the bar: the CLIENT (or «كل العملاء») and, under it, the PROJECT inside it. Opening it shows a search box
 * and every client with its projects indented beneath — one look answers «where am I», one click moves. Picking a
 * client narrows every section that reads the scope (`useAgencyClient`, AGENCY-006); picking a project picks its client
 * with it, so the two can never disagree. «كل العملاء» lets go of both.
 *
 * Both lists are the server's (`/client-workspaces`, `/projects`), already narrowed to the member's reach, so a member
 * confined to three clients is offered three; the rail's switcher drops a stored id that is no longer reachable.
 *
 * The panel is drawn on the page (a portal, fixed under the button) so the sticky bar never clips it. From the
 * small-tablet width up; on a phone the same choice is the menu's scope switcher, because a wide control in a 360px bar
 * is what pushes the bar sideways.
 */
export function ClientScopeChip() {
  const ar = useUi((s) => s.locale) === 'ar'
  const current = useAgencyClient((s) => s.currentClientId)
  const setClient = useAgencyClient((s) => s.setCurrentClientId)
  const { currentProjectId, setCurrentProjectId } = useProject()
  const clients = useQuery({ queryKey: ['agency-scope', 'clients'], queryFn: listClientWorkspaces, staleTime: 60_000 })
  const projects = useQuery({ queryKey: ['projects', 'list'], queryFn: () => listProjects(false), staleTime: 60_000 })
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const button = useRef<HTMLButtonElement | null>(null)
  const panel = useRef<HTMLDivElement | null>(null)
  const [at, setAt] = useState<{ top: number; left: number; width: number } | null>(null)

  const list = clients.data ?? []
  const all = projects.data ?? []
  const client = current !== null ? list.find((c) => String(c.id) === current) ?? null : null
  const project = currentProjectId !== null ? all.find((p) => p.id === currentProjectId) ?? null : null

  const groups = useMemo(() => {
    const q = query.trim().toLowerCase()
    const hit = (s: string) => q === '' || s.toLowerCase().includes(q)

    return list
      .map((c) => {
        const own = all.filter((p) => String(p.client_workspace_id) === String(c.id))
        const projectHits = own.filter((p) => hit(p.name))

        return { client: c, projects: hit(c.name) ? own : projectHits, match: hit(c.name) || projectHits.length > 0 }
      })
      .filter((g) => g.match)
  }, [list, all, query])

  useEffect(() => {
    if (!open) return
    const place = () => {
      const r = button.current?.getBoundingClientRect()
      if (!r) return
      const width = Math.min(340, window.innerWidth - 16)
      const left = Math.max(8, Math.min(window.innerWidth - width - 8, ar ? r.right - width : r.left))
      setAt({ top: r.bottom + 6, left, width })
    }
    place()
    const away = (e: MouseEvent) => {
      const t = e.target as Node
      if (!panel.current?.contains(t) && !button.current?.contains(t)) setOpen(false)
    }
    const esc = (e: KeyboardEvent) => { if (e.key === 'Escape') { setOpen(false); button.current?.focus() } }
    window.addEventListener('resize', place)
    window.addEventListener('scroll', place, true)
    document.addEventListener('mousedown', away)
    document.addEventListener('keydown', esc)

    return () => {
      window.removeEventListener('resize', place)
      window.removeEventListener('scroll', place, true)
      document.removeEventListener('mousedown', away)
      document.removeEventListener('keydown', esc)
    }
  }, [open, ar])

  if (list.length < 2) return null

  const pickClient = (id: string | null) => {
    setClient(id)
    // The project belonged to the previous client; the rail's auto-select picks the new client's own.
    setCurrentProjectId(null)
    setOpen(false)
  }
  const pickProject = (clientId: string, projectId: string) => {
    setClient(clientId)
    setCurrentProjectId(projectId)
    setOpen(false)
  }

  const top = client?.name ?? (ar ? 'كل العملاء' : 'All clients')
  const sub = client ? (project?.name ?? (ar ? 'كل المشاريع' : 'All projects')) : (ar ? 'العميل والمشروع' : 'Client and project')

  return (
    <>
      <button
        ref={button}
        type="button"
        data-testid="client-scope-chip"
        aria-haspopup="dialog"
        aria-expanded={open}
        onClick={() => { setOpen((v) => !v); setQuery('') }}
        className={`hidden h-10 min-w-0 max-w-72 items-center gap-2 rounded-xl border px-2.5 text-start transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-600 sm:inline-flex ${
          client ? 'border-brand-600/40 bg-brand-600/10' : 'border-border bg-surface hover:bg-surface-hover'
        }`}
      >
        <span className={`grid h-7 w-7 shrink-0 place-items-center rounded-lg ${client ? 'bg-brand-600 text-white' : 'bg-surface-secondary text-text-secondary'}`}><Building2 size={14} aria-hidden /></span>
        <span className="flex min-w-0 flex-col leading-tight">
          <span className="truncate text-xs font-extrabold text-text-primary" data-testid="client-scope-current">{top}</span>
          <span className="truncate text-xs text-text-muted">{sub}</span>
        </span>
        <ChevronDown size={14} aria-hidden className={`shrink-0 text-text-muted transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>

      {open && at && createPortal(
        <div
          ref={panel}
          role="dialog"
          aria-label={ar ? 'اختر العميل والمشروع' : 'Choose client and project'}
          data-testid="client-scope-panel"
          dir={ar ? 'rtl' : 'ltr'}
          style={{ top: at.top, left: at.left, width: at.width }}
          className="fixed z-[70] flex max-h-[min(70vh,560px)] flex-col overflow-hidden rounded-2xl border border-border bg-surface shadow-[var(--shadow-large)]"
        >
          <label className="flex items-center gap-2 border-b border-border px-3 py-2.5">
            <Search size={15} aria-hidden className="shrink-0 text-text-muted" />
            <input
              autoFocus
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder={ar ? 'ابحث عن عميل أو مشروع…' : 'Find a client or project…'}
              data-testid="client-scope-search"
              className="min-w-0 flex-1 bg-transparent text-sm text-text-primary outline-none placeholder:text-text-muted"
            />
          </label>
          <div className="min-h-0 flex-1 overflow-y-auto py-1" data-testid="client-scope-list">
            {query.trim() === '' && (
              <Row onClick={() => pickClient(null)} active={current === null} testid="client-scope-all" strong>
                <Building2 size={14} aria-hidden className="shrink-0 text-text-muted" />
                {ar ? 'كل العملاء' : 'All clients'}
              </Row>
            )}
            {groups.map(({ client: c, projects: ps }) => (
              <div key={c.id} className="border-t border-border/60 first:border-t-0">
                <Row onClick={() => pickClient(String(c.id))} active={current === String(c.id) && currentProjectId === null} testid={`client-scope-client-${c.id}`} strong>
                  <span className="grid h-5 w-5 shrink-0 place-items-center rounded-md bg-surface-secondary text-xs font-extrabold text-text-secondary">{c.name.trim().charAt(0).toUpperCase()}</span>
                  <span className="truncate">{c.name}</span>
                </Row>
                {ps.map((p) => (
                  <Row key={p.id} onClick={() => pickProject(String(c.id), p.id)} active={currentProjectId === p.id} testid={`client-scope-project-${p.id}`} indent>
                    <FolderKanban size={13} aria-hidden className="shrink-0 text-text-muted" />
                    <span className="truncate">{p.name}</span>
                  </Row>
                ))}
              </div>
            ))}
            {groups.length === 0 && (
              <p className="px-3 py-6 text-center text-sm text-text-muted">{ar ? 'لا نتائج' : 'No results'}</p>
            )}
          </div>
        </div>,
        document.body,
      )}
    </>
  )
}

function Row({ children, onClick, active, testid, strong = false, indent = false }: { children: React.ReactNode; onClick: () => void; active: boolean; testid: string; strong?: boolean; indent?: boolean }) {
  return (
    <button
      type="button"
      onClick={onClick}
      data-testid={testid}
      aria-current={active ? 'true' : undefined}
      className={`flex w-full min-w-0 items-center gap-2 py-2 text-start text-sm transition-colors hover:bg-surface-hover focus-visible:bg-surface-hover focus-visible:outline-none ${
        indent ? 'ps-9 pe-3 text-text-secondary' : 'px-3'
      } ${strong ? 'font-bold text-text-primary' : ''} ${active ? 'bg-brand-600/10 text-brand-600' : ''}`}
    >
      {children}
      {active && <Check size={14} aria-hidden className="ms-auto shrink-0 text-brand-600" />}
    </button>
  )
}
