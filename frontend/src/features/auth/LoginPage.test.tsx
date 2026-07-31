import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { LoginPage } from './LoginPage'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, login: vi.fn() }
})

import { login } from './api'

/** AUTH-002: the /login brand panel adapts to the portal the user arrived from — content only, same auth. */
describe('LoginPage — adaptive brand panel (AUTH-002)', () => {
  afterEach(() => signOut())

  it('default panel = paid campaign management', () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })
    expect(screen.getByText('One platform for every paid campaign')).toBeInTheDocument()
    // The hook reads as one sentence even though its closing phrase is styled separately.
    expect(screen.getByRole('heading', { name: 'All your paid ad campaigns in one place' })).toBeInTheDocument()
  })

  it('?portal=influencer adapts the panel to influencer campaigns', () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login?portal=influencer', locale: 'en' })
    expect(screen.getByText('One platform for influencer campaigns')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: /Influencer and content campaigns in one place/i })).toBeInTheDocument()
  })

  it('?portal=agency keeps the promise but speaks to multi-client work', () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login?portal=agency', locale: 'en' })
    expect(screen.getByText('One platform for every client’s campaigns')).toBeInTheDocument()
    expect(screen.getByText('A separate space per client and project')).toBeInTheDocument()
    // The card carries a plain sentence, not just a capability name.
    expect(screen.getByText(/Each client’s data stays isolated/)).toBeInTheDocument()
  })

  it('a /client redirect adapts the panel to request tracking', () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login?redirect=%2Fclient', locale: 'en' })
    expect(screen.getByText('A secure portal for your requests')).toBeInTheDocument()
  })

  /** Every portal must be reachable, and each one must be a real link — not a decorative pill. */
  it('offers all four portals, marking the active one and linking the rest', () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login?portal=agency', locale: 'en' })
    const tabs = screen.getByTestId('login-portals')
    expect(tabs.querySelectorAll('a')).toHaveLength(4)
    expect(screen.getByTestId('login-portal-agency')).toHaveAttribute('aria-current', 'page')
    // Leaving a portal drops the parameter rather than inventing a "default" value.
    expect(screen.getByTestId('login-portal-default')).toHaveAttribute('href', '/login')
    expect(screen.getByTestId('login-portal-influencer')).toHaveAttribute('href', '/login?portal=influencer')
  })

  /** The panel never replaces the form: sign-in stays reachable from every portal. */
  it('keeps one auth engine behind every portal', () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login?portal=influencer', locale: 'en' })
    expect(screen.getByRole('button', { name: /Sign in/i })).toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: /email/i })).toBeInTheDocument()
  })
})

describe('LoginPage — the controls actually work', () => {
  beforeEach(() => { vi.clearAllMocks(); signOut() })
  afterEach(() => signOut())

  /**
   * "Keep me signed in" drives Auth::login($user, $remember) on the server. Holding it in local state and
   * never sending it would make the checkbox decorative, so the submitted payload is asserted directly.
   */
  it('sends the remember flag with the credentials', async () => {
    vi.mocked(login).mockResolvedValue({ id: '1', name: 'T', email: 't@t.dev' } as never)
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    fireEvent.change(screen.getByRole('textbox', { name: /email/i }), { target: { value: 't@t.dev' } })
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'secret123' } })
    fireEvent.click(screen.getByRole('button', { name: /Sign in/i }))

    await waitFor(() => expect(login).toHaveBeenCalled())
    // `portal: null` because a plain /login claims no portal — the server then decides the
    // destination from memberships alone (LOGIN-003).
    expect(vi.mocked(login).mock.calls[0][0]).toEqual({ email: 't@t.dev', password: 'secret123', remember: true, portal: null })
  })

  it('sends remember: false once the box is cleared', async () => {
    vi.mocked(login).mockResolvedValue({ id: '1', name: 'T', email: 't@t.dev' } as never)
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    fireEvent.click(screen.getByRole('checkbox'))
    fireEvent.change(screen.getByRole('textbox', { name: /email/i }), { target: { value: 't@t.dev' } })
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'secret123' } })
    fireEvent.click(screen.getByRole('button', { name: /Sign in/i }))

    await waitFor(() => expect(login).toHaveBeenCalled())
    expect(vi.mocked(login).mock.calls[0][0]).toEqual({ email: 't@t.dev', password: 'secret123', remember: false, portal: null })
  })

  /** Zero dead links: every secondary action points at a route that exists. */
  it('links forgot-password, create-account and request tracking to real routes', () => {
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })
    expect(screen.getByRole('link', { name: /Forgot/i })).toHaveAttribute('href', '/forgot-password')
    expect(screen.getByRole('link', { name: /Create an account/i })).toHaveAttribute('href', '/register')
    expect(screen.getByRole('link', { name: /Track my requests/i })).toHaveAttribute('href', '/portal/login')
  })
})
