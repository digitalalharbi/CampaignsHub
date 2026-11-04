import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'

import { renderWithProviders } from '@/test/utils'
import type { ReconcileStatus } from '../api'
import { reconcileWords, riyadhStamp, signedPct, SpendReconciliationStamp, type ReconcileEntry } from './SpendReconciliationStamp'
import { FreshnessStrip } from './LiveSections'

/** SPEND-RECONCILE-RECORD-001 — matched / drifted (+15.9%) / never, in both languages, on a Riyadh clock with Latin digits. */
const AT = '2026-11-02T11:32:00Z' // 14:32 in Riyadh

const entry = (status: ReconcileStatus, pct: number | null = null, at: string | null = AT): ReconcileEntry => ({
  provider: 'snapchat',
  state: 'synced',
  last_reconciled_at: status === 'never' ? null : at,
  reconcile_status: status,
  reconcile_diff_pct: pct,
})

describe('riyadhStamp / signedPct', () => {
  it('prints the Riyadh date and time with Latin digits', () => {
    expect(riyadhStamp(AT)).toBe('2026-11-02 14:32')
    // 21:30Z is already the next Riyadh day.
    expect(riyadhStamp('2026-11-02T21:30:00Z')).toBe('2026-11-03 00:30')
  })

  it('signs the share and keeps one decimal', () => {
    expect(signedPct(15.91)).toBe('+15.9%')
    expect(signedPct(-3.2)).toBe('−3.2%')
    expect(signedPct(0)).toBe('+0.0%')
  })
})

for (const ar of [true, false]) {
  const lang = ar ? 'ar' : 'en'
  const tz = ar ? 'بتوقيت الرياض' : 'Riyadh time'

  describe(`SpendReconciliationStamp (${lang})`, () => {
    it('matched: shows the Riyadh clock and the word, with no drift tone', () => {
      renderWithProviders(<SpendReconciliationStamp freshness={[entry('matched', 0)]} ar={ar} />)
      const box = screen.getByTestId('spend-reconciliation')
      expect(box.getAttribute('data-drift')).toBe('false')
      expect(box.textContent).toContain(ar ? 'آخر مطابقة مؤكدة مع المصدر' : 'Last confirmed match with the source')
      expect(within(box).getByTestId('spend-reconciliation-snapchat-time').textContent).toContain('2026-11-02 14:32')
      expect(within(box).getByTestId('spend-reconciliation-snapchat-time').textContent).toContain(tz)
      expect(within(box).getByTestId('spend-reconciliation-snapchat-status').textContent).toContain(ar ? 'مطابق' : 'Matched')
      expect(within(box).getByTestId('spend-reconciliation-snapchat-status').textContent).not.toContain('%')
    })

    it('drifted: names the share and takes the warning tone', () => {
      renderWithProviders(<SpendReconciliationStamp freshness={[entry('drifted', 15.9)]} ar={ar} />)
      const box = screen.getByTestId('spend-reconciliation')
      expect(box.getAttribute('data-drift')).toBe('true')
      expect(screen.getByTestId('spend-reconciliation-snapchat').getAttribute('data-status')).toBe('drifted')
      expect(screen.getByTestId('spend-reconciliation-snapchat-status').textContent).toContain(ar ? 'غير مطابق (+15.9%)' : 'Does not match (+15.9%)')
      expect(screen.getByTestId('spend-reconciliation-snapchat-time').textContent).toContain('2026-11-02 14:32')
    })

    it('never: claims no time and says it was not reconciled yet', () => {
      renderWithProviders(<SpendReconciliationStamp freshness={[entry('never')]} ar={ar} />)
      expect(screen.queryByTestId('spend-reconciliation-snapchat-time')).toBeNull()
      expect(screen.getByTestId('spend-reconciliation-snapchat-status').textContent).toContain(ar ? 'لم تُطابق بعد' : 'Not yet reconciled')
      expect(screen.getByTestId('spend-reconciliation').getAttribute('data-drift')).toBe('false')
    })

    it('reconcileWords is the exact row text', () => {
      expect(reconcileWords(entry('drifted', -3.25), ar)).toBe(ar ? 'غير مطابق (−3.3%)' : 'Does not match (−3.3%)')
      expect(reconcileWords(entry('failed'), ar)).toBe(ar ? 'تعذّرت المطابقة' : 'Check failed')
    })
  })
}

describe('the client report does not mount the stamp (#938)', () => {
  it('keeps the excluded-platform sentence and no reconciliation', () => {
    renderWithProviders(<FreshnessStrip freshness={[entry('matched', 0), { provider: 'meta', state: 'awaiting_credentials', reconcile_status: 'never' }]} ar />)
    expect(screen.queryByTestId('spend-reconciliation')).toBeNull()
    expect(screen.getByTestId('live-freshness')).toBeInTheDocument()
  })

  it('renders nothing for a payload that predates the stamp', () => {
    const { container } = renderWithProviders(<FreshnessStrip freshness={[{ provider: 'meta', state: 'synced' }]} ar />)
    expect(container.querySelector('[data-testid="spend-reconciliation"]')).toBeNull()
    expect(container.querySelector('[data-testid="live-freshness"]')).toBeNull()
  })
})
