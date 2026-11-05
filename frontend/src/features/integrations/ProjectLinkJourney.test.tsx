import { beforeEach, describe, expect, it, vi } from 'vitest'
import { act, fireEvent, screen, waitFor, within } from '@testing-library/react'
import { Route, Routes, useLocation } from 'react-router-dom'
import { ProjectLinkFlow } from './ProjectLinkFlow'
import { AUTO_RETURN_SECONDS, LinkSuccess, moneyChipText } from './LinkSuccess'
import { ProjectIntegrationsPage } from '@/features/projects/ProjectIntegrationsPage'
import type { ConnectionHub as Hub, HubConnection, ProjectLink, ProjectLinkAccount } from './api'
import { renderWithProviders, signInWith } from '@/test/utils'

/**
 * INTEG-PROJECT-LINK-001 (Owner 2026-11-06) — «الأساس ربط المصدر بالمشروع».
 *
 * project (fixed) → platform → authorisation → organisation → account → «تم الربط» → back to the project.
 * One journey, started from the project page and ended on it; the project is never asked for inside it,
 * and what a provider's consent screen returns is resumed only on the SERVER's word.
 */

const hub = vi.hoisted(() => ({ data: { connections: [], connectable: [] } as Hub }))
const link = vi.hoisted(() => ({ data: null as ProjectLink | null, error: false, asked: [] as Array<[string, string]> }))
const calls = vi.hoisted(() => ({
  started: [] as Array<{ provider: string; projectId?: string | null; portal?: string | null }>,
  keyed: [] as Array<{ provider: string; projectId: string | null }>,
  wizard: [] as Array<{ connectionId: string; destinationProjectId: string | null }>,
}))

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return {
    ...actual,
    fetchConnectionHub: () => Promise.resolve(hub.data),
    fetchMetaApps: () => Promise.resolve({ default: 'live', apps: [{ key: 'live', available: true }] }),
    fetchProjectLink: (projectId: string, connectionId: string) => {
      link.asked.push([projectId, connectionId])
      return link.error || link.data === null ? Promise.reject(new Error('not found')) : Promise.resolve(link.data)
    },
    startPlatformOAuth: (provider: string, _ws?: string | null, projectId?: string | null, _app?: string | null, portal?: string | null) => {
      calls.started.push({ provider, projectId, portal })
      return Promise.resolve({ authorization_url: `https://platform.test/${provider}` })
    },
    connectWithApiKey: (provider: string, _key: string, projectId: string | null) => {
      calls.keyed.push({ provider, projectId })
      return Promise.resolve({ connection: 'c-key' })
    },
    removeConnection: () => Promise.resolve({ removed: true, disabled_bindings: 0 }),
  }
})

// The organisation and account picker is the centre's own wizard, proven in its own tests.
vi.mock('./ConnectionWizard', () => ({
  ConnectionWizard: (props: { connectionId: string; destinationProjectId: string | null; onStepChange?: (step: string) => void }) => {
    calls.wizard.push({ connectionId: props.connectionId, destinationProjectId: props.destinationProjectId })
    return <button type="button" data-testid="wizard-stub" onClick={() => props.onStepChange?.('done')}>wizard</button>
  },
}))

vi.mock('@/features/projects/api', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  listProjects: () => Promise.resolve([
    { id: 'p-1', name: 'Razzah Avenue', client_workspace_id: 'ws-1' },
    { id: 'p-2', name: 'Nasel', client_workspace_id: 'ws-2' },
  ]),
  listProjectBindings: () => Promise.resolve([]),
  listProjectTasks: () => Promise.resolve({ tasks: [], total: 0 }),
}))
vi.mock('@/features/campaigns/api', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  listExternalCampaigns: () => Promise.resolve([]),
}))
vi.mock('@/features/projects/PlatformIntegrationsPanel', () => ({ PlatformIntegrationsPanel: () => null }))
vi.mock('@/features/projects/ProjectSyncHistory', () => ({ ProjectSyncHistory: () => null }))
vi.mock('@/features/commerce/ProjectStores', () => ({ ProjectStores: () => null }))

