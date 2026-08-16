import { useEffect, useId, useState } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import { MoreHorizontal, X } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useUi } from '@/stores/ui'

/**
 * MOBILE-APP-001 — the bottom navigation that makes a portal feel like an app on a phone.
 *
 * ## Why a tab bar and not the drawer that was already there
 *
 * Every portal shipped one navigation: a desktop rail, hidden below `md`, reachable on a phone only
 * through a hamburger that opened the same rail as a drawer. That is a website's navigation shown on
 * a small screen — every destination costs a deliberate tap-open-scan-tap, and nothing tells you
 * where you are without opening it. It is the single biggest reason the product read as a shrunk
 * desktop rather than an app.
 *
 * A phone app puts the few things you actually do on screen permanently, and everything else one tap
 * away. That is what this is: **four primary destinations plus «More»**, always visible, with the
 * current one marked, and the complete remaining navigation inside the More sheet.
 *
 * ## What is deliberately NOT done
 *
 * **Nothing is removed.** The four tabs are a shortcut to the four sections a portal is most often
 * opened for; the More sheet carries EVERY other destination the rail offers, in the rail's own
 * groups and order. A tab bar that quietly dropped six sections would be a worse defect than the one
 * it fixes, so `moreGroups` is the whole rail minus what is already a tab, computed by the shell
 * rather than hand-listed here.
 *
 * Five is the ceiling, and four-plus-More is the shape, because a 375px screen divided six ways
 * gives each target 62px with a label that has to wrap. Four gives 93px — comfortable, and above the
 * 44px floor in both dimensions.
 *
 * ## Layout contract
 *
 * The bar is `fixed` at the bottom and therefore out of flow, so it would sit ON TOP of the last
 * rows of any page. `PortalFrame` pays for it with bottom padding on the content — the two are a
 * pair, and the padding is expressed in the same custom property this file sets, so they cannot
 * drift apart. `env(safe-area-inset-bottom)` keeps the tabs above the iPhone home indicator.
 */

export interface MobileTab {
  to: string
  ar: string
  en: string
  icon: LucideIcon
  /** `end` for an index route, so `/admin` is not marked active on `/admin/tenants`. */
  end?: boolean
}

/** A section of the More sheet: the rail's own grouping, so the sheet reads like the rail. */
export interface MobileMoreGroup {
  key: string
  ar: string
  en: string
  items: MobileTab[]
}

/**
 * The height the bar occupies, published as a custom property.
 *
 * `PortalFrame` reads it for the content's bottom padding. A number written twice is a number that
 * eventually disagrees with itself, and the symptom — the last row of a list sitting under the tab
 * bar — is exactly the kind of thing that survives review because you have to scroll to the end of a
 * long page to see it.
 */
export const MOBILE_TAB_BAR_HEIGHT = '4rem'

/**
 * The bottom clearance a page owes the bar — and only where the bar exists.
 *
 * It has to be a CLASS rather than an inline style, because the bar is `md:hidden` and an inline
 * `padding-bottom` cannot carry a media query. Applied unconditionally it put 64px of empty page
 * under the footer on every desktop window — the same dead-space defect SHELL-001 exists to remove,
 * reintroduced by its own fix, and caught by the guard written for the original.
 *
 * The extra `0.75rem` over the bar's own height is breathing room, not slack. At exactly the bar
 * height the footer's last pixel abuts the bar's first, which reads as the content running under it
 * and measured as a 1px overlap once sub-pixel rounding was applied.
 */
export const MOBILE_TAB_BAR_CLEARANCE = 'pb-[calc(4rem+env(safe-area-inset-bottom)+0.75rem)] md:pb-0'

