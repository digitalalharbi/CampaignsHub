import { useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useQuery } from '@tanstack/react-query'
import { Building2, Check, ChevronsUpDown, Search } from 'lucide-react'
import { listClientWorkspaces, listProjects } from '@/features/projects/api'
import { useAgencyClient } from '@/stores/agencyClient'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'

/**
 * CLIENT-SCOPE-TOP-001 / CLIENT-SWITCHER-X-001 — the client switcher at the top of every agency page, the way X's ads manager does it.
 *
 * Owner 2026-11-20: ONE name per row. Every client's account belongs to the agency, so «client» over «project» was a double label
 * for one thing: the bar and the list name the client only, with its own logo where one was set and its initial until then. A
 * search box, «الأخيرة» (the last clients picked here) and every client under it. Picking a client narrows every section that
 * reads the scope (`useAgencyClient`); its project follows by itself — the only one when there is one, the rail's choice otherwise.
 *
 * Both lists are the server's, already narrowed to the member's reach. Drawn on the page (a portal) so the sticky bar never clips it.
 */
/** How many clients the open list draws at once; the search reaches the rest. */
const SHOWN = 40
const RECENT_KEY = 'ch.clientScope.recent'
const RECENT_MAX = 3

const readRecent = (): string[] => {
  try { const v = JSON.parse(localStorage.getItem(RECENT_KEY) ?? '[]'); return Array.isArray(v) ? v.filter((x) => typeof x === 'string') : [] } catch { return [] }
}
const pushRecent = (id: string) => {
  try { localStorage.setItem(RECENT_KEY, JSON.stringify([id, ...readRecent().filter((x) => x !== id)].slice(0, RECENT_MAX))) } catch { /* storage unavailable */ }
}

/** The client's mark: its own logo, or its initial on a tinted tile. */
export function ClientAvatar({ name, logo, size = 28 }: { name: string; logo?: string | null; size?: number }) {
  const [broken, setBroken] = useState(false)
  const initial = name.trim().charAt(0).toUpperCase() || '؟'
  if (logo && !broken) {
    return <img src={logo} alt="" onError={() => setBroken(true)} className="shrink-0 rounded-full border border-border bg-white object-contain" style={{ width: size, height: size }} />
  }

  return <span aria-hidden className="grid shrink-0 place-items-center rounded-full bg-brand-600/15 font-extrabold text-brand-600" style={{ width: size, height: size, fontSize: Math.round(size * 0.42) }}>{initial}</span>
}

export function ClientScopeChip() {
  return <ClientPicker variant="bar" />
}

/**
 * The one client picker, drawn two ways: on the top bar (`bar`) and as the rail's «العميل» field (`field`). Same list, same
 * search, same «الأخيرة», same marks — the rail used to be a native select and the bar a client-over-project tree.
 */
