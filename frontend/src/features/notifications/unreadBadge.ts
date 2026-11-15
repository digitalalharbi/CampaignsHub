import { useEffect } from 'react'

/**
 * NOTIF-V2-INTERNAL-001 — the unread number lives in three places (spec §5): the bell icon (the centre draws it), the TAB TITLE
 * and the INSTALLED APP'S ICON (the Badging API). Where a browser has no Badging API (Firefox, Safari without an installed app)
 * the tab title carries it, so the number is never missing everywhere at once.
 */
const PREFIX = /^\((?:\d+|99\+)\)\s+/

export function titleWithCount(title: string, unread: number): string {
  const base = title.replace(PREFIX, '')

  return unread > 0 ? `(${unread > 99 ? '99+' : unread}) ${base}` : base
}

type Badging = { setAppBadge?: (n?: number) => Promise<void>; clearAppBadge?: () => Promise<void> }

/** Returns what was done so a test can see it: the title it set and whether the icon badge API was used. */
export function applyUnread(unread: number, doc: Document = document, nav: Navigator = navigator): { title: string; badgeApi: boolean } {
  const title = titleWithCount(doc.title, unread)
  if (doc.title !== title) doc.title = title

  const b = nav as Navigator & Badging
  let badgeApi = false
  try {
    if (unread > 0 && typeof b.setAppBadge === 'function') {
      void b.setAppBadge(unread).catch(() => undefined)
      badgeApi = true
    } else if (unread <= 0 && typeof b.clearAppBadge === 'function') {
      void b.clearAppBadge().catch(() => undefined)
      badgeApi = true
    }
  } catch {
    // A browser that refuses the badge (not installed, no permission) keeps the title.
  }

  return { title, badgeApi }
}

export function useUnreadBadge(unread: number): void {
  useEffect(() => {
    applyUnread(unread)
    // Pages set their own title on navigation; put the count back on whatever they set.
    const el = document.querySelector('title')
    if (!el || typeof MutationObserver === 'undefined') return undefined
    const observer = new MutationObserver(() => {
      const next = titleWithCount(document.title, unread)
      if (document.title !== next) document.title = next
    })
    observer.observe(el, { childList: true, characterData: true, subtree: true })

    return () => observer.disconnect()
  }, [unread])
}
