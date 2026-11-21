import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { ProjectLinkFlow } from './ProjectLinkFlow'
import { SourcePlatformRows } from './SourcePlatformRows'
import type { ConnectionHub as Hub, LinkSourceOption, ProjectLink } from './api'
import type { StoreProvider } from '@/features/commerce/api'
import type { MeasurementProvider } from '@/features/measurement/api'
import { renderWithProviders, signInWith } from '@/test/utils'

/**
 * INTEG-PROJECT-LINK-STORES-GA4-001 (Owner 2026-11-06) — سلة, زد and GA4 are linked the way an ad platform is:
 *
 *     project (fixed) → منصة → the platform's own page → متجر / خاصية → «تم» → back to the project
 *
 * The same flow, the same list of platforms, one «التالي» per step, and nothing asked about the project. What
 * the sign-in reaches is asked for the project's own connection; only the confirmed one is sent to be stored.
 */

const hub = vi.hoisted(() => ({ data: { connections: [], connectable: [] } as Hub }))
const boards = vi.hoisted(() => ({ stores: [] as StoreProvider[], measurement: [] as MeasurementProvider[], asked: [] as Array<[string, string | null | undefined]> }))
const directory = vi.hoisted(() => ({ kind: 'commerce' as 'commerce' | 'measurement', sources: [] as LinkSourceOption[], refuse: false }))
const calls = vi.hoisted(() => ({
  stores: [] as Array<{ provider: string; workspace?: string | null; journey?: { projectId: string; returnPortal: string } | null }>,
  measurement: [] as Array<{ provider: string; workspace?: string | null; journey?: { projectId: string; returnPortal: string } | null }>,
  ads: [] as string[],
  listed: [] as Array<{ projectId: string; connectionId: string; q?: string; page?: number }>,
  confirmed: [] as Array<{ projectId: string; connectionId: string; externalId: string }>,
  removed: [] as Array<{ id: string; unconfirmedOnly?: boolean }>,
}))
const link = vi.hoisted(() => ({ data: null as ProjectLink | null }))

vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  fetchConnectionHub: () => Promise.resolve(hub.data),
  fetchMetaApps: () => Promise.resolve({ default: 'live', apps: [{ key: 'live', available: true }] }),
  fetchProjectLink: () => (link.data === null ? Promise.reject(new Error('not found')) : Promise.resolve(link.data)),
  startPlatformOAuth: (provider: string) => { calls.ads.push(provider); return Promise.resolve({ authorization_url: `https://platform.test/${provider}` }) },
  removeConnection: (id: string, options: { unconfirmedOnly?: boolean } = {}) => {
    calls.removed.push({ id, unconfirmedOnly: options.unconfirmedOnly })
    return Promise.resolve({ removed: true, disabled_bindings: 0 })
  },
  fetchLinkSources: (projectId: string, connectionId: string, params: { q?: string; page?: number } = {}) => {
    calls.listed.push({ projectId, connectionId, ...params })
    const needle = (params.q ?? '').toLowerCase()
    const all = directory.sources.filter((s) => s.name.toLowerCase().includes(needle) || (s.detail ?? '').toLowerCase().includes(needle))
    return Promise.resolve({ kind: directory.kind, sources: all, total: all.length, page: 1, per_page: 25 })
  },
  confirmLinkSource: (input: { projectId: string; connectionId: string; externalId: string }) => {
    calls.confirmed.push(input)
    return directory.refuse
      ? Promise.reject({ isAxiosError: true, response: { status: 409, data: { message: 'elsewhere' } } })
      : Promise.resolve({ account_id: 'a-1', relinked: false })
  },
}))

vi.mock('@/features/commerce/api', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  listStoreProviders: (projectId?: string | null) => { boards.asked.push(['stores', projectId]); return Promise.resolve(boards.stores) },
  startStoreOAuth: (provider: string, workspace?: string | null, journey?: { projectId: string; returnPortal: string } | null) => {
    calls.stores.push({ provider, workspace, journey })
    return Promise.resolve({ authorization_url: `https://store.test/${provider}` })
  },
}))
vi.mock('@/features/measurement/api', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  listMeasurementProviders: (projectId?: string | null) => { boards.asked.push(['measurement', projectId]); return Promise.resolve(boards.measurement) },
  startMeasurementOAuth: (provider: string, workspace?: string | null, journey?: { projectId: string; returnPortal: string } | null) => {
    calls.measurement.push({ provider, workspace, journey })
    return Promise.resolve({ authorization_url: `https://google.test/${provider}` })
  },
}))
vi.mock('@/features/projects/api', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  listProjects: () => Promise.resolve([
    { id: 'p-1', name: 'Razzah Avenue', client_workspace_id: 'ws-1' },
    { id: 'p-2', name: 'Nasel', client_workspace_id: 'ws-2' },
  ]),
}))

