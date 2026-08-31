import { describe, expect, it } from 'vitest'

import { scopeNote } from './filterScope'

/**
 * The note exists because the alternative is a panel that answers a wider question than the chips
 * above it promise, with nothing on screen to say so. So the two properties that matter are that it
 * appears when an axis WAS declined, and that it stays quiet otherwise — a note on every page is one
 * nobody reads by the time it means something.
 */
describe('the note a panel shows when it could not honour a filter', () => {
  it('says nothing when every requested axis was applied', () => {
    expect(scopeNote({ applied: ['provider', 'campaign'], unapplied: [] }, false)).toBeNull()
  })

  it('says nothing when the reader has filtered nothing', () => {
    expect(scopeNote({ applied: [], unapplied: [] }, false)).toBeNull()
  })

  it('says nothing when the endpoint never reported a scope', () => {
    expect(scopeNote(undefined, false)).toBeNull()
  })

  it('names the axis that was declined', () => {
    const note = scopeNote({ applied: ['provider'], unapplied: ['campaign'] }, false)

    expect(note).toContain('whole project')
    expect(note).toContain('campaign')
  })

  it('names several declined axes together', () => {
    const note = scopeNote({ applied: ['provider'], unapplied: ['objective', 'campaign'] }, false)

    expect(note).toContain('objective and campaign')
  })

  it('speaks Arabic when the reader does', () => {
    const note = scopeNote({ applied: [], unapplied: ['campaign'] }, true)

    expect(note).toContain('الحملة')
    expect(note).not.toMatch(/[a-z]/)
  })

  /*
   * An axis the client has never heard of is dropped rather than printed raw. A server that grows a
   * fourth axis would otherwise render «undefined does not narrow it» on a customer's screen.
   */
  it('ignores an axis it has no name for', () => {
    expect(scopeNote({ applied: [], unapplied: ['placement'] }, false)).toBeNull()
    expect(scopeNote({ applied: [], unapplied: ['placement', 'campaign'] }, false)).toContain('campaign')
  })
})
