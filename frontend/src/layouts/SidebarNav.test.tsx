import { beforeEach, describe, expect, it } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { Building2, LayoutDashboard, Megaphone } from 'lucide-react'
import { SidebarNav, type NavGroup } from './SidebarNav'
import { renderWithProviders } from '@/test/utils'

const GROUPS: NavGroup[] = [
  {
    key: 'overview', ar: 'لوحة', en: 'Dashboard', icon: LayoutDashboard,
    leaves: [{ to: '/app/dashboard', ar: 'لوحة', en: 'Dashboard', icon: LayoutDashboard, ent: 'dashboard' }],
  },
  {
    key: 'work', ar: 'العمل', en: 'Work', icon: Megaphone,
    leaves: [
      { to: '/app/campaigns', ar: 'الحملات', en: 'Campaigns', icon: Megaphone, ent: 'campaigns' },
      { to: '/app/clients', ar: 'العملاء', en: 'Clients', icon: Building2, ent: 'clients' },
    ],
  },
]

function render(route = '/app/dashboard', allow?: (l: { ent?: string }) => boolean) {
  return renderWithProviders(
    <SidebarNav groups={GROUPS} ar={false} label="Sections" storageKey="test.nav" allow={allow} />,
    { route, locale: 'en' },
  )
}

describe('SidebarNav', () => {
  beforeEach(() => localStorage.clear())

  /**
   * The rule the E2E suite caught me breaking: grouping must not put a section behind a click plus a
   * guess about which label holds it. Everything is reachable on arrival.
   */
  it('shows every section without expanding anything', () => {
    render()

    expect(screen.getByRole('link', { name: 'Dashboard' })).toBeVisible()
    expect(screen.getByRole('link', { name: 'Campaigns' })).toBeVisible()
    expect(screen.getByRole('link', { name: 'Clients' })).toBeVisible()
  })

  /** A group of one is a plain link — a disclosure triangle over a single item is noise. */
  it('renders a single-section group as a plain link', () => {
    render()

    expect(screen.queryByTestId('nav-group-overview')).not.toBeInTheDocument()
    expect(screen.getByTestId('nav-group-work')).toBeInTheDocument()
  })

  it('remembers a group the user collapsed', () => {
    const { unmount } = render()
    fireEvent.click(screen.getByTestId('nav-group-work'))
    expect(screen.queryByRole('link', { name: 'Campaigns' })).not.toBeInTheDocument()
    unmount()

    render()
    expect(screen.queryByRole('link', { name: 'Campaigns' })).not.toBeInTheDocument()
  })

  /**
   * …but never when the current page is inside it. A deep link must not land someone in a collapsed
   * rail with no indication of where they are.
   */
  it('opens the group holding the current page whatever the stored preference', () => {
    localStorage.setItem('test.nav', JSON.stringify(['work']))
    render('/app/campaigns')

    expect(screen.getByRole('link', { name: 'Campaigns' })).toBeVisible()
  })

  /** A group whose sections are all unentitled disappears rather than sitting empty. */
  it('drops a group left with no permitted sections', () => {
    render('/app/dashboard', (l) => l.ent !== 'campaigns' && l.ent !== 'clients')

    expect(screen.queryByTestId('nav-group-work')).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Dashboard' })).toBeVisible()
  })

  /** A corrupt preference must not break navigation — everything open is the safe answer. */
  it('ignores an unreadable stored preference', () => {
    localStorage.setItem('test.nav', 'not json')
    render()

    expect(screen.getByRole('link', { name: 'Campaigns' })).toBeVisible()
  })
})