export function ClientPicker({ variant }: { variant: 'bar' | 'field' }) {
  const field = variant === 'field'
  const base = field ? 'agency-scope-client' : 'client-scope'
  const ar = useUi((s) => s.locale) === 'ar'
  const current = useAgencyClient((s) => s.currentClientId)
  const setClient = useAgencyClient((s) => s.setCurrentClientId)
  const { setCurrentProjectId } = useProject()
  const clients = useQuery({ queryKey: ['agency-scope', 'clients'], queryFn: listClientWorkspaces, staleTime: 60_000 })
  const projects = useQuery({ queryKey: ['projects', 'list'], queryFn: () => listProjects(false), staleTime: 60_000 })
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [recent, setRecent] = useState<string[]>(() => readRecent())
  const button = useRef<HTMLButtonElement | null>(null)
  const panel = useRef<HTMLDivElement | null>(null)
  const [at, setAt] = useState<{ top: number; left: number; width: number } | null>(null)

  const list = useMemo(() => clients.data ?? [], [clients.data])
  const all = projects.data ?? []
  const client = current !== null ? list.find((c) => String(c.id) === current) ?? null : null

  const q = query.trim().toLowerCase()
  const matches = useMemo(() => list.filter((c) => q === '' || c.name.toLowerCase().includes(q)), [list, q])
  const recentRows = q === '' ? recent.map((id) => list.find((c) => String(c.id) === id)).filter((c): c is NonNullable<typeof c> => c !== undefined) : []

  useEffect(() => {
    if (!open) return
    const place = () => {
      const r = button.current?.getBoundingClientRect()
      if (!r) return
      const width = Math.min(field ? Math.max(r.width, 280) : 340, window.innerWidth - 16)
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
  }, [open, ar, field])

  if (!field && list.length < 2) return null

  const pick = (id: string | null) => {
    setClient(id)
    // The client's own project follows: the only one when there is one; otherwise the rail's auto-select picks it.
    const own = id === null ? [] : all.filter((p) => String(p.client_workspace_id) === id)
    setCurrentProjectId(own.length === 1 ? own[0]!.id : null)
    if (id !== null) { pushRecent(id); setRecent(readRecent()) }
    setOpen(false)
  }

  const row = (c: (typeof list)[number], prefix: string) => (
    <Row key={`${prefix}-${c.id}`} onClick={() => pick(String(c.id))} active={current === String(c.id)} testid={`${base}-${prefix}-${c.id}`}>
      <ClientAvatar name={c.name} logo={c.logo_url} size={30} />
      <span className="truncate">{c.name}</span>
    </Row>
  )

  return (
    <>
      {field ? (
        <div>
          <span className="mb-1 flex items-center gap-1.5 text-[11px] font-semibold text-text-muted"><Building2 size={14} aria-hidden />{ar ? 'العميل' : 'Client'}</span>
          <button
            ref={button}
            type="button"
            data-testid="agency-scope-client"
            aria-haspopup="dialog"
            aria-expanded={open}
            aria-label={ar ? 'العميل' : 'Client'}
            onClick={() => { setOpen((v) => !v); setQuery('') }}
            className="flex h-11 w-full min-w-0 items-center gap-2 rounded-xl border border-border bg-surface-secondary px-2.5 text-start transition-colors hover:border-border-strong focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-600"
          >
            {client ? <ClientAvatar name={client.name} logo={client.logo_url} size={26} /> : <span className="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-full bg-surface text-text-muted"><Building2 size={13} aria-hidden /></span>}
            <span dir="auto" className={`min-w-0 flex-1 truncate text-sm font-bold ${client ? 'text-text-primary' : 'text-text-muted'}`} data-testid="agency-scope-client-current">{client?.name ?? (ar ? 'اختر عميلًا' : 'Select a client')}</span>
            <ChevronsUpDown size={14} aria-hidden className="shrink-0 text-text-muted" />
          </button>
        </div>
      ) : (
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
        {client ? <ClientAvatar name={client.name} logo={client.logo_url} size={28} /> : <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-surface-secondary text-text-secondary"><Building2 size={14} aria-hidden /></span>}
        <span className="truncate text-sm font-extrabold text-text-primary" data-testid="client-scope-current">{client?.name ?? (ar ? 'كل العملاء' : 'All clients')}</span>
        <ChevronsUpDown size={14} aria-hidden className="shrink-0 text-text-muted" />
      </button>
      )}

      {open && at && createPortal(
        <div
          ref={panel}
          role="dialog"
          aria-label={ar ? 'اختر العميل' : 'Choose a client'}
          data-testid={`${base}-panel`}
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
              placeholder={ar ? 'ابحث باسم العميل…' : 'Search clients by name…'}
              data-testid={`${base}-search`}
              className="min-w-0 flex-1 border-0 bg-transparent text-sm text-text-primary outline-none placeholder:text-text-muted"
            />
          </label>
          <div className="min-h-0 flex-1 overflow-y-auto p-1.5" data-testid={`${base}-list`}>
            {q === '' && (
              <Row onClick={() => pick(null)} active={current === null} testid={`${base}-all`}>
                <span className="grid h-[30px] w-[30px] shrink-0 place-items-center rounded-full bg-surface-secondary text-text-secondary"><Building2 size={15} aria-hidden /></span>
                {ar ? 'كل العملاء' : 'All clients'}
              </Row>
            )}
            {recentRows.length > 0 && (
              <>
                <p className="px-2.5 pb-1 pt-2.5 text-xs font-semibold text-text-muted">{ar ? 'الأخيرة' : 'Recent'}</p>
                {recentRows.map((c) => row(c, 'recent'))}
              </>
            )}
            {q === '' && <p className="px-2.5 pb-1 pt-2.5 text-xs font-semibold text-text-muted">{ar ? 'كل العملاء' : 'All clients'}</p>}
            {matches.slice(0, SHOWN).map((c) => row(c, 'client'))}
            {/* SCALE-1000-001 — a thousand clients is a search, not a scroll: the list draws the first page and says so. */}
            {matches.length > SHOWN && (
              <p className="px-3 py-3 text-center text-xs text-text-muted" data-testid={`${base}-more`}>
                {ar ? `يُعرض ${SHOWN} من ${matches.length} — اكتب للبحث` : `Showing ${SHOWN} of ${matches.length} — type to search`}
              </p>
            )}
            {matches.length === 0 && (
              <p className="px-3 py-6 text-center text-sm text-text-muted" data-testid={`${base}-empty`}>{ar ? 'لا نتائج' : 'No results'}</p>
            )}
          </div>
        </div>,
        document.body,
      )}
    </>
  )
}

function Row({ children, onClick, active, testid }: { children: React.ReactNode; onClick: () => void; active: boolean; testid: string }) {
  return (
    <button
      type="button"
      onClick={onClick}
      data-testid={testid}
      aria-current={active ? 'true' : undefined}
      className={`flex w-full min-w-0 items-center gap-2.5 rounded-xl px-2.5 py-2 text-start text-sm font-semibold text-text-primary transition-colors hover:bg-surface-hover focus-visible:bg-surface-hover focus-visible:outline-none ${active ? 'bg-brand-600/10' : ''}`}
    >
      {children}
      {active && <Check size={15} aria-hidden className="ms-auto shrink-0 text-brand-600" />}
    </button>
  )
}
