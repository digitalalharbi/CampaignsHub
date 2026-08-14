import { useEffect, useId, useState, type ReactNode } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { Megaphone, Menu, Moon, Sun, X } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { useUi } from '@/stores/ui'

/**
 * MOBILE-002 — the one public header. Every public page wears this and none builds its own.
 *
 * ## The root cause this exists to remove
 *
 * Three pages hand-built the same header row: the marketing homepage, `/services`, and
 * `PublicPageShell` (which is `/privacy`, `/terms` and `/data-deletion`). Same brand link, same two
 * toggles, same trailing call to action — three copies, and therefore **three independent width
 * budgets for one row**.
 *
 * That duplication had already caused this defect once and been fixed in only one of the three
 * copies. MKT-UGC-001 found the homepage row asking for 423px at 375px wide and fixed it by standing
 * the wordmark down below 480px — a good fix, written inside `PublicHomePage.tsx`, where the other
 * two pages could never inherit it. So the moment MOBILE-001 grew the shared controls to a 44px
 * touch target (+8px each, twice), the two copies that never got the fix overflowed:
 *
 * ```
 * /privacy · /terms · /data-deletion at 375×667 → document overflows by 8px
 *   div.ms-auto flex items-center gap-1.5 [-8..164]
 * ```
 *
 * **The defect was the duplication, not the eight pixels.** Widening a control is a normal thing to
 * do to a design system; a row that breaks when one does is a row nobody owns. Patching the two
 * pages would have left the third budget in place and the next primitive change would find it again.
 * So the row moves here, once, and the pages below pass content into it.
 *
 * ## What the phone gets that it did not have
 *
 * The homepage section nav (`Features · How it works · Services · Integrations`) was `hidden lg:flex`
 * and nothing replaced it, so on every phone those four destinations did not exist — the same was
 * true of «Log in», «Track my requests» and «Request a service», each hidden at its own breakpoint.
 * That is information removed to make a row fit, which is the thing a mobile pass is supposed to
 * stop. They now collapse into the menu below instead of disappearing, and the button that opens it
 * is a 44px target.
 *
 * ## The width budget, stated so it can be checked
 *
 * At 375px: 32 padding + 36 mark + 8 gap leaves 299px for the controls. Two toggles (44 each), the
 * menu button when present (44), three 6px gaps and one primary action of ~130px comes to 286. The
 * wordmark is what yields below 480px, and only the wordmark — the mark itself, the language toggle,
 * the theme toggle and the primary action are all present at every width, because on an Arabic-first
 * product the language toggle is how the page is read at all and the action is what it is for.
 *
 * `e2e/responsive-audit.spec.ts` re-measures this on every run at 375/390/430/768.
 */

/** An in-page section link. Shown inline from `lg` up, inside the menu below it. */
export interface PublicNavLink {
  label: string
  /** A same-page anchor (`#features`). */
  href: string
}

/** A destination in the header. `inline` actions are always visible; the rest live in the menu. */
export interface PublicAction {
  label: string
  to: string
  variant?: 'primary' | 'secondary' | 'ghost'
}

const TOGGLE = 'flex h-11 min-w-11 items-center justify-center rounded-lg text-text-secondary transition-colors hover:bg-surface-hover sm:h-9 sm:min-w-9'

