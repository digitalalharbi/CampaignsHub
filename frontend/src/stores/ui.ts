import { create } from 'zustand'

export type Theme = 'light' | 'dark'
export type Locale = 'ar' | 'en'

interface UiState {
  theme: Theme
  locale: Locale
  sidebarOpen: boolean
  sidebarCollapsed: boolean
  toggleTheme: () => void
  toggleLocale: () => void
  setSidebarOpen: (open: boolean) => void
  toggleSidebarCollapsed: () => void
}

/** Applies theme + direction to <html> so tokens and RTL/LTR take effect globally. */
export function applyDocument(theme: Theme, locale: Locale): void {
  const root = document.documentElement
  root.setAttribute('data-theme', theme)
  root.setAttribute('dir', locale === 'ar' ? 'rtl' : 'ltr')
  root.setAttribute('lang', locale)
}

const COLLAPSE_KEY = 'campaign-hub-sidebar-collapsed'

/*
 * Language and theme are REMEMBERED (APP-100).
 *
 * They were not, while the sidebar's collapsed state was — so choosing English or dark mode lasted
 * until the next full page load and then silently reverted. Every bookmark, refresh, new tab and
 * hard navigation put the customer back into Arabic and light, and nothing on screen explained why.
 *
 * Found by walking the portal in English rather than by reading this file: the choice survives while
 * clicking around inside the SPA, which is the path a manual check takes, and only broke on the full
 * navigations an automated walk performs.
 */
const THEME_KEY = 'campaign-hub-theme'
const LOCALE_KEY = 'campaign-hub-locale'

/** A remembered choice, falling back to the product default when absent or unusable. */
function remembered<T extends string>(key: string, allowed: readonly T[], fallback: T): T {
  try {
    const value = localStorage.getItem(key)

    return allowed.includes(value as T) ? (value as T) : fallback
  } catch {
    // Private browsing, a disabled store, a quota error — never a reason to fail to render.
    return fallback
  }
}

function remember(key: string, value: string): void {
  try {
    localStorage.setItem(key, value)
  } catch {
    /* ignore — the preference simply will not survive this session */
  }
}

const initialCollapsed = (() => {
  try {
    return localStorage.getItem(COLLAPSE_KEY) === '1'
  } catch {
    return false
  }
})()

// Arabic and light are the product defaults, and stay the answer for a first-time visitor.
const initialTheme = remembered<Theme>(THEME_KEY, ['light', 'dark'], 'light')
const initialLocale = remembered<Locale>(LOCALE_KEY, ['ar', 'en'], 'ar')

export const useUi = create<UiState>((set, get) => ({
  theme: initialTheme,
  locale: initialLocale,
  sidebarOpen: false,
  sidebarCollapsed: initialCollapsed,
  toggleTheme: () => {
    const theme = get().theme === 'light' ? 'dark' : 'light'
    applyDocument(theme, get().locale)
    remember(THEME_KEY, theme)
    set({ theme })
  },
  toggleLocale: () => {
    const locale = get().locale === 'ar' ? 'en' : 'ar'
    applyDocument(get().theme, locale)
    remember(LOCALE_KEY, locale)
    set({ locale })
  },
  setSidebarOpen: (sidebarOpen) => set({ sidebarOpen }),
  toggleSidebarCollapsed: () => {
    const sidebarCollapsed = !get().sidebarCollapsed
    remember(COLLAPSE_KEY, sidebarCollapsed ? '1' : '0')
    set({ sidebarCollapsed })
  },
}))

/*
 * Applied at module load, before React paints.
 *
 * Without it the remembered choice would sit in the store while `<html>` still carried the default —
 * the page would render with Arabic direction and English text until something happened to call
 * `applyDocument` again.
 */
applyDocument(initialTheme, initialLocale)