const META = { key: 'meta', label: 'Meta Ads', label_ar: 'ميتا', kind: 'advertising', has_parent: true, auth: 'oauth' } as const
const OPENAI = { key: 'openai_ads', label: 'OpenAI Ads', label_ar: 'إعلانات ChatGPT', kind: 'advertising', has_parent: false, auth: 'api_key' } as const

function connection(over: Partial<HubConnection> = {}): HubConnection {
  return {
    id: 'c-1', provider: 'meta', label: 'Meta Ads', label_ar: 'ميتا', connection_name: 'Meta',
    authorised_by: { name: 'Agency Owner', email: 'owner@campaignshub.io' },
    provider_account: { label: 'Sara Meta', email: 'sara@meta.test', fetched_at: null },
    client_workspace_id: null, removed_at: null, abandoned: false, authorised_at: null,
    selected_accounts: 1, discovered_accounts: 40, connection_state: 'CONNECTED', sync_state: 'SUCCEEDED',
    needs_attention: false, last_success_at: null, next_sync_at: null, projects: [], has_parent: true,
    discovery_blocked_reason: null, token_expires_at: null,
    ...over,
  }
}

function account(over: Partial<ProjectLinkAccount> = {}): ProjectLinkAccount {
  return {
    id: 'a-1', name: 'Riyadh Retail', external_id: 'act_1', account_type: 'ad_account', organisation: 'Acme Business',
    currency: 'SAR', money: { state: 'converted', rate: 0.2667, rate_date: '2026-06-01' }, health: 'pending_first_sync', last_synced_at: null,
    ...over,
  }
}

function projectLink(over: Partial<ProjectLink> = {}): ProjectLink {
  return {
    pending: true,
    project: { id: 'p-1', name: 'Razzah Avenue' },
    connection: { id: 'c-1', provider: 'meta', label: 'Meta Ads', label_ar: 'ميتا', status: 'connected', has_parent: true, platform_account: { label: 'Sara Meta', email: 'sara@meta.test' } },
    reporting_currency: 'USD',
    accounts: [account()],
    ...over,
  }
}

beforeEach(() => {
  hub.data = { connections: [], connectable: [META, OPENAI] }
  link.data = null
  link.error = false
  link.asked = []
  calls.started = []
  calls.keyed = []
  calls.wizard = []
  signInWith(['projects.view', 'integrations.view', 'integrations.connect'])
})