export function PublicHeader({
  /** Matches the page's own content width so the header aligns with what is under it. */
  width = 'max-w-6xl',
  nav = [],
  /** Collapses into the menu below `lg`. Never dropped. */
  secondaryActions = [],
  /** Always on screen at every width — the one thing the page is for. */
  primaryAction,
  /** Escape hatch for a page whose primary action is not a plain link. Rendered beside the toggles. */
  trailing,
}: {
  width?: string
  nav?: PublicNavLink[]
  secondaryActions?: PublicAction[]
  primaryAction?: PublicAction
  trailing?: ReactNode
}) {
  const { locale, theme, toggleLocale, toggleTheme } = useUi()
  const ar = locale === 'ar'
  const [open, setOpen] = useState(false)
  const menuId = useId()
  const location = useLocation()

  const collapsible = nav.length > 0 || secondaryActions.length > 0

  // Navigating away — including to an anchor on this page — must not leave the panel open over it.
  useEffect(() => setOpen(false), [location.pathname, location.hash])

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false) }
    window.addEventListener('keydown', onKey)

    return () => window.removeEventListener('keydown', onKey)
  }, [open])

  return (
    <header className="sticky top-0 z-40 border-b border-border bg-surface/85 backdrop-blur-md">
      <div className={`mx-auto flex h-16 ${width} items-center gap-2 px-4 sm:gap-4 sm:px-6`}>
        <Link to="/" className="flex shrink-0 items-center gap-2 sm:gap-2.5">
          <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white"><Megaphone size={18} /></span>
          {/* The wordmark is the only thing that yields, and only below 480px — see the header note. */}
          <span className="font-heading text-base font-extrabold tracking-tight max-[479px]:hidden sm:text-lg">CampaignsHub</span>
        </Link>

        {nav.length > 0 && (
          <nav className="ms-6 hidden items-center gap-5 text-sm font-medium text-text-secondary lg:flex">
            {nav.map((item) => (
              <a key={item.href} href={item.href} className="hover:text-text-primary">{item.label}</a>
            ))}
          </nav>
        )}

        <div className="ms-auto flex items-center gap-1.5">
          <button onClick={toggleLocale} aria-label="Toggle language" className={`${TOGGLE} px-2 text-sm font-semibold`}>{ar ? 'EN' : 'ع'}</button>
          <button onClick={toggleTheme} aria-label="Toggle theme" className={TOGGLE}>{theme === 'light' ? <Moon size={18} /> : <Sun size={18} />}</button>

          {secondaryActions.map((action) => (
            <Link key={action.to + action.label} to={action.to} className="hidden lg:block">
              <Button variant={action.variant ?? 'ghost'} size="sm" className="whitespace-nowrap">{action.label}</Button>
            </Link>
          ))}

          {trailing}
          {primaryAction && (
            <Link to={primaryAction.to}>
              <Button variant={primaryAction.variant ?? 'primary'} size="sm" className="whitespace-nowrap">{primaryAction.label}</Button>
            </Link>
          )}

          {collapsible && (
            <button
              type="button"
              onClick={() => setOpen((v) => !v)}
              aria-expanded={open}
              aria-controls={menuId}
              aria-label={ar ? 'القائمة' : 'Menu'}
              data-testid="public-menu-toggle"
              className={`${TOGGLE} lg:hidden`}
            >
              {open ? <X size={20} /> : <Menu size={20} />}
            </button>
          )}
        </div>
      </div>

      {collapsible && open && (
        <>
          {/* Closes on a tap anywhere else, which is what everybody tries first. */}
          <button
            type="button"
            aria-hidden
            tabIndex={-1}
            onClick={() => setOpen(false)}
            className="fixed inset-0 top-16 z-30 cursor-default bg-black/20 lg:hidden"
          />
          <div id={menuId} data-testid="public-menu" className="absolute inset-x-0 top-16 z-40 border-b border-border bg-surface shadow-[var(--shadow-medium)] lg:hidden">
            <nav className={`mx-auto flex ${width} flex-col gap-0.5 px-4 py-3 sm:px-6`}>
              {nav.map((item) => (
                <a
                  key={item.href}
                  href={item.href}
                  onClick={() => setOpen(false)}
                  className="flex min-h-11 items-center rounded-lg px-3 text-[15px] font-medium text-text-secondary hover:bg-surface-hover hover:text-text-primary"
                >
                  {item.label}
                </a>
              ))}
              {nav.length > 0 && secondaryActions.length > 0 && <span className="my-2 h-px bg-border" />}
              {secondaryActions.map((action) => (
                <Link
                  key={action.to + action.label}
                  to={action.to}
                  onClick={() => setOpen(false)}
                  className="flex min-h-11 items-center rounded-lg px-3 text-[15px] font-semibold text-text-primary hover:bg-surface-hover"
                >
                  {action.label}
                </Link>
              ))}
            </nav>
          </div>
        </>
      )}
    </header>
  )
}
