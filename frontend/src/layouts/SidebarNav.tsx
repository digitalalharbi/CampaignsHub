import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import { ChevronDown } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'

/**
 * The grouped sidebar shared by the portals that have too many sections for a flat list.
 *
 * Exactly TWO levels: a group, and the sections inside it. Nothing nests further, because a third
 * level is where a rail stops being scannable and starts being a filing system.
 *
 * The rendering is shared; the CONTENT is not. Each portal passes its own groups, and the portals
 * differ in what they contain rather than in how they draw it — an agency has clients and team
 * scopes, an advertiser has integrations and subscriptions, and neither shows the other's.
 *
 * Grouping never HIDES a section, and that is why every group is OPEN by default. Collapsing them
 * would trade one problem for a worse one: a long list becomes a short list of labels, and reaching
 * any section costs a click plus the guess about which label holds it. The labels are the value here
 * — they say what the sections have in common — not the collapsing.
 *
 * A user may still collapse a group they do not use, and that choice is remembered per portal. The
 * group holding the current page is always open regardless, so a deep link never lands someone
 * inside a collapsed rail with no idea where they are.
 *
 * `navLeaves()` exists so a test can assert that flattening the groups returns exactly the sections
 * the portal had before.
 */

export interface NavLeaf {
  to: string
  ar: string
  en: string
  icon: LucideIcon
  /** The account-entitlement key. A leaf shows only when the workspace is entitled to it. */
  ent?: string
  /**
   * The PROJECT capability this destination needs — TEAM-PROJECT-RBAC-001.
   *
   * A leaf naming one is not offered to somebody who does not hold it in the current project. This is
   * not the enforcement: the route states the same capability and the server refuses without it. It
   * is what stops the rail offering a door that answers 403, which reads as a broken product rather
   * than as a boundary.
   */
  cap?: string
  end?: boolean
}

export interface NavGroup {
  key: string
  ar: string
  en: string
  icon: LucideIcon
  /** A group of one renders as a plain link — a disclosure triangle over a single item is noise. */
  leaves: NavLeaf[]
}

/** Every section across every group, in order. Used by tests to prove nothing was dropped. */
export function navLeaves(groups: readonly NavGroup[]): NavLeaf[] {
  return groups.flatMap((g) => g.leaves)
}

function leafClass(collapsed: boolean | undefined, isActive: boolean, nested: boolean): string {
  const base = 'group relative flex items-center rounded-xl text-sm font-semibold transition'
  const spacing = collapsed ? 'h-11 w-11 justify-center' : `gap-3 px-3 py-2.5 ${nested ? 'ms-3' : ''}`
  const tone = isActive
    ? 'bg-[var(--brand-background)] text-brand-600'
    : 'text-text-secondary hover:bg-surface-hover hover:text-text-primary'

  return `${base} ${spacing} ${tone}`
}

export function SidebarNav({
  groups,
  ar,
  collapsed,
  onNavigate,
  allow,
  label,
  storageKey,
}: {
  groups: readonly NavGroup[]
  ar: boolean
  collapsed?: boolean
  onNavigate?: () => void
  /** Entitlement filter. Applied to leaves; a group whose leaves are all filtered out disappears. */
  allow?: (leaf: NavLeaf) => boolean
  label: string
  /** Where this portal remembers which groups the user chose to collapse. */
  storageKey?: string
}) {
  const { pathname } = useLocation()
  const permitted = (leaf: NavLeaf) => (allow ? allow(leaf) : true)

  const visible = groups
    .map((g) => ({ ...g, leaves: g.leaves.filter(permitted) }))
    .filter((g) => g.leaves.length > 0)
  const allLeaves = visible.flatMap((g) => g.leaves)

  return (
    <nav aria-label={label} className="flex flex-col gap-1">
      {visible.map((group) =>
        group.leaves.length === 1 ? (
          <Leaf key={group.key} leaf={group.leaves[0]} ar={ar} collapsed={collapsed} onNavigate={onNavigate} nested={false} all={allLeaves} />
        ) : (
          <Group
            key={group.key}
            group={group}
            ar={ar}
            collapsed={collapsed}
            onNavigate={onNavigate}
            pathname={pathname}
            storageKey={storageKey}
            all={allLeaves}
          />
        ),
      )}
    </nav>
  )
}

