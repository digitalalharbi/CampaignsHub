import { create } from 'zustand'

/**
 * SAVE-STATE-001 — the one answer to «did it save?», for every save, create, edit and delete.
 *
 * A mutation that finished used to be visible only if its author remembered to draw something. Many did not, and
 * the Owner saw buttons that did nothing visible. The state lives in ONE store fed by the MutationCache
 * (see `app/providers.tsx`), and `SaveFeedback` draws it: «يحفظ…» while a request is in flight, «انحفظ» when the
 * server confirmed, «ما انحفظ» with the reason when it refused.
 *
 * It only ever reports what the request did. «انحفظ» is shown after a 2xx and never before one.
 */
export type SaveNoticeKind = 'saving' | 'saved' | 'failed'

export interface SaveNotice {
  /** Changes on every transition so the host can restart its timer even when the kind repeats. */
  seq: number
  kind: SaveNoticeKind
  /** The server's own reason where it gave one, already in the reader's language. Failed only. */
  reason?: string
}

interface SaveFeedbackState {
  pending: number
  notice: SaveNotice | null
  begin: () => void
  succeed: () => void
  fail: (reason: string) => void
  dismiss: () => void
}

let seq = 0

export const useSaveFeedback = create<SaveFeedbackState>((set, get) => ({
  pending: 0,
  notice: null,
  begin: () => set((s) => ({ pending: s.pending + 1, notice: { seq: ++seq, kind: 'saving' } })),
  succeed: () => {
    const pending = Math.max(0, get().pending - 1)
    // A failure stays on screen until read, even if a later request succeeds.
    const keep = get().notice?.kind === 'failed'
    set({ pending, notice: keep ? get().notice : pending > 0 ? { seq: ++seq, kind: 'saving' } : { seq: ++seq, kind: 'saved' } })
  },
  fail: (reason) => set((s) => ({ pending: Math.max(0, s.pending - 1), notice: { seq: ++seq, kind: 'failed', reason } })),
  dismiss: () => set((s) => (s.pending > 0 ? s : { notice: null })),
}))
