import { useEffect, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Megaphone } from 'lucide-react'
import { HOME_COPY, type Locale } from './homeCopy'
import { Button } from '@/components/ui/Button'
import { useUi } from '@/stores/ui'

/**
 * The frame every public page outside the marketing homepage wears.
 *
 * It was inlined in `PublicInfoPage` and nowhere else, so `/data-deletion` — a page written later,
 * against the same design — shipped with no header, no footer, no logo and no way to change language.
 * A platform reviewer opening that URL cold saw an unbranded form and nothing identifying whose site
 * it was, which is a poor answer to «is this really CampaignsHub's deletion page?».
 *
 * Extracted rather than copied. A second hand-built copy of a header is a second header to keep in
 * step, and the first thing that drifts is the thing nobody looks at — which on these pages is
 * everything, because policy pages are read by strangers and reviewed by nobody.
 *
 * ## What it owns
 *
 * Direction and language on the document element, the page title, and the chrome. These pages set
 * `dir` themselves because they are reachable without ever passing through the app shell — somebody
 * lands on `/privacy` from a Google result, or on `/data-deletion` from a Meta review form.
 */
export function PublicPageShell({
  /** Page title, already localised. Rendered into `document.title`, never on screen. */
  title,
  children,
}: {
  title: string
  children: ReactNode
}) {
  const { locale, theme, toggleLocale, toggleTheme } = useUi()
  const c = HOME_COPY[locale as Locale]

  useEffect(() => {
    document.documentElement.setAttribute('dir', c.dir)
    document.documentElement.setAttribute('lang', locale)
  }, [c.dir, locale])

  useEffect(() => {
    document.title = `${title} — CampaignsHub`
  }, [title])

  return (
    <div className="min-h-screen bg-background text-text-primary" dir={c.dir}>
      <header className="sticky top-0 z-40 border-b border-border bg-surface/85 backdrop-blur-md">
        <div className="mx-auto flex h-16 max-w-4xl items-center gap-4 px-4 sm:px-6">
          <Link to="/" className="flex shrink-0 items-center gap-2.5">
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white"><Megaphone size={18} /></span>
            <span className="font-heading text-lg font-extrabold tracking-tight">CampaignsHub</span>
          </Link>
          <div className="ms-auto flex items-center gap-1.5">
            <button onClick={toggleLocale} aria-label="Toggle language" className="flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover">{locale === 'ar' ? 'EN' : 'ع'}</button>
            <button onClick={toggleTheme} aria-label="Toggle theme" className="flex h-9 w-9 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover">{theme === 'light' ? '🌙' : '☀️'}</button>
            <Link to="/"><Button variant="secondary" size="sm">{locale === 'ar' ? 'العودة للرئيسية' : 'Back to home'}</Button></Link>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-4xl px-4 py-10 sm:px-6">{children}</main>

      <footer className="border-t border-border bg-surface py-6 text-center text-xs text-text-muted">
        © {new Date().getFullYear()} CampaignsHub — {c.footer.rights}
      </footer>
    </div>
  )
}