const META = { key: 'meta', label: 'Meta Ads', label_ar: 'ميتا', kind: 'advertising', has_parent: true, auth: 'oauth' } as const

const storeBoard = (key: string, state: StoreProvider['state'], stores: StoreProvider['stores'] = []): StoreProvider =>
  ({ key, label: key === 'salla' ? 'Salla' : 'Zid', state, connection_error: null, token_expires_at: null, stores, supports_abandoned_carts: key === 'salla' })
const ga4Board = (state: MeasurementProvider['state'], properties: MeasurementProvider['properties'] = []): MeasurementProvider =>
  ({ key: 'ga4', label: 'Google Analytics', label_ar: 'جوجل أناليتكس', state, connection_error: null, discovered_count: properties.length, selected_count: properties.length, properties })

const source = (over: Partial<LinkSourceOption>): LinkSourceOption => ({ external_id: 'store-9', name: 'متجر تجريبي', detail: 'demo.salla.sa', currency: 'SAR', linked: null, ...over })

function linked(provider: string, kind: 'commerce' | 'measurement', accountType: string): ProjectLink {
  return {
    pending: false,
    project: { id: 'p-1', name: 'Razzah Avenue' },
    connection: { id: 'c-9', provider, label: provider, label_ar: provider, status: 'connected', kind, has_parent: false, platform_account: null },
    reporting_currency: 'USD',
    accounts: [{
      id: 'a-1', name: 'متجر تجريبي', external_id: 'store-9', account_type: accountType, organisation: null, currency: 'SAR',
      money: { state: 'converted', rate: 0.2667, rate_date: '2026-06-01' }, health: 'pending_first_sync', last_synced_at: null,
    }],
  }
}

const stepWords = () => within(screen.getByTestId('link-steps')).getAllByRole('listitem').map((li) => li.textContent?.replace(/\d+\s*/, ''))

beforeEach(() => {
  hub.data = { connections: [], connectable: [META] }
  boards.stores = [storeBoard('salla', 'disconnected'), storeBoard('zid', 'disconnected')]
  boards.measurement = [ga4Board('disconnected')]
  boards.asked = []
  directory.kind = 'commerce'
  directory.sources = [source({})]
  directory.refuse = false
  link.data = null
  calls.stores = []
  calls.measurement = []
  calls.ads = []
  calls.listed = []
  calls.confirmed = []
  calls.removed = []
  signInWith(['projects.view', 'integrations.view', 'integrations.connect'])
})

