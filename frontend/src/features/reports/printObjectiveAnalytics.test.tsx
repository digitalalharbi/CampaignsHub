import { beforeAll, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { PrintDocument } from './PrintDocument'

/*
 * REPORT-OBJECTIVE-ANALYTICS-001 — the PDF prints the same objective section the screen draws, from the
 * same snapshot key: a block per family, its own figures, its leaders, and nothing across families.
 */
beforeAll(() => {
  if (!('fonts' in document)) {
    Object.defineProperty(document, 'fonts', { value: { ready: Promise.resolve() }, configurable: true })
  }
})

const kpi = (key: string, label: string, kind: string, value: number | null) => ({
  key, label_ar: label, label_en: label, kind, value, state: value === null ? 'unavailable' : 'reported', reason: value === null ? 'not_reported' : null, not_reported_by: [],
})

const data = {
  period: { from: '2026-09-01', to: '2026-09-14' },
  kpis: { spend: 1000 },
  objective_analytics: {
    version: 1,
    period: { from: '2026-09-01', to: '2026-09-14' },
    mixed: true,
    cross_family_blend: false,
    unclassified_present: false,
    families: [
      {
        family: 'awareness', label_ar: 'الوعي', label_en: 'Awareness',
        kpis: [kpi('spend', 'Spend', 'money', 500), kpi('reach', 'Reach', 'count', null), kpi('cpm', 'CPM', 'money', 5)],
        platforms: [],
        contribution: { outcome: 'impressions', label_ar: '', label_en: 'Impressions', total: 100, rows: [{ provider: 'meta', value: 75, share: 0.75 }, { provider: 'tiktok', value: 25, share: 0.25 }] },
        platform_ranking: { metric: 'cpm', reason: null, eligible: 2, candidates: 2, best: { provider: 'meta', value: 4, volume: { key: 'impressions', value: 90000 } }, weakest: { provider: 'tiktok', value: 9, volume: { key: 'impressions', value: 20000 } } },
        content_ranking: { metric: null, best: null, weakest: null, reason: 'only_one_candidate', eligible: 0, candidates: 1 },
        trend: null,
      },
      {
        family: 'leads', label_ar: '', label_en: 'Leads',
        kpis: [kpi('spend', 'Spend', 'money', 300), kpi('leads', 'Leads', 'count', 0), kpi('cpl', 'CPL', 'money', null)],
        platforms: [], contribution: null,
        platform_ranking: { metric: null, best: null, weakest: null, reason: 'only_one_candidate', eligible: 0, candidates: 1 },
        content_ranking: null, trend: null,
      },
    ],
  },
  recommendations: [],
}

describe('the printed objective section', () => {
  it('prints a block per family with its own figures, leaders and contribution', () => {
    render(<PrintDocument data={data as never} currency="SAR" reportName="R" clientName="C" />)

    const awareness = screen.getByTestId('print-objective-family-awareness')
    expect(awareness).toHaveTextContent('Awareness')
    expect(awareness).toHaveTextContent('5 SAR')
    expect(awareness).toHaveTextContent('Strongest: meta — CPM 4 SAR')
    expect(awareness).toHaveTextContent('75.0%')

    const leads = screen.getByTestId('print-objective-family-leads')
    expect(leads).toHaveTextContent('Leads')
    // A reported zero prints 0; an unavailable cost prints «—»; no ranking prints no line.
    expect(leads).toHaveTextContent('0')
    expect(leads).toHaveTextContent('—')
    expect(leads).not.toHaveTextContent('Strongest')
    expect(leads).not.toHaveTextContent('CPM')
  })
})
