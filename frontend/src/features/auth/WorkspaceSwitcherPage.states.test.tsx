import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { WorkspaceSwitcherPage, shouldRetryMembershipLoad } from './WorkspaceSwitcherPage'
import { fetchMemberships, switchMembership } from './memberships'
import { useUi } from '@/stores/ui'

vi.mock('./memberships', async (orig) => ({
  ...(await orig<typeof import('./memberships')>()),
  fetchMemberships: vi.fn(),
  switchMembership: vi.fn(),
}))

const base = { role: 'owner', is_default: false, is_active: false, client_scope_ids: [] as string[], project_scope_ids: [] as string[] }
const app = { ...base, id: 'm-1', portal: 'app', portal_path: '/app', landing_path: '/app/dashboard', tenant: { id: 't-1', name: 'First Co', slug: 'first' } }
const agency = { ...base, id: 'm-2', portal: 'agency', portal_path: '/agency', landing_path: '/agency', tenant: { id: 't-2', name: 'Second Co', slug: 'second' } }

const answer = (memberships: unknown[], destination = '/switch', extra = {}) => ({
  memberships, current: null, destination, needs_switcher: memberships.length > 1, requested_portal: null, requested_portal_held: null, ...extra,
})
const http = (status: number, meta?: unknown) => Object.assign(new Error(`raw ${status}`), { response: { status, data: { message: 'RAW SERVER SENTENCE', meta } }, request: {} })
const offline = () => Object.assign(new Error('Network Error'), { request: {} })

function open(client = new QueryClient()) {
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/switch']}>
        <Routes>
          <Route path="/switch" element={<WorkspaceSwitcherPage />} />
          <Route path="/app/dashboard" element={<p>app landing</p>} />
          <Route path="/admin" element={<p>console</p>} />
          <Route path="/signup/status" element={<p>registration status</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )

  return client
}

