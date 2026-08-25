import { describe, expect, it } from 'vitest'

import { KIND_LABEL, creativeKindLabel } from './CreativesPage'

/**
 * CONTENT-KIND-LABEL-001 — «نوع المحتوى video» on the one screen dedicated to describing an asset.
 *
 * The library badge said «فيديو»; the detail page rendered `preview.kind` straight.
 */
describe('creative kind labels', () => {
  const KINDS = ['image', 'video', 'carousel']

  it.each(KINDS)('labels %s in both languages', (kind) => {
    expect(creativeKindLabel(kind, true)).not.toBe(kind)
    expect(creativeKindLabel(kind, false)).not.toBe(kind)
  })

  it('is the same map the library badge reads, not a second copy', () => {
    expect(Object.keys(KIND_LABEL).sort()).toEqual([...KINDS].sort())
  })

  it('shows an unrecognised format as itself rather than hiding it', () => {
    expect(creativeKindLabel('playable', true)).toBe('playable')
  })

  it('says «—» when the platform sent no kind at all', () => {
    expect(creativeKindLabel(null, true)).toBe('—')
    expect(creativeKindLabel(undefined, true)).toBe('—')
    expect(creativeKindLabel('', true)).toBe('—')
  })
})
