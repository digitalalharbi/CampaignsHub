import { Megaphone, Moon, Sun } from 'lucide-react'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'
import type { Locale } from '@/features/marketing/homeCopy'
import { AuthPanel, AuthPanelMobile, type AuthPortal } from './AuthPanel'

/** Premium split auth layout shared by login / register / forgot-password (marketing panel + form). */
export function AuthShell({ children, portal = 'default' }: { children: React.ReactNode; portal?: AuthPortal }) {
  const t = useT()
  const { theme, locale, toggleTheme, toggleLocale } = useUi()

  return (
    <div className="grid min-h-screen grid-cols-1 bg-background lg:grid-cols-[1.3fr_1fr]">
      <AuthPanel locale={locale as Locale} portal={portal} />

      <main className="flex flex-col px-5 py-4 sm:px-8">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2 lg:invisible">
            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-brand-500 to-brand-700 text-white"><Megaphone size={16} /></div>
            <span className="font-extrabold text-text-primary">{t('app_name')}</span>
          </div>
          <div className="flex items-center gap-1.5">
            <button onClick={toggleLocale} aria-label="Toggle language" className="flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover">{locale === 'ar' ? 'EN' : 'ع'}</button>
            <button onClick={toggleTheme} aria-label="Toggle theme" className="flex h-9 w-9 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover">{theme === 'light' ? <Moon size={18} /> : <Sun size={18} />}</button>
          </div>
        </div>

        <div className="mx-auto flex w-full max-w-[460px] flex-1 flex-col justify-center py-4">
          {children}
          {/* Phones get the value proposition here, collapsed, after the form. */}
          <AuthPanelMobile locale={locale as Locale} portal={portal} />
        </div>
      </main>
    </div>
  )
}
