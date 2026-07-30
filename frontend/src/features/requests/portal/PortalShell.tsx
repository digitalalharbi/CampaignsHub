import type { ReactNode } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { LogOut, Megaphone, Moon, Sun } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { portalLogout } from '../clientPortalApi'
import { PortalNav } from './portalNav'
import { useClientSpacePath } from './clientSpace'
import { brandStyle, useClientBranding } from './useClientBranding'

/**
 * Minimal, mobile-first, RTL/LTR + light/dark shell for the public client portal.
 *
 * - `nav` renders the section nav bar under the header (authenticated pages).
 * - `showLogout` renders a built-in sign-out action (clears the cookie session + query cache).
 */
export function PortalShell({
  title,
  action,
  children,
  nav = false,
  showLogout = false,
}: {
  title: string
  action?: ReactNode
  children: ReactNode
  nav?: boolean
  showLogout?: boolean
}) {
  const locale = useUi((s) => s.locale)
  const theme = useUi((s) => s.theme)
  const toggleLocale = useUi((s) => s.toggleLocale)
  const toggleTheme = useUi((s) => s.toggleTheme)
  const ar = locale === 'ar'
  const navigate = useNavigate()
  const qc = useQueryClient()
  const spaceTo = useClientSpacePath()
  const branding = useClientBranding()
  // The primary mark if the agency uploaded one; otherwise the platform's own.
  const logo = branding?.logos.find((l) => l.kind === 'primary_horizontal' || l.kind === 'client_logo')

  const signOut = async () => {
    try { await portalLogout() } catch { /* clearing the local session is enough to sign the user out */ }
    qc.clear()
    navigate('/portal/login', { replace: true })
  }

  return (
    <div
      dir={ar ? 'rtl' : 'ltr'}
      // The agency's colour, scoped to the portal subtree. Set as a variable override rather than by
      // replacing the palette: an agency supplies a brand colour, not a theme, and letting one value
      // redefine every token is how a portal ends up unreadable in dark mode.
      style={brandStyle(branding)}
      className="min-h-screen bg-background text-text-primary"
    >
      <header className="border-b border-border bg-surface">
        <div className="mx-auto flex h-16 max-w-4xl items-center justify-between gap-2.5 px-4 sm:px-6">
          <Link to={spaceTo("")} className="flex items-center gap-2.5">
            {logo === undefined ? (
              <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white"><Megaphone size={18} /></span>
            ) : (
              // alt is the space's name, so a screen reader hears whose portal this is rather than
              // "logo".
              <img src={logo.url} alt={branding?.space?.name ?? 'CampaignsHub'} className="h-9 max-w-[140px] object-contain" />
            )}
            {logo === undefined && (
              <span className="font-heading text-base font-extrabold">
                {branding?.space?.name ?? 'CampaignsHub'}
              </span>
            )}
            <span className="hidden text-xs text-text-muted sm:inline">· {title}</span>
          </Link>
          <div className="flex items-center gap-1.5">
            {action}
            {showLogout && (
              <button onClick={signOut} className="flex h-9 items-center gap-1.5 rounded-lg px-2.5 text-sm font-semibold text-text-secondary hover:bg-surface-hover">
                <LogOut size={15} /> <span className="hidden sm:inline">{ar ? 'خروج' : 'Sign out'}</span>
              </button>
            )}
            <button onClick={toggleLocale} aria-label="Toggle language" className="flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover">{ar ? 'EN' : 'ع'}</button>
            <button onClick={toggleTheme} aria-label="Toggle theme" className="flex h-9 w-9 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover">{theme === 'dark' ? <Sun size={17} /> : <Moon size={17} />}</button>
          </div>
        </div>
      </header>
      {nav && <PortalNav ar={ar} />}
      <main className="mx-auto max-w-4xl px-4 py-6 sm:px-6 sm:py-8">{children}</main>
    </div>
  )
}