describe('the project link journey: platform and authorisation', () => {
  it('states the project, never asks for it, and names the steps in one word each', async () => {
    renderWithProviders(<ProjectLinkFlow projectId="p-1" onClose={() => {}} onReturn={() => {}} />, { locale: 'ar' })

    await waitFor(() => expect(screen.getByTestId('link-project')).toHaveTextContent('Razzah Avenue'))
    expect(within(screen.getByTestId('link-steps')).getAllByRole('listitem').map((li) => li.textContent?.replace(/\d+\s*/, '')))
      .toEqual(['منصة', 'دخول', 'منظمة', 'حساب', 'تم'])
    expect(screen.getByTestId('link-step-platform')).toHaveAttribute('aria-current', 'step')
    expect(screen.getByTestId('link-next')).toHaveTextContent('التالي')

    // No project picker anywhere in the journey.
    expect(screen.queryByTestId('picker-project')).toBeNull()
    expect(screen.queryByTestId('flow-destination')).toBeNull()
    expect(screen.getByTestId('project-link-flow').textContent).not.toMatch(/[٠-٩]/)
  })

  it('advances with one «التالي», or Enter, and only once a platform is chosen', async () => {
    renderWithProviders(<ProjectLinkFlow projectId="p-1" onClose={() => {}} onReturn={() => {}} />)

    const next = await screen.findByTestId('link-next')
    await screen.findByTestId('link-platform-meta')
    expect(next).toBeDisabled()

    fireEvent.click(screen.getByTestId('link-platform-meta'))
    expect(next).toBeEnabled()
    fireEvent.submit(screen.getByTestId('project-link-flow'))

    // Nothing to reuse is not a question: straight to the platform's sign-in, project fixed.
    expect(await screen.findByTestId('flow-step-login')).toBeInTheDocument()
    expect(await screen.findByTestId('flow-project-fixed')).toHaveTextContent('Razzah Avenue')
    expect(screen.queryByTestId('flow-destination')).toBeNull()

    fireEvent.click(screen.getByTestId('flow-authorise'))
    await waitFor(() => expect(calls.started).toEqual([{ provider: 'meta', projectId: 'p-1', portal: 'app' }]))
  })

  /**
   * Owner 2026-11-06: «وكل مشروع بنفس العملية نفسها». A working authorisation for the same platform is
   * never offered for reuse — each project's link signs in at the platform, for that project.
   */
  it('never reuses an existing authorisation: every link signs in for its own project', async () => {
    hub.data = { connections: [connection()], connectable: [META] }
    renderWithProviders(<ProjectLinkFlow projectId="p-1" provider={META} onClose={() => {}} onReturn={() => {}} />)

    expect(await screen.findByTestId('flow-step-login')).toBeInTheDocument()
    expect(await screen.findByTestId('flow-project-fixed')).toHaveTextContent('Razzah Avenue')
    expect(screen.queryByTestId('wizard-stub')).toBeNull()
    expect(document.body).not.toHaveTextContent('sara@meta.test')
    expect(calls.wizard).toEqual([])

    fireEvent.click(screen.getByTestId('flow-authorise'))
    await waitFor(() => expect(calls.started).toEqual([{ provider: 'meta', projectId: 'p-1', portal: 'app' }]))
  })

  it('asks an API-key platform for its key instead of a sign-in, for the same fixed project', async () => {
    renderWithProviders(<ProjectLinkFlow projectId="p-2" provider={OPENAI} onClose={() => {}} onReturn={() => {}} />)

    const key = await screen.findByTestId('flow-api-key')
    expect(screen.queryByTestId('flow-authorise')).toBeNull()
    expect(await screen.findByTestId('flow-project-fixed')).toHaveTextContent('Nasel')

    fireEvent.change(key, { target: { value: 'sk-ads-test' } })
    fireEvent.click(screen.getByTestId('flow-connect-key'))
    await waitFor(() => expect(calls.keyed).toEqual([{ provider: 'openai_ads', projectId: 'p-2' }]))
  })

  it('continues on the connection the sign-in came back with, ends on «تم», and returns to the project', async () => {
    link.data = projectLink()
    const onReturn = vi.fn()
    renderWithProviders(
      <ProjectLinkFlow projectId="p-1" resume={{ id: 'c-1', provider: 'meta', has_parent: true, projects: [] }} onClose={() => {}} onReturn={onReturn} />,
    )

    fireEvent.click(await screen.findByTestId('wizard-stub'))
    expect(calls.wizard.at(-1)).toEqual({ connectionId: 'c-1', destinationProjectId: 'p-1' })

    expect(await screen.findByTestId('link-success-title')).toHaveTextContent('Linked')
    expect(link.asked.at(-1)).toEqual(['p-1', 'c-1'])
    fireEvent.click(screen.getByTestId('link-return'))
    expect(onReturn).toHaveBeenCalledTimes(1)
  })
})

