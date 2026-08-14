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
    /*
      Same split as LoginPage — the two pages share a panel, so they must share its proportions too.

      `minmax(0,…)` on both tracks rather than bare `fr` — AUTH-FIT-001. A bare `1fr` track has a
      min-content floor, so any wide child (a long unbroken heading, a price row) pushes the track
      past its share and the whole page acquires a horizontal scrollbar. `minmax(0,…)` lets the
      track shrink and the child wrap, which is the only reliable way to guarantee no sideways
      scroll at any width.
    */
    <div className="grid min-h-screen grid-cols-1 bg-background lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)]">
      <AuthPanel locale={locale as Locale} portal={portal} />

      {/* `min-w-0` for the same reason as the track: a flex item defaults to min-content width. */}
      <main className="flex min-w-0 flex-col px-[clamp(1rem,3.2vw,2rem)] py-[clamp(0.5rem,1.1vh,0.875rem)]">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2 lg:invisible">
            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-brand-500 to-brand-700 text-white"><Megaphone size={16} /></div>
            <span className="font-extrabold text-text-primary">{t('app_name')}</span>
          </div>
          <div className="flex items-center gap-1.5">
            <button onClick={toggleLocale} aria-label="Toggle language" className="flex h-11 min-w-11 items-center justify-center rounded-lg px-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover sm:h-9 sm:min-w-9">{locale === 'ar' ? 'EN' : 'ع'}</button>
            <button onClick={toggleTheme} aria-label="Toggle theme" className="flex h-11 w-11 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover sm:h-9 sm:w-9">{theme === 'light' ? <Moon size={18} /> : <Sun size={18} />}</button>
          </div>
        </div>

        {/*
          Centred below `lg`; only pulled toward the divider once the panel is actually beside it.

          The measure and the pull both scale now: a fixed 460px column with a fixed 56px offset was
          sized for one screen and had to be scrolled on a 1366×768 laptop. `min(…, 100%)` keeps the
          column from ever exceeding its track, which is what would have produced a sideways scroll.

          The measure is WIDER than it was, which is the opposite of what «make it compact» sounds
          like and is why the page got shorter: at 460px the English journey descriptions and plan
          summaries each wrapped to three lines, and the column spent that height on wrapping rather
          than on content. Still well inside a comfortable reading measure for text this size.

          And it is CENTRED at every width. An `xl:ms-…` used to pull it toward the divider on wide
          screens, which left the content sitting off-centre in its own track — most visible on the
          agency path, where a single plan card had nothing beside it to balance against. Centring is
          also the behaviour that does not depend on a breakpoint, which is what makes it look the
          same on every device and in every browser.
        */}
        <div className="mx-auto flex w-full max-w-[min(33rem,100%)] flex-1 flex-col justify-center py-[clamp(0.25rem,0.8vh,0.75rem)]">
          {children}
          {/* Phones get the value proposition here, collapsed, after the form. */}
          <AuthPanelMobile locale={locale as Locale} portal={portal} />
        </div>
      </main>
    </div>
  )
}
