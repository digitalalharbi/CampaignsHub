import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { AttributionSettingsPanel } from './AttributionSettingsPanel'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('./api', async (orig) => ({
  ...(await (orig() as Promise<Record<string, unknown>>)),
  getAttributionSettings: vi.fn(),
  updateAttributionSettings: vi.fn(),
}))
import { getAttributionSettings, updateAttributionSettings } from './api'

/** ATTRIB-PRESETS-001 — the chooser writes a preset, exposes the advanced options only where they apply, and lists the versions. */
const PRESETS = {
  basic: { label_ar: 'أساسي', label_en: 'Basic', models: ['last_click'], advanced: false },
  standard: { label_ar: 'قياسي', label_en: 'Standard', models: ['last_click', 'first_click', 'linear'], advanced: false },
  advanced: { label_ar: 'متقدم', label_en: 'Advanced', models: ['last_click', 'first_click', 'linear', 'position_based', 'time_decay'], advanced: true },
  custom: { label_ar: 'مخصّص', label_en: 'Custom', models: ['custom_weighted'], advanced: true },
}
const DATA = {
  configured: true, models_available: [], defaults: { models: [], lookback_days: 30, half_life_days: 7, weights: {} },
  current: { models: ['last_click'], lookback_days: 30, half_life_days: 7, weights: { first: 0.4, middle: 0.2, last: 0.4 }, version: 'v', preset: 'basic', advanced: null },
  current_version: 'v', presets: PRESETS, default_preset: 'basic',
  advanced_defaults: { honour_consent: true, exclude_refunds: true, eligible_channels: null },
  versions: [{ number: 2, preset: 'basic', models: ['last_click'], lookback_days: 30, half_life_days: 7, weights: {}, advanced: null, version: 'v', changed_by: 1, created_at: '2026-10-22T10:00:00Z' }, { number: 1, preset: 'standard', models: [], lookback_days: 30, half_life_days: 7, weights: {}, advanced: null, version: 'v', changed_by: 1, created_at: '2026-10-21T10:00:00Z' }],
}

describe('the attribution preset chooser', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    vi.mocked(getAttributionSettings).mockResolvedValue(DATA as never)
    vi.mocked(updateAttributionSettings).mockResolvedValue(DATA as never)
    signInWith(['analytics.view', 'reports.manage'])
  })
  afterEach(() => signOut())

  it('shows the four presets with the current one chosen, lists the versions newest first, and saves a plain preset', async () => {
    renderWithProviders(<AttributionSettingsPanel ar={false} />, { locale: 'en' })
    expect(await screen.findByTestId('attr-preset-basic')).toBeChecked()
    expect(screen.queryByTestId('attr-advanced')).not.toBeInTheDocument()
    expect(screen.getByTestId('attr-versions').textContent).toMatch(/#2.*Basic.*#1.*Standard/s)

    fireEvent.click(screen.getByTestId('attr-preset-standard'))
    fireEvent.click(screen.getByTestId('attr-preset-save'))
    await waitFor(() => expect(updateAttributionSettings).toHaveBeenCalledWith('p1', { preset: 'standard' }))
  })

  it('exposes the advanced options on ADVANCED and the weights on CUSTOM, and sends them', async () => {
    renderWithProviders(<AttributionSettingsPanel ar={false} />, { locale: 'en' })
    fireEvent.click(await screen.findByTestId('attr-preset-advanced'))
    fireEvent.click(screen.getByTestId('attr-exclude-refunds'))
    fireEvent.change(screen.getByTestId('attr-channels'), { target: { value: 'snapchat, tiktok' } })
    fireEvent.click(screen.getByTestId('attr-preset-save'))
    await waitFor(() => expect(updateAttributionSettings).toHaveBeenCalledWith('p1', { preset: 'advanced', advanced: { honour_consent: true, exclude_refunds: false, eligible_channels: ['snapchat', 'tiktok'] } }))

    fireEvent.click(screen.getByTestId('attr-preset-custom'))
    fireEvent.change(screen.getByTestId('attr-weight-last'), { target: { value: '1' } })
    fireEvent.click(screen.getByTestId('attr-preset-save'))
    await waitFor(() => expect(vi.mocked(updateAttributionSettings).mock.calls.at(-1)?.[1]).toMatchObject({ preset: 'custom', weights: { first: 0.4, middle: 0.2, last: 1 } }))
  })

  it('renders nothing when the module is off (403)', async () => {
    vi.mocked(getAttributionSettings).mockRejectedValue(Object.assign(new Error('off'), { response: { status: 403 } }))
    const { container } = renderWithProviders(<AttributionSettingsPanel ar={false} />, { locale: 'en' })
    await waitFor(() => expect(getAttributionSettings).toHaveBeenCalled())
    expect(container.querySelector('[data-testid="attribution-presets"]')).toBeNull()
  })
})
