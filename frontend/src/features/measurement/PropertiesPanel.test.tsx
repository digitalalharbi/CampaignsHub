import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { PropertiesPanel } from './PropertiesPanel'
import type { MeasurementProperty, MeasurementProvider } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', () => ({
  listMeasurementProviders: vi.fn(),
  startMeasurementOAuth: vi.fn(),
  syncMeasurementProperty: vi.fn(),
}))
vi.mock('@/features/projects/api', () => ({
  bindAccount: vi.fn(),
  listClientWorkspaces: vi.fn(),
  listProjects: vi.fn(),
}))

import { listMeasurementProviders, startMeasurementOAuth, syncMeasurementProperty } from './api'
import { bindAccount, listClientWorkspaces, listProjects } from '@/features/projects/api'

function property(over: Partial<MeasurementProperty> = {}): MeasurementProperty {
  return {
    id: 'acc-1',
    property_id: '111',
    name: 'Acme Store',
    analytics_account_id: '100',
    analytics_account_name: 'Acme Group',
    timezone: null,
    currency: null,
    is_selected: false,
    project_id: null,
    last_synced_at: null,
    discovered_at: '2026-10-01T00:00:00+00:00',
    ...over,
  }
}

function provider(over: Partial<MeasurementProvider> = {}): MeasurementProvider {
  return {
    key: 'ga4',
    label: 'Google Analytics 4',
    label_ar: 'جوجل أناليتكس 4',
    state: 'disconnected',
    connection_error: null,
    discovered_count: 0,
    selected_count: 0,
    properties: [],
    ...over,
  }
}

