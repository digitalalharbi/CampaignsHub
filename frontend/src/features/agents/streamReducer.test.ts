import { describe, expect, it } from 'vitest'
import { initialStream, streamReducer, type StreamState } from './streamReducer'
import type { LiveReply } from './api'

const live = (over: Partial<LiveReply> = {}): LiveReply => ({ status: 'thinking', error: null, phase: 'writing', specialist: 'analyst', text: '', tools: [], seq: 1, ...over })
const run = (actions: Parameters<typeof streamReducer>[1][], from: StreamState = initialStream) => actions.reduce(streamReducer, from)

/** AGENTS-STREAM-001 — what the screen holds between two reads of the live buffer. */
describe('the streaming reducer', () => {
  it('starts waiting, then grows the text as words arrive', () => {
    const s = run([{ type: 'sent' }, { type: 'snapshot', live: live({ text: 'أول', seq: 2 }) }, { type: 'snapshot', live: live({ text: 'أول ثاني', seq: 3 }) }])
    expect(s).toMatchObject({ phase: 'streaming', text: 'أول ثاني', specialist: 'analyst', seq: 3 })
  })

  it('never rolls back on a late, older answer', () => {
    const s = run([{ type: 'sent' }, { type: 'snapshot', live: live({ text: 'أول ثاني', seq: 5 }) }, { type: 'snapshot', live: live({ text: 'أول', seq: 4 }) }])
    expect(s.text).toBe('أول ثاني')
  })

  it('clears the words while the assistant reads, and starts a new round fresh even if the reading step was missed', () => {
    const reading = run([{ type: 'sent' }, { type: 'snapshot', live: live({ text: 'سأقرأ', seq: 2 }) }, { type: 'snapshot', live: live({ phase: 'reading', text: '', tools: ['list_campaigns'], seq: 3 }) }])
    expect(reading).toMatchObject({ phase: 'waiting', text: '', step: 'reading', tools: ['list_campaigns'] })
    const missed = run([{ type: 'sent' }, { type: 'snapshot', live: live({ text: 'سأقرأ الحملات', seq: 2 }) }, { type: 'snapshot', live: live({ text: 'الربيع', seq: 6 }) }])
    expect(missed.text).toBe('الربيع')
  })

  it('shows Stop at once and ends when the thread is no longer thinking', () => {
    const stopping = run([{ type: 'sent' }, { type: 'snapshot', live: live({ text: 'x', seq: 2 }) }, { type: 'stop' }, { type: 'snapshot', live: live({ text: 'xy', seq: 3 }) }])
    expect(stopping.phase).toBe('stopping')
    expect(run([{ type: 'snapshot', live: live({ status: 'idle', seq: 9 }) }], stopping)).toEqual(initialStream)
  })

  it('ignores a buffer when nothing was sent and stop when idle', () => {
    expect(run([{ type: 'snapshot', live: live({ text: 'stale', seq: 9 }) }])).toEqual(initialStream)
    expect(run([{ type: 'stop' }])).toEqual(initialStream)
  })
})
