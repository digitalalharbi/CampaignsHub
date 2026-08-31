import { useState, type ReactNode } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { LogOut, Megaphone, Moon, Sun } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { portalLogout } from '../clientPortalApi'
import { PORTAL_NAV, PortalNav } from './portalNav'
import { useClientSpacePath } from './clientSpace'
import { brandStyle, useClientBranding } from './useClientBranding'
import { PortalFooter } from '@/features/legal/PolicyFooter'
import { MOBILE_TAB_BAR_CLEARANCE, MobileTabBar } from '@/layouts/MobileTabBar'

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

  /*
   * The four a client opens the portal for, resolved against THEIR space (MOBILE-APP-001).
   *
   * `PORTAL_NAV` holds suffixes, not paths — `/invoices`, resolved to `/portal/clients/acme/invoices`
   * or `/client/invoices` depending on where the visitor is. Building the bar from that same array
   * keeps the phone and the desktop strip the same navigation, and `spaceTo` keeps a tap from
   * dropping somebody out of their brand's space.
   *
   * Home, requests, campaigns and reports: what is happening and how it is going. Quotes, invoices,
   * messages, files and profile are in More — the paperwork, which is looked up rather than watched.
   */
  const PRIMARY = ['', '/requests', '/campaigns', '/reports']
  const tabs = PORTAL_NAV.filter((i) => PRIMARY.includes(i.to))
    .map((i) => ({ to: spaceTo(i.to), ar: i.ar, en: i.en, icon: i.icon, end: i.end }))
  const moreItems = PORTAL_NAV.filter((i) => !PRIMARY.includes(i.to))
    .map((i) => ({ to: spaceTo(i.to), ar: i.ar, en: i.en, icon: i.icon, end: i.end }))
  // The primary mark if the agency uploaded one; otherwise the platform's own.
  const uploaded = branding?.logos.find((l) => l.kind === 'primary_horizontal' || l.kind === 'client_logo')

  /*
   * BRANDING-HIERARCHY-001 — «never a broken image or blank header».
   *
   * The fallback below is skipped precisely BECAUSE a logo exists, so a mark that fails to load left
   * the header showing a browser's broken-image glyph and no name at all — the one outcome that
   * requirement rules out, and the one this header was previously guaranteed to produce, since the
   * URL it was handed answered 401 to every portal session.
   *
   * The URL is fixed on the server. This is the second half: a mark that cannot be drawn for any
   * other reason — a deleted file, an offline CDN, a corrupt upload — falls back to the same mark and
   * name a client with no logo sees, instead of to nothing. `onError` is the only signal a browser
   * gives for that, and it is per-URL, so a new logo gets its own chance rather than inheriting the
   * previous one's failure.
   */
  const [brokenLogo, setBrokenLogo] = useState<string | null>(null)
  const logo = uploaded !== undefined && uploaded.url !== brokenLogo ? uploaded : undefined

  const signOut = async () => {
    try { await portalLogout() } catch { /* clearing the local session is enough to sign the user out */ }
    qc.clear()
    navigate('/login', { replace: true })
  }

  return (
    <div
      dir={ar ? 'rtl' : 'ltr'}
      // The agency's colour, scoped to the portal subtree. Set as a variable override rather than by
      // replacing the palette: an agency supplies a brand colour, not a theme, and letting one value
      // redefine every token is how a portal ends up unreadable in dark mode.
      style={brandStyle(branding)}
      className="flex min-h-[100dvh] flex-col bg-background text-text-primary"
    >
      <header className="border-b border-border bg-surface">
        <div className="mx-auto flex h-16 max-w-4xl items-center justify-between gap-2.5 px-4 sm:px-6">
          <Link to={spaceTo("")} className="flex items-center gap-2.5">
            {logo === undefined ? (
              <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white"><Megaphone size={18} /></span>
            ) : (
              // alt is the space's name, so a screen reader hears whose portal this is rather than
              // "logo".
              <img
                src={logo.url}
                onError={() => setBrokenLogo(logo.url)}
                alt={branding?.space?.name ?? 'CampaignsHub'}
                data-testid="portal-logo"
                className="h-9 max-w-[140px] object-contain"
              />
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
            <button onClick={toggleLocale} aria-label="Toggle language" className="flex h-11 min-w-11 items-center justify-center rounded-lg px-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover sm:h-9 sm:min-w-9">{ar ? 'EN' : 'ع'}</button>
            <button onClick={toggleTheme} aria-label="Toggle theme" className="flex h-11 w-11 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover sm:h-9 sm:w-9">{theme === 'dark' ? <Sun size={17} /> : <Moon size={17} />}</button>
          </div>
        </div>
      </header>
      {/*
        * The horizontal section strip is a DESKTOP control now (MOBILE-APP-001).
        *
        * On a phone it was a scrollable row of nine tabs, most of them off-screen, with no indication
        * that scrolling was even possible — the section you wanted was usually the one you could not
        * see. Below `md` the bottom bar replaces it: four destinations always visible, the other five
        * in the More sheet. Above `md` the strip is unchanged.
        */}
      {nav && <div className="hidden md:block"><PortalNav ar={ar} /></div>}

      {/* SHELL-001 — the content box takes the slack so the footer sinks rather than stranding space. */}
      <main className="flex min-w-0 flex-1 flex-col">
        <div
          className="mx-auto w-full min-w-0 max-w-4xl flex-1 px-4 py-6 sm:px-6 sm:py-8"
        >
          {children}
        </div>
        <div className={`px-4 sm:px-6 ${nav ? MOBILE_TAB_BAR_CLEARANCE : ''}`}>
          <div className="mx-auto w-full max-w-4xl"><PortalFooter /></div>
        </div>
      </main>

      {nav && (
        <MobileTabBar
          tabs={tabs}
          moreGroups={[{ key: 'portal', ar: 'كل الأقسام', en: 'All sections', items: moreItems }]}
        />
      )}
    </div>
  )
}
