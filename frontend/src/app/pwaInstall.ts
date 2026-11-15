import { useSyncExternalStore } from 'react'

/**
 * NOTIF-PWA-001 — «ثبّت التطبيق».
 *
 * Android / desktop Chrome and Edge fire `beforeinstallprompt` ONCE, early, and only if nobody is listening later — so the
 * listener is installed from `main.tsx`, before React mounts, and the event is kept until the person presses the button.
 * iPhone and iPad Safari never fire it: installing there is «مشاركة ← إضافة إلى الشاشة الرئيسية», so the button shows the steps
 * instead. An app that is already installed (opened standalone) offers nothing.
 */
export type InstallState = 'installed' | 'prompt' | 'ios' | 'unavailable'

interface DeferredPrompt extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

let deferred: DeferredPrompt | null = null
let installedNow = false
let wired = false
const listeners = new Set<() => void>()
const emit = () => listeners.forEach((l) => l())

export function isIos(nav: Pick<Navigator, 'userAgent' | 'maxTouchPoints'> = navigator): boolean {
  // iPadOS 13+ reports itself as a Mac with a touch screen.
  return /iPad|iPhone|iPod/.test(nav.userAgent) || (/Macintosh/.test(nav.userAgent) && nav.maxTouchPoints > 1)
}

export function isStandalone(): boolean {
  if (typeof window === 'undefined') return false

  return window.matchMedia?.('(display-mode: standalone)').matches === true || (navigator as { standalone?: boolean }).standalone === true
}

/** Called once from `main.tsx`. Safe to call again and outside a browser. */
export function captureInstallPrompt(): void {
  if (wired || typeof window === 'undefined') return
  wired = true
  window.addEventListener('beforeinstallprompt', (e) => {
    // Hold it: the browser's own mini-bar is replaced by the button in the user menu.
    e.preventDefault()
    deferred = e as DeferredPrompt
    emit()
  })
  window.addEventListener('appinstalled', () => {
    installedNow = true
    deferred = null
    emit()
  })
}

export function installState(): InstallState {
  if (installedNow || isStandalone()) return 'installed'
  if (deferred) return 'prompt'
  if (typeof navigator !== 'undefined' && isIos()) return 'ios'

  return 'unavailable'
}

/** Ask the browser to install. Resolves true when the person accepted. */
export async function promptInstall(): Promise<boolean> {
  if (!deferred) return false
  const event = deferred
  deferred = null
  emit()
  try {
    await event.prompt()

    return (await event.userChoice).outcome === 'accepted'
  } catch {
    return false
  }
}

export function useInstallState(): InstallState {
  return useSyncExternalStore(
    (cb) => { listeners.add(cb); return () => { listeners.delete(cb) } },
    installState,
    () => 'unavailable' as const,
  )
}

/** Test seam: forget everything the module has seen. */
export function resetInstallStateForTests(): void {
  deferred = null
  installedNow = false
  wired = false
  listeners.clear()
}