/**
 * NAV-TAXONOMY-001 — which leaf is the current page, when two leaves share a path.
 *
 * «المجموعات» and «الإعلانات» are the Analytics page opened on a tab (`/analytics?tab=ad_sets`), and «حملة جديدة»
 * is the Campaigns page with its builder open. Router matching reads the path only, so all three would light up
 * together with their parent. A leaf with a query is current when the address carries those parameters; a plain leaf
 * on the same path steps aside for it, so exactly one leaf is current.
 */
export function leafActive(leaf: NavLeaf, all: readonly NavLeaf[], pathname: string, search: string): boolean {
  const [path, query] = leaf.to.split('?')
  const onPath = leaf.end ? pathname === path : pathname === path || pathname.startsWith(`${path}/`)
  if (!onPath) return false
  const params = new URLSearchParams(search)
  if (query) {
    for (const [k, v] of new URLSearchParams(query)) {
      if (params.get(k) !== v) return false
    }

    return true
  }

  return !all.some((o) => o !== leaf && o.to.includes('?') && leafActive(o, [], pathname, search))
}

function Leaf({
  leaf, ar, collapsed, onNavigate, nested, all,
}: { leaf: NavLeaf; ar: boolean; collapsed?: boolean; onNavigate?: () => void; nested: boolean; all: readonly NavLeaf[] }) {
  const text = ar ? leaf.ar : leaf.en
  const location = useLocation()
  const isActive = leafActive(leaf, all, location.pathname, location.search)
  const pop = useRailPop()

  return (
    <div ref={pop.wrapRef} className={collapsed ? 'flex justify-center' : undefined} {...(collapsed ? pop.hover : {})}>
      <NavLink
        ref={pop.triggerRef}
        to={leaf.to}
        end={leaf.end}
        onClick={onNavigate}
        // The name is the tooltip on the 64px strip — shown by the layer below, read by assistive tech from here.
        aria-label={collapsed ? text : undefined}
        // NavLink sets aria-current from its own path match; on a shared path only the leaf chosen above is the page.
        aria-current={isActive ? 'page' : 'false'}
        className={() => leafClass(collapsed, isActive, nested)}
        {...(collapsed ? pop.focus : {})}
      >
        {() => (
          <>
            {isActive && <span className="absolute inset-y-2 start-0 w-[3px] rounded-full bg-brand-600" aria-hidden />}
            <leaf.icon size={nested ? 17 : 19} strokeWidth={isActive ? 2.4 : 2} aria-hidden />
            {!collapsed && <span>{text}</span>}
          </>
        )}
      </NavLink>
      {collapsed && (
        <div
          ref={pop.panelRef}
          role="tooltip"
          style={pop.style}
          className={`${pop.open ? 'block' : 'hidden'} pointer-events-none fixed z-[70] whitespace-nowrap rounded-lg border border-border bg-surface-elevated px-3 py-1.5 text-sm font-semibold text-text-primary shadow-[var(--shadow-medium)]`}
        >
          {text}
        </div>
      )}
    </div>
  )
}

