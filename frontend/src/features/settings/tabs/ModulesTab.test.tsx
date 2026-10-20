import { describe, expect, it, vi, beforeEach } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { ModulesTab, reasonText } from './ModulesTab'
import * as client from '@/lib/api/client'

vi.mock('@/lib/api/client', async (orig) => ({ ...(await orig<typeof client>()), getData: vi.fn(), putData: vi.fn() }))

const cap = (over: Record<string, unknown>) => ({
  module: 'm', label: 'M', entitled: true, enabled: false, reason: null, activation: 'default_on', decided_at: null, ...over,
})

describe('ModulesTab', () => {
  beforeEach(() => vi.clearAllMocks())

  it('shows every module with its state, and offers no switch for one the plan does not include', async () => {
    vi.mocked(client.getData).mockResolvedValue([
      cap({ module: 'smart_links', label: 'Smart links', enabled: true }),
      cap({ module: 'attribution_intelligence', label: 'Attribution intelligence', reason: 'not_activated', activation: 'explicit' }),
      cap({ module: 'link_in_bio', label: 'Link in bio', entitled: false, reason: 'not_entitled', activation: 'explicit' }),
    ])
    renderWithProviders(<ModulesTab />)

    expect(await screen.findByTestId('module-smart_links')).toBeTruthy()
    expect(screen.getByTestId('module-state-smart_links').textContent).toMatch(/On|مفعّلة/)
    expect(screen.getByTestId('module-state-attribution_intelligence').textContent).toMatch(/Off|متوقفة/)
    expect(screen.getByTestId('module-state-link_in_bio').textContent).toMatch(/Unavailable|غير متاحة/)
    expect((screen.getByTestId('module-switch-link_in_bio') as HTMLButtonElement).disabled).toBe(true)
    expect((screen.getByTestId('module-switch-attribution_intelligence') as HTMLButtonElement).disabled).toBe(false)
  })

  it('switching an available module on asks the server to switch it at the organisation level', async () => {
    vi.mocked(client.getData).mockResolvedValue([cap({ module: 'attribution_intelligence', label: 'Attribution', reason: 'not_activated', activation: 'explicit' })])
    vi.mocked(client.putData).mockResolvedValue([])
    renderWithProviders(<ModulesTab />)

    fireEvent.click(await screen.findByTestId('module-switch-attribution_intelligence'))
    await waitFor(() => expect(client.putData).toHaveBeenCalledWith('/settings/capabilities/attribution_intelligence', { scope: 'tenant', scope_id: null, enabled: true }))
  })

  it('says what each reason means in words, in both languages', () => {
    for (const r of ['not_entitled', 'not_activated', 'platform_disabled', 'tenant_off', 'client_off', 'project_off'] as const) {
      expect(reasonText(r, false)).not.toBe(r)
      expect(reasonText(r, true)).toMatch(/[؀-ۿ]/)
    }
  })
})
