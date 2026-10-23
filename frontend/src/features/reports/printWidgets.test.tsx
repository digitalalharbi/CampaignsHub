import { beforeAll, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { PrintDocument } from './PrintDocument'

/*
 * WIDGET-REGISTRY-001 — the PDF carries the widgets a link shows, as the same tables the spreadsheet has, and only those.
 */
beforeAll(() => {
  if (!('fonts' in document)) {
    Object.defineProperty(document, 'fonts', { value: { ready: Promise.resolve() }, configurable: true })
  }
})

const base = { period: { from: '2026-10-14', to: '2026-10-21' }, kpis: {}, summary: [], platforms: [], recommendations: [], outline: [] }

const linkOpens = { key: 'link_opens', data: { opens: 12, tracking_since: '2026-10-18', series: [{ period: '2026-10-18', opens: 5 }, { period: '2026-10-19', opens: 7 }] } }
const attribution = {
  key: 'attribution_credit',
  data: {
    models: ['linear', 'last_click'], coverage: { orders_without_evidence: 2 },
    channels: [{ channel: 'smart_link', models: { linear: { orders: 1.5 }, last_click: { orders: 2 } } }, { channel: 'unattributed', models: { linear: { orders: 2 }, last_click: { orders: 1.5 } } }],
  },
}

const doc = (widgets?: unknown[]) => render(<PrintDocument data={{ ...base, ...(widgets ? { widgets } : {}) } as never} currency="SAR" reportName="R" clientName="C" />)

describe('printed widgets', () => {
  it('prints nothing for a link with no widgets switched on', () => {
    doc()
    expect(screen.queryByTestId('print-widget-link_opens')).toBeNull()
    expect(screen.queryByTestId('print-widget-attribution_credit')).toBeNull()
  })

  it('prints link activity as a table with its total, since when it was measured, and that it is not sales', () => {
    doc([linkOpens])
    const section = screen.getByTestId('print-widget-link_opens')
    expect([...section.querySelectorAll('th')].map((h) => h.textContent)).toEqual(['Period', 'Opens'])
    expect([...section.querySelectorAll('tbody tr')].map((r) => r.textContent)).toEqual(['2026-10-185', '2026-10-197', 'Total12'])
    expect(section.textContent).toContain('Measured since 2026-10-18. Link opens, not sales.')
  })

  it('prints attribution with every chosen model as a column, the fractions kept, and the unattributed row present', () => {
    doc([attribution])
    const section = screen.getByTestId('print-widget-attribution_credit')
    expect([...section.querySelectorAll('th')].map((h) => h.textContent)).toEqual(['Channel', 'linear', 'last_click'])
    const rows = [...section.querySelectorAll('tbody tr')].map((r) => [...r.querySelectorAll('td')].map((c) => c.textContent))
    expect(rows).toEqual([['smart_link', '1.5', '2'], ['unattributed', '2', '1.5']])
    expect(section.textContent).toContain('2 order(s) have none and stay unattributed')
    expect(section.textContent).toContain('A click is not a purchase')
  })
})
