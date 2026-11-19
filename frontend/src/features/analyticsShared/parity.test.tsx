import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render, renderHook, screen } from '@testing-library/react'
import { MetricStrip } from '@/components/ui/MetricStrip'
import { metricsForKeys } from '@/features/analytics/metricCatalog'
import type { Summary } from '@/features/analytics/api'
import { LiveKpiBoard } from '@/features/reports/live/LiveKpis'
import { useLiveMetricReader } from '@/features/reports/live/liveMetrics'
import type { LivePayload } from '@/features/reports/api'
import { FIXTURES, PARITY_KEYS, type Totals } from './parityFixture'

/**
 * ANALYTICS-PARITY-001 (Owner, 2026-11-19) — «أي فرق رقمي بين الاثنين يعتبر خطأ».
 *
 * The same fixture goes through BOTH surfaces — the /r/ report's KPI board (`LiveKpiBoard`) and the internal board's
 * metric strip (`MetricStrip` over the catalogue) — and the figure each one DRAWS, per metric, per locale, must be the same
 * text. A metric a surface has no figure for is the same «nothing» on both, whatever each says in words.
 *
 * Out of scope on purpose, and said so: figures that exist only on the report because a link defines them (Results and
 * their cost, form starts, landing leads — `RESULT-EVENTS-001`), and the report's own gating of which cards a client sees.
 * This pins the arithmetic and the written form, not the selection.
 */
const MISSING = '∅'

const withheldWords = new Set(['—', 'غير متاح', 'Not available', 'لا بيانات', 'No data'])

function reportFigure(key: string, totals: Totals, currency: string, ar: boolean): string {
  const payload = {
    currency,
    totals,
    reported: Object.fromEntries(Object.keys(totals).map((k) => [k, true])),
    period: { from: '2026-11-01', to: '2026-11-07', days: 7 },
    results: { configured: false, total: null, cost: null, by_platform: {}, by_path: {}, events: {} },
    // The operator's own choice: no gating of the card, so what is compared is the figure and not the selection.
    metrics: [key],
  } as unknown as LivePayload
  const { result } = renderHook(() => useLiveMetricReader(currency, ar))
  render(<LiveKpiBoard payload={payload} reader={result.current} ar={ar} keys={[key]} />)
  const text = screen.getByTestId('live-kpis').querySelector('[dir="ltr"]')?.textContent?.trim() ?? MISSING
  cleanup()

  return withheldWords.has(text) ? MISSING : text
}

function boardFigure(key: string, totals: Totals, currency: string, ar: boolean): string {
  const summary = {
    current: totals,
    previous: {},
    delta: {},
    currency,
    reported: Object.fromEntries(Object.keys(totals).map((k) => [k, true])),
    rows_in_scope: true,
    previous_rows_in_scope: false,
  } as unknown as Summary
  const items = metricsForKeys([key], 'all', summary, ar)
  render(<MetricStrip id="parity" ar={ar} primary={items} hasRows />)
  const card = screen.getByTestId(`metric-${key}`)
  const text = card.getAttribute('data-state') === 'value' ? (screen.getByTestId('metric-value').textContent ?? '').trim() : MISSING
  cleanup()

  return text
}

describe('ANALYTICS-PARITY-001 — the report and the board draw the same figure', () => {
  afterEach(() => cleanup())

  for (const [name, f] of Object.entries(FIXTURES)) {
    for (const ar of [true, false]) {
      it(`${name} · ${ar ? 'ar' : 'en'}: every shared metric reads identically`, () => {
        const differences: string[] = []

        for (const key of PARITY_KEYS) {
          /*
           * The one declared rule that is a SELECTION and not an arithmetic: a client link whose platforms all reported zero
           * revenue drops the revenue and ROAS cards (NASEL 2026-10-29, `revenueReported`), as if «hide revenue» were on. The
           * board states the zero. Both are the same measurement; the report just does not draw it.
           */
          if ((key === 'revenue' || key === 'roas') && !(Number(f.totals.revenue) > 0)) continue
          const report = reportFigure(key, f.totals, f.currency, ar)
          const board = boardFigure(key, f.totals, f.currency, ar)
          if (report !== board) differences.push(`${key}: report «${report}» vs board «${board}»`)
        }

        expect(differences, differences.join('\n')).toEqual([])
      })
    }
  }
})