function Group({
  group, ar, collapsed, onNavigate, pathname, storageKey, all,
}: {
  group: NavGroup; ar: boolean; collapsed?: boolean; onNavigate?: () => void
  pathname: string; storageKey?: string; all: readonly NavLeaf[]
}) {
  // Open unless the user has collapsed this one before. Defaulting to closed would put every section
  // behind a click and a guess, which is not simpler — it is the same list with the labels removed.
  const [open, setOpen] = useState(() => !collapsedGroups(storageKey).includes(group.key))

  // Always open when the current page lives inside it, whatever the stored preference: a deep link
  // must never land someone in a collapsed rail with no indication of where they are.
  const holdsCurrent = group.leaves.some((l) => { const p = l.to.split('?')[0]; return pathname === p || pathname.startsWith(`${p}/`) })
  const text = ar ? group.ar : group.en

  const toggle = () => {
    const next = !open
    setOpen(next)
    rememberCollapsed(storageKey, group.key, !next)
  }

  // The 64px strip has no room for a disclosure, and drawing every section as its own icon made it twenty tall.
  // A group is ONE icon; its sections open beside it as a flyout (NOV-SIDEBAR-001).
  if (collapsed) {
    return <RailGroup group={group} ar={ar} all={all} holdsCurrent={holdsCurrent} onNavigate={onNavigate} />
  }

  return (
    <div>
      <button
        type="button"
        aria-expanded={open || holdsCurrent}
        data-testid={`nav-group-${group.key}`}
        onClick={toggle}
        className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition-colors ${
          holdsCurrent ? 'text-text-primary' : 'text-text-secondary hover:bg-surface-hover hover:text-text-primary'
        }`}
      >
        <group.icon size={19} strokeWidth={holdsCurrent ? 2.4 : 2} aria-hidden />
        <span className="flex-1 text-start">{text}</span>
        <ChevronDown size={15} className={`shrink-0 transition-transform ${open || holdsCurrent ? 'rotate-180' : ''}`} aria-hidden />
      </button>

      {(open || holdsCurrent) && (
        <div className="mt-0.5 flex flex-col gap-0.5">
          {group.leaves.map((l) => (
            <Leaf key={l.to} leaf={l} ar={ar} onNavigate={onNavigate} nested all={all} />
          ))}
        </div>
      )}
    </div>
  )
}

/**
 * NOV-SIDEBAR-001 — the position and the open/close rules shared by the strip's tooltip and its flyout.
 *
 * The layer is `position: fixed`, measured from its trigger when it opens: the strip scrolls on its own
 * (`overflow-y: auto`), and an absolutely positioned child of a scroller is clipped by it. Fixed is not.
 * Opening follows the mouse only for a MOUSE — a finger has no hover, so a touch opens on tap and the
 * second tap on the icon closes it. Escape and a press outside close it, and so does arriving on another page.
 */
function useRailPop() {
  const [open, setOpen] = useState(false)
  const [pos, setPos] = useState<{ top: number; inline: number; rtl: boolean } | null>(null)
  const wrapRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLAnchorElement & HTMLButtonElement>(null)
  const panelRef = useRef<HTMLDivElement>(null)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  /** Escape hands focus back to the trigger, which must not read as «focused, so open» and undo the close. */
  const skipFocus = useRef(false)
  /** A mouse already opened it on hover, so its click must not close it again; a finger or a key toggles. */
  const pointer = useRef<string>('mouse')
  const { pathname, search } = useLocation()

  const cancel = () => { if (timer.current) { clearTimeout(timer.current); timer.current = null } }

  useEffect(() => { cancel(); setOpen(false) }, [pathname, search])
  useEffect(() => cancel, [])

  useLayoutEffect(() => {
    const trigger = triggerRef.current
    if (!open || !trigger) return
    const rect = trigger.getBoundingClientRect()
    const rtl = getComputedStyle(trigger).direction === 'rtl'
    const height = panelRef.current?.offsetHeight ?? 0
    setPos({
      top: Math.max(8, Math.min(rect.top, window.innerHeight - height - 8)),
      inline: rtl ? document.documentElement.clientWidth - rect.left + 6 : rect.right + 6,
      rtl,
    })
  }, [open])

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') { setOpen(false); skipFocus.current = true; triggerRef.current?.focus() } }
    const onPress = (e: PointerEvent) => { if (!wrapRef.current?.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('keydown', onKey)
    document.addEventListener('pointerdown', onPress)

    return () => { document.removeEventListener('keydown', onKey); document.removeEventListener('pointerdown', onPress) }
  }, [open])

  const press = (e: React.PointerEvent) => { pointer.current = e.pointerType }
  const toggleFromClick = () => setOpen(pointer.current === 'mouse' ? true : !open)
  const hover = {
    onPointerDown: press,
    onPointerEnter: (e: React.PointerEvent) => { if (e.pointerType === 'mouse') { cancel(); setOpen(true) } },
    onPointerLeave: (e: React.PointerEvent) => { if (e.pointerType === 'mouse') { cancel(); timer.current = setTimeout(() => setOpen(false), 140) } },
  }
  const focus = {
    onFocus: () => { cancel(); if (skipFocus.current) { skipFocus.current = false; return } setOpen(true) },
    onBlur: (e: React.FocusEvent) => { if (!wrapRef.current?.contains(e.relatedTarget as Node | null)) setOpen(false) },
  }
  const style: React.CSSProperties = pos
    ? { top: pos.top, ...(pos.rtl ? { right: pos.inline } : { left: pos.inline }) }
    : { top: 0, visibility: 'hidden' }

  return { open, setOpen, toggleFromClick, wrapRef, triggerRef, panelRef, hover, focus, style }
}

/** One icon for a whole group on the strip; its sections open beside it. Every link stays in the document. */
function RailGroup({
  group, ar, all, holdsCurrent, onNavigate,
}: { group: NavGroup; ar: boolean; all: readonly NavLeaf[]; holdsCurrent: boolean; onNavigate?: () => void }) {
  const pop = useRailPop()
  const panelId = useId()
  const text = ar ? group.ar : group.en
  const { pathname, search } = useLocation()

  return (
    <div ref={pop.wrapRef} className="flex justify-center" {...pop.hover}>
      <button
        ref={pop.triggerRef}
        type="button"
        data-testid={`nav-group-${group.key}`}
        aria-label={text}
        aria-haspopup="true"
        aria-expanded={pop.open}
        aria-controls={panelId}
        onClick={pop.toggleFromClick}
        className={`group relative flex h-11 w-11 items-center justify-center rounded-xl transition ${
          holdsCurrent ? 'bg-[var(--brand-background)] text-brand-600' : 'text-text-secondary hover:bg-surface-hover hover:text-text-primary'
        }`}
        {...pop.focus}
      >
        {holdsCurrent && <span className="absolute inset-y-2 start-0 w-[3px] rounded-full bg-brand-600" aria-hidden />}
        <group.icon size={20} strokeWidth={holdsCurrent ? 2.4 : 2} aria-hidden />
      </button>
      <div
        id={panelId}
        ref={pop.panelRef}
        role="group"
        aria-label={text}
        style={pop.style}
        className={`${pop.open ? 'flex' : 'hidden'} fixed z-[70] min-w-[200px] flex-col gap-0.5 rounded-xl border border-border bg-surface-elevated p-1.5 shadow-[var(--shadow-large)]`}
      >
        <p className="px-2.5 pb-1 pt-1 text-xs font-bold text-text-muted">{text}</p>
        {group.leaves.map((l) => {
          const active = leafActive(l, all, pathname, search)

          return (
            <NavLink
              key={l.to}
              to={l.to}
              end={l.end}
              onClick={onNavigate}
              aria-current={active ? 'page' : 'false'}
              className={() => `flex h-11 items-center gap-2.5 rounded-lg px-2.5 text-sm font-semibold transition-colors ${
                active ? 'bg-[var(--brand-background)] text-brand-600' : 'text-text-secondary hover:bg-surface-hover hover:text-text-primary'
              }`}
            >
              {() => (<><l.icon size={17} strokeWidth={active ? 2.4 : 2} aria-hidden /><span>{ar ? l.ar : l.en}</span></>)}
            </NavLink>
          )
        })}
      </div>
    </div>
  )
}

/** @return list<string> group keys the user has collapsed in this portal. */
function collapsedGroups(storageKey?: string): string[] {
  if (storageKey === undefined || typeof window === 'undefined') return []

  try {
    const raw = window.localStorage.getItem(storageKey)

    return raw === null ? [] : (JSON.parse(raw) as string[])
  } catch {
    // A corrupt preference is not worth failing navigation over — everything open is the safe answer.
    return []
  }
}

function rememberCollapsed(storageKey: string | undefined, key: string, isCollapsed: boolean): void {
  if (storageKey === undefined || typeof window === 'undefined') return

  const next = new Set(collapsedGroups(storageKey))
  isCollapsed ? next.add(key) : next.delete(key)

  try {
    window.localStorage.setItem(storageKey, JSON.stringify([...next]))
  } catch {
    // Storage unavailable (private mode, quota). The rail still works; the choice just is not kept.
  }
}
