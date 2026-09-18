import { cloneElement, isValidElement, type ReactNode } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { render } from '@testing-library/react'

vi.mock('recharts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('recharts')>()

  // jsdom has no layout, so a responsive container measures 0 and draws nothing; give the chart a size.
  return {
    ...actual,
    ResponsiveContainer: ({ children }: { children: ReactNode }) =>
      isValidElement(children) ? cloneElement(children as never, { width: 200, height: 36 }) : null,
  }
})

import { KpiCard } from './components'

/*
 * The sparkline's fill referenced a gradient id built from the card's LABEL. A label with a space —
 * «تكلفة النتيجة», «Cost per result» — makes `url(#sp-Cost per result)` an invalid reference, and
 * the browser paints the area solid black. Found on the live link's summary at 390px.
 */
describe('a KPI card sparkline', () => {
  it('fills from a gradient it can actually reference, whatever the label says', () => {
    const { container } = render(<KpiCard label="Cost per result" value="82.22 SAR" spark={[3, 5, 4]} accent="#10b981" />)

    const gradient = container.querySelector('linearGradient')
    const area = container.querySelector('.recharts-area-area')
    expect(gradient, 'no gradient was drawn — the harness is not measuring anything').not.toBeNull()
    expect(gradient!.id).not.toMatch(/\s/)
    expect(area?.getAttribute('fill')).toBe(`url(#${gradient!.id})`)
  })
})
