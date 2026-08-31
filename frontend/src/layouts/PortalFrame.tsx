import type { CSSProperties, ReactNode } from 'react'
import { PortalFooter } from '@/features/legal/PolicyFooter'
import { MOBILE_TAB_BAR_CLEARANCE, MobileTabBar, type MobileMoreGroup, type MobileTab } from './MobileTabBar'

/**
 * SHELL-001 — the frame every portal wears, owned once.
 *
 * ## The defect this exists to remove
 *
 * `AppShell`, `AgencyShell`, `AdminShell` and `InfluencerShell` each hand-built the same skeleton,
 * character for character:
 *
 * ```jsx
 * <div className="flex min-h-screen …">
 *   <aside className="sticky top-0 h-screen … md:flex" />
 *   <div className="flex min-w-0 flex-1 flex-col">
 *     <header className="sticky top-0 …" />
 *     <main className="mx-auto w-full max-w-[1440px] flex-1 px-4 pb-12 pt-4 …">
 *       <Outlet />
 *       <PortalFooter />
 *     </main>
 *   </div>
 * </div>
 * ```
 *
 * Four copies of one layout is four places for one bug to live, and it did:
 *
 * **`<main>` is `flex-1` but lays its children out in normal block flow.** So on any page whose
 * content is shorter than the viewport, `main` stretches to fill the column, the footer sits
 * immediately under the short content, and everything below the footer is empty — a band of dead
 * space, often more than half the screen, with the copyright line stranded in the middle of it. It
 * is not a footer that "ends early"; it is a footer that never learned it should sink.
 *
 * The fix is one line of intent: **`main` becomes the flex column, the content area takes the
 * slack, and the footer is the last item.** Short page → the content box absorbs the space and the
 * footer lands on the bottom edge. Long page → the content box is its natural height and the footer
 * follows the content, as it always did. No `position: fixed`, no viewport arithmetic, nothing that
 * behaves differently at one height than another.
 *
 * ## `100dvh`, not `100vh`
 *
 * `min-h-screen` is `100vh`, which on a mobile browser means the viewport *with the URL bar hidden*
 * — a height the page does not have when it loads. Every portal was therefore ~60px too tall on a
 * phone before you scrolled, which is its own small band of dead space. `100dvh` is the height the
 * page actually has, and it tracks the bar as it collapses.
 *
 * ## What each portal still owns
 *
 * The CONTENTS of the rail, the drawer and the header, and its own four primary tabs. Those genuinely
 * differ — an agency has clients and scopes, an advertiser has integrations and a subscription — and
 * REG-001 exists because they were once shared. This owns the geometry and nothing else.
 */
export function PortalFrame({
  testId,
  /** Desktop rail contents, rendered inside the frame's own `<aside>`. */
  rail,
  /** Mobile drawer contents (the hamburger drawer). Rendered inside the frame's overlay. */
  drawer,
  drawerOpen = false,
  onDrawerClose,
  /** Header contents, rendered inside the frame's own sticky `<header>`. */
  header,
  /** Bottom-navigation tabs on a phone. Four, plus the More sheet the frame adds. */
  tabs,
  /** Everything the tabs do not cover, for the More sheet. Never a subset — see `MobileTabBar`. */
  moreGroups,
  moreHeader,
  /** Rail width, which the portals vary by their own collapse state. */
  railWidth = 'w-[264px]',
  /** The reading measure for content. Portals with dense tables use the wide one. */
  contentWidth = 'max-w-[1440px]',
  style,
  children,
}: {
  testId?: string
  rail?: ReactNode
  drawer?: ReactNode
  drawerOpen?: boolean
  onDrawerClose?: () => void
  header?: ReactNode
  tabs?: MobileTab[]
  moreGroups?: MobileMoreGroup[]
  moreHeader?: ReactNode
  railWidth?: string
  contentWidth?: string
  style?: CSSProperties
  children: ReactNode
}) {
  const hasTabs = Boolean(tabs && tabs.length > 0)

  return (
    <div data-testid={testId} style={style} className="flex min-h-[100dvh] bg-background text-text-primary">
      {rail && (
        <aside
          className={`sticky top-0 hidden h-[100dvh] shrink-0 flex-col gap-6 overflow-y-auto border-e border-border bg-surface p-3.5 transition-[width] duration-200 md:flex ${railWidth}`}
        >
          {rail}
        </aside>
      )}

      {drawer && drawerOpen && (
        <div className="fixed inset-0 z-50 md:hidden" role="dialog" aria-modal="true">
          <div className="absolute inset-0 bg-black/50 backdrop-blur-[2px]" onClick={onDrawerClose} />
          <aside className="absolute inset-y-0 start-0 flex h-full w-[280px] max-w-[82vw] flex-col gap-6 overflow-y-auto border-e border-border bg-surface p-3.5 shadow-[var(--shadow-large)]">
            {drawer}
          </aside>
        </div>
      )}

      {/*
        * `min-w-0` is load-bearing, not defensive.
        *
        * A flex item's default `min-width: auto` refuses to shrink below its content's intrinsic
        * width, so one wide table or one long unbroken string inside a page pushes this column past
        * the viewport and the WHOLE document scrolls sideways — the table's own `overflow-x` never
        * gets a chance to contain it. This is what keeps a dense portal page honest at 1280px and at
        * 375px alike.
        */}
      <div className="flex min-w-0 flex-1 flex-col">
        {header}

        {/*
          * The column that fixes the dead space: `main` is the flex parent, the content box takes
          * the slack, the footer is last and therefore sits on the bottom edge of a short page.
          */}
        <main className="flex min-w-0 flex-1 flex-col">
          <div className={`mx-auto w-full min-w-0 flex-1 px-4 pb-12 pt-4 sm:px-5 lg:px-6 ${contentWidth}`}>
            {children}
          </div>
          {/*
            * The footer clears the tab bar, and only the footer does.
            *
            * It is the last thing on the page, so paying the bar's height here is enough for every
            * row above it; paying it on the content box as well would add a phone-sized gap between
            * the content and the footer on every page — the same dead space in a new place.
            */}
          <div className={`px-4 sm:px-5 lg:px-6 ${hasTabs ? MOBILE_TAB_BAR_CLEARANCE : ''}`}>
            <div className={`mx-auto w-full ${contentWidth}`}><PortalFooter /></div>
          </div>
        </main>
      </div>

      {hasTabs && <MobileTabBar tabs={tabs!} moreGroups={moreGroups} moreHeader={moreHeader} />}
    </div>
  )
}
