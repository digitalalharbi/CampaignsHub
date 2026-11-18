import { useQueryClient } from '@tanstack/react-query'
import { useEffect, useId, useLayoutEffect, useRef, useState, type CSSProperties } from 'react'
import { createPortal } from 'react-dom'
import { useNavigate } from 'react-router-dom'
import { Bell, ChevronDown, KeyRound, LogOut, Palette, Shield, User as UserIcon } from 'lucide-react'
import { signOutCompletely } from '@/features/auth/signOut'
import { InstallAppItem } from './InstallAppItem'
import { usePortalPath } from '@/app/portalPath'
import { useAuth } from '@/stores/auth'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'
import type { AuthUser } from '@/lib/api/types'
import type { NavGroup, NavLeaf } from '@/layouts/SidebarNav'
import { WorkspaceHop } from './WorkspaceHop'

/**
 * PERSONAL settings, plus — when a shell passes it — the workspace's «الإعدادات» group (NAV-HOME-PLANS-001: the
 * integrations, team, subscription and settings pages left the rail for this menu). Each entry is one distinct route.
 *
 * Suffixes, not absolute paths (LOGIN-002). They used to be hard-coded to `/app/account/…`, so an
 * agency operator opening their own profile was sent out of the agency portal into the advertiser
 * one — and once `/app` was guarded, was refused outright. `usePortalPath` resolves each against the
 * portal the menu is actually rendered in.
 */
const OPTIONS = [
  { key: 'menu_profile', to: '/account/profile', icon: UserIcon },
  { key: 'menu_password', to: '/account/password', icon: KeyRound },
  { key: 'menu_security', to: '/account/security', icon: Shield },
  { key: 'menu_preferences', to: '/account/preferences', icon: Palette },
  { key: 'menu_notifications', to: '/account/notifications', icon: Bell },
] as const

function initialsOf(user: AuthUser | null): string {
  if (user?.initials) return user.initials
  const src = (user?.name || user?.email || '?').trim()
  const parts = src.split(/\s+/)
  return (parts.length >= 2 ? parts[0][0] + parts[parts.length - 1][0] : src.slice(0, 2)).toUpperCase()
}