export function MobileTabBar({
  tabs,
  moreGroups = [],
  /** Rendered at the top of the More sheet — the account menu, a scope switcher, whatever the portal needs. */
  moreHeader,
}: {
  tabs: MobileTab[]
  moreGroups?: MobileMoreGroup[]
  moreHeader?: React.ReactNode
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const [open, setOpen] = useState(false)
  const sheetId = useId()
  const location = useLocation()

  // A navigation must not leave the sheet open over the page it just opened.
  useEffect(() => setOpen(false), [location.pathname])

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false) }
    window.addEventListener('keydown', onKey)

    return () => window.removeEventListener('keydown', onKey)
  }, [open])

  const hasMore = moreGroups.some((g) => g.items.length > 0) || Boolean(moreHeader)

  return (
    <>
      {open && (
        <div className="fixed inset-0 z-[60] md:hidden" role="dialog" aria-modal="true" aria-label={ar ? 'كل الأقسام' : 'All sections'}>
          <button
            type="button"
            aria-label={ar ? 'إغلاق' : 'Close'}
            onClick={() => setOpen(false)}
            className="absolute inset-0 cursor-default bg-black/50 backdrop-blur-[2px]"
          />
          {/*
            * A sheet, not a full-screen page: the context underneath stays visible, which is what
            * tells somebody this is a menu over their page rather than a navigation away from it.
            * `max-h-[78dvh]` with its own scroll keeps a long portal (the agency's has eighteen
            * sections) usable without the sheet growing past the screen.
            */}
          <div
            id={sheetId}
            data-testid="mobile-more-sheet"
            className="absolute inset-x-0 bottom-0 flex max-h-[78dvh] flex-col rounded-t-2xl border-t border-border bg-surface shadow-[var(--shadow-large)]"
            style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}
          >
            <div className="flex shrink-0 items-center justify-between border-b border-border px-4 py-3">
              <span className="font-heading text-[15px] font-extrabold text-text-primary">{ar ? 'كل الأقسام' : 'All sections'}</span>
              <button
                type="button"
                onClick={() => setOpen(false)}
                aria-label={ar ? 'إغلاق' : 'Close'}
                className="flex h-11 w-11 items-center justify-center rounded-lg text-text-muted hover:bg-surface-hover"
              >
                <X size={18} />
              </button>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto px-3 py-3">
              {moreHeader && <div className="mb-3">{moreHeader}</div>}
              {moreGroups.filter((g) => g.items.length > 0).map((group) => (
                <div key={group.key} className="mb-4 last:mb-0">
                  <p className="px-2 pb-1.5 text-[11.5px] font-bold uppercase tracking-wide text-text-muted">
                    {ar ? group.ar : group.en}
                  </p>
                  <div className="grid gap-0.5">
                    {group.items.map((item) => (
                      <NavLink
                        key={item.to}
                        to={item.to}
                        end={item.end}
                        onClick={() => setOpen(false)}
                        className={({ isActive }) =>
                          `flex min-h-11 items-center gap-3 rounded-xl px-3 text-[15px] font-semibold transition-colors ${
                            isActive
                              ? 'bg-[var(--brand-background)] text-brand-600'
                              : 'text-text-secondary hover:bg-surface-hover hover:text-text-primary'
                          }`
                        }
                      >
                        <item.icon size={18} className="shrink-0" />
                        <span className="truncate">{ar ? item.ar : item.en}</span>
                      </NavLink>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}

      <nav
        data-testid="mobile-tab-bar"
        aria-label={ar ? 'التنقل الرئيسي' : 'Primary navigation'}
        className="fixed inset-x-0 bottom-0 z-50 border-t border-border bg-surface/95 backdrop-blur-md md:hidden"
        style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}
      >
        <div className="mx-auto flex items-stretch" style={{ height: MOBILE_TAB_BAR_HEIGHT }}>
          {tabs.map((tab) => (
            <NavLink
              key={tab.to}
              to={tab.to}
              end={tab.end}
              className={({ isActive }) =>
                `flex min-w-0 flex-1 flex-col items-center justify-center gap-1 px-1 text-[11px] font-semibold transition-colors ${
                  isActive ? 'text-brand-600' : 'text-text-muted'
                }`
              }
            >
              {({ isActive }) => (
                <>
                  {/* The active pill, not a colour change alone — a phone is read at a glance. */}
                  <span className={`flex h-7 w-12 items-center justify-center rounded-full transition-colors ${isActive ? 'bg-[var(--brand-background)]' : ''}`}>
                    <tab.icon size={19} />
                  </span>
                  <span className="w-full truncate text-center leading-none">{ar ? tab.ar : tab.en}</span>
                </>
              )}
            </NavLink>
          ))}

          {hasMore && (
            <button
              type="button"
              data-testid="mobile-more-toggle"
              onClick={() => setOpen((v) => !v)}
              aria-expanded={open}
              aria-controls={sheetId}
              className={`flex min-w-0 flex-1 flex-col items-center justify-center gap-1 px-1 text-[11px] font-semibold transition-colors ${
                open ? 'text-brand-600' : 'text-text-muted'
              }`}
            >
              <span className={`flex h-7 w-12 items-center justify-center rounded-full transition-colors ${open ? 'bg-[var(--brand-background)]' : ''}`}>
                <MoreHorizontal size={19} />
              </span>
              <span className="w-full truncate text-center leading-none">{ar ? 'المزيد' : 'More'}</span>
            </button>
          )}
        </div>
      </nav>
    </>
  )
}
