import type { ReactNode } from 'react'
import { Megaphone, Moon, Sun } from 'lucide-react'
import { useUi } from '@/stores/ui'

/** Centered, mobile-first, RTL/LTR + light/dark shell for verification + onboarding (pre-app). */
export function OnboardingShell({ title, children }: { title: string; children: ReactNode }) {
  const locale = useUi((s) => s.locale)
  const theme = useUi((s) => s.theme)
  const toggleLocale = useUi((s) => s.toggleLocale)
  const toggleTheme = useUi((s) => s.toggleTheme)
  const ar = locale === 'ar'

  return (
    <div dir={ar ? 'rtl' : 'ltr'} className="min-h-screen bg-background text-text-primary">
      <header className="border-b border-border bg-surface">
        <div className="mx-auto flex h-16 max-w-2xl items-center justify-between px-4 sm:px-6">
          <div className="flex items-center gap-2.5">
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white"><Megaphone size={18} /></span>
            <span className="font-heading text-base font-extrabold">CampaignsHub</span>
            <span className="hidden text-xs text-text-muted sm:inline">· {title}</span>
          </div>
          <div className="flex items-center gap-1.5">
            <button onClick={toggleLocale} aria-label="Toggle language" className="flex h-11 min-w-11 items-center justify-center rounded-lg px-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover sm:h-9 sm:min-w-9">{ar ? 'EN' : 'ع'}</button>
            <button onClick={toggleTheme} aria-label="Toggle theme" className="flex h-11 w-11 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover sm:h-9 sm:w-9">{theme === 'dark' ? <Sun size={17} /> : <Moon size={17} />}</button>
          </div>
        </div>
      </header>
      <main className="mx-auto max-w-2xl px-4 py-8 sm:px-6"><div className="rounded-2xl border border-border bg-surface p-6 sm:p-8">{children}</div></main>
    </div>
  )
}