function StatusBadge({ status }: { status?: AuthUser['status'] }) {
  const t = useT()
  const map = {
    active: 'bg-success/15 text-success',
    unverified: 'bg-warning/15 text-warning',
    suspended: 'bg-danger/15 text-danger',
  } as const
  const s = status ?? 'active'
  return <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${map[s]}`}>{t(`account_status_${s}`)}</span>
}

/** The shared menu body — identical whether opened from the topbar avatar or the sidebar card. */
function MenuBody({ onNavigate, onClose, workspace }: { onNavigate: (to: string, absolute?: boolean) => void; onClose: () => void; workspace?: NavLeaf[] }) {
  const t = useT()
  const ar = useUi((s) => s.locale) === 'ar'
  const { user } = useAuth()
  const queryClient = useQueryClient()

  /*
   * ACCESS-EXIT-001 — sign out means the BROWSER forgets, not just the server.
   *
   * This cleared the server session and the auth store and left everything else: the memoised query
   * cache, the persisted project and client selection, and every `chub:draft:*` form draft. Signing
   * in as somebody else then inherited the previous person's project and half-filled forms — which is
   * confusing on your own machine and a small disclosure on a shared one.
   */
  const handleLogout = async () => {
    await signOutCompletely(queryClient)
  }

  return (
    <div className="w-[300px] max-w-[86vw] overflow-hidden rounded-2xl border border-border bg-surface-elevated shadow-[var(--shadow-large)]">
      {/* Header — avatar, name, FULL email, role, workspace, status. */}
      <div className="flex items-start gap-3 border-b border-border bg-surface-secondary p-4">
        <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-100 text-sm font-bold text-brand-700">
          {user?.avatar_url ? <img src={user.avatar_url} alt="" className="h-full w-full rounded-xl object-cover" /> : initialsOf(user)}
        </div>
        <div className="min-w-0 flex-1">
          <div className="truncate text-sm font-bold text-text-primary">{user?.name}</div>
          <div className="break-all text-xs text-text-secondary" title={user?.email}>{user?.email}</div>
          <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
            {user?.role && <span className="rounded-full bg-surface px-2 py-0.5 text-[11px] font-semibold text-text-secondary">{user.role}</span>}
            <StatusBadge status={user?.status} />
          </div>
          {user?.workspace_name && <div className="mt-1 truncate text-[11px] text-text-muted">{user.workspace_name}</div>}
        </div>
      </div>

      {/* CONS-ADMIN-SWITCH — admin panel ⇄ own workspaces, for an account that holds both. Renders nothing otherwise. */}
      <WorkspaceHop onNavigate={onNavigate} />

      {workspace !== undefined && workspace.length > 0 && (
        <div className="border-b border-border p-1.5" role="menu" aria-label={ar ? 'الإعدادات' : 'Settings'} data-testid="account-menu-workspace">
          {workspace.map(({ to, ar: label, en, icon: Icon }) => (
            <button
              key={to}
              role="menuitem"
              onClick={() => onNavigate(to, true)}
              className="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-start text-sm font-semibold text-text-secondary transition-colors hover:bg-surface-hover hover:text-text-primary"
            >
              <Icon size={16} className="shrink-0 text-text-muted" />
              {ar ? label : en}
            </button>
          ))}
        </div>
      )}

      {/* Options. */}
      <div className="p-1.5" role="menu">
        {OPTIONS.map(({ key, to, icon: Icon }) => (
          <button
            key={key}
            role="menuitem"
            onClick={() => onNavigate(to)}
            className="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-start text-sm text-text-secondary transition-colors hover:bg-surface-hover hover:text-text-primary"
          >
            <Icon size={16} className="shrink-0 text-text-muted" />
            {t(key)}
          </button>
        ))}
      </div>

      {/* NOTIF-PWA-001 — only where the browser can install it; empty otherwise. */}
      <div className="border-t border-border p-1.5 empty:hidden" role="menu">
        <InstallAppItem onDone={onClose} />
      </div>

      {/* Logout — last, calm danger, clearly separated. */}
      <div className="border-t border-border p-1.5">
        <button
          role="menuitem"
          onClick={handleLogout}
          className="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-start text-sm font-semibold text-danger transition-colors hover:bg-[var(--negative-background)]"
        >
          <LogOut size={16} className="shrink-0" />
          {t('sign_out')}
        </button>
      </div>
    </div>
  )
}

/**
 * Unified account menu. Opens the SAME body from either the topbar avatar (`variant="topbar"`) or the
 * sidebar user card (`variant="sidebar"`). Closes on outside-click, Escape, or navigation.
 */
export function AccountMenu({ variant, collapsed, workspace }: {
  variant: 'topbar' | 'sidebar'
  collapsed?: boolean
  /** The portal's «الإعدادات» group and the same permission filter its rail applies. */
  workspace?: { group: NavGroup; allow?: (leaf: NavLeaf) => boolean }
}) {
  const t = useT()
  const { user } = useAuth()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)
  const buttonRef = useRef<HTMLButtonElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)
  const menuId = useId()
  const [pos, setPos] = useState<CSSProperties | null>(null)

  /*
   * MENU-RAIL-CLIP-001 — the sidebar's menu is drawn on the page, not inside the rail. Placed absolutely inside a
   * collapsed rail it was cut to the rail's width (only the icons and the first letter of each entry showed). It now
   * opens BESIDE a collapsed rail and ABOVE the expanded card, held inside the screen, and scrolls inside itself when the
   * screen is shorter than the menu (a phone held sideways, a small laptop) — the same in every browser.
   */
  useLayoutEffect(() => {
    if (!open || variant !== 'sidebar') { setPos(null); return }
    const place = () => {
      const b = buttonRef.current?.getBoundingClientRect()
      if (!b) return
      const vw = window.innerWidth
      const vh = window.innerHeight
      const rtl = (document.documentElement.getAttribute('dir') ?? document.dir) === 'rtl'
      const gap = 8
      const width = Math.min(300, vw - 2 * gap)
      const beside = collapsed && vw >= 640
      let left = beside ? (rtl ? b.left - gap - width : b.right + gap) : (rtl ? b.right - width : b.left)
      left = Math.max(gap, Math.min(left, vw - width - gap))
      const bottom = Math.max(gap, beside ? vh - b.bottom : vh - b.top + gap)
      setPos({ position: 'fixed', left, bottom, width, maxHeight: Math.max(160, vh - bottom - gap), zIndex: 90 })
    }
    place()
    window.addEventListener('resize', place)
    window.addEventListener('scroll', place, true)
    return () => { window.removeEventListener('resize', place); window.removeEventListener('scroll', place, true) }
  }, [open, variant, collapsed])

  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => {
      const target = e.target as Node
      if (rootRef.current?.contains(target) || menuRef.current?.contains(target)) return
      setOpen(false)
    }
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false) }
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    return () => { document.removeEventListener('mousedown', onDown); document.removeEventListener('keydown', onKey) }
  }, [open])

  // Resolved against the CURRENT portal, so the account menu never walks the user out of it.
  const portalPath = usePortalPath()
  const go = (to: string, absolute?: boolean) => { setOpen(false); navigate(absolute ? to : portalPath(to)) }
  const workspaceLeaves = workspace?.group.leaves.filter((l) => (workspace.allow ? workspace.allow(l) : true))

  return (
    <div ref={rootRef} className="relative">
      {variant === 'topbar' ? (
        <button
          onClick={() => setOpen((o) => !o)}
          aria-haspopup="menu" aria-expanded={open} aria-controls={menuId} aria-label={t('open_user_menu')}
          className="flex h-9 w-9 items-center justify-center rounded-full bg-brand-100 text-xs font-bold text-brand-700 ring-2 ring-transparent transition hover:ring-brand-200"
        >
          {user?.avatar_url ? <img src={user.avatar_url} alt="" className="h-full w-full rounded-full object-cover" /> : initialsOf(user)}
        </button>
      ) : (
        <button
          ref={buttonRef}
          onClick={() => setOpen((o) => !o)}
          aria-haspopup="menu" aria-expanded={open} aria-controls={menuId} aria-label={t('open_user_menu')}
          className={`mt-3 flex w-full items-center gap-2.5 rounded-xl border border-border bg-surface-secondary p-2 text-start transition-colors hover:border-border-strong ${collapsed ? 'justify-center' : ''}`}
        >
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-100 text-sm font-bold text-brand-700">
            {user?.avatar_url ? <img src={user.avatar_url} alt="" className="h-full w-full rounded-lg object-cover" /> : initialsOf(user)}
          </span>
          {!collapsed && (
            <>
              <span className="min-w-0 flex-1 leading-tight">
                <span className="block truncate text-sm font-semibold text-text-primary">{user?.name}</span>
                <span className="block truncate text-xs text-text-muted" title={user?.email}>{user?.email}</span>
              </span>
              <ChevronDown size={16} className={`shrink-0 text-text-muted transition-transform ${open ? 'rotate-180' : ''}`} />
            </>
          )}
        </button>
      )}

      {open && variant === 'topbar' && (
        <div id={menuId} ref={menuRef} className="absolute end-0 top-11 z-50 max-h-[calc(100dvh-4rem)] overflow-y-auto overscroll-contain rounded-2xl">
          <MenuBody onNavigate={go} onClose={() => setOpen(false)} workspace={workspaceLeaves} />
        </div>
      )}
      {open && variant === 'sidebar' && createPortal(
        <div id={menuId} ref={menuRef} data-testid="account-menu-floating" style={pos ?? { position: 'fixed', visibility: 'hidden' }} className="overflow-y-auto overscroll-contain rounded-2xl [&>div]:w-full [&>div]:max-w-none">
          <MenuBody onNavigate={go} onClose={() => setOpen(false)} workspace={workspaceLeaves} />
        </div>,
        document.body,
      )}
    </div>
  )
}