describe('the project link journey: a store and a GA4 property are chosen from the same list', () => {
  it('offers سلة, زد and GA4 after the ad platforms, read through THIS project', async () => {
    renderWithProviders(<ProjectLinkFlow projectId="p-1" onClose={() => {}} onReturn={() => {}} />, { locale: 'ar' })

    await screen.findByTestId('link-platform-row-salla')
    const order = screen.getAllByTestId(/^link-platform-row-/).map((el) => el.getAttribute('data-testid'))
    expect(order).toEqual(['link-platform-row-meta', 'link-platform-row-salla', 'link-platform-row-zid', 'link-platform-row-ga4'])
    expect(screen.getByTestId('link-platform-row-salla')).toHaveTextContent('سلة')
    expect(screen.getByTestId('link-platform-row-ga4')).toHaveTextContent('الموقع')
    // Asked for this project and for no other; never the whole workspace.
    expect(boards.asked).toEqual(expect.arrayContaining([['stores', 'p-1'], ['measurement', 'p-1']]))
    expect(boards.asked.every(([, project]) => project === 'p-1')).toBe(true)
    // No project picker, no client picker: the project is stated and fixed.
    expect(screen.getByTestId('link-project')).toHaveTextContent('Razzah Avenue')
    expect(document.querySelectorAll('select')).toHaveLength(0)
  })

  it('does not offer a provider the platform operator has not finished setting up', async () => {
    boards.stores = [storeBoard('salla', 'awaiting_credentials'), storeBoard('zid', 'unavailable')]
    boards.measurement = [ga4Board('awaiting_credentials')]

    renderWithProviders(<ProjectLinkFlow projectId="p-1" onClose={() => {}} onReturn={() => {}} />, { locale: 'ar' })

    await screen.findByTestId('link-platform-row-meta')
    for (const key of ['salla', 'zid', 'ga4']) expect(screen.queryByTestId(`link-platform-row-${key}`)).not.toBeInTheDocument()
  })

  it('names a store’s steps منصة ← متجر ← تم, and «التالي» leaves for the platform’s own page for this project', async () => {
    const assign = vi.fn()
    Object.defineProperty(window, 'location', { configurable: true, value: { ...window.location, pathname: '/agency/projects/p-1/integrations', assign } })

    renderWithProviders(<ProjectLinkFlow projectId="p-1" onClose={() => {}} onReturn={() => {}} />, { locale: 'ar' })

    fireEvent.click(await screen.findByTestId('link-platform-salla'))
    expect(stepWords()).toEqual(['منصة', 'متجر', 'تم'])

    fireEvent.click(screen.getByTestId('link-next'))

    await waitFor(() => expect(assign).toHaveBeenCalledWith('https://store.test/salla'))
    expect(calls.stores).toEqual([{ provider: 'salla', workspace: 'ws-1', journey: { projectId: 'p-1', returnPortal: 'agency' } }])
    // It is not dragged through the ad-account sign-in, and nothing else was started.
    expect(calls.ads).toEqual([])
    expect(calls.measurement).toEqual([])
    expect(screen.queryByTestId('flow-step-login')).not.toBeInTheDocument()
  })

  it('names a property’s steps منصة ← خاصية ← تم, and «التالي» leaves for Google’s sign-in for this project', async () => {
    const assign = vi.fn()
    Object.defineProperty(window, 'location', { configurable: true, value: { ...window.location, pathname: '/app/projects/p-1/integrations', assign } })

    renderWithProviders(<ProjectLinkFlow projectId="p-1" onClose={() => {}} onReturn={() => {}} />, { locale: 'ar' })

    fireEvent.click(await screen.findByTestId('link-platform-ga4'))
    expect(stepWords()).toEqual(['منصة', 'خاصية', 'تم'])

    fireEvent.click(screen.getByTestId('link-next'))

    await waitFor(() => expect(assign).toHaveBeenCalledWith('https://google.test/ga4'))
    expect(calls.measurement).toEqual([{ provider: 'ga4', workspace: 'ws-1', journey: { projectId: 'p-1', returnPortal: 'app' } }])
    expect(calls.stores).toEqual([])
  })

  it('opens on the platform already marked when the reader pressed «ربط» on its row', async () => {
    renderWithProviders(
      <ProjectLinkFlow projectId="p-1" provider={{ key: 'zid', label: 'Zid', label_ar: 'زد', kind: 'commerce', has_parent: false, auth: 'oauth' }} onClose={() => {}} onReturn={() => {}} />,
      { locale: 'ar' },
    )

    expect(await screen.findByTestId('link-platform-zid')).toBeChecked()
    expect(stepWords()).toEqual(['منصة', 'متجر', 'تم'])
    expect(screen.getByTestId('link-next')).toBeEnabled()
  })
})