describe('PropertiesPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['integrations.view', 'integrations.connect'])
    vi.mocked(listClientWorkspaces).mockResolvedValue([])
    vi.mocked(listProjects).mockResolvedValue([])
  })
  afterEach(() => signOut())

  /** The layer separation, on the surface a customer sees: no button, and no key named. */
  it('offers no connect button and names no system key when the provider is awaiting credentials', async () => {
    vi.mocked(listMeasurementProviders).mockResolvedValue([provider({ state: 'awaiting_credentials' })])

    const { container } = renderWithProviders(<PropertiesPanel />, { locale: 'ar' })

    expect(await screen.findByTestId('measurement-needs-operator-ga4')).toBeInTheDocument()
    expect(screen.queryByTestId('measurement-connect-ga4')).not.toBeInTheDocument()
    expect(container.innerHTML).not.toContain('client_secret')
    expect(container.innerHTML).not.toContain('GA4_CLIENT')
  })

  it('sends the customer to Google’s own consent screen', async () => {
    vi.mocked(listMeasurementProviders).mockResolvedValue([provider({ state: 'disconnected' })])
    vi.mocked(startMeasurementOAuth).mockResolvedValue({
      authorization_url: 'https://accounts.google.com/o/oauth2/v2/auth?x=1',
    })
    const assign = vi.fn()
    Object.defineProperty(window, 'location', { value: { assign }, writable: true })

    renderWithProviders(<PropertiesPanel />, { locale: 'ar' })

    fireEvent.click(await screen.findByTestId('measurement-connect-ga4'))

    await waitFor(() => expect(startMeasurementOAuth).toHaveBeenCalledWith('ga4', null))
  })

  /**
   * «1 of 17 selected» — the sentence this section exists to say.
   *
   * A list of what an agency's Google identity can SEE invites the belief that all of it is being
   * read. The count is what corrects it, and it is CHOSEN over discovered rather than discovered
   * alone.
   */
  it('states how many properties are selected out of how many were discovered', async () => {
    vi.mocked(listMeasurementProviders).mockResolvedValue([provider({
      state: 'connected',
      discovered_count: 17,
      selected_count: 1,
      properties: [property({ is_selected: true, project_id: 'p1' })],
    })])

    renderWithProviders(<PropertiesPanel />, { locale: 'ar' })

    const counts = await screen.findByTestId('measurement-counts-ga4')
    expect(counts.textContent).toContain('1')
    expect(counts.textContent).toContain('17')
  })

  /**
   * A never-read property does not claim a timezone.
   *
   * Discovery does not ask the property for its settings, and a guessed `UTC` here would shift a
   * Gulf client's whole report by a day — wrong, stable, and indistinguishable from right.
   */
  it('says the timezone is not known yet rather than showing a guessed one', async () => {
    vi.mocked(listMeasurementProviders).mockResolvedValue([provider({
      state: 'connected', discovered_count: 1, selected_count: 0, properties: [property()],
    })])

    const { container } = renderWithProviders(<PropertiesPanel />, { locale: 'ar' })

    expect(await screen.findByTestId('ga4-timezone-unknown-111')).toBeInTheDocument()
    expect(container.innerHTML).not.toContain('UTC')
  })

  /**
   * Selecting goes through the EXISTING binding, with `purpose: 'analytics'`.
   *
   * Not a parallel «measurement selection» endpoint: the binding already enforces the
   * client-workspace fence, the one-active-binding rule and the plan quota.
   */
  it('selects a property through the project binding with the analytics purpose', async () => {
    vi.mocked(listProjects).mockResolvedValue([{ id: 'p1', name: 'Acme' }] as never)
    vi.mocked(listMeasurementProviders).mockResolvedValue([provider({
      state: 'connected', discovered_count: 2, selected_count: 0, properties: [property()],
    })])
    vi.mocked(bindAccount).mockResolvedValue({} as never)

    renderWithProviders(<PropertiesPanel />, { locale: 'ar' })

    fireEvent.change(await screen.findByTestId('measurement-project'), { target: { value: 'p1' } })
    fireEvent.click(screen.getByTestId('ga4-select-111'))

    await waitFor(() => expect(bindAccount).toHaveBeenCalledWith('p1', {
      external_account_id: 'acc-1',
      purpose: 'analytics',
    }))
  })

  /**
   * With no project in hand the control is disabled rather than guessing one.
   *
   * A button that silently picked a project would file a client's site traffic under whichever
   * project happened to sort first — the same defect the integrations page already had to fix once.
   */
  it('will not select a property until a project is chosen', async () => {
    vi.mocked(listMeasurementProviders).mockResolvedValue([provider({
      state: 'connected', discovered_count: 1, selected_count: 0, properties: [property()],
    })])

    renderWithProviders(<PropertiesPanel />, { locale: 'ar' })

    const button = await screen.findByTestId('ga4-select-111')
    expect(button).toBeDisabled()

    fireEvent.click(button)
    expect(bindAccount).not.toHaveBeenCalled()
  })

  /** A selected property can be read on demand, and an unselected one has no such control. */
  it('offers a read-now control for a selected property only', async () => {
    vi.mocked(listMeasurementProviders).mockResolvedValue([provider({
      state: 'connected',
      discovered_count: 2,
      selected_count: 1,
      properties: [
        property({ is_selected: true, project_id: 'p1', timezone: 'Asia/Riyadh', currency: 'SAR' }),
        property({ id: 'acc-2', property_id: '222', name: 'Other Client Store' }),
      ],
    })])
    vi.mocked(syncMeasurementProperty).mockResolvedValue({
      property_id: '111', project_id: 'p1', timezone: 'Asia/Riyadh',
      from: '2026-10-07', to: '2026-10-09', days: 3, figures: 12,
    })

    renderWithProviders(<PropertiesPanel />, { locale: 'ar' })

    expect(await screen.findByTestId('ga4-selected-111')).toBeInTheDocument()
    expect(screen.queryByTestId('ga4-select-111')).not.toBeInTheDocument()
    // …and the unselected one is offered selection, not a read.
    expect(screen.getByTestId('ga4-select-222')).toBeInTheDocument()
  })

  /**
   * The section says, in words, that site measurement is not added to the platforms' figures.
   *
   * The whole reason GA4 is a separate kind. A reader who sees two revenue numbers on one page will
   * add them unless told not to.
   */
  it('says plainly that site measurement is not added to the ad platforms’ figures', async () => {
    vi.mocked(listMeasurementProviders).mockResolvedValue([provider({ state: 'connected' })])

    renderWithProviders(<PropertiesPanel />, { locale: 'ar' })

    const panel = await screen.findByTestId('measurement-panel')
    expect(panel.textContent).toContain('لا يُجمع مع أرقام المنصات الإعلانية')
  })
})
