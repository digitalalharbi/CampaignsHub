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

import { listMeasurementProviders, startMeasurementOAuth, syncMeasurementProperty } from './api'

function property(over: Partial<MeasurementProperty> = {}): MeasurementProperty {
  return {
    id: 'acc-1', property_id: '111', name: 'Acme Store', analytics_account_id: '100', analytics_account_name: 'Acme Group',
    timezone: null, currency: null, is_selected: true, project_id: 'p1', last_synced_at: null, discovered_at: '2026-10-01T00:00:00+00:00',
    ...over,
  }
}

function provider(over: Partial<MeasurementProvider> = {}): MeasurementProvider {
  return {
    key: 'ga4', label: 'Google Analytics 4', label_ar: 'جوجل أناليتكس 4', state: 'disconnected', connection_error: null,
    discovered_count: 0, selected_count: 0, properties: [],
    ...over,
  }
}

/**
 * INTEG-PROJECT-LINK-STORES-GA4-001 — the panel is the property THIS project reads and nothing else. The
 * older step — a «belongs to client» dropdown, a «select for project» dropdown and the list of every
 * property a sign-in could see — is gone: a property is linked from the project's journey.
 */
describe('PropertiesPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['integrations.view', 'integrations.connect'])
  })
  afterEach(() => signOut())

  it('asks for the one project it is given and shows the property it reads', async () => {
    vi.mocked(listMeasurementProviders).mockResolvedValue([provider({ state: 'connected', selected_count: 1, discovered_count: 1, properties: [property()] })])

    renderWithProviders(<PropertiesPanel projectId="p1" />, { locale: 'ar' })

    const row = await screen.findByTestId('ga4-property-111')
    expect(row).toHaveTextContent('Acme Store')
    expect(row).toHaveTextContent('Acme Group')
    expect(listMeasurementProviders).toHaveBeenCalledTimes(1)
    expect(listMeasurementProviders).toHaveBeenCalledWith('p1')
  })

  it('draws nothing at all when the project reads no property: the GA4 row carries «ربط»', async () => {
    vi.mocked(listMeasurementProviders).mockResolvedValue([provider({ state: 'awaiting_credentials' })])

    const { container } = renderWithProviders(<PropertiesPanel projectId="p1" />, { locale: 'ar' })

    await waitFor(() => expect(listMeasurementProviders).toHaveBeenCalled())
    await waitFor(() => expect(container.querySelector('.animate-pulse')).toBeNull())
    expect(screen.queryByTestId('measurement-panel')).not.toBeInTheDocument()
    expect(container.innerHTML).not.toContain('client_secret')
  })

  it('never renders a property this project did not choose, even if one arrives', async () => {
    vi.mocked(listMeasurementProviders).mockResolvedValue([provider({
      state: 'connected',
      properties: [property(), property({ id: 'acc-2', property_id: '222', name: 'Nova Shop', is_selected: false, project_id: null }), property({ id: 'acc-3', property_id: '333', name: 'Other Client', project_id: 'p2' })],
    })])

    renderWithProviders(<PropertiesPanel projectId="p1" />, { locale: 'ar' })

    expect(await screen.findByTestId('ga4-property-111')).toBeInTheDocument()
    expect(screen.queryByTestId('ga4-property-222')).not.toBeInTheDocument()
    expect(screen.queryByTestId('ga4-property-333')).not.toBeInTheDocument()
    expect(screen.queryByText('Nova Shop')).not.toBeInTheDocument()
    expect(screen.queryByText('Other Client')).not.toBeInTheDocument()
  })

  it('has no selection step: no dropdowns, no «select for this project», no connect button', async () => {
    vi.mocked(listMeasurementProviders).mockResolvedValue([provider({ state: 'connected', properties: [property()] })])

    renderWithProviders(<PropertiesPanel projectId="p1" />, { locale: 'ar' })
    await screen.findByTestId('ga4-property-111')

    expect(screen.queryByTestId('measurement-client-workspace')).not.toBeInTheDocument()
    expect(screen.queryByTestId('measurement-project')).not.toBeInTheDocument()
    expect(screen.queryByTestId('ga4-select-111')).not.toBeInTheDocument()
    expect(screen.queryByTestId('measurement-connect-ga4')).not.toBeInTheDocument()
    expect(document.querySelectorAll('select')).toHaveLength(0)
    expect(screen.queryByText('الخاصية تخصّ العميل')).not.toBeInTheDocument()
    expect(screen.queryByText('الاختيار لمشروع')).not.toBeInTheDocument()
    expect(screen.queryByText('اختر لهذا المشروع')).not.toBeInTheDocument()
    expect(startMeasurementOAuth).not.toHaveBeenCalled()
  })

  it('says a never-read property’s timezone is not known rather than guessing one', async () => {
    vi.mocked(listMeasurementProviders).mockResolvedValue([provider({ state: 'connected', properties: [property()] })])

    renderWithProviders(<PropertiesPanel projectId="p1" />, { locale: 'ar' })

    const unknown = await screen.findByTestId('ga4-timezone-unknown-111')
    expect(unknown).not.toHaveTextContent('UTC')
  })

  it('reads one property now', async () => {
    vi.mocked(listMeasurementProviders).mockResolvedValue([provider({ state: 'connected', properties: [property({ timezone: 'Asia/Riyadh', currency: 'SAR' })] })])
    vi.mocked(syncMeasurementProperty).mockResolvedValue({ property_id: '111', project_id: 'p1', timezone: 'Asia/Riyadh', from: '2026-10-01', to: '2026-10-02', days: 2, figures: 8 })

    renderWithProviders(<PropertiesPanel projectId="p1" />, { locale: 'ar' })
    fireEvent.click(await screen.findByTestId('ga4-sync-111'))

    await waitFor(() => expect(vi.mocked(syncMeasurementProperty).mock.calls[0]?.[0]).toBe('acc-1'))
    expect(screen.getByTestId('ga4-property-111')).toHaveTextContent('Asia/Riyadh')
  })

  it('shows the provider’s own failure when a read is refused', async () => {
    vi.mocked(listMeasurementProviders).mockResolvedValue([provider({ state: 'connected', properties: [property()] })])
    vi.mocked(syncMeasurementProperty).mockRejectedValue({ response: { status: 502, data: { message: 'Quota exhausted for this property.' } } })

    renderWithProviders(<PropertiesPanel projectId="p1" />, { locale: 'en' })
    fireEvent.click(await screen.findByTestId('ga4-sync-111'))

    expect(await screen.findByTestId('measurement-action-error')).toBeInTheDocument()
  })
})
