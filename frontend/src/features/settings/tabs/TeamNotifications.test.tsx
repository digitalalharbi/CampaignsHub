import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { TeamNotifications } from './TeamNotifications'
import { renderWithProviders } from '@/test/utils'
import type { TeamNotifications as Data, TeamNotificationPerson } from '../api'

vi.mock('../api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, useTeamNotifications: vi.fn() }
})

import { useTeamNotifications } from '../api'

function person(over: Partial<TeamNotificationPerson> = {}): TeamNotificationPerson {
  return {
    user_id: 1,
    name: 'سارة',
    email: 'sara@example.com',
    roles: ['مدير'],
    portal: 'agency',
    projects: ['Acme · Q3 Launch'],
    categories: ['budget'],
    rhythms: { daily: true, weekly: false, alerts: true },
    arranged_by_manager: false,
    last_message: null,
    state: 'never_sent',
    ...over,
  }
}

function open(data: Partial<Data> = {}) {
  vi.mocked(useTeamNotifications).mockReturnValue({
    data: { people: [person()], email_provider_configured: true, available_categories: [], ...data },
    isLoading: false,
  } as ReturnType<typeof useTeamNotifications>)
  return renderWithProviders(<TeamNotifications />, { locale: 'ar' })
}

/**
 * MAIL-012 — the read a manager does when somebody says they never get the alerts.
 *
 * The assertions are about the states that look the same from outside. Everything else on this
 * screen is a listing, and a listing that renders is not worth a test.
 */
describe('the team notification board', () => {
  beforeEach(() => vi.mocked(useTeamNotifications).mockReset())

  /**
   * «Receives nothing» and «nothing sent yet» must not read as the same row.
   *
   * One is a settings mistake somebody should fix today; the other is an ordinary quiet week. A
   * table printing «—» for both would be read as the first every time.
   */
  it('separates a member who will never receive anything from one who is simply waiting', () => {
    open({ people: [person({ state: 'silent', categories: [], rhythms: { daily: false, weekly: false, alerts: false } })] })

    expect(screen.getByText('لا يصله شيء')).toBeInTheDocument()
    expect(screen.getByText(/لن تصله أي رسالة مهما حدث/)).toBeInTheDocument()
    expect(screen.queryByText('لم يُرسل شيء بعد')).not.toBeInTheDocument()
  })

  it('says a subscribed member with an empty ledger is waiting, not silenced', () => {
    open()

    expect(screen.getByText('لم يُرسل شيء بعد')).toBeInTheDocument()
    expect(screen.getByText(/ولم يحدث بعد ما يستحق رسالة/)).toBeInTheDocument()
  })

  /**
   * The missing provider is stated once, as a fact about the install.
   *
   * Twenty rows carrying the same warning reads as twenty problems rather than the single
   * configuration step it is.
   */
  it('explains the awaiting-credentials rows once, above the table', () => {
    open({ email_provider_configured: false, people: [person({ state: 'awaiting_credentials' })] })

    expect(screen.getByText('لا يوجد مزوّد بريد مربوط')).toBeInTheDocument()
    expect(screen.getByText('بانتظار ربط مزوّد البريد')).toBeInTheDocument()
  })

  it('says nothing about a provider when one is wired', () => {
    open()

    expect(screen.queryByText('لا يوجد مزوّد بريد مربوط')).not.toBeInTheDocument()
  })

  /** A client contact is on this board, and the row says which portal they are in. */
  it('names the portal beside the role, so a client contact is not read as a colleague', () => {
    open({ people: [person({ portal: 'portal', roles: ['Client Portal'] })] })

    expect(screen.getByText(/بوابة العميل/)).toBeInTheDocument()
  })

  /** Mail somebody did not choose is explained by the row that caused it. */
  it('marks a member who is on a recipient list', () => {
    open({ people: [person({ arranged_by_manager: true })] })

    expect(screen.getByText('مضاف من قائمة المستلمين')).toBeInTheDocument()
  })

  it('names the client beside the project, and the categories in words', () => {
    open()

    expect(screen.getByText('Acme · Q3 Launch')).toBeInTheDocument()
    expect(screen.getByText('الميزانية')).toBeInTheDocument()
  })
})
