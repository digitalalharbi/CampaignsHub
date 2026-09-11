import { describe, expect, it } from 'vitest'
import { renderHook } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import type { ReactNode } from 'react'
import { useSectionTitle } from './sectionTitle'
import { appNavGroups } from './appNav'

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
  it('names the section the reader is on', () => {
    document.title = 'كل حملاتك الإعلانية المدفوعة في مكان واحد — CampaignsHub'

    renderHook(() => useSectionTitle(appNavGroups), { wrapper: at('/app/analytics') })

    expect(document.title).toBe('التحليلات — CampaignsHub')
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
})
