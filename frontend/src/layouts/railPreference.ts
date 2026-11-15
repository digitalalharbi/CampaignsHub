import { create } from 'zustand'
import { useAuth } from '@/stores/auth'

/**
 * NOV-SIDEBAR-001 — the side rail is a 64px icon strip until the person opens it, and the choice is theirs.
 *
 * ## Per person, not per browser
 *
 * The old preference was one key for the whole browser, so two people sharing a workstation (an agency's
 * front desk, a shared laptop in a meeting room) inherited each other's rail. The key now carries the user id,
 * and a person who signs in on a browser where somebody else opened the rail still starts collapsed.
 *
 * ## Where it is stored
 *
 * There is no server-side preferences mechanism to extend (the account «اللغة والمظهر» page keeps language and
 * theme in this same browser storage), so this stays in `localStorage` — wrapped, because private windows, blocked
 * site data and thumbnail capture all make the accessor throw, and a rail that cannot remember is still a rail.
 * Nothing depends on the value being readable: the default is the collapsed strip.
 */
const PREFIX = 'campaign-hub-rail:'
const ANONYMOUS = 'anonymous'

export function railStorageKey(userId: string | null | undefined): string {
  return `${PREFIX}${userId ?? ANONYMOUS}`
}

/** `true` unless this person opened it before — the strip is the default, never the expanded rail. */
export function readRailCollapsed(userId: string | null | undefined): boolean {
  try {
    return window.localStorage.getItem(railStorageKey(userId)) !== 'open'
  } catch {
    return true
  }
}

function writeRailCollapsed(userId: string | null | undefined, collapsed: boolean): void {
  try {
    window.localStorage.setItem(railStorageKey(userId), collapsed ? 'closed' : 'open')
  } catch {
    /* the choice simply will not survive this session */
  }
}

interface RailState {
  userId: string | null
  collapsed: boolean
  /** Adopt a person's saved choice — called when the signed-in user is known or changes. */
  hydrate: (userId: string | null | undefined) => void
  toggle: () => void
}

const initialUser = useAuth.getState().user?.id ?? null

export const useRail = create<RailState>((set, get) => ({
  userId: initialUser,
  collapsed: readRailCollapsed(initialUser),
  hydrate: (userId) => {
    const id = userId ?? null
    set({ userId: id, collapsed: readRailCollapsed(id) })
  },
  toggle: () => {
    const { userId, collapsed } = get()
    writeRailCollapsed(userId, !collapsed)
    set({ collapsed: !collapsed })
  },
}))
