import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Megaphone, Moon, Sun } from 'lucide-react'
import { useUi } from '@/stores/ui'

/** Minimal, mobile-first, RTL/LTR + light/dark shell for the public client portal. */
export function PortalShell({ title, action, children }: { title: string; action?: ReactNode; children: ReactNode }) {
  const locale = useUi((s) => s.locale)
  const theme = useUi((s) => s.theme)
  const toggleLocale = useUi((s) => s.toggleLocale)
  const toggleTheme = useUi((s) => s.toggleTheme)
  const ar = locale === 'ar'

  return (
    <div dir={ar ? 'rtl' : 'ltr'} className="min-h-screen bg-background text-text-primary">
      <header className="border-b border-border bg-surface">
        <div className="mx-auto flex h-16 max-w-4xl items-center justify-between gap-2.5 px-4 sm:px-6">
          <Link to="/client" className="flex items-center gap-2.5">
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white"><Megaphone size={18} /></span>
            <span className="font-heading text-base font-extrabold">CampaignsHub</span>
            <span className="hidden text-xs text-text-muted sm:inline">· {title}</span>
          </Link>
          <div className="flex items-center gap-1.5">
            {action}
            <button onClick={toggleLocale} aria-label="Toggle language" className="flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover">{ar ? 'EN' : 'ع'}</button>
            <button onClick={toggleTheme} aria-label="Toggle theme" className="flex h-9 w-9 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover">{theme === 'dark' ? <Sun size={17} /> : <Moon size={17} />}</button>
          </div>
        </div>
      </header>
      <main className="mx-auto max-w-4xl px-4 py-6 sm:px-6 sm:py-8">{children}</main>
    </div>
  )
}
