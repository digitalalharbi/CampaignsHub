import type { LiveReply, SpecialistKey } from './api'

/**
 * AGENTS-STREAM-001 — the reply being written, as the screen holds it between two reads of the live buffer.
 *
 * Pure, so the rules are testable without a network: a snapshot older than the one already shown (an out-of-order poll answer)
 * never rolls the text back; the text only grows within one reply; a new reply starts empty; Stop is shown at once and a
 * finished or failed thread ends the stream whatever the buffer still says.
 */
export interface StreamState {
  /** idle: nothing running · waiting: sent, no word yet · streaming: words arriving · stopping: Stop pressed */
  phase: 'idle' | 'waiting' | 'streaming' | 'stopping'
  text: string
  specialist: SpecialistKey | null
  /** reading | writing | routing — what the assistant is doing now (the chip under the bubble). */
  step: LiveReply['phase']
  tools: string[]
  seq: number
}

export type StreamAction =
  | { type: 'sent' }
  | { type: 'snapshot'; live: LiveReply }
  | { type: 'stop' }
  | { type: 'done' }

export const initialStream: StreamState = { phase: 'idle', text: '', specialist: null, step: null, tools: [], seq: 0 }

export function streamReducer(state: StreamState, action: StreamAction): StreamState {
  switch (action.type) {
    case 'sent':
      return { ...initialStream, phase: 'waiting' }
    case 'stop':
      return state.phase === 'idle' ? state : { ...state, phase: 'stopping' }
    case 'done':
      return initialStream
    case 'snapshot': {
      const live = action.live
      if (live.status !== 'thinking') return initialStream
      if (state.phase === 'idle') return state
      // An older answer arriving late changes nothing.
      if (live.seq < state.seq) return state
      // Within one reply the text only grows; a reading step after a written one clears it for the next round of words.
      const text = live.phase === 'writing' ? (live.text.length >= state.text.length || !live.text.startsWith(state.text.slice(0, live.text.length)) ? live.text : state.text) : live.phase === 'reading' ? '' : state.text
      return {
        phase: state.phase === 'stopping' ? 'stopping' : text !== '' ? 'streaming' : 'waiting',
        text,
        specialist: live.specialist ?? state.specialist,
        step: live.phase,
        tools: live.tools ?? [],
        seq: live.seq,
      }
    }
  }
}
