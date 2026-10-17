import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { RulePresets, isPresetAdded } from './RulePresets'
import { RULE_TEMPLATES } from './ruleTemplates'
import { ALERT_TYPES, type AlertRule } from './api'
import { renderWithProviders } from '@/test/utils'

/**
 * ALERTS-PRESETS-001 — ready-made rules are the form's own payload, a preset already covered is not offered
 * twice, and the catalogue can only contain rules the engine understands.
 */
const rule = (over: Partial<AlertRule>): AlertRule => ({
  id: 'r1', project_id: null, type: 'budget_risk', name: 'my own name', threshold: { ratio: 0.9 }, cooldown_minutes: 720,
  channels: ['in_app'], create_task: false, severity: 'warning', active: true, created_at: null, ...over,
})

describe('the preset catalogue', () => {
  it('only offers types the engine knows, with unique keys and bilingual copy', () => {
    const keys = new Set<string>()
    for (const t of RULE_TEMPLATES) {
      expect(ALERT_TYPES as readonly string[]).toContain(t.rule.type)
      expect(keys.has(t.key)).toBe(false)
      keys.add(t.key)
      expect(t.name.ar.length).toBeGreaterThan(3)
      expect(t.name.en.length).toBeGreaterThan(3)
      expect(t.why.ar.length).toBeGreaterThan(10)
    }
  })

  it('states each threshold in the shape the engine reads it', () => {
    const by = Object.fromEntries(RULE_TEMPLATES.map((t) => [t.key, t.rule.threshold]))

    expect(by.budget_90).toEqual({ ratio: 0.9 })
    expect(by.roas_drop_25).toEqual({ pct: 25, days: 7 })
    expect(by.no_results_3).toEqual({ days: 3 })
    expect(by.token_7).toEqual({ days: 7 })
    expect(by.measurement_24).toEqual({ hours: 24 })
    expect(by.lead_unassigned_60).toEqual({ minutes: 60 })
    expect(by.anomaly).toBeUndefined()
  })
})

describe('RulePresets', () => {
  it('adds a preset through the one create path with its own payload', () => {
    const onAdd = vi.fn()
    renderWithProviders(<RulePresets rules={[]} onAdd={onAdd} busy={false} ar={false} />, { locale: 'en' })

    fireEvent.click(screen.getByTestId('preset-add-roas_drop_25'))

    expect(onAdd).toHaveBeenCalledWith(expect.objectContaining({ type: 'roas_drop', threshold: { pct: 25, days: 7 }, severity: 'warning', name: 'ROAS fell by 25%', active: true }), 'roas_drop_25')
  })

  it('shows a preset as added when a rule of that type and threshold exists, however it was renamed', () => {
    renderWithProviders(<RulePresets rules={[rule({})]} onAdd={vi.fn()} busy={false} ar={false} />, { locale: 'en' })

    expect(screen.getByTestId('preset-add-budget_90')).toBeDisabled()
    expect(screen.getByTestId('preset-add-budget_90')).toHaveTextContent('Added')
    expect(screen.getByTestId('preset-add-budget_100')).toBeEnabled()   // same type, a different threshold, is another rule
  })

  it('matches by threshold values, not by key order', () => {
    const t = RULE_TEMPLATES.find((x) => x.key === 'roas_drop_25')!
    expect(isPresetAdded(t, [rule({ type: 'roas_drop', threshold: { days: 7, pct: 25 } })])).toBe(true)
    expect(isPresetAdded(t, [rule({ type: 'roas_drop', threshold: { pct: 30, days: 7 } })])).toBe(false)
  })

  it('speaks Arabic in the Arabic locale', () => {
    renderWithProviders(<RulePresets rules={[]} onAdd={vi.fn()} busy={false} ar />, { locale: 'ar' })

    expect(screen.getByText('قواعد جاهزة')).toBeInTheDocument()
    expect(screen.getByText('الميزانية وصلت 90%')).toBeInTheDocument()
  })

  it('disables every add while one is in flight', () => {
    renderWithProviders(<RulePresets rules={[]} onAdd={vi.fn()} busy ar={false} />, { locale: 'en' })

    expect(screen.getByTestId('preset-add-budget_90')).toBeDisabled()
  })
})
