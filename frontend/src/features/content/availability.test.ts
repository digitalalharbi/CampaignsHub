import { describe, expect, it } from 'vitest'
import { emptyReason } from './availability'

/**
 * CONTENT-STATE-SEMANTICS-001 — the four states must not collapse back into one sentence.
 *
 * «لا توجد بيانات» under every empty card meant a paused creative and a broken pipeline looked
 * identical. These assert they no longer do.
 */
describe('why a creative card is empty', () => {
  it('says the creative did not run when the fetch actually succeeded', () => {
    const r = emptyReason({ status: 'success', rows: 814, error: null, at: null }, 'ar')

    expect(r.kind).toBe('did_not_run')
    expect(r.text).toBe('لم يعمل خلال هذه الفترة')
  })

  it('says the platform does not report creatives rather than implying data is missing', () => {
    const r = emptyReason({ status: 'unsupported', rows: 0, error: null, at: null }, 'en')

    expect(r.kind).toBe('unsupported')
    expect(r.text).toMatch(/does not report/i)
  })

  /** The one that must never read as an idle campaign. */
  it('surfaces a failed fetch as a warning, with the provider reason', () => {
    const r = emptyReason(
      { status: 'failed', rows: 0, error: 'Rate limited by the platform (429).', at: null },
      'en',
    )

    expect(r.kind).toBe('failed')
    expect(r.tone).toBe('warning')
    expect(r).toHaveProperty('detail', 'Rate limited by the platform (429).')
  })

  it('does not invent a reason when nothing was recorded', () => {
    expect(emptyReason(undefined, 'en').kind).toBe('unknown')
    expect(emptyReason({ status: 'skipped', rows: null, error: null, at: null }, 'en').kind).toBe('unknown')
  })

  /** A did-not-run creative is not a problem, so it must not be dressed as one. */
  it('reserves the warning tone for the state that needs action', () => {
    expect(emptyReason({ status: 'success', rows: 5, error: null, at: null }, 'en').tone).toBe('muted')
    expect(emptyReason({ status: 'unsupported', rows: 0, error: null, at: null }, 'en').tone).toBe('muted')
  })
})
