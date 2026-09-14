import { afterEach, describe, expect, it } from 'vitest'
import { act, renderHook } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import type { ReactNode } from 'react'
import { useSectionTitle } from './sectionTitle'
import { appNavGroups } from './appNav'
import { useUi } from '@/stores/ui'

/**
 * REPORT-TITLE-METADATA-001 — the tab title actually changes, and changes back.
 *
 * `sectionTitle()` being right is half of it: the shell has to apply it. Nothing inside `/app` or
 * `/agency` ever touched `document.title`, so every screen carried `index.html`'s marketing line —
 * and a portal that renamed the tab and left it renamed would follow the reader back out to the
 * marketing site, which is why the restore is asserted too.
 */
const at = (path: string) => ({ children }: { children: ReactNode }) => (
  <MemoryRouter initialEntries={[path]}>{children}</MemoryRouter>
)

describe('the shell’s document title', () => {
  /*
    The locale is GLOBAL, so the case below puts it back.
    
    A test that switches the product's language and leaves it switched hands the next file a store
    it did not set up. The full run turned one unrelated legal-page case red exactly once while this
    passed in isolation — the signature of shared state, and the reason this restores rather than
    relies on file isolation holding.
  */
  const locale = useUi.getState().locale

  afterEach(() => {
    act(() => {
      useUi.setState({ locale })
    })
  })

  it('names the section the reader is on', () => {
    document.title = 'كل حملاتك الإعلانية المدفوعة في مكان واحد — CampaignsHub'

    renderHook(() => useSectionTitle(appNavGroups), { wrapper: at('/app/analytics') })

    expect(document.title).toBe('التحليلات — كامبينز هب')
  })

  it('gives the document its own title back on the way out', () => {
    document.title = 'كل حملاتك الإعلانية المدفوعة في مكان واحد — CampaignsHub'

    const { unmount } = renderHook(() => useSectionTitle(appNavGroups), { wrapper: at('/app/campaigns') })
    unmount()

    expect(document.title).toBe('كل حملاتك الإعلانية المدفوعة في مكان واحد — CampaignsHub')
  })

  /* A route the rail does not offer keeps whatever title that page set for itself. */
  it('leaves a page outside the rail alone', () => {
    document.title = 'Something a page chose'

    renderHook(() => useSectionTitle(appNavGroups), { wrapper: at('/onboarding') })

    expect(document.title).toBe('Something a page chose')
  })

  /**
   * The title cannot go STALE across a language switch.
   *
   * `useSectionTitle` recomputes on locale, so this proves the wiring rather than the string: a tab
   * left reading «Analytics — CampaignsHub» after the reader switched to Arabic is the same defect
   * as never setting it at all, and it is the failure mode a title set once in an effect invites.
   */
  it('follows the reader from one language to the other', () => {
    act(() => {
      useUi.setState({ locale: 'en' })
    })
    const { rerender } = renderHook(() => useSectionTitle(appNavGroups), { wrapper: at('/app/analytics') })
    expect(document.title).toBe('Analytics — CampaignsHub')

    act(() => {
      useUi.setState({ locale: 'ar' })
    })
    rerender()
    expect(document.title).toBe('التحليلات — كامبينز هب')

    act(() => {
      useUi.setState({ locale: 'en' })
    })
    rerender()
    expect(document.title).toBe('Analytics — CampaignsHub')
  })

})
