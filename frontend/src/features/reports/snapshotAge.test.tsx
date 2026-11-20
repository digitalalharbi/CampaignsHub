import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { InteractiveReport } from './InteractiveReport'
import { useUi } from '@/stores/ui'

/**
 * The report states its period in one plain line (Owner, 2026-11-19): «الأرقام خلال الفترة {من} – {إلى}» /
 * «Figures for {from} – {to}». No «snapshot», no generation time, no «not current performance», and no
 * «older rules» warning anywhere in the report: when it was last updated lives only in the footer cell.
 */
const base = {
  slides: [{ id: 's1', type: 'recommendations', title: 'Next', visible: true }],
  recommendations: [],
  totals: {},
  period: { from: '2026-10-20', to: '2026-11-18' },
} as never

const meta = { currency: 'SAR', locale: 'ar', platforms: [], clientName: 'Client', title: 'Report' } as never

describe('a report states the period its figures cover', () => {
  it('says «الأرقام خلال الفترة {من} – {إلى}» with the report’s own period', () => {
    useUi.setState({ locale: 'ar' })
    render(<InteractiveReport data={{ ...(base as object), mode: 'snapshot', generated_at: '2026-11-19T09:08:00Z' } as never} meta={meta} />)

    const line = screen.getByTestId('report-snapshot-age')

    expect(line).toHaveTextContent('الأرقام خلال الفترة')
    expect(line).toHaveTextContent('20 أكتوبر – 18 نوفمبر 2026')
  })

  it('says «Figures for {from} – {to}» in English', () => {
    useUi.setState({ locale: 'en' })
    render(<InteractiveReport data={{ ...(base as object), mode: 'snapshot' } as never} meta={{ ...(meta as object), locale: 'en' } as never} />)

    expect(screen.getByTestId('report-snapshot-age')).toHaveTextContent(/Figures for\s*Oct 20 – Nov 18, 2026/)
    useUi.setState({ locale: 'ar' })
  })

  it('never shows the words snapshot / لقطة, a generation time, or «not current performance»', () => {
    useUi.setState({ locale: 'ar' })
    const { container } = render(
      <InteractiveReport data={{ ...(base as object), mode: 'snapshot', generated_at: '2026-11-19T09:08:00Z', data_version: 1 } as never} meta={meta} />,
    )

    expect(container).not.toHaveTextContent('لقطة')
    expect(container).not.toHaveTextContent('وليست أداءً حاليًا')
    expect(container).not.toHaveTextContent('الأرقام كما كانت')
    expect(container).not.toHaveTextContent(/snapshot/i)
    // The generation time may appear only in the footer's «last updated» cell, never in the period line.
    expect(screen.getByTestId('report-snapshot-age')).not.toHaveTextContent('2026-11-19')
  })

  /** The old-rules warning is gone for good, whatever the stored data version. */
  it('shows no «older rules» warning for an old data version', () => {
    useUi.setState({ locale: 'ar' })
    const { container } = render(
      <InteractiveReport data={{ ...(base as object), mode: 'snapshot', data_version: 1 } as never} meta={meta} />,
    )

    expect(screen.queryByTestId('report-stale-contract')).not.toBeInTheDocument()
    expect(container).not.toHaveTextContent('قواعد أقدم')
    expect(container).not.toHaveTextContent('أعد إنشاء التقرير للمقارنة')
  })

  it('says a live report is computed now rather than quoting a period', () => {
    useUi.setState({ locale: 'ar' })
    render(<InteractiveReport data={{ ...(base as object), mode: 'live' } as never} meta={meta} />)

    expect(screen.getByTestId('report-snapshot-age')).toHaveTextContent('الأرقام محسوبة الآن من أحدث البيانات')
  })

  /** A report with no recorded period invents none. */
  it('claims nothing when no period was recorded', () => {
    useUi.setState({ locale: 'ar' })
    render(<InteractiveReport data={{ slides: (base as { slides: unknown }).slides, recommendations: [], totals: {}, mode: 'snapshot' } as never} meta={meta} />)

    expect(screen.queryByTestId('report-snapshot-age')).not.toBeInTheDocument()
  })

  /** The attribution basis rides along, because two bases are two different measurements. */
  it('names the attribution basis when one was recorded', () => {
    useUi.setState({ locale: 'ar' })
    render(<InteractiveReport data={{ ...(base as object), mode: 'snapshot', attribution_window: 'swipe_28d' } as never} meta={meta} />)

    expect(screen.getByTestId('report-snapshot-age')).toHaveTextContent('swipe_28d')
  })

  /** Owner 2026-11-19 — a period with a missing or blank date hides the whole line instead of printing «الفترة –». */
  it.each([
    ['an empty from', { from: '', to: '2026-11-18' }],
    ['an empty to', { from: '2026-10-20', to: '' }],
    ['both blank', { from: '  ', to: '' }],
    ['a placeholder', { from: '—', to: '—' }],
    ['a missing to', { from: '2026-10-20' }],
  ])('hides the line for %s', (_name, period) => {
    useUi.setState({ locale: 'ar' })
    const { container } = render(<InteractiveReport data={{ ...(base as object), mode: 'snapshot', period } as never} meta={meta} />)

    expect(screen.queryByTestId('report-snapshot-age')).not.toBeInTheDocument()
    expect(container).not.toHaveTextContent('الأرقام خلال الفترة')
  })
})
