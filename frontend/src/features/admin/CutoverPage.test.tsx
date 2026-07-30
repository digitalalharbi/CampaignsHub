import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { CutoverPage } from './CutoverPage'
import type { CutoverReadiness, PortalConflict } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    fetchCutoverReadiness: vi.fn(),
    fetchPortalConflicts: vi.fn(),
    resolvePortalConflict: vi.fn(),
  }
})

import { fetchCutoverReadiness, fetchPortalConflicts, resolvePortalConflict } from './api'

function readiness(over: Partial<CutoverReadiness> = {}): CutoverReadiness {
  return {
    ready: true,
    blockers: [],
    open_conflicts: 0,
    legacy_sessions: 0,
    legacy_holders: [],
    parity: { checked: 4, mismatched: 0, mismatches: [] },
    last_checked_at: '2026-07-30T20:00:00+00:00',
    ...over,
  }
}

function conflict(over: Partial<PortalConflict> = {}): PortalConflict {
  return {
    id: 'c1', tenant_id: 't1', tenant_name: 'Acme Agency',
    contact_email: 'both@agency.test', contact_phone: null,
    reason: 'email_belongs_to_staff', client_ids: ['s1', 's2'],
    resolution: null, note: null, resolved_at: null,
    ...over,
  }
}

describe('CutoverPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(fetchCutoverReadiness).mockResolvedValue(readiness())
    vi.mocked(fetchPortalConflicts).mockResolvedValue({ conflicts: [], open: 0, safe_to_retire_legacy_engine: true })
  })

  /**
   * The page must never offer to perform the cutover. Retiring the engine is a reviewed code change,
   * and a button here would let someone do it by reading a green light.
   */
  it('offers no control that performs the cutover', async () => {
    renderWithProviders(<CutoverPage />, { route: '/admin/cutover', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('cutover-verdict')).toBeInTheDocument())
    expect(screen.queryByRole('button', { name: /retire|cut over|cutover/i })).not.toBeInTheDocument()
    expect(screen.getByText(/deliberately no button/)).toBeInTheDocument()
  })

  it('says it is safe only when nothing blocks', async () => {
    renderWithProviders(<CutoverPage />, { route: '/admin/cutover', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('cutover-verdict')).toBeInTheDocument())
    expect(screen.getByTestId('cutover-verdict')).toHaveTextContent('Conditions met')
  })

  /** Each blocker is named. "Not ready" without the reason is not actionable. */
  it('names every blocker rather than just refusing', async () => {
    vi.mocked(fetchCutoverReadiness).mockResolvedValue(readiness({
      ready: false,
      blockers: ['2 identity conflict(s) still open', '5 session(s) still depend on the legacy token'],
      open_conflicts: 2,
      legacy_sessions: 5,
    }))
    renderWithProviders(<CutoverPage />, { route: '/admin/cutover', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('cutover-verdict')).toBeInTheDocument())
    // Composed from the numbers rather than echoed from the server, so the sentence reads correctly
    // in both directions — a server English string inside an Arabic list strands its digits.
    const blockers = screen.getByTestId('cutover-blockers')
    expect(blockers).toHaveTextContent('2 identity conflict(s) still open')
    expect(blockers).toHaveTextContent('5 session(s) still depend on the legacy token')
  })

  /** A disagreement names the person — "3 mismatches" tells nobody whose portal would change. */
  it('names the contacts the engines disagree about', async () => {
    vi.mocked(fetchCutoverReadiness).mockResolvedValue(readiness({
      ready: false,
      blockers: ['1 contact(s) where the two engines disagree'],
      parity: { checked: 4, mismatched: 1, mismatches: [{ contact: 'drifted@test.dev', membership: ['a'], token: ['a', 'b'] }] },
    }))
    renderWithProviders(<CutoverPage />, { route: '/admin/cutover', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('parity-mismatches')).toBeInTheDocument())
    expect(screen.getByText('drifted@test.dev')).toBeInTheDocument()
  })

  /**
   * A token holder WITH a membership fixes itself on next sign-in; one WITHOUT needs a decision.
   * Those are different problems and the page must not present them as one list of names.
   */
  it('distinguishes a holder who upgrades themselves from one who is stuck', async () => {
    vi.mocked(fetchCutoverReadiness).mockResolvedValue(readiness({
      ready: false,
      blockers: ['2 session(s) still depend on the legacy token'],
      legacy_sessions: 2,
      legacy_holders: [
        { contact: 'fine@test.dev', expires_at: '2026-08-13T00:00:00+00:00', last_used_at: null, has_membership: true },
        { contact: 'stuck@test.dev', expires_at: null, last_used_at: null, has_membership: false },
      ],
    }))
    renderWithProviders(<CutoverPage />, { route: '/admin/cutover', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('legacy-holders')).toBeInTheDocument())
    expect(screen.getByText('upgrades on next sign-in')).toBeInTheDocument()
    expect(screen.getByText('no membership — needs a conflict resolved')).toBeInTheDocument()
  })

  /** Linking requires a reason before it will send. */
  it('will not link without a stated reason', async () => {
    vi.mocked(fetchPortalConflicts).mockResolvedValue({
      conflicts: [conflict()], open: 1, safe_to_retire_legacy_engine: false,
    })
    renderWithProviders(<CutoverPage />, { route: '/admin/cutover', locale: 'en' })

    fireEvent.click(await screen.findByRole('button', { name: /Same person/ }))

    const submit = await screen.findByRole('button', { name: /Link and grant/ })
    expect(submit).toBeDisabled()

    fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Confirmed by phone.' } })
    fireEvent.click(submit)

    await waitFor(() => expect(resolvePortalConflict).toHaveBeenCalledWith('c1', 'link', 'Confirmed by phone.'))
  })

  /** Separating grants nothing and needs no reason — it is the safe answer. */
  it('separates without demanding a reason', async () => {
    vi.mocked(fetchPortalConflicts).mockResolvedValue({
      conflicts: [conflict()], open: 1, safe_to_retire_legacy_engine: false,
    })
    renderWithProviders(<CutoverPage />, { route: '/admin/cutover', locale: 'en' })

    fireEvent.click(await screen.findByRole('button', { name: /Different people/ }))

    await waitFor(() => expect(resolvePortalConflict).toHaveBeenCalledWith('c1', 'separate', undefined))
  })

  /** The consequence is shown before the choice: how many spaces linking would grant. */
  it('shows how many spaces a link would grant, before the decision', async () => {
    vi.mocked(fetchPortalConflicts).mockResolvedValue({
      conflicts: [conflict()], open: 1, safe_to_retire_legacy_engine: false,
    })
    renderWithProviders(<CutoverPage />, { route: '/admin/cutover', locale: 'en' })

    expect(await screen.findByText(/2 space\(s\)/)).toBeInTheDocument()
  })
})
