import { useState } from 'react'
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
  const base = 'group relative flex items-center rounded-xl text-sm font-semibold transition-all duration-150'
  const spacing = collapsed ? 'justify-center p-2.5' : `gap-3 px-3 py-2.5 ${nested ? 'ms-3' : ''}`
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

  return (
    <nav aria-label={label} className="flex flex-col gap-1">
      {visible.map((group) =>
        group.leaves.length === 1 ? (
          <Leaf key={group.key} leaf={group.leaves[0]} ar={ar} collapsed={collapsed} onNavigate={onNavigate} nested={false} />
        ) : (
          <Group
            key={group.key}
            group={group}
            ar={ar}
            collapsed={collapsed}
            onNavigate={onNavigate}
            pathname={pathname}
            storageKey={storageKey}
          />
        ),
      )}
    </nav>
  )
}

function Leaf({
  leaf, ar, collapsed, onNavigate, nested,
}: { leaf: NavLeaf; ar: boolean; collapsed?: boolean; onNavigate?: () => void; nested: boolean }) {
  const text = ar ? leaf.ar : leaf.en

  return (
    <NavLink
      to={leaf.to}
      end={leaf.end}
      onClick={onNavigate}
      title={collapsed ? text : undefined}
      className={({ isActive }) => leafClass(collapsed, isActive, nested)}
    >
      {({ isActive }) => (
        <>
          {isActive && <span className="absolute inset-y-2 start-0 w-[3px] rounded-full bg-brand-600" aria-hidden />}
          <leaf.icon size={nested ? 17 : 19} strokeWidth={isActive ? 2.4 : 2} aria-hidden />
          {!collapsed && <span>{text}</span>}
        </>
      )}
    </NavLink>
  )
}

function Group({
  group, ar, collapsed, onNavigate, pathname, storageKey,
}: {
  group: NavGroup; ar: boolean; collapsed?: boolean; onNavigate?: () => void
  pathname: string; storageKey?: string
}) {
  // Open unless the user has collapsed this one before. Defaulting to closed would put every section
  // behind a click and a guess, which is not simpler — it is the same list with the labels removed.
  const [open, setOpen] = useState(() => !collapsedGroups(storageKey).includes(group.key))

  // Always open when the current page lives inside it, whatever the stored preference: a deep link
  // must never land someone in a collapsed rail with no indication of where they are.
  const holdsCurrent = group.leaves.some((l) => pathname === l.to || pathname.startsWith(`${l.to}/`))
  const text = ar ? group.ar : group.en

  const toggle = () => {
    const next = !open
    setOpen(next)
    rememberCollapsed(storageKey, group.key, !next)
  }

  // Collapsed rail: no room for a disclosure, so the sections show directly as icons. Hiding them
  // behind a popover would make the collapsed rail strictly worse than the expanded one.
  if (collapsed) {
    return (
      <>
        {group.leaves.map((l) => (
          <Leaf key={l.to} leaf={l} ar={ar} collapsed onNavigate={onNavigate} nested={false} />
        ))}
      </>
    )
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
            <Leaf key={l.to} leaf={l} ar={ar} onNavigate={onNavigate} nested />
          ))}
        </div>
      )}
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
