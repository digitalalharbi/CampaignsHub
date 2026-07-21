import { create } from 'zustand'

export type Theme = 'light' | 'dark'
export type Locale = 'ar' | 'en'

interface UiState {
  theme: Theme
  locale: Locale
  sidebarOpen: boolean
  toggleTheme: () => void
  toggleLocale: () => void
  setSidebarOpen: (open: boolean) => void
}

/** Applies theme + direction to <html> so tokens and RTL/LTR take effect globally. */
export function applyDocument(theme: Theme, locale: Locale): void {
  const root = document.documentElement
  root.setAttribute('data-theme', theme)
  root.setAttribute('dir', locale === 'ar' ? 'rtl' : 'ltr')
  root.setAttribute('lang', locale)
}

export const useUi = create<UiState>((set, get) => ({
  theme: 'light',
  locale: 'ar',
  sidebarOpen: false,
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
}))
