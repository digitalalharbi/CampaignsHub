import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { useState } from 'react'
import { MetricVisibilityPicker } from './MetricVisibilityPicker'
import { CLIENT_PRESET_HIDDEN, METRIC_CHOICES, columnHidden, hiddenMetricsOf, visibleColumns } from './metricVisibility'
import { clientKpiKeys } from './clientKpis'
import { reportMetrics } from './reportMetrics'
import { renderWithProviders } from '@/test/utils'

/**
 * REPORT-KPI-VISIBILITY-001 — the page's half of the metric switches.
 *
 * The server removes a hidden metric's figures; these tests prove the page then leaves out the card, the column and the strip entry
 * that would NAME it, and that the grouped selector reaches every metric the owner listed.
 */
vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  saveReportMetricTemplate: vi.fn(() => Promise.resolve({ template: [], starting_point: [] })),
}))

function Harness({ initial, seen }: { initial: string[]; seen: (h: string[]) => void }) {
  const [hidden, setHidden] = useState(initial)
  seen(hidden)

  return <MetricVisibilityPicker ar hidden={hidden} onChange={setHidden} projectId="p1" />
}

describe('the metric visibility selector', () => {
  it('offers every metric the owner listed, grouped, with the cost per purchase unticked by the client default', () => {
    renderWithProviders(<Harness initial={[...CLIENT_PRESET_HIDDEN]} seen={() => {}} />, { locale: 'ar' })

    for (const key of ['spend', 'impressions', 'clicks', 'add_to_cart', 'purchases', 'revenue', 'roas', 'cpa', 'cpl', 'cpc', 'cpm', 'ctr', 'conversion_rate']) {
      expect(screen.getByTestId(`kpi-vis-${key}`)).toBeInTheDocument()
    }
    for (const g of ['delivery', 'results', 'value', 'cost']) expect(screen.getByTestId(`kpi-group-${g}`)).toBeInTheDocument()

    expect(screen.getByTestId('kpi-vis-cpa')).not.toBeChecked()
    for (const shown of ['spend', 'impressions', 'clicks', 'add_to_cart', 'purchases', 'revenue', 'roas']) {
      expect(screen.getByTestId(`kpi-vis-${shown}`)).toBeChecked()
    }
  })

  it('each metric toggles on its own and the presets reset the list', () => {
    let latest: string[] = []
    renderWithProviders(<Harness initial={['cpa']} seen={(h) => { latest = h }} />, { locale: 'en' })

    fireEvent.click(screen.getByTestId('kpi-vis-clicks'))
    expect(new Set(latest)).toEqual(new Set(['cpa', 'clicks']))
    fireEvent.click(screen.getByTestId('kpi-vis-cpa'))
    expect(latest).toEqual(['clicks'])

    fireEvent.click(screen.getByTestId('kpi-vis-all'))
    expect(latest).toEqual([])
    fireEvent.click(screen.getByTestId('kpi-vis-preset'))
    expect(latest).toEqual(['cpa'])
  })

  it('saves the client template from the selector', async () => {
    const { saveReportMetricTemplate } = await import('./api')
    renderWithProviders(<Harness initial={['cpa', 'cpm']} seen={() => {}} />, { locale: 'ar' })

    fireEvent.click(screen.getByTestId('kpi-vis-save-template'))
    expect(await screen.findByTestId('kpi-vis-saved')).toBeInTheDocument()
    expect(saveReportMetricTemplate).toHaveBeenCalledWith('p1', ['cpa', 'cpm'])
  })
})

describe('what the page leaves out for a hidden metric', () => {
  it('keeps the catalog in the groups the selector draws', () => {
    expect(new Set(METRIC_CHOICES.map((c) => c.group))).toEqual(new Set(['delivery', 'results', 'value', 'cost']))
  })

  it('drops a hidden metric from the KPI cards even when the objective or the operator chose it', () => {
    const renderable = new Set(['spend', 'impressions', 'clicks', 'conversions', 'revenue', 'roas', 'cpa'])

    expect(clientKpiKeys({ metrics: ['spend', 'conversions', 'cpa', 'roas'], hidden_metrics: ['cpa'] }, renderable)).toEqual(['spend', 'conversions', 'roas'])
    expect(clientKpiKeys({
      objective_performance: { paths: [{ spend: 10, headline_metrics: ['spend', 'orders', 'cpa', 'revenue', 'roas'] }] },
      hidden_metrics: ['cpa'],
    }, renderable)).not.toContain('cpa')
    expect(clientKpiKeys({ metrics: ['spend', 'cpa'] }, renderable)).toContain('cpa')
  })

  it('removes a hidden metric from the report strip', () => {
    const cards = reportMetrics({
      objective: 'sales',
      kpis: { spend: 10, conversions: 2, cpa: 5, roas: 3, revenue: 30 },
      currency: 'SAR',
      metric_set: ['spend', 'conversions', 'cpa', 'roas', 'revenue'],
      hidden_metrics: ['cpa'],
    })

    expect(cards.map((c) => c.key)).not.toContain('cpa')
    expect(cards.map((c) => c.key)).toEqual(expect.arrayContaining(['spend', 'conversions', 'roas', 'revenue']))
  })

  it('takes the shared cost column with any hidden cost metric and keeps the name column', () => {
    const hidden = hiddenMetricsOf({ hidden_metrics: ['cpa'] })
    expect(columnHidden(hidden, 'cost')).toBe(true)
    expect(columnHidden(hidden, 'spend')).toBe(false)
    expect(columnHidden(hiddenMetricsOf({ hidden_metrics: ['clicks'] }), 'clicks')).toBe(true)
    expect(columnHidden(hiddenMetricsOf(undefined), 'cost')).toBe(false)

    const cols = [{ key: 'platform' }, { key: 'spend' }, { key: 'cpa' }, { key: 'cost' }, { key: 'roas' }]
    expect(visibleColumns(cols, hidden).map((c) => c.key)).toEqual(['platform', 'spend', 'roas'])
  })
})