describe('the project link journey: «تم الربط»', () => {
  it('draws the link as a chain — project, platform and its platform account, organisation, accounts', async () => {
    link.data = projectLink()
    renderWithProviders(<LinkSuccess projectId="p-1" connectionId="c-1" onReturn={() => {}} />, { locale: 'ar' })

    expect(await screen.findByTestId('link-success-title')).toHaveTextContent('تم الربط')
    const chain = screen.getByTestId('link-chain')
    expect(within(chain).getByTestId('link-fact-project')).toHaveTextContent('Razzah Avenue')
    expect(within(chain).getByTestId('link-fact-platform')).toHaveTextContent('ميتا')
    expect(within(chain).getByTestId('link-fact-platform-account')).toHaveTextContent('sara@meta.test')
    expect(within(chain).getByTestId('link-fact-organisation')).toHaveTextContent('Acme Business')
    expect(within(chain).getByTestId('link-fact-accounts')).toHaveTextContent('1')
    expect(screen.getByTestId('link-success').textContent).not.toMatch(/[٠-٩]/)
  })

  it('marks each account\'s money as a code chip: as billed, converted to USD, or withheld — never assumed', async () => {
    link.data = projectLink({
      accounts: [
        account({ id: 'usd', name: 'Dollars', currency: 'USD', money: { state: 'reporting', rate: 1, rate_date: null }, health: 'healthy' }),
        account({ id: 'sar', name: 'Riyals' }),
        account({ id: 'eur', name: 'Euros', currency: 'EUR', money: { state: 'withheld_no_rate', rate: null, rate_date: null } }),
        account({ id: 'none', name: 'Unknown', currency: null, money: { state: 'withheld_unknown', rate: null, rate_date: null } }),
      ],
    })
    renderWithProviders(<LinkSuccess projectId="p-1" connectionId="c-1" onReturn={() => {}} />, { locale: 'ar' })

    expect(await screen.findByTestId('link-account-money-usd')).toHaveTextContent('USD')
    expect(screen.getByTestId('link-account-money-sar')).toHaveTextContent('SAR → USD')
    expect(screen.getByTestId('link-account-money-sar')).toHaveAttribute('title', 'تُحوَّل المبالغ ويُحفظ الأصل')
    expect(screen.getByTestId('link-account-money-eur')).toHaveTextContent('EUR ✕ USD')
    expect(screen.getByTestId('link-account-money-eur')).toHaveAttribute('title', 'لا سعر صرف: المبالغ محجوبة')
    expect(screen.getByTestId('link-account-money-none')).toHaveTextContent('?')
    expect(screen.getByTestId('link-account-money-none')).toHaveAttribute('title', 'العملة غير معروفة: المبالغ محجوبة')
    expect(moneyChipText(account({ currency: null, money: { state: 'withheld_unknown', rate: null, rate_date: null } }), 'USD')).not.toContain('USD')

    // The first sync is a chip, in the product's own words for it.
    expect(screen.getByTestId('link-account-sync-sar')).toHaveTextContent('بانتظار أول مزامنة')
    expect(screen.getByTestId('link-account-sync-usd')).toHaveTextContent('تعمل')
  })

  it('returns to the project by itself after a few seconds, unless the reader stays', async () => {
    link.data = projectLink()
    vi.useFakeTimers({ shouldAdvanceTime: true })
    try {
      const onReturn = vi.fn()
      const { unmount } = renderWithProviders(<LinkSuccess projectId="p-1" connectionId="c-1" onReturn={onReturn} />)
      await screen.findByTestId('link-success-title')
      expect(screen.getByTestId('link-auto-return')).toHaveTextContent(String(AUTO_RETURN_SECONDS))

      // One tick per second, each re-rendering before the next is scheduled.
      for (let i = 0; i <= AUTO_RETURN_SECONDS + 1; i++) await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
      expect(onReturn).toHaveBeenCalled()
      unmount()

      // Any press on the screen means «I am reading»: the countdown goes and nothing leaves by itself.
      const stays = vi.fn()
      renderWithProviders(<LinkSuccess projectId="p-1" connectionId="c-1" onReturn={stays} />)
      fireEvent.pointerDown(await screen.findByTestId('link-success'))
      for (let i = 0; i <= AUTO_RETURN_SECONDS + 3; i++) await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
      expect(stays).not.toHaveBeenCalled()
      expect(screen.queryByTestId('link-auto-return')).toBeNull()
    } finally {
      vi.useRealTimers()
    }
  })

  it('still offers the way back when the summary cannot be read', async () => {
    link.error = true
    const onReturn = vi.fn()
    renderWithProviders(<LinkSuccess projectId="p-1" connectionId="c-1" onReturn={onReturn} />)

    await screen.findByTestId('link-success-error')
    fireEvent.click(screen.getByTestId('link-return'))
    expect(onReturn).toHaveBeenCalled()
  })
})