describe('the project link journey: the store the platform returns', () => {
  it('shows the one store, already marked, and one «التالي» confirms it and reaches «تم»', async () => {
    link.data = linked('salla', 'commerce', 'store')
    const onReturn = vi.fn()

    renderWithProviders(
      <ProjectLinkFlow projectId="p-1" resume={{ id: 'c-9', provider: 'salla', has_parent: false, projects: [], kind: 'commerce' }} onClose={() => {}} onReturn={onReturn} />,
      { locale: 'ar' },
    )

    const row = await screen.findByTestId('picker-source-store-9')
    expect(calls.listed[0]).toMatchObject({ projectId: 'p-1', connectionId: 'c-9' })
    expect(stepWords()).toEqual(['منصة', 'متجر', 'تم'])
    await waitFor(() => expect(row).toBeChecked())
    expect(screen.getByTestId('picker-currency-store-9')).toHaveTextContent('SAR')
    // One store is not a list: no search box.
    expect(screen.queryByTestId('picker-source-search')).not.toBeInTheDocument()
    // Nothing is sent to be stored before the reader confirms.
    expect(calls.confirmed).toEqual([])

    fireEvent.click(screen.getByTestId('link-next'))

    expect(await screen.findByTestId('link-success-title')).toHaveTextContent('تم الربط')
    expect(calls.confirmed).toEqual([{ projectId: 'p-1', connectionId: 'c-9', externalId: 'store-9' }])
    expect(screen.getByTestId('project-source-picker').textContent).not.toMatch(/[٠-٩]/)
    // The ending names what was linked as a store, and says what happens to its money.
    expect(screen.getByTestId('link-fact-accounts').closest('li')).toHaveAttribute('aria-label', 'المتاجر: 1')
    expect(await screen.findByTestId('link-account-money-a-1')).toHaveAttribute('data-money', 'converted')
    expect(screen.queryByTestId('link-fact-platform-account')).not.toBeInTheDocument()

    fireEvent.click(screen.getByTestId('link-return'))
    expect(onReturn).toHaveBeenCalledTimes(1)
  })

  it('shows a store without a stated currency with no currency chip at all', async () => {
    directory.sources = [source({ currency: null })]

    renderWithProviders(
      <ProjectLinkFlow projectId="p-1" resume={{ id: 'c-9', provider: 'zid', has_parent: false, projects: [], kind: 'commerce' }} onClose={() => {}} onReturn={() => {}} />,
      { locale: 'ar' },
    )

    await screen.findByTestId('picker-source-store-9')
    expect(screen.queryByTestId('picker-currency-store-9')).not.toBeInTheDocument()
  })

  it('«إلغاء» removes the authorisation nobody confirmed a store on', async () => {
    const onClose = vi.fn()

    renderWithProviders(
      <ProjectLinkFlow projectId="p-1" resume={{ id: 'c-9', provider: 'salla', has_parent: false, projects: [], kind: 'commerce' }} onClose={onClose} onReturn={() => {}} />,
      { locale: 'ar' },
    )

    await screen.findByTestId('picker-source-store-9')
    fireEvent.click(screen.getByTestId('picker-cancel'))

    await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1))
    expect(calls.removed).toEqual([{ id: 'c-9', unconfirmedOnly: true }])
    expect(calls.confirmed).toEqual([])
  })
})

describe('the project link journey: the property picker', () => {
  const properties = (): LinkSourceOption[] => [
    ...['Acme Store', 'Acme Blog', 'Acme App', 'Acme Help', 'Acme Careers', 'Acme Pay'].map((name, i) => source({ external_id: `11${i}`, name, detail: 'Acme Group', currency: null })),
    source({ external_id: '222', name: 'Nova Shop', detail: 'Nova Group', currency: null }),
    source({ external_id: '333', name: 'Taken Site', detail: 'Nova Group', currency: null, linked: 'elsewhere' }),
  ]

  const open = () => renderWithProviders(
    <ProjectLinkFlow projectId="p-1" resume={{ id: 'c-9', provider: 'ga4', has_parent: false, projects: [], kind: 'measurement' }} onClose={() => {}} onReturn={() => {}} />,
    { locale: 'ar' },
  )

  beforeEach(() => {
    directory.kind = 'measurement'
    directory.sources = properties()
  })

  it('lists only what this sign-in can read, marks nothing, and searches on the server as the reader types', async () => {
    open()

    await screen.findByTestId('picker-source-110')
    expect(stepWords()).toEqual(['منصة', 'خاصية', 'تم'])
    expect(screen.getAllByTestId(/^picker-source-\d+$/)).toHaveLength(8)
    // Several properties is a question: nothing is marked for the reader, and «التالي» waits.
    expect(screen.getAllByRole('radio').some((r) => (r as HTMLInputElement).checked)).toBe(false)
    expect(screen.getByTestId('link-next')).toBeDisabled()

    fireEvent.change(screen.getByTestId('picker-source-search'), { target: { value: 'nova' } })

    await waitFor(() => expect(calls.listed.some((c) => c.q === 'nova')).toBe(true))
    await waitFor(() => expect(screen.queryByTestId('picker-source-110')).not.toBeInTheDocument())
    expect(screen.getByTestId('picker-source-222')).toBeInTheDocument()
    expect(calls.listed.every((c) => c.projectId === 'p-1' && c.connectionId === 'c-9')).toBe(true)
  })

  it('shows a property another project reads and does not let it be chosen', async () => {
    open()

    const taken = await screen.findByTestId('picker-source-333')
    expect(taken).toBeDisabled()
    expect(taken.closest('label')).toHaveTextContent('مشروع آخر')
  })

  it('stores nothing until «التالي», then confirms exactly the chosen property', async () => {
    link.data = linked('ga4', 'measurement', 'ga4_property')
    open()

    fireEvent.click(await screen.findByTestId('picker-source-222'))
    expect(calls.confirmed).toEqual([])

    fireEvent.click(screen.getByTestId('link-next'))

    expect(await screen.findByTestId('link-success-title')).toBeInTheDocument()
    expect(calls.confirmed).toEqual([{ projectId: 'p-1', connectionId: 'c-9', externalId: '222' }])
    // A property states its currency on its first read: no money mark is drawn for it at the link.
    expect((await screen.findByTestId('link-fact-accounts')).closest('li')).toHaveAttribute('aria-label', 'الخصائص: 1')
    expect(screen.queryByTestId('link-account-money-a-1')).not.toBeInTheDocument()
  })

  it('says a refusal in two words and stays on the property step', async () => {
    directory.refuse = true
    open()

    fireEvent.click(await screen.findByTestId('picker-source-222'))
    fireEvent.click(screen.getByTestId('link-next'))

    expect(await screen.findByTestId('picker-refused')).toHaveTextContent('مرتبط بمشروع آخر')
    expect(screen.getByTestId('picker-sources')).toBeInTheDocument()
    expect(screen.queryByTestId('link-success-title')).not.toBeInTheDocument()
  })
})

