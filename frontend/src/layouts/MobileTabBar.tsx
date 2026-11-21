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
// MOBILE-APP-002 — the bar floats 0.5rem above the screen's edge now, so the clearance carries that too.
export const MOBILE_TAB_BAR_CLEARANCE = 'pb-[calc(4rem+env(safe-area-inset-bottom)+1.5rem)] md:pb-0'

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
          {/*
            * MOBILE-APP-002 — the sheet reads like an app's «all services»: a grab handle, then each group as a grid of
            * tiles (a tinted icon over its name), three to a row — the way a banking app lays out its services — rather
            * than a website's list of links. Every destination the rail offers is still here, in the rail's groups.
            */}
          <div
            id={sheetId}
            data-testid="mobile-more-sheet"
            className="absolute inset-x-0 bottom-0 flex max-h-[82dvh] flex-col rounded-t-[28px] border-t border-border bg-surface shadow-[var(--shadow-large)]"
            style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}
          >
            <div className="flex shrink-0 justify-center pt-2" aria-hidden><span className="h-1 w-10 rounded-full bg-border" /></div>
            <div className="flex shrink-0 items-center justify-between px-4 pb-2 pt-1">
              <span className="font-heading text-base font-extrabold text-text-primary">{ar ? 'كل الأقسام' : 'All sections'}</span>
              <button
                type="button"
                onClick={() => setOpen(false)}
                aria-label={ar ? 'إغلاق' : 'Close'}
                className="flex h-9 w-9 items-center justify-center rounded-full bg-surface-secondary text-text-muted hover:bg-surface-hover"
              >
                <X size={16} />
              </button>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto px-3 pb-4">
              {moreHeader && <div className="mb-3">{moreHeader}</div>}
              {moreGroups.filter((g) => g.items.length > 0).map((group) => (
                <section key={group.key} className="mb-4 last:mb-0">
                  <p className="px-1 pb-2 text-xs font-bold text-text-muted">
                    {ar ? group.ar : group.en}
                  </p>
                  <div className="grid grid-cols-3 gap-2">
                    {group.items.map((item) => (
                      <NavLink
                        key={item.to}
                        to={item.to}
                        end={item.end}
                        onClick={() => setOpen(false)}
                        className={({ isActive }) =>
                          `flex min-h-[84px] min-w-0 flex-col items-center justify-center gap-1.5 rounded-2xl border px-1.5 py-2.5 text-center text-xs font-bold transition-colors active:scale-[0.98] ${
                            isActive
                              ? 'border-brand-600/40 bg-[var(--brand-background)] text-brand-600'
                              : 'border-border bg-surface-secondary/60 text-text-primary hover:bg-surface-hover'
                          }`
                        }
                      >
                        <span className="grid h-9 w-9 place-items-center rounded-xl bg-[var(--brand-background)] text-brand-600">
                          <item.icon size={18} className="shrink-0" />
                        </span>
                        <span className="line-clamp-2 w-full leading-tight">{ar ? item.ar : item.en}</span>
                      </NavLink>
                    ))}
                  </div>
                </section>
              ))}
            </div>
          </div>
        </div>
      )}

      {/*
        MOBILE-APP-002 — a floating bar, the way a banking app sits its tabs: inset from the edges, rounded, glass over
        the page, the current tab a filled pill. Same tabs, same targets (each over 44px); only its shape changed.
      */}
      <nav
        data-testid="mobile-tab-bar"
        aria-label={ar ? 'التنقل الرئيسي' : 'Primary navigation'}
        className="fixed inset-x-3 z-50 overflow-hidden rounded-[22px] border border-border bg-surface/90 shadow-[var(--shadow-large)] backdrop-blur-xl md:hidden"
        style={{ bottom: 'calc(env(safe-area-inset-bottom) + 0.5rem)' }}
      >
        <div className="mx-auto flex items-stretch px-1" style={{ height: MOBILE_TAB_BAR_HEIGHT }}>
          {tabs.map((tab) => (
            <NavLink
              key={tab.to}
              to={tab.to}
              end={tab.end}
              className={({ isActive }) =>
                `flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 px-0.5 text-xs font-bold transition-colors ${
                  isActive ? 'text-brand-600' : 'text-text-muted'
                }`
              }
            >
              {({ isActive }) => (
                <>
                  {/* The active pill, not a colour change alone — a phone is read at a glance. */}
                  <span className={`flex h-8 w-12 items-center justify-center rounded-2xl transition-colors ${isActive ? 'bg-brand-600 text-white shadow-[var(--shadow-small)]' : ''}`}>
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
              className={`flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 px-0.5 text-xs font-bold transition-colors ${
                open ? 'text-brand-600' : 'text-text-muted'
              }`}
            >
              <span className={`flex h-8 w-12 items-center justify-center rounded-2xl transition-colors ${open ? 'bg-brand-600 text-white shadow-[var(--shadow-small)]' : ''}`}>
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