describe('WorkspaceSwitcherPage — WORKSPACE-SWITCH-001', () => {
  const assign = vi.fn()
  const original = window.location

  beforeEach(() => {
    Object.defineProperty(window, 'location', { configurable: true, value: { ...original, assign } })
    useUi.setState({ locale: 'ar' })
    vi.useFakeTimers({ shouldAdvanceTime: true })
  })

  afterEach(() => {
    vi.useRealTimers()
    Object.defineProperty(window, 'location', { configurable: true, value: original })
    window.localStorage.clear()
    assign.mockReset()
    vi.mocked(fetchMemberships).mockReset()
    vi.mocked(switchMembership).mockReset()
    useUi.setState({ locale: 'ar' })
  })

  it('claims nothing about several workspaces before the data has loaded', async () => {
    vi.mocked(fetchMemberships).mockReturnValue(new Promise(() => {}))
    open()

    expect(screen.queryByTestId('workspace-intro')).toBeNull()
    expect(document.body.textContent).not.toMatch(/أكثر من مساحة/)
  })

  it('401: confirms once, then goes to sign-in with the return path, leaving no cache and no stored selection', async () => {
    window.localStorage.setItem('campaign-hub-project-storage', '{"state":{"currentProjectId":"p-old"}}')
    vi.mocked(fetchMemberships).mockRejectedValue(http(401))
    const client = new QueryClient()
    client.setQueryData(['projects'], [{ id: 'p-old' }])
    open(client)

    await act(async () => { await vi.advanceTimersByTimeAsync(2_500) })

    await waitFor(() => expect(assign).toHaveBeenCalledWith('/login?reason=session&redirect=%2Fswitch'))
    expect(await screen.findByTestId('workspace-session-ended')).toBeInTheDocument()
    // Leaving clears the cache under a mounted query: that must not ask the dead session again.
    await act(async () => { await vi.advanceTimersByTimeAsync(30_000) })
    expect(fetchMemberships).toHaveBeenCalledTimes(2)
    expect(assign).toHaveBeenCalledTimes(1)
    expect(client.getQueryData(['projects'])).toBeUndefined()
    expect(window.localStorage.getItem('campaign-hub-project-storage')).toBeNull()
  })

  it('403: says it is a refusal, offers no retry, keeps the exits, and never prints the server sentence', async () => {
    vi.mocked(fetchMemberships).mockRejectedValue(http(403))
    open()

    expect(await screen.findByTestId('workspace-no-permission')).toBeInTheDocument()
    expect(fetchMemberships).toHaveBeenCalledTimes(1)
    expect(screen.queryByTestId('workspace-retry')).toBeNull()
    expect(screen.getByTestId('recovery-sign-out')).toBeInTheDocument()
    expect(document.body.textContent).not.toContain('RAW SERVER SENTENCE')
    expect(assign).not.toHaveBeenCalled()
  })

  it('5xx: retries by itself, says so, and recovers without a click', async () => {
    vi.mocked(fetchMemberships).mockRejectedValueOnce(http(503)).mockResolvedValueOnce(answer([app, agency]) as never)
    open()

    expect(await screen.findByTestId('workspace-retrying')).toHaveTextContent('2 من 4')
    await act(async () => { await vi.advanceTimersByTimeAsync(2_100) })

    expect(await screen.findByTestId('workspace-switcher')).toBeInTheDocument()
    expect(screen.getByTestId('workspace-intro')).toBeInTheDocument()
  })

  it('5xx that persists: stops after four attempts, explains, gives a support reference, and only a click asks again', async () => {
    vi.mocked(fetchMemberships).mockRejectedValue(http(500, { request_id: 'req_abc123' }))
    open()

    await act(async () => { await vi.advanceTimersByTimeAsync(18_000) })

    const panel = await screen.findByTestId('workspace-unavailable')
    expect(panel).toHaveAttribute('data-http-status', '500')
    expect(fetchMemberships).toHaveBeenCalledTimes(4)
    expect(screen.getByTestId('workspace-support-ref')).toHaveTextContent('req_abc123')
    expect(document.body.textContent).not.toContain('RAW SERVER SENTENCE')

    await act(async () => { await vi.advanceTimersByTimeAsync(120_000) })
    expect(fetchMemberships).toHaveBeenCalledTimes(4)

    vi.mocked(fetchMemberships).mockResolvedValueOnce(answer([app, agency]) as never)
    fireEvent.click(screen.getByTestId('workspace-retry'))
    expect(await screen.findByTestId('workspace-switcher')).toBeInTheDocument()
  })

  it('offline: names the connection, not the server, in the reader\'s language', async () => {
    useUi.setState({ locale: 'en' })
    vi.mocked(fetchMemberships).mockRejectedValue(offline())
    open()

    await act(async () => { await vi.advanceTimersByTimeAsync(18_000) })

    expect(await screen.findByTestId('workspace-offline')).toHaveTextContent('Check your connection')
  })

  it('0 memberships is an answer, not a failure: the honest empty state with its exits and no error', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(answer([], '/onboarding') as never)
    open()

    expect(await screen.findByTestId('no-workspace')).toBeInTheDocument()
    expect(screen.getByTestId('recovery-onboarding')).toBeInTheDocument()
    expect(screen.queryByRole('alert')).toBeNull()
  })

  it('approved and awaiting payment, no workspace: goes to their own status, shows no list, and does not bounce back', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(answer([], '/signup/status?request=r-1', { pending_registration: { id: 'r-1', state: 'approved_awaiting_payment' } }) as never)
    open()

    expect(await screen.findByText('registration status')).toBeInTheDocument()
    expect(fetchMemberships).toHaveBeenCalledTimes(1)
  })

  it('the platform owner holds no workspace by design and is taken to the console, not told to finish setting up', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(answer([], '/admin') as never)
    open()

    expect(await screen.findByText('console')).toBeInTheDocument()
    expect(screen.queryByTestId('no-workspace')).toBeNull()
  })

  it('1 membership goes straight through, the waived-and-active case included: it is a membership like any other', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(answer([app], '/app/dashboard') as never)
    open()

    expect(await screen.findByText('app landing')).toBeInTheDocument()
  })

  it('many memberships are a choice, and a restricted one says it is restricted', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(answer([app, { ...agency, client_scope_ids: ['c-1', 'c-2'] }]) as never)
    open()

    expect(await screen.findByTestId('workspace-switcher')).toBeInTheDocument()
    expect(screen.getByTestId('switch-to-agency')).toHaveTextContent(/محدّد/)
    expect(screen.getByTestId('switch-to-app')).not.toHaveTextContent(/محدّد/)
  })

  it('a refused switch says so in the reader\'s words, prints no server sentence, and re-reads the list', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(answer([app, agency]) as never)
    vi.mocked(switchMembership).mockRejectedValue(http(403))
    open()

    fireEvent.click(await screen.findByTestId('switch-to-agency'))

    expect(await screen.findByTestId('workspace-switch-error')).toHaveTextContent('لم تعد هذه المساحة متاحة')
    expect(document.body.textContent).not.toContain('RAW SERVER SENTENCE')
    await waitFor(() => expect(fetchMemberships).toHaveBeenCalledTimes(2))
    expect(assign).not.toHaveBeenCalled()
  })

  it('a session that died during the switch goes to sign-in rather than offering the choice again', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(answer([app, agency]) as never)
    vi.mocked(switchMembership).mockRejectedValue(http(401))
    open()

    fireEvent.click(await screen.findByTestId('switch-to-agency'))

    await waitFor(() => expect(assign).toHaveBeenCalledWith('/login?reason=session&redirect=%2Fswitch'))
  })

  it('switching across two workspaces drops the first one\'s cache and selection and tells the other tabs', async () => {
    window.localStorage.setItem('campaign-hub-project-storage', '{"state":{"currentProjectId":"p-old"}}')
    window.localStorage.setItem('campaign-hub-agency-client', '{"state":{"clientId":"c-old"}}')
    vi.mocked(fetchMemberships).mockResolvedValue(answer([app, agency]) as never)
    vi.mocked(switchMembership).mockResolvedValue({ current: agency as never, destination: '/agency' })
    const client = new QueryClient()
    client.setQueryData(['clients', 'list'], [{ name: 'First Co client' }])
    open(client)

    fireEvent.click(await screen.findByTestId('switch-to-agency'))

    await waitFor(() => expect(assign).toHaveBeenCalledWith('/agency'))
    expect(vi.mocked(switchMembership).mock.calls[0][0]).toBe('m-2')
    expect(client.getQueryData(['clients', 'list'])).toBeUndefined()
    expect(window.localStorage.getItem('campaign-hub-project-storage')).toBeNull()
    expect(window.localStorage.getItem('campaign-hub-agency-client')).toBeNull()
    expect(JSON.parse(window.localStorage.getItem('chub:workspace-switched') ?? '{}').destination).toBe('/agency')
  })

  it('a failed background refresh does not take away a list that already loaded', async () => {
    vi.mocked(fetchMemberships).mockRejectedValue(http(502))
    const client = new QueryClient()
    client.setQueryData(['memberships'], answer([app, agency]))
    open(client)

    await act(async () => { await vi.advanceTimersByTimeAsync(18_000) })

    expect(await screen.findByTestId('workspace-stale-note')).toBeInTheDocument()
    expect(screen.getByTestId('workspace-switcher')).toBeInTheDocument()
    expect(screen.queryByTestId('workspace-unavailable')).toBeNull()
  })

  it('the retry budget is bounded for every kind of failure', () => {
    expect(shouldRetryMembershipLoad(0, http(403))).toBe(false)
    expect(shouldRetryMembershipLoad(0, http(401))).toBe(true)
    expect(shouldRetryMembershipLoad(1, http(401))).toBe(false)
    expect(shouldRetryMembershipLoad(2, http(500))).toBe(true)
    expect(shouldRetryMembershipLoad(3, http(500))).toBe(false)
    expect(shouldRetryMembershipLoad(3, offline())).toBe(false)
  })
})