describe('the project’s «المنصات»: stores and GA4 as rows with one «ربط»', () => {
  it('draws a row per provider and hands the journey the platform that was pressed', async () => {
    const onLink = vi.fn()
    renderWithProviders(<ul><SourcePlatformRows projectId="p-1" ar onLink={onLink} /></ul>, { locale: 'ar' })

    fireEvent.click(await screen.findByTestId('hub-platform-link-salla'))
    expect(onLink).toHaveBeenCalledWith(expect.objectContaining({ key: 'salla', kind: 'commerce' }))

    fireEvent.click(screen.getByTestId('hub-platform-link-ga4'))
    expect(onLink).toHaveBeenLastCalledWith(expect.objectContaining({ key: 'ga4', kind: 'measurement' }))

    expect(screen.getByTestId('hub-platform-link-zid')).toHaveTextContent('ربط')
    expect(boards.asked.every(([, project]) => project === 'p-1')).toBe(true)
  })

  it('offers nothing to press, and names no key, for a provider that is not set up', async () => {
    boards.stores = [storeBoard('salla', 'awaiting_credentials'), storeBoard('zid', 'unavailable')]
    boards.measurement = [ga4Board('awaiting_credentials')]

    const { container } = renderWithProviders(<ul><SourcePlatformRows projectId="p-1" ar onLink={() => {}} /></ul>, { locale: 'ar' })

    expect(await screen.findByTestId('hub-platform-unavailable-salla')).toHaveTextContent('غير متاح')
    for (const key of ['salla', 'zid', 'ga4']) expect(screen.queryByTestId(`hub-platform-link-${key}`)).not.toBeInTheDocument()
    expect(container.innerHTML).not.toContain('client_secret')
    expect(container.textContent ?? '').not.toMatch(/awaiting_credentials/)
  })

  it('counts what the project already reads and offers «إعادة الربط»', async () => {
    boards.stores = [
      storeBoard('salla', 'connected', [{
        id: 's1', external_id: 'store-9', name: 'متجر', project_id: 'p-1', project_name: 'Razzah Avenue', domain: null, currency: 'SAR',
        last_synced_at: null, counts: { products: 0, orders: 0, abandoned_carts: 0 }, last_run: null,
      }]),
      storeBoard('zid', 'disconnected'),
    ]

    renderWithProviders(<ul><SourcePlatformRows projectId="p-1" ar onLink={() => {}} /></ul>, { locale: 'ar' })

    expect(await screen.findByTestId('hub-platform-linked-salla')).toHaveTextContent('1')
    expect(screen.getByTestId('hub-platform-link-salla')).toHaveTextContent('إعادة الربط')
    expect(screen.queryByTestId('hub-platform-linked-zid')).not.toBeInTheDocument()
  })
})