function Address() {
  const location = useLocation()
  return <span data-testid="address">{location.pathname}{location.search}</span>
}

function page(route: string, locale: 'ar' | 'en' = 'en') {
  return renderWithProviders(
    <Routes>
      <Route path="/app/projects/:projectId/integrations" element={<><ProjectIntegrationsPage /><Address /></>} />
    </Routes>,
    { route, locale },
  )
}

describe('the project link journey: starts and ends on the project page', () => {
  it('offers «ربط مصدر» on the project page and opens the journey for THAT project', async () => {
    page('/app/projects/p-2/integrations', 'ar')

    const entry = await screen.findByTestId('project-link-source')
    expect(entry).toHaveTextContent('ربط مصدر')
    fireEvent.click(entry)

    expect(await screen.findByTestId('link-project')).toHaveTextContent('Nasel')
  })

  it('shows no entry point to a member who may not link', async () => {
    signInWith(['projects.view', 'integrations.view'])
    page('/app/projects/p-1/integrations')

    await screen.findByTestId('project-manage-sources')
    expect(screen.queryByTestId('project-link-source')).toBeNull()
  })

  it.each([
    ['denied', 'أُلغي التفويض. لم يُربط شيء.'],
    ['failed', 'تعذّر التفويض. لم يُربط شيء.'],
    ['invalid_state', 'انتهت صلاحية الرابط.'],
  ])('says what a %s return means in a few words, opens nothing, and offers one way forward', async (outcome, sentence) => {
    page(`/app/projects/p-1/integrations?provider=meta&outcome=${outcome}&reason=whatever`, 'ar')

    const notice = await screen.findByTestId('project-link-notice')
    expect(notice).toHaveTextContent(sentence)
    expect(notice).toHaveAttribute('data-outcome', outcome)
    expect(screen.queryByTestId('connection-flow')).toBeNull()
    expect(link.asked).toEqual([])
    // The address is cleaned, so a reload does not replay the return.
    await waitFor(() => expect(screen.getByTestId('address')).toHaveTextContent(/^\/app\/projects\/p-1\/integrations$/))

    fireEvent.click(screen.getByTestId('project-link-retry'))
    expect(await screen.findByTestId('project-link-flow')).toBeInTheDocument()
  })

  it('resumes after the provider only when the SERVER says the link is pending for this project', async () => {
    link.data = projectLink({ pending: true, accounts: [] })
    page('/app/projects/p-1/integrations?provider=meta&outcome=connected&accounts=40&link=c-1')

    await screen.findByTestId('wizard-stub')
    expect(link.asked[0]).toEqual(['p-1', 'c-1'])
    expect(calls.wizard.at(-1)).toEqual({ connectionId: 'c-1', destinationProjectId: 'p-1' })
    expect(screen.queryByTestId('project-link-notice')).toBeNull()
  })

  /** The address was edited to another project, or another person opened it: nothing resumes. */
  it('resumes nothing when the link was not opened for this project by this person', async () => {
    link.data = projectLink({ pending: false, accounts: [] })
    page('/app/projects/p-2/integrations?provider=meta&outcome=connected&link=c-1', 'ar')

    expect(await screen.findByTestId('project-link-notice')).toHaveTextContent('هذا الربط ليس لهذا المشروع.')
    expect(link.asked[0]).toEqual(['p-2', 'c-1'])
    expect(screen.queryByTestId('wizard-stub')).toBeNull()
    expect(calls.wizard).toEqual([])
  })

  it('resumes nothing for a connection the server does not know', async () => {
    link.error = true
    page('/app/projects/p-1/integrations?outcome=connected&link=c-unknown')

    expect(await screen.findByTestId('project-link-notice')).toHaveTextContent('This link is not for this project.')
    expect(screen.queryByTestId('wizard-stub')).toBeNull()
  })
})
