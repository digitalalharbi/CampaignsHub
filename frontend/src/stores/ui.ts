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
const initialCollapsed = (() => {
  try {
    return localStorage.getItem(COLLAPSE_KEY) === '1'
  } catch {
    return false
  }
})()

export const useUi = create<UiState>((set, get) => ({
  theme: 'light',
  locale: 'ar',
  sidebarOpen: false,
  sidebarCollapsed: initialCollapsed,
  toggleTheme: () => {
    const theme = get().theme === 'light' ? 'dark' : 'light'
    applyDocument(theme, get().locale)
    set({ theme })
  },
  toggleLocale: () => {
    const locale = get().locale === 'ar' ? 'en' : 'ar'
    applyDocument(get().theme, locale)
    set({ locale })
  },
  setSidebarOpen: (sidebarOpen) => set({ sidebarOpen }),
  toggleSidebarCollapsed: () => {
    const sidebarCollapsed = !get().sidebarCollapsed
    try {
      localStorage.setItem(COLLAPSE_KEY, sidebarCollapsed ? '1' : '0')
    } catch {
      /* ignore */
    }
    set({ sidebarCollapsed })
  },
}))
